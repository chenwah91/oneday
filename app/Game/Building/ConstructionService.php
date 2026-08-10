<?php

namespace App\Game\Building;

use App\Game\Resource\ResourceCode;
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

    // 拆除返还:已完工等级的累计建造材料 × 50%(v3.2 §10.9)
    public const DEMOLISH_REFUND_RATE = 0.50;

    // 取消返还:该次未完工工程材料 × 70%(v3.2 §3.2 / §16.3)。
    // 高于拆除的 50% 是刻意的 —— §10.9「拆除返还低于升级取消返还 70%,防止拆建套利」
    public const CANCEL_REFUND_RATE = 0.70;

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

    // 某一级的「建造 / 升级材料」= cost_json 去掉资金。
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

        return $cost;
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
            ->get(['cost_json']);

        $total = [];
        foreach ($rows as $row) {
            foreach (json_decode($row->cost_json ?: '[]', true) ?: [] as $res => $amt) {
                if ($res === ResourceCode::MONEY) {
                    continue;
                }
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
