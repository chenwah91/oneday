<?php

namespace App\Game\Population;

use App\Game\Simulation\SimConstants;
use App\Game\Simulation\SimulationService;
use Illuminate\Support\Facades\DB;

// 存档回填(v3.2 §10.4「M2 接入劳动力系统时的存档兼容」):
//   1) 现有城市 population < 30 → 30
//   2) 按 building_id 排序对已建建筑逐栋补满工人,总分配 <= floor(population × 0.60)
//
// 只在 M2-C1 的补列迁移里调用一次,不是长期游戏规则(之后一律玩家自己分配)。
// 之所以从迁移里抽出来单独成类:回填规则要能被测试直接验(迁移跑完就没法再触发),
// 逻辑放在匿名迁移类里是测不到的。
final class WorkerBackfill
{
    // $note:进度回调(迁移里打印到控制台,测试里传 null)。返回本次回填的统计
    public static function run(?callable $note = null): array
    {
        $note ??= static fn (string $m) => null;

        $populationRaised = self::raisePopulation($note);
        [$cities, $workers] = self::fillWorkers($note);

        return ['populationRaised' => $populationRaised, 'cities' => $cities, 'workers' => $workers];
    }

    // 人口迁移 10 → 30:只抬升不下压,人口已经超过 30 的城市一律不动
    private static function raisePopulation(callable $note): int
    {
        $target = SimConstants::START_POPULATION;
        $affected = DB::table('cities')->where('population', '<', $target)->update(['population' => $target]);
        $note("人口回填:{$affected} 座城市的 population 提升到 {$target}");

        return $affected;
    }

    // 工人回填:逐城按 building_id 排序补满,总量夹在 floor(population × 0.60) 以内
    private static function fillWorkers(callable $note): array
    {
        $totalCities = 0;
        $totalWorkers = 0;

        foreach (DB::table('cities')->orderBy('id')->get(['id', 'population']) as $city) {
            $available = SimulationService::availableWorkers((int) $city->population);
            $remaining = $available;

            // 逐栋:按 building_id 排序(同 building_id 再按 id),保证任何库上的回填结果一致可复现
            $instances = DB::table('city_building_instances as ci')
                ->join('building_level_definition as bl', function ($j) {
                    $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
                })
                ->where('ci.city_id', $city->id)
                ->orderBy('ci.building_id')->orderBy('ci.id')
                ->get(['ci.id', 'ci.building_id', 'bl.worker_required']);

            $assignedInCity = 0;
            foreach ($instances as $inst) {
                $give = min((int) $inst->worker_required, $remaining);
                if ($give <= 0) {
                    continue; // 需求为 0(住宅/仓库)或名额已用完
                }
                DB::table('city_building_instances')->where('id', $inst->id)->update(['assigned_workers' => $give]);
                $remaining -= $give;
                $assignedInCity += $give;
            }

            if ($assignedInCity > 0) {
                $totalCities++;
                $totalWorkers += $assignedInCity;
                $note("city {$city->id}: 可用工人 {$available},{$instances->count()} 栋建筑共分配 {$assignedInCity} 人(剩余 {$remaining})");
            }
        }

        $note("工人回填完成:{$totalCities} 座城市,合计分配 {$totalWorkers} 人");

        return [$totalCities, $totalWorkers];
    }
}
