<?php

namespace App\Game\Simulation;

use App\Models\City;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Time Delta 懒结算:按 now - last_simulated_at 应用生产/消耗/维护/粮食,资源夹在 [0, 存储上限]
class SimulationService
{
    // 兼容包装:自开事务 + 锁城市行,再走 applyLocked(全项目唯一开结算事务的入口)
    // 只读路径(快照)用它;写路径(建造/升级/拆除)已自带事务与锁,应直接调 applyLocked
    public static function simulate(City $city): array
    {
        return DB::transaction(function () use ($city) {
            // 先锁城市行:与 build/upgrade/demolish 用同一把锁串行化,避免用事务前的旧快照覆盖并发中的扣款/扣建
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            return self::applyLocked($locked, now());
        });
    }

    // 锁内结算:把 [last_simulated_at, $now] 这段时间的产出/消耗/维护结清并落库
    //
    // 前置约定:调用方必须已在事务内对该 cities 行 lockForUpdate,并把锁到的行原样传进来。
    // 本方法不自开事务、不再加锁;建筑实例与资源现值都在锁之后才读,确保拿到并发写入之后的最新值。
    //
    // 返回值除速率/容量/经过秒数外,另带结算后的最新 money 与 resources,
    // 供调用方(建造/升级)直接做余额校验,不必再查一次库,也避免误用结算前的旧值。
    public static function applyLocked(object $lockedCity, CarbonInterface $now): array
    {
        $lastSimulatedAt = Carbon::parse($lockedCity->last_simulated_at);
        $elapsed = max(0, $now->getTimestamp() - $lastSimulatedAt->getTimestamp());
        // 离线封顶:超过上限的部分不结算(但 last_simulated_at 仍推进到 $now,否则会积压反复重算)
        $elapsed = min($elapsed, SimConstants::MAX_OFFLINE_SECONDS);

        // 锁后再读 active 建筑实例的每级定义,消除"锁前读实例"的竞态
        $levels = DB::table('city_building_instances as ci')
            ->join('building_level_definition as bl', function ($j) {
                $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
            })
            ->where('ci.city_id', $lockedCity->id)
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

        // 人口粮食消耗(人口取自锁到的城市行,不依赖事务外的 Eloquent 模型)
        $ratePerMin['粮食'] = ($ratePerMin['粮食'] ?? 0) - (int) $lockedCity->population * SimConstants::FOOD_PER_CAPITA_PER_MIN;

        // 锁后再读资源现值,确保是并发写入之后的最新值
        $resources = DB::table('city_resources')->where('city_id', $lockedCity->id)
            ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all();
        $money = (float) $lockedCity->money;

        // elapsed == 0:跳过写库,但速率/容量仍照常算出返回
        if ($elapsed > 0) {
            $minutes = $elapsed / 60.0;

            foreach ($ratePerMin as $res => $rate) {
                $val = (float) ($resources[$res] ?? 0) + $rate * $minutes;
                $val = max(0, min($val, $storageCap));
                DB::table('city_resources')->updateOrInsert(
                    ['city_id' => $lockedCity->id, 'resource_id' => $res],
                    ['amount' => $val]
                );
                $resources[$res] = $val;
            }

            $money = max(0, $money - $maintenanceMoneyPerMin * $minutes);
            DB::table('cities')->where('id', $lockedCity->id)->update([
                'money'             => $money,
                'last_simulated_at' => $now,
            ]);
        }

        return [
            'ratesPerMin'        => $ratePerMin,
            'storageCapacity'    => $storageCap,
            'populationCapacity' => $populationCap,
            'elapsedSeconds'     => $elapsed,
            'money'              => $money,
            'resources'          => $resources,
        ];
    }
}
