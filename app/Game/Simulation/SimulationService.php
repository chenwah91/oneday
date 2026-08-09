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

        // 锁后再读资源现值,确保是并发写入之后的最新值
        // (必须先于速率计算:加工建筑的"库存满足率"要拿现值当分子)
        $resources = DB::table('city_resources')->where('city_id', $lockedCity->id)
            ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all();
        $money = (float) $lockedCity->money;
        $minutes = $elapsed / 60.0;

        $ratePerMin = [];   // 资源 => 每分钟净速率
        $storageCap = SimConstants::BASE_STORAGE;
        $populationCap = 0;
        $maintenanceMoneyPerMin = 0.0;
        $processing = [];   // 加工建筑(有 input 的等级行):[['in' => [资源=>速率], 'out' => [资源=>速率]], ...]

        foreach ($levels as $lv) {
            $in = [];
            foreach (json_decode($lv->input_json ?: '[]', true) as $i) {
                $res = $i['resource'];
                $in[$res] = ($in[$res] ?? 0) + (float) $i['rate_per_min'];
            }

            $out = [];
            foreach (json_decode($lv->output_json ?: '[]', true) as $o) {
                $res = $o['resource']; $r = (float) $o['rate_per_min'];
                if ($res === '仓储容量') { $storageCap += $r; continue; }
                if ($res === '人口容量') { $populationCap += $r; continue; }
                if (in_array($res, SimConstants::CAPACITY_OUTPUTS, true)) { continue; } // 其他容量:M1 不结算
                $out[$res] = ($out[$res] ?? 0) + $r;
            }

            if ($in) {
                // 加工建筑:产出/投入都要按原料满足率打折,推迟到下面两遍计算里再并入净速率
                $processing[] = ['in' => $in, 'out' => $out];
            } else {
                // 无投入的建筑(采集/农田等):产出直接并入净速率
                foreach ($out as $res => $r) { $ratePerMin[$res] = ($ratePerMin[$res] ?? 0) + $r; }
            }

            // 维护粮食计入粮食支出(不是配方投入,不参与满足率;缺粮时仍由下面的 max(0,…) 夹住)
            $mf = (float) $lv->maintenance_food_per_min;
            if ($mf > 0) { $ratePerMin['粮食'] = ($ratePerMin['粮食'] ?? 0) - $mf; }
            // 维护资金不受满足率影响:建筑闲置也照付维护
            $maintenanceMoneyPerMin += (float) $lv->maintenance_money_per_min;
        }

        // 加工建筑限流:保守库存满足率(修 M1 经济漏洞——缺料照样出货 = 凭空造成品)
        // 两遍计算:先按库存算出每种原料的全局满足率,再让每栋建筑取其配方中最稀缺原料的满足率打折。
        // 保守性:不计入"本区间内上游同时在生产该原料"(如 P01 本区间产的面粉不能立刻喂给 P02),
        // 宁可少产不可多产;精确的分段结算留给 M2。
        if ($processing) {
            // 第一遍:汇总本区间各原料的总需求(多栋共享同一原料时天然合并,总消耗不会超过库存)
            $demand = [];
            foreach ($processing as $p) {
                foreach ($p['in'] as $res => $r) { $demand[$res] = ($demand[$res] ?? 0) + $r * $minutes; }
            }
            // 每种原料的全局满足率 = 库存 / 总需求,夹在 [0,1];
            // elapsed == 0 时 minutes=0 → 需求为 0 → 满足率取 1,返回的是"无约束名义速率",仅供前端显示
            $globalRate = [];
            foreach ($demand as $res => $need) {
                $globalRate[$res] = $need > 0
                    ? max(0.0, min(1.0, (float) ($resources[$res] ?? 0) / $need))
                    : 1.0;
            }
            // 第二遍:逐栋按配方满足率(取所有投入原料中最小的那个)缩放产出与投入
            foreach ($processing as $p) {
                $recipeRate = 1.0;
                foreach (array_keys($p['in']) as $res) { $recipeRate = min($recipeRate, $globalRate[$res] ?? 1.0); }
                foreach ($p['out'] as $res => $r) { $ratePerMin[$res] = ($ratePerMin[$res] ?? 0) + $r * $recipeRate; }
                foreach ($p['in'] as $res => $r) { $ratePerMin[$res] = ($ratePerMin[$res] ?? 0) - $r * $recipeRate; }
            }
        }

        // 人口粮食消耗(人口取自锁到的城市行,不依赖事务外的 Eloquent 模型)
        $ratePerMin['粮食'] = ($ratePerMin['粮食'] ?? 0) - (int) $lockedCity->population * SimConstants::FOOD_PER_CAPITA_PER_MIN;

        // elapsed == 0:跳过写库,但速率/容量仍照常算出返回
        if ($elapsed > 0) {
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
