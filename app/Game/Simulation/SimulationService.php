<?php

namespace App\Game\Simulation;

use App\Models\City;
use Illuminate\Support\Facades\DB;

// Time Delta 懒结算:按 now - last_simulated_at 应用生产/消耗/维护/粮食,资源夹在 [0, 存储上限]
class SimulationService
{
    public static function simulate(City $city): array
    {
        $now = now();
        $elapsed = max(0, $now->getTimestamp() - $city->last_simulated_at->getTimestamp());

        // 读 active 建筑实例的每级定义
        $levels = DB::table('city_building_instances as ci')
            ->join('building_level_definition as bl', function ($j) {
                $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
            })
            ->where('ci.city_id', $city->id)
            ->where('ci.status', 'active')
            ->select('bl.output_json', 'bl.input_json', 'bl.maintenance_money_per_min', 'bl.maintenance_food_per_min')
            ->get();

        $ratePerMin = [];   // 资源 => 每分钟净速率
        $storageCap = SimConstants::BASE_STORAGE;
        $populationCap = 0;
        $maintenanceMoneyPerMin = 0.0;

        foreach ($levels as $lv) {
            foreach (json_decode($lv->output_json ?: '[]', true) as $o) {
                $res = $o['resource']; $r = (float) $o['rate_per_min'];
                if ($res === '仓储容量') { $storageCap += $r; continue; }
                if ($res === '人口容量') { $populationCap += $r; continue; }
                if (in_array($res, SimConstants::CAPACITY_OUTPUTS, true)) { continue; } // 其他容量:M1 不结算
                $ratePerMin[$res] = ($ratePerMin[$res] ?? 0) + $r;
            }
            foreach (json_decode($lv->input_json ?: '[]', true) as $i) {
                $res = $i['resource']; $r = (float) $i['rate_per_min'];
                $ratePerMin[$res] = ($ratePerMin[$res] ?? 0) - $r;
            }
            // 维护粮食计入粮食支出
            $mf = (float) $lv->maintenance_food_per_min;
            if ($mf > 0) { $ratePerMin['粮食'] = ($ratePerMin['粮食'] ?? 0) - $mf; }
            $maintenanceMoneyPerMin += (float) $lv->maintenance_money_per_min;
        }

        // 人口粮食消耗
        $ratePerMin['粮食'] = ($ratePerMin['粮食'] ?? 0) - $city->population * SimConstants::FOOD_PER_CAPITA_PER_MIN;

        if ($elapsed > 0) {
            DB::transaction(function () use ($city, $ratePerMin, $elapsed, $storageCap, $maintenanceMoneyPerMin, $now) {
                // 先锁城市行:与 build/upgrade/demolish 用同一把锁串行化,避免用事务前的旧快照覆盖并发中的扣款/扣建
                $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

                $minutes = $elapsed / 60.0;
                // 加锁后再读资源现值,确保是并发写入之后的最新值
                $current = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id');

                foreach ($ratePerMin as $res => $rate) {
                    $base = (float) ($current[$res] ?? 0);
                    $val = $base + $rate * $minutes;
                    $val = max(0, min($val, $storageCap));
                    DB::table('city_resources')->updateOrInsert(
                        ['city_id' => $city->id, 'resource_id' => $res],
                        ['amount' => $val]
                    );
                }

                $money = max(0, (float) $locked->money - $maintenanceMoneyPerMin * $minutes);
                DB::table('cities')->where('id', $city->id)->update([
                    'money'             => $money,
                    'last_simulated_at' => $now,
                ]);
            });
        }

        return [
            'ratesPerMin'        => $ratePerMin,
            'storageCapacity'    => $storageCap,
            'populationCapacity' => $populationCap,
            'elapsedSeconds'     => $elapsed,
        ];
    }
}
