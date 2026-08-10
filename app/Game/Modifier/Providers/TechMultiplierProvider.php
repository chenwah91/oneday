<?php

namespace App\Game\Modifier\Providers;

use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;
use App\Game\Simulation\SimConstants;
use App\Game\Technology\TechService;
use Illuminate\Support\Facades\DB;

// tech 乘区(v3.2 §5,M2-B3 迁入):multiplier = 1 + 0.02 × 该建筑所属分支的已解锁科技条数。
//
// 效果口径完全照 §5 科技表的 effect_code 列:50 条科技一律 `<branch>_base_efficiency_2pct`,
// 即「解锁一条科技 → 该分支建筑基础效率 +2%」,同分支多条线性累加(不是复利)。
//
// 建筑 → 分支不另立映射表,直接用定义数据推:
//   building_definition.tech_id(§3.4 的 tech_id 列,94 栋全部非空)→ technology_definition.branch。
// 即「解锁这栋楼的那条科技属于哪条分支,这栋楼就吃哪条分支的加成」——
// 这样新增建筑只要填 tech_id 就自动归位,不必回来改代码(CLAUDE §13 数据驱动)。
//
// 两次查询都在 prepare()(锁内、分段循环之外)完成;
// 一条科技都没解锁的城(绝大多数新城)在第一次查询之后就直接返回,不发第二条 SQL。
final class TechMultiplierProvider extends MultiplierProvider
{
    // building_id => 乘数,只含真正拿到加成的建筑;查不到的建筑在 multiplierFor 里回落 1.0
    private array $byBuilding = [];

    public function slot(): string
    {
        return ModifierTarget::SLOT_TECH;
    }

    public function prepare(ModifierContext $context, array $units): void
    {
        $this->byBuilding = [];

        if (! $context->buildingIds) {
            return;
        }

        // 已解锁科技按分支计数(researching 不算解锁,与 BuildService 的科技闸门同一口径)
        $counts = [];
        $rows = DB::table('city_technologies as ct')
            ->join('technology_definition as td', 'ct.tech_id', '=', 'td.tech_id')
            ->where('ct.city_id', $context->cityId)
            ->where('ct.status', TechService::STATUS_UNLOCKED)
            ->groupBy('td.branch')
            ->selectRaw('td.branch as branch, count(*) as unlocked_count')
            ->get();
        foreach ($rows as $row) {
            $counts[$row->branch] = (int) $row->unlocked_count;
        }
        if (! $counts) {
            return;
        }

        $branchOf = DB::table('building_definition as bd')
            ->join('technology_definition as td', 'bd.tech_id', '=', 'td.tech_id')
            ->whereIn('bd.building_id', $context->buildingIds)
            ->pluck('td.branch', 'bd.building_id')->all();

        foreach ($branchOf as $buildingId => $branch) {
            $unlocked = $counts[$branch] ?? 0;
            if ($unlocked > 0) {
                $this->byBuilding[$buildingId] = 1.0 + $unlocked * SimConstants::TECH_BRANCH_EFFICIENCY_BONUS;
            }
        }
    }

    public function multiplierFor(array $unit): float
    {
        // 没解锁(或该分支一条都没解锁)时映射表里没有这个键 → 保持 1.0
        return (float) ($this->byBuilding[$unit['buildingId'] ?? ''] ?? 1.0);
    }
}
