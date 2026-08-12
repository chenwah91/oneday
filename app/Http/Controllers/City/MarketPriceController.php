<?php

namespace App\Http\Controllers\City;

use App\Game\Market\MarketDefinition;
use App\Game\Market\PriceEngine;
use App\Game\Market\TradeService;
use App\Game\Modifier\ConsumptionPoint;
use App\Game\Modifier\ModifierTarget;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Support\ApiResponse;
use App\Support\GameSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 市场价目表(只读)。
//
// 为什么是独立端点而不是塞进城市快照(backlog §5.3 明文):
//   ① 价目是**全服共享**的,与城市无关 —— 塞进快照等于给每个玩家重算一遍同一张表;
//   ② CityController 是两个并行 agent 的争抢点,市场不进去就少一处冲突;
//   ③ 快照体积不该被 26 行价目撑大(手机端每 10 秒拉一次)。
//
// 本端点**不创建城市、不做任何写入**:它是纯 GET,不该有副作用
// (其他 Mutation 端点会 CityFactory::createForUser 顺手建城,那是写路径的语义)。
// W7 起响应里多了一项**本城**读数(buy_price_pct),取的是**只读查询**出来的城市 ——
// 查不到城(还没建)一律按 0 处理,绝不在 GET 里顺手建城。
class MarketPriceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $epoch = PriceEngine::currentEpoch();
        $definitions = MarketDefinition::all();

        // 本城的事件价格冲击(W7 契约补齐):只读、批量、一趟查完。
        //
        // ⚠️ 口径与 TradeService 的消费点严格一致,**但只是显示值**:
        //   · 它是**该城买入侧**的附加冲击,卖出侧恒 0(理由见 ModifierTarget::MARKET_PRICE_PCT
        //     与 TradeService 第 11' 步:两侧同步上抬 = 抛货套利的印钞机);
        //   · **绝不乘进 price / base_price** —— 那两个是全服口径,一座城市的事件不许改别人的行情。
        // 成交时仍由 TradeService 在城市行锁内重新取值(这里的快照可能已经过期),
        // 前端拿它只是为了把「本城为什么比别人贵」显示出来。
        $city = City::where('user_id', $request->user()->id)->first();
        $buyPricePct = $city === null
            ? []
            : ConsumptionPoint::pctByResource(
                ModifierTarget::MARKET_PRICE_PCT,
                (int) $city->id,
                array_keys($definitions)
            );

        $prices = [];

        foreach ($definitions as $resourceId => $def) {
            $price = PriceEngine::priceFor($def, $epoch);
            $tradeable = MarketDefinition::isTradeable($def);
            $feeRate = MarketDefinition::effectiveFeeRate($def);

            $prices[] = [
                // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
                'resource_code'   => $resourceId,
                'rs_code'         => $def['rs_code'],
                'market_category' => $def['market_category'],
                'trade_mode'      => $def['trade_mode'],
                // tradeable 是给前端「按钮要不要置灰」用的派生值:
                // trade_mode 有三种取值,前端不该自己维护「哪几种算可交易」的清单
                'tradeable'       => $tradeable,
                'base_price'      => round($def['base_price'], 4),
                // 服务器基准价(未含手续费与滑点)。**全服口径,不含本城的事件冲击**
                'price'           => $price,
                // 参考买卖价:§8.1 的 buyPrice / sellPrice = price × (1 ± feeRate)。
                // **仅为零滑点时的参考值** —— 真实成交价还要叠一层随数量变化的滑点,
                // 以服务端返回的成交结果为准(§45:客户端算出来的价永远不作数)
                'buy_price'       => $tradeable ? round($price * (1 + $feeRate), 4) : null,
                'sell_price'      => $tradeable ? round($price * (1 - $feeRate), 4) : null,
                'fee_rate'        => round($feeRate, 6),
                // 本城买入侧的事件价格冲击(W7):0 = 没有事件在生效。
                // 前端预估买价 = price × (1 + 滑点率) × max(0, 1 + buy_price_pct) × (1 + 有效费率);
                // 卖出侧没有对应字段,因为卖出侧口径上就恒 0(见类顶部说明)
                'buy_price_pct'   => round((float) ($buyPricePct[$resourceId] ?? 0.0), 6),
                'volatility'      => round($def['volatility'], 4),
                'min_price'       => round($def['min_price'], 4),
                'max_price'       => round($def['max_price'], 4),
                // 有效流动性(W7)= 定义表 base_liquidity × 全局倍率。
                // 它是滑点公式的**分母**:滑点率 = min(上限, 系数 × 数量 / 有效流动性)。
                // 不下发的话前端只能拿 base_liquidity 自己乘一遍全局倍率 —— 倍率是后台设定,
                // 前端拿不到,预估必然与服务器对不上(§45 之下预估对不上就等于没有预估)
                'effective_liquidity' => $tradeable ? round(MarketDefinition::effectiveLiquidity($def), 4) : 0.0,
                // 额度提示:**流动性口径**的单窗 / 每小时上限(§8.1「不超过流动性的 10%」)。
                // W5 起玩家的实际额度还要再取一层 min:城市侧的贸易吞吐口径
                //(= (基础额度 + 全城 trade_capacity) × 系数 × 窗口分钟数,backlog §5.4)。
                // 那一层**不在本端点算**:它依赖城市的结算结果(trade_capacity 要跑一次容量聚合),
                // 而本端点刻意保持纯 GET、零副作用、全服共享一份结果。
                // 玩家的真实额度由交易响应回带(window_quota / window_remaining),
                // 被额度挡下时 MARKET_LIMIT_REACHED 会同时给出两条口径,好判断该等下一窗还是该建市场
                'window_quota'    => $tradeable ? round(MarketDefinition::windowQuota($def), 4) : 0.0,
                'hourly_quota'    => $tradeable ? round(MarketDefinition::hourlyQuota($def), 4) : 0.0,
            ];
        }

        return ApiResponse::ok(['data' => [
            // 本 epoch 与下一 epoch 的时刻:前端据此显示「距离下次调价 xx 秒」并安排刷新
            'window_index'    => $epoch,
            'window_seconds'  => PriceEngine::windowSeconds(),
            'window_start_at' => PriceEngine::epochStartsAt($epoch)->toIso8601String(),
            'next_window_at'  => PriceEngine::epochEndsAt($epoch)->toIso8601String(),
            'server_time'     => now()->toIso8601String(),
            // 停市时价目照常返回(玩家仍看得见行情),只有买卖被挡
            'market_enabled'  => GameSetting::get(GameSetting::MARKET_ENABLED) === true,
            // ---- 买卖预估所需的全局参数(W7)----
            //
            // 只下发**算预估要用的三个数**,一个不多:
            //   slippage_coefficient  滑点系数 k(后台可调),配合逐资源的 effective_liquidity 算滑点率;
            //   max_slippage_rate     滑点率的硬夹取上限(TradeService 的常量),满额大单会撞到它;
            //   market_max_order_quantity  单笔数量硬上限(§69 的绝对天花板,与流动性额度是两道独立的闸)。
            //
            // ⚠️ 刻意**不**下发的:MARKET_PRICE_SECRET 与由它派生的一切(noise 值、下一窗价格)。
            // 玩家能算出下一窗的价 = 无风险套利(PriceEngine 顶部「服务器权威」那一段的全部意义)。
            // volatility 照旧下发是安全的:它只是振幅上界(定义表公开数值),给不出方向也给不出具体值
            'slippage_coefficient'      => (float) GameSetting::get(GameSetting::MARKET_SLIPPAGE_COEFFICIENT),
            'max_slippage_rate'         => TradeService::MAX_SLIPPAGE_RATE,
            'market_max_order_quantity' => (int) GameSetting::get(GameSetting::MARKET_MAX_ORDER_QUANTITY),
            'prices'          => $prices,
        ]]);
    }
}
