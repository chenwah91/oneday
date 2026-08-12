<?php

namespace App\Game\Market;

use App\Game\Modifier\ConsumptionPoint;
use App\Game\Modifier\ModifierTarget;
use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\GameSetting;
use App\Support\Idempotency;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

// 市场买卖:完整安全链(停市 / 输入 / 可交易 / 幂等 / Revision / 结算 / 服务器算价 / 四机制 /
// 余额库存 / 仓储 / 事务行锁 / Invariant / 流水 / 审计 / revision+1)。
// 结构与 BuildService 逐段对齐 —— 经济类 Mutation 只有一个模板,不另起一套。
//
// ══ §13 四机制(「手续费、成交量上限、滑点和移动平均价格必须同时存在」)在本文件的落点 ══
//   ① 手续费    → feeRate = 定义表 fee_rate × 全局倍率 × max(0, 1 + Σmarket_fee_pct),
//                 买卖两个方向都是**玩家出**、共用同一个费率(W7 接线,见第 11 步);
//   ② 成交量上限 → 单笔 ≤ 单窗额度,且 本窗累计 / 本小时累计 都不得超额(city_market_quota);
//   ③ 滑点      → 9.C4:slippage = k × 本笔数量 / 有效流动性,买价上抬、卖价下压;
//   ④ 移动平均   → PriceEngine::movingAverage,最近 N 个已结束窗口的全服供需。
//   四者缺一个,市场就是印钞机(见 backlog §11.2 的测算)。任何「临时关掉一条」的改动都要重跑反刷测试。
//
// ══ 为什么同一 epoch 内「买了立刻卖」必然亏(反刷的数学保证)══════════════════════
//   同一 epoch 内基准价 P 恒定(见 PriceEngine 顶部:移动平均不含当前窗)。设滑点率 s、费率 f:
//       买入每单位付出 P × (1 + s) × (1 + f)
//       卖出每单位收到 P × (1 − s) × (1 − f)
//   往返回收率 = (1 − s)(1 − f) / [(1 + s)(1 + f)]。对任意 s ≥ 0、f > 0 恒 < 1。
//   即使 s = 0(数量小到滑点可忽略),f = 0.03 也留下 1 − 0.97/1.03 ≈ 5.83% 的净损失。
//   这就是 §13 要求四机制「同时存在」的原因:手续费管住小单,滑点管住大单。
//
//   ── W7 接入 market_fee_pct 之后这条结论**不变** ────────────────────────────────
//   商人 NPC 只能把有效费率压到 f' = max(0, f × (1 + Σpct)),**下限就是 0、绝不为负**。
//   把 f 换成 f' 重走一遍化简:净额 = −2·P·q·(s + f')。f' = 0 时净额 = −2·P·q·s,
//   只要成交量 q > 0 且滑点系数 k > 0,滑点就独自把往返按在负数上 ——
//   减费能让玩家少亏,但永远不可能让他赚(MarketFeePctTest 用最大减免直接验这条)。
final class TradeService
{
    public const SIDE_BUY = 'buy';
    public const SIDE_SELL = 'sell';

    // 滑点率的硬夹取上限。后台把系数调到 5、又赶上满额单时,理论滑点率可达 0.5;
    // 夹在 0.95 是为了「卖出价永不为负、买入价永不翻到天上」的绝对兜底,正常参数下够不着。
    // public(W7):GET /api/market/prices 要把它下发给前端,前端的买卖预估才和服务器夹在同一处
    public const MAX_SLIPPAGE_RATE = 0.95;

    // 有效费率的下限。market_fee_pct 累加到 −100% 以下时费率被夹成 0(白嫖手续费),
    // 但**绝不允许变成负数** —— 负费率 = 交易所倒贴钱,同窗往返立刻转正,那是一台印钞机。
    // 反套利的闭式因此仍然成立:净额 = −2·P·q·(s + f'),f' ≥ 0(见类顶部的证明)
    public const MIN_FEE_RATE = 0.0;

    // 买入。返回资源/资金 diff + 本次成交明细
    public static function buy(City $city, string $resourceId, int $quantity, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        return self::trade($city, self::SIDE_BUY, $resourceId, $quantity, $idempotencyKey, $expectedRevision);
    }

    // 卖出
    public static function sell(City $city, string $resourceId, int $quantity, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        return self::trade($city, self::SIDE_SELL, $resourceId, $quantity, $idempotencyKey, $expectedRevision);
    }

    private static function trade(City $city, string $side, string $resourceId, int $quantity, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        $action = $side === self::SIDE_BUY ? AuditAction::MARKET_BUY : AuditAction::MARKET_SELL;

        // ---- 1. 停市开关(§11.2「经济出事时能一键停市」)----
        // 排在最前:停市期间连幂等键都不该落,否则重开市后旧 key 会带着旧参数重放
        if (GameSetting::get(GameSetting::MARKET_ENABLED) !== true) {
            throw new GameRuleException(ErrorCode::MARKET_CLOSED, 422);
        }

        // ---- 2. 输入校验(§69「校验 quantity / 防止负数 / NaN / 超大数字」)----
        // Controller 已用 FormRequest 拦过一遍(integer / min:1);这里是服务层的第二道 ——
        // 服务层不能假设自己只被 Controller 调用(测试、后台、将来的批量工具都可能直接进来)
        $maxQuantity = (int) GameSetting::get(GameSetting::MARKET_MAX_ORDER_QUANTITY);
        if ($quantity <= 0 || $quantity > $maxQuantity) {
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        // ---- 3. 可交易性:未登记 / non_tradeable / capacity_contract 一律拒 ----
        $def = MarketDefinition::find($resourceId);
        if (! MarketDefinition::isTradeable($def)) {
            throw new GameRuleException(ErrorCode::RESOURCE_NOT_TRADEABLE, 422);
        }

        // 请求指纹:只含业务参数,不含 expected_revision(重试时 revision 可能已变)。
        // 注意 side 必须进指纹 —— 同一个 key 先 buy 后 sell 是典型的客户端重试串味,必须被 409 挡下
        $requestHash = Idempotency::hash($action, ['resource_id' => $resourceId, 'quantity' => $quantity, 'side' => $side]);

        // ---- 4. 幂等(锁前快速路径)----
        if ($idempotencyKey !== null) {
            $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, $action, $requestHash);
            if ($existing) {
                return self::snapshotDiff($city->fresh());
            }
        }

        return DB::transaction(function () use ($city, $def, $side, $resourceId, $quantity, $idempotencyKey, $expectedRevision, $requestHash, $action) {
            // ---- 5. 行锁:与 build / upgrade / 快照用同一把城市行锁 ----
            // 并发双花靠它:两个「卖同一批库存」的请求会在这里串行,
            // 第二个进来时下面的 applyLocked 读到的已经是第一个扣完之后的余额
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后重新校验,关闭「锁前检查、锁后写入」之间的并发窗口(TOCTOU)
            if ($idempotencyKey !== null) {
                $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, $action, $requestHash);
                if ($existing) {
                    return self::snapshotDiff($city->fresh());
                }
            }

            // ---- 6. Revision ----
            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // ---- 7. 锁内先跑 Time Delta 结算(CLAUDE §51)----
            // 不结算就成交,玩家可以拿「离线期间早已被吃掉的旧快照库存」卖钱
            $sim = SimulationService::applyLocked($locked, now());

            // ---- 8. 服务器算价(§45 / §66:客户端不得提交成交价,这里根本不接收价格入参)----
            $epoch = PriceEngine::currentEpoch();
            $midPrice = PriceEngine::priceFor($def, $epoch);

            // ---- 9. 四机制之②:成交量上限(单笔 + 本窗累计 + 本小时累计)----
            //
            // W5 起额度多了一层**城市侧分母**(backlog §5.4):
            //     单窗上限 = min(流动性口径, (基础额度 + 全城 trade_capacity) × 系数 × 窗口分钟数)
            // 贸易容量取自本次结算结果(已乘过 trade_capacity_pct),不在这里另查建筑表 ——
            // 那会立刻裂成第二份口径。这也是 C01~C04 / M01 / M02 六栋建筑第一次真正生效的地方:
            // 没有市场建筑的城市仍能小额买卖(基础额度,后台可调),但做大宗就必须建市场。
            $tradeCapacity = (float) ($sim['tradeCapacity'] ?? 0.0);
            $windowQuota = MarketDefinition::cityWindowQuota($def, $tradeCapacity);
            $hourlyQuota = MarketDefinition::cityHourlyQuota($def, $tradeCapacity);

            $windowUsed = (float) DB::table('city_market_quota')
                ->where('city_id', $city->id)->where('resource_id', $resourceId)->where('window_index', $epoch)
                ->value('traded_qty');

            // 本小时 = 最近 ceil(3600 / 窗口秒) 个窗口(含当前窗)。
            // 复合主键 (city_id, resource_id, window_index) 的前缀正好覆盖这个范围查询,不必另建索引
            $epochsPerHour = (int) ceil(3600 / PriceEngine::windowSeconds());
            $hourUsed = (float) DB::table('city_market_quota')
                ->where('city_id', $city->id)->where('resource_id', $resourceId)
                ->where('window_index', '>', $epoch - $epochsPerHour)
                ->sum('traded_qty');

            // 买卖合并计入同一个额度:限制的是换手量而不是净头寸,
            // 否则「买 10% + 卖 10%」会变成一窗 20% 的换手,反刷上限形同虚设
            if ($quantity > $windowQuota || $windowUsed + $quantity > $windowQuota || $hourUsed + $quantity > $hourlyQuota) {
                throw new GameRuleException(ErrorCode::MARKET_LIMIT_REACHED, 422, [
                    'window_quota'      => round($windowQuota, 4),
                    'window_remaining'  => round(max(0.0, $windowQuota - $windowUsed), 4),
                    'hourly_quota'      => round($hourlyQuota, 4),
                    'hourly_remaining'  => round(max(0.0, $hourlyQuota - $hourUsed), 4),
                    // 两条口径都回给前端:玩家才看得出「是市场吃不下,还是我的贸易容量不够」——
                    // 前者只能等下一窗,后者要去建 C 系列建筑,提示不同才有意义
                    'liquidity_quota'   => round(MarketDefinition::windowQuota($def), 4),
                    'trade_capacity'    => round($tradeCapacity, 4),
                    'trade_capacity_quota' => round(MarketDefinition::tradeThroughputQuota($tradeCapacity), 4),
                ]);
            }

            // ---- 10. 四机制之③:滑点(9.C4,§8.1 缺、§13 要求)----
            $liquidity = MarketDefinition::effectiveLiquidity($def);
            $slippageRate = min(
                self::MAX_SLIPPAGE_RATE,
                (float) GameSetting::get(GameSetting::MARKET_SLIPPAGE_COEFFICIENT) * $quantity / $liquidity
            );

            // ---- 11. 四机制之①:手续费(D0.3 的 market_fee_pct,**唯一消费点就是这里**)----
            //
            // 投稿者:§6.3 的 7 位商人类 NPC(N046 −6% / N065 −8% / N086 −10% / N099 −5% /
            // N114 −7% / N127 −10% / N146 −10%,specs 里是**负值 = 减费**)。
            //
            // 口径:有效费率 = 定义表 fee_rate × 全局倍率 × max(0, 1 + Σpct)。
            //   · 一处算、买卖两侧共用同一个 $feeRate —— 只减买入侧的话,「卖出免费」会立刻变成
            //     单边套利的方向盘;§6.3 的文案也是「市场手续费 −X%」,没有分侧的语义;
            //   · 夹到 ≥ 0(MIN_FEE_RATE):七位商人全招齐 Σ = −0.56 还够不着 −1,但事件 / 后台
            //     可以填出更负的数。负费率 = 交易所倒贴,同窗往返当场转正 —— 那正是 §13 四机制要堵的缝。
            //     夹了之后闭式仍然是 净额 = −2·P·q·(s + f'),f' ≥ 0:**滑点独自兜底**,
            //     免费手续费也刷不出钱(MarketFeePctTest 把这条钉成用例)。
            //
            // 取数在**城市行锁内、任何写入之前**一次性取值(与三步之前的 tradeCapacity 同一批读数):
            // 本方法没有分段循环,但纪律照旧 —— 一次成交只读一次投稿,不许边算边查。
            $feePct = ConsumptionPoint::pct(ModifierTarget::MARKET_FEE_PCT, (int) $city->id);
            $baseFeeRate = MarketDefinition::effectiveFeeRate($def);
            $feeRate = max(self::MIN_FEE_RATE, $baseFeeRate * max(0.0, 1.0 + $feePct));

            // ---- 11'. 事件价格冲击(D0.3 的 market_price_pct,**唯一消费点就是这里**)----
            //
            // 投稿者:§9.2 的 EVT_OIL_SHOCK(石油/燃料价格 +40%)与 EVT_SPECULATION(随机战略资源 +25%~50%)。
            // 取数走 ConsumptionPoint::pctForResource —— 全城作用域 + 该资源作用域两项相加。
            //
            // ══ 三条口径,缺一条这里就是印钞机(裁决理由,改动前请先读完)══════════════
            // ① **全服定价一个字不动**:PriceEngine 的价格是全服共享的纯函数(f(资源, epoch)),
            //    而这两条事件是**城市级**实例。让一座城市的事件去推全服价格 = 玩家可以用自己的事件
            //    改别人的行情,也会让「同一 epoch 内价格恒定」这条反刷前提失效。
            // ② **只作用于买入侧**:两条都是 negative 事件,惩罚就该落在「买东西更贵」上。
            //    若卖出价同步上抬,玩家只要囤着货等事件,事件期间抛售、事件后买回,
            //    每轮净赚 pct×数量(受额度限制但可反复),那是一台确定性印钞机 ——
            //    §13 要求四机制「同时存在」正是为了堵这种缝,不能自己再开一条。
            // ③ 因此 EVT_SPECULATION 的选项 B「顺势交易:一次高风险套利机会」维持 unmapped:
            //    它要的正是被 ② 否掉的那个方向,宁可不做,不发明。
            //
            // 夹到 ≥ 0:后台把强度调成大负数也不该出现负价格。
            $eventPricePct = $side === self::SIDE_BUY
                ? ConsumptionPoint::pctForResource(ModifierTarget::MARKET_PRICE_PCT, (int) $city->id, $resourceId)
                : 0.0;
            $eventPriceFactor = max(0.0, 1.0 + $eventPricePct);

            // 方向:买入把价格推高(玩家吃亏)、卖出把价格压低(玩家吃亏)。
            // 两个方向都对玩家不利,这正是滑点存在的意义 —— 它是「大额冲击市场」的代价,不是手续费的第二份
            $effectiveUnit = $side === self::SIDE_BUY
                ? $midPrice * (1.0 + $slippageRate) * $eventPriceFactor
                : $midPrice * (1.0 - $slippageRate);

            $gross = $effectiveUnit * $quantity;
            $fee = $gross * $feeRate;
            // 滑点金额:与基准价的差额,只作报表与审计口径,不额外扣一次钱(已经含在 gross 里)
            $slippageAmount = abs($midPrice * $slippageRate * $quantity);

            $held = (float) ($sim['resources'][$resourceId] ?? 0.0);
            $money = (float) $sim['money'];

            // 资金一律先落到 2 位小数再动账:cities.money 是 DECIMAL(16,2),
            // 不先取整的话「审计里记的 delta」与「数据库实际变动」会差一个数据库四舍五入的尾巴,
            // 资金对账时那点差额永远找不出来源
            if ($side === self::SIDE_BUY) {
                $cost = round($gross + $fee, 2);
                $moneyDelta = -$cost;

                // ---- 12. 校验余额 ----
                if ($money < $cost) {
                    throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
                }

                // ---- 13. 校验仓储(买入撑爆仓储一律拒绝,不静默截断)----
                // 静默少给的资源比直接报错更难查:玩家付了钱却没拿到货,只会变成客服工单
                if ($held + $quantity > (float) $sim['storageCapacity']) {
                    throw new GameRuleException(ErrorCode::STORAGE_FULL, 422, [
                        'storage_capacity' => round((float) $sim['storageCapacity'], 4),
                        'current_amount'   => round($held, 4),
                    ]);
                }

                $newHeld = $held + $quantity;
                $newMoney = $money - $cost;
            } else {
                $proceeds = round(max(0.0, $gross - $fee), 2);
                $moneyDelta = $proceeds;

                // ---- 12'. 校验库存 ----
                if ($held < $quantity) {
                    throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
                }

                $newHeld = $held - $quantity;
                $newMoney = $money + $proceeds;
            }

            // ---- 14. 落库:资源与资金 ----
            // updateOrInsert 而不是 increment:市场是玩家第一次拿到某种资源的入口
            // (电子元件全服 0 产出,city_resources 里根本没有这一行),increment 会静默漏掉
            DB::table('city_resources')->updateOrInsert(
                ['city_id' => $city->id, 'resource_id' => $resourceId],
                ['amount' => $newHeld]
            );
            DB::table('cities')->where('id', $city->id)->update(['money' => $newMoney]);

            // 额度累计:同城同资源同窗只有一行,复合主键保证并发下不会写出两行
            DB::table('city_market_quota')->updateOrInsert(
                ['city_id' => $city->id, 'resource_id' => $resourceId, 'window_index' => $epoch],
                ['traded_qty' => $windowUsed + $quantity]
            );

            // ---- 15. Invariant(§52):资源与资金均 ≥ 0 ----
            $negative = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($negative > 0 || (float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            // 实际成交单价 = 玩家真正付出/收到的每单位金额(含手续费与滑点)。
            // 前端要显示的是这个数,不是基准价
            $unitPrice = abs($moneyDelta) / $quantity;

            // ---- 16. 成交流水(§69「市场记录必须可以追踪」)----
            $now = now();
            $orderId = DB::table('city_market_orders')->insertGetId([
                'city_id'         => $city->id,
                'user_id'         => $city->user_id,
                'resource_id'     => $resourceId,
                'side'            => $side,
                'quantity'        => $quantity,
                'mid_price'       => round($midPrice, 4),
                'slippage_rate'   => round($slippageRate, 4),
                'unit_price'      => round($unitPrice, 4),
                'fee'             => round($fee, 4),
                'slippage'        => round($slippageAmount, 4),
                'money_delta'     => round($moneyDelta, 4),
                'window_index'    => $epoch,
                'request_id'      => Context::get('request_id'),
                'idempotency_key' => $idempotencyKey,
                'created_at'      => $now,
            ]);

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, $action, $requestHash);
            }

            // ---- 17. 审计(§56 经济类日志必须带资源变化)----
            //
            // ⚠️ 记账口径(9.C5 批准,与税收严格分开,防双计):
            //   · 市场买卖的钱**直接进出 cities.money**,就是上面第 14 步那一笔,再无第二处扣加;
            //   · `tradeIncome`(§10.5)只统计 C 系列贸易建筑的资金产出,**不含市场成交额** ——
            //     市场成交不是「城市的经营收入」,它是玩家用资产换资金的等价交换,
            //     算进 tradeIncome 会让同一笔钱在财政面板里出现两次;
            //   · `marketFees` 只用于面板显示与审计(就是下面 metadata 里的 fee),
            //     **绝不二次扣款** —— 手续费已经含在 money_delta 里了。
            //     任何人以后要在结算内核里「再减一次 marketFees」都是双计,请先回来读这段。
            $delta = [$resourceId => $side === self::SIDE_BUY ? $quantity : -$quantity, ResourceCode::MONEY => round($moneyDelta, 4)];

            AuditLogger::record($action, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'market_order', 'entity_id' => (string) $orderId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'delta_json' => $delta, 'idempotency_key' => $idempotencyKey,
                'metadata_json' => [
                    'resource'      => $resourceId,
                    'quantity'      => $quantity,
                    'unit_price'    => round($unitPrice, 4),
                    'mid_price'     => round($midPrice, 4),
                    'fee'           => round($fee, 4),
                    // fee_rate 记的是**实际**费率(已含商人减免);base_fee_rate 是定义表 × 全局倍率的原值,
                    // fee_pct 是本次吃到的减免比例。三个都记 —— 与建造的 durationSeconds /
                    // baseDurationSeconds 同一条理由:半年后要能回答「他这笔为什么只收了这么点手续费」
                    'fee_rate'      => round($feeRate, 6),
                    'base_fee_rate' => round($baseFeeRate, 6),
                    'fee_pct'       => round($feePct, 6),
                    'slippage'      => round($slippageAmount, 4),
                    'slippage_rate' => round($slippageRate, 6),
                    'money_delta'   => round($moneyDelta, 4),
                    'window_index'  => $epoch,
                    // 事件价格冲击与城市侧额度:半年后回查「他那天为什么买得这么贵 / 为什么只让买这么点」
                    'event_price_pct' => round($eventPricePct, 6),
                    'trade_capacity'  => round($tradeCapacity, 4),
                    'window_quota'    => round($windowQuota, 4),
                ],
            ]);

            return self::snapshotDiff($city->fresh(), $delta, [
                'order_id'         => (int) $orderId,
                'side'             => $side,
                'resource_id'      => $resourceId,
                'quantity'         => $quantity,
                'mid_price'        => round($midPrice, 4),
                'unit_price'       => round($unitPrice, 4),
                'fee'              => round($fee, 4),
                // 实际费率与本次吃到的减免:前端要能解释「为什么这笔比价目表上的 fee_rate 便宜」
                'fee_rate'         => round($feeRate, 6),
                'fee_pct'          => round($feePct, 6),
                'slippage'         => round($slippageAmount, 4),
                'slippage_rate'    => round($slippageRate, 6),
                'money_delta'      => round($moneyDelta, 4),
                'window_index'     => $epoch,
                // 额度提示一律按**城市侧口径**返回(= 玩家实际能用的那条),
                // 流动性口径只在被拒时一并给出(见 MARKET_LIMIT_REACHED 的 payload)
                'window_quota'     => round($windowQuota, 4),
                'window_remaining' => round(max(0.0, $windowQuota - $windowUsed - $quantity), 4),
                'hourly_remaining' => round(max(0.0, $hourlyQuota - $hourUsed - $quantity), 4),
                // 本次成交实际吃到的事件价格冲击(0 = 没有事件在生效;卖出侧恒 0,见上面的口径说明)
                'event_price_pct'  => round($eventPricePct, 6),
            ]);
        });
    }

    // 返回资源/revision 简要 diff(形状与 BuildService::snapshotDiff 一致,前端一套解析代码通吃)。
    // $trade 非 null 时附带本次成交明细;幂等重放路径拿不到本次成交,该键缺省不出现
    private static function snapshotDiff(City $city, array $delta = [], ?array $trade = null): array
    {
        $diff = [
            'revision'  => (int) $city->revision,
            'resources' => DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
            'money'     => (float) $city->money,
            'delta'     => $delta,
        ];

        if ($trade !== null) {
            $diff['trade'] = $trade;
        }

        return $diff;
    }
}
