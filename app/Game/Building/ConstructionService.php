<?php

namespace App\Game\Building;

use App\Game\Modifier\ConsumptionPoint;
use App\Game\Modifier\ModifierTarget;
use App\Game\Resource\ResourceCode;
use App\Support\GameSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

// 建筑生命周期(M2-C5,v3.2 §16.3 / §10.9 / §3.2):施工计时的状态机 + 返还材料的算法。
//
// 状态机(status 列,varchar(16)):
//   (无) --建造--> constructing --完工--> active --升级--> upgrading --完工--> active(level + 1)
//                      |                                      |
//                      +--拆除 = 取消建造(退 70%)            +--取消升级(退 70%)/ 拆除(70% + 50%)
//
// 计时口径(v3.2 §16.3):
//   - construction_finished_at 一律由服务器时间算出,客户端倒计时只用于显示;
//   - 完工不靠定时任务扫描,而是下一次 Time Delta 结算 / 快照 / 命令时懒翻正(settleFinished);
//   - 改客户端时间不可能提前完工。
//
// 本类不自开事务:settleFinished 由 SimulationService::applyLocked 在城市行锁内调用,
// 返还相关方法由 UpgradeService / DemolishController 在各自事务内调用。
class ConstructionService
{
    // 实例状态(v3.2 §12.1 建议字段 status 的取值,M2 只用到这三个)
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONSTRUCTING = 'constructing';
    public const STATUS_UPGRADING = 'upgrading';

    // 拆除返还:已完工等级的累计建造材料 × 50%(v3.2 §10.9)。
    // W11-A 起改成后台设定 demolish_refund_rate,本常量保留为**登记默认值的出处**,读取一律走下面的方法
    public const DEMOLISH_REFUND_RATE = 0.50;

    // 取消返还:该次未完工工程材料 × 70%(v3.2 §3.2 / §16.3)。
    // 高于拆除的 50% 是刻意的 —— §10.9「拆除返还低于升级取消返还 70%,防止拆建套利」。
    // 两者的高低关系由 GameSetting 的跨键约束(demolish ≤ cancel)在写入时钉死,后台调不反
    public const CANCEL_REFUND_RATE = 0.70;

    // ---- 返还比例的唯一读取口(后台可调)----

    public static function demolishRefundRate(): float
    {
        return (float) GameSetting::get(GameSetting::DEMOLISH_REFUND_RATE);
    }

    public static function cancelRefundRate(): float
    {
        return (float) GameSetting::get(GameSetting::CANCEL_REFUND_RATE);
    }

    // 施工加速的最低速度倍率(安全夹取):construction_speed_pct 合计到 −90% 以下就按 0.1 倍速算。
    // 现有数据里这条 target 只有正值(N008 +8% / N030 +25% / IT005 +8% / IT013 +15%),
    // 这个夹子是为「将来有负面事件投稿 −100% 甚至更负」准备的:除数不能是 0 或负数,
    // 否则工期会变成无穷大或负数(负工期 = 建筑瞬间完工,那是能被刷的)
    public const CONSTRUCTION_SPEED_FLOOR = 0.1;

    // ---- 施工加速(D0.3 登记的 construction_speed_pct,**唯一消费点就是这里**)----

    // 本城当前的施工速度倍率。投稿者:§6.3 的建造类 NPC 特性(N008 / N030)与 §7 的建造工具(IT005 / IT013)。
    //
    // 「速度 +25%」按**速度**解释,不是按工期解释:工期 = 基础工期 ÷ (1 + pct)。
    // 两种解释在小数值上差别不大(+8% → 92.6% vs 92%),但速度式永远得不到 0 工期,
    // 而工期式在 pct 累加到 +100% 时会直接把工期打成 0(建筑瞬间完工)—— 这正是要避开的。
    public static function speedMultiplier(int $cityId): float
    {
        $pct = ConsumptionPoint::pct(ModifierTarget::CONSTRUCTION_SPEED_PCT, $cityId);

        return max(self::CONSTRUCTION_SPEED_FLOOR, 1.0 + $pct);
    }

    // 折减后的实际工期(秒)。$baseSeconds = building_level_definition.duration_seconds。
    //
    // 顺序固定:基础秒数 × 全局工期倍率(construction_duration_multiplier)÷ (1 + 施工加速)。
    // 先乘倍率再除加速 —— 倍率是「这张定义表整体贵/便宜多少」,加速是「这座城此刻快多少」,
    // 反过来算(先除后乘)在数学上等价,但语义上会让人以为加速是作用在定义值上的。
    //
    // 取整方向:round。工期不是资源,四舍五入不产生任何可套利的零头
    //(材料返还那种「玩家净收益」才需要 floor 的保守方向,见 scale() 的注释)。
    // 下限 0:后台把速度调到极限时至少还是「立刻完工」,不会出现负的完工时刻
    public static function plannedSeconds(int $cityId, int $baseSeconds): int
    {
        $scaled = max(0, $baseSeconds) * (float) GameSetting::get(GameSetting::CONSTRUCTION_DURATION_MULTIPLIER);

        return (int) max(0, round($scaled / self::speedMultiplier($cityId)));
    }

    // ---- 成本全局倍率(build_cost_multiplier / upgrade_cost_multiplier)----

    // cost_json 折算:资金与材料**同乘**同一个倍率(不给「材料涨价但资金不变」这种半吊子口径)。
    //
    // 倍率恰为 1.0 时**原样返回**:默认配置下这条路径一个字节都不改,老数据的整数成本不会被取整摸过一遍。
    // 非 1.0 时向上取整 —— 与 §3.2 升级成本用 ceil 同一个保守方向:零头永远算玩家的,不给套利留缝。
    public static function scaleCost(array $cost, float $multiplier): array
    {
        if ($multiplier === 1.0) {
            return $cost;
        }

        $out = [];
        foreach ($cost as $res => $amt) {
            $out[$res] = ceil((float) $amt * $multiplier);
        }

        return $out;
    }

    // 某一级的成本倍率:L1 = 建造,L2/L3 = 升级。
    // 返还路径也走它 —— 「按 0.3 倍价建、按原价的一半退」是一台印钞机,两侧必须同一个倍率
    public static function costMultiplierForLevel(int $level): float
    {
        return (float) GameSetting::get(
            $level <= 1 ? GameSetting::BUILD_COST_MULTIPLIER : GameSetting::UPGRADE_COST_MULTIPLIER
        );
    }

    // ---- 懒完工 ----

    // 把到点的工程翻正,并把「完工点已在本次结算窗口之前」的实例放进生产集合。
    //
    // 调用约定:必须在 cities 行锁内、在读取建筑实例之前调用(SimulationService::applyLocked 开头)。
    //
    // $now         本次结算的终点(服务器时间)
    // $windowStart 本次结算的窗口起点 = $now − elapsed(离线封顶后的 elapsed)
    //
    // 刻意不写审计:懒结算会在每次快照轮询里被调用,完工翻正写审计等于让玩家挂机就能刷审计表,
    // 与 §53「审计回答谁在什么请求里改了什么」的用途也不符(没有玩家请求,是时间到了)。
    // 完工事件的可追溯性由建造 / 升级下单时那条审计(metadata 里带 finished_at)承担。
    public static function settleFinished(int $cityId, CarbonInterface $now, CarbonInterface $windowStart): void
    {
        // 先用一次 exists 探路:applyLocked 在事务内高频调用,绝大多数调用没有任何工程到点,
        // 不该无脑发三条 UPDATE(§38 反「Simulation 全表扫描」)
        $pending = DB::table('city_building_instances')
            ->where('city_id', $cityId)
            ->whereNotNull('construction_finished_at')
            ->where('construction_finished_at', '<=', $now)
            ->exists();
        if (! $pending) {
            return;
        }

        // 1) 建造完工:constructing → active。等级不动(建造出来本来就是 L1)
        DB::table('city_building_instances')
            ->where('city_id', $cityId)
            ->where('status', self::STATUS_CONSTRUCTING)
            ->whereNotNull('construction_finished_at')
            ->where('construction_finished_at', '<=', $now)
            ->update(['status' => self::STATUS_ACTIVE, 'updated_at' => $now]);

        // 2) 升级完工:upgrading → active 且 level + 1。
        //    升级期间 level 一直是旧等级(取消升级要退回旧级,拆除要按旧级算 50% 返还),
        //    真正写级的唯一落点就在这里
        DB::table('city_building_instances')
            ->where('city_id', $cityId)
            ->where('status', self::STATUS_UPGRADING)
            ->whereNotNull('construction_finished_at')
            ->where('construction_finished_at', '<=', $now)
            ->update(['status' => self::STATUS_ACTIVE, 'level' => DB::raw('level + 1'), 'updated_at' => $now]);

        // 3) 完工点已落在窗口起点之前 → 清掉完工戳,本次结算起正式计入生产。
        //    窗口中途才完工的实例这里不清,于是本次结算不产出、下次才产出 ——
        //    宁可少产一点,也绝不允许「完工瞬间追溯整个窗口的产出」(与建造不追溯同一条纪律)
        DB::table('city_building_instances')
            ->where('city_id', $cityId)
            ->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('construction_finished_at')
            ->where('construction_finished_at', '<=', $windowStart)
            ->update(['construction_finished_at' => null, 'updated_at' => $now]);
    }

    // ---- 材料与返还 ----

    // 某一级的「建造 / 升级材料」= cost_json 去掉资金,再乘该级的成本全局倍率。
    // 资金不返还是 v3.2 的明文规则(§10.9「只返还建造材料 / 资金不返还」、§3.2「资金不返还」)
    public static function materialCost(string $buildingId, int $level): array
    {
        $row = DB::table('building_level_definition')
            ->where('building_id', $buildingId)->where('level', $level)
            ->first(['cost_json']);
        if (! $row) {
            return [];
        }

        $cost = json_decode($row->cost_json ?: '[]', true) ?: [];
        unset($cost[ResourceCode::MONEY]);

        return self::scaleCost($cost, self::costMultiplierForLevel($level));
    }

    // 「已完工等级」的累计建造材料:L1 建造 + 已完成的每一次升级(§10.9「原始建造材料」)。
    // 一次查库取回 <= $level 的所有级,避免逐级往返
    public static function cumulativeMaterialCost(string $buildingId, int $level): array
    {
        if ($level < 1) {
            return [];
        }

        $rows = DB::table('building_level_definition')
            ->where('building_id', $buildingId)->where('level', '<=', $level)
            ->get(['level', 'cost_json']);

        $total = [];
        foreach ($rows as $row) {
            // 逐级各乘自己那一档的倍率:L1 吃 build_cost_multiplier,L2/L3 吃 upgrade_cost_multiplier ——
            // 玩家当初就是分别按这两个价付的,退也得分别按这两个价退
            $cost = json_decode($row->cost_json ?: '[]', true) ?: [];
            unset($cost[ResourceCode::MONEY]);
            foreach (self::scaleCost($cost, self::costMultiplierForLevel((int) $row->level)) as $res => $amt) {
                $total[$res] = ($total[$res] ?? 0) + (float) $amt;
            }
        }

        return $total;
    }

    // 按比例折算并取整。
    //
    // 取整方向(v3.2 §10.9 只写了「返还数量按服务器规则取整」,没定方向,本次按下取整):
    // 返还是玩家的净收益,向下取整对玩家不利但对经济安全有利 —— 与 §3.2 升级成本用 ceil(对玩家不利)
    // 是同一个保守方向,合起来杜绝「反复建了拆、拆了建」把零头刷成正收益的套利。
    public static function scale(array $cost, float $rate): array
    {
        $out = [];
        foreach ($cost as $res => $amt) {
            $value = floor((float) $amt * $rate);
            if ($value > 0) {
                $out[$res] = $value;
            }
        }

        return $out;
    }

    // 两份返还相加(拆除一栋升级中的建筑 = 取消升级返还 + 拆除返还)
    public static function mergeRefund(array $a, array $b): array
    {
        foreach ($b as $res => $amt) {
            $a[$res] = ($a[$res] ?? 0) + $amt;
        }

        return $a;
    }

    // 发放返还:按内核夹紧口径写库(资源夹在仓储上限,资金不受限 —— 但资金本来就不返还)。
    //
    // 与管理员补偿的「超上限直接拒绝」不同:返还是玩家主动操作的必然结果,
    // 拒绝会让仓库满的玩家连拆房都做不到,所以这里按内核口径截断(SimulationService 也是把资源夹在 [0, cap])。
    // 被截掉的量记进审计 metadata,事后能回答「玩家为什么少收到了材料」。
    //
    // 已经高于上限的历史存量不会被这次返还压低:只在「还有空间」时往上加。
    //
    // 返回 [已发放, 被截断](两份都是 资源 => 数量)
    public static function grantRefund(int $cityId, array $refund, float $storageCapacity): array
    {
        $granted = [];
        $truncated = [];

        foreach ($refund as $res => $amt) {
            $amt = (float) $amt;
            if ($amt <= 0) {
                continue;
            }

            $before = (float) (DB::table('city_resources')
                ->where('city_id', $cityId)->where('resource_id', $res)->value('amount') ?? 0);
            $room = max(0.0, $storageCapacity - $before);
            $give = min($amt, $room);

            if ($give > 0) {
                // 复合主键 (city_id, resource_id):玩家从没持有过该资源时 upsert 直接建行
                DB::table('city_resources')->upsert(
                    [['city_id' => $cityId, 'resource_id' => $res, 'amount' => $before + $give]],
                    ['city_id', 'resource_id'],
                    ['amount']
                );
                $granted[$res] = $give;
            }

            if ($amt - $give > 0) {
                $truncated[$res] = $amt - $give;
            }
        }

        return [$granted, $truncated];
    }
}
