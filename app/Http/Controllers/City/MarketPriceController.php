<?php

namespace App\Http\Controllers\City;

use App\Game\Market\MarketDefinition;
use App\Game\Market\PriceEngine;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\GameSetting;
use Illuminate\Http\JsonResponse;

// 市场价目表(只读)。
//
// 为什么是独立端点而不是塞进城市快照(backlog §5.3 明文):
//   ① 价目是**全服共享**的,与城市无关 —— 塞进快照等于给每个玩家重算一遍同一张表;
//   ② CityController 是两个并行 agent 的争抢点,市场不进去就少一处冲突;
//   ③ 快照体积不该被 26 行价目撑大(手机端每 10 秒拉一次)。
//
// 本端点**不创建城市、不做任何写入**:它是纯 GET,不该有副作用
// (其他 Mutation 端点会 CityFactory::createForUser 顺手建城,那是写路径的语义)。
class MarketPriceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $epoch = PriceEngine::currentEpoch();
        $prices = [];

        foreach (MarketDefinition::all() as $resourceId => $def) {
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
                // 服务器基准价(未含手续费与滑点)
                'price'           => $price,
                // 参考买卖价:§8.1 的 buyPrice / sellPrice = price × (1 ± feeRate)。
                // **仅为零滑点时的参考值** —— 真实成交价还要叠一层随数量变化的滑点,
                // 以服务端返回的成交结果为准(§45:客户端算出来的价永远不作数)
                'buy_price'       => $tradeable ? round($price * (1 + $feeRate), 4) : null,
                'sell_price'      => $tradeable ? round($price * (1 - $feeRate), 4) : null,
                'fee_rate'        => round($feeRate, 6),
                'volatility'      => round($def['volatility'], 4),
                'min_price'       => round($def['min_price'], 4),
                'max_price'       => round($def['max_price'], 4),
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
            'prices'          => $prices,
        ]]);
    }
}
