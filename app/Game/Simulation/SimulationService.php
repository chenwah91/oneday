<?php

namespace App\Game\Simulation;

use App\Models\City;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Time Delta 懒结算:按 now - last_simulated_at 应用生产/消耗/维护/粮食,资源夹在 [0, 存储上限]
class SimulationService
{
    // 逐建筑实例的乘区初始值(全部 1.0 = 无影响)。
    // M2 各生产系统(科技/NPC/工具/电力/物流/事件)各占一个乘区,只写自己那一格,互不覆盖。
    // M1 阶段七项恒为 1.0,乘数积恒等于 1.0,所以行为与"无乘数"的旧实现完全一致。
    private const BASE_MULTIPLIERS = [
        'worker'    => 1.0, // 用工满足率(人力不足打折)
        'power'     => 1.0, // 电力满足率(按建筑)
        'logistics' => 1.0, // 物流满足率
        'tech'      => 1.0, // 科技加成(按建筑分支)
        'npc'       => 1.0, // NPC 加成(按实例)
        'tool'      => 1.0, // 工具加成(按实例)
        'event'     => 1.0, // 事件加成
    ];

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

    // 乘区连乘:把一栋建筑实例的七个乘区乘成一个系数。
    // M2 各系统在此接入自己的乘区(只改 multipliers 里对应的一格,不要另起加法通道);
    // §13 的生产倍率硬封顶(2.75×)将来也统一落在这里夹紧,不要散落进各系统内部。
    private static function multiplierProduct(array $multipliers): float
    {
        $product = 1.0;
        foreach ($multipliers as $m) { $product *= (float) $m; }

        return $product;
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
        // ci.id / ci.building_id / ci.level 一并取出:M2 的乘数按实例/按建筑生效,聚合前必须能区分是哪一栋
        $levels = DB::table('city_building_instances as ci')
            ->join('building_level_definition as bl', function ($j) {
                $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
            })
            ->where('ci.city_id', $lockedCity->id)
            ->where('ci.status', 'active')
            ->select(
                'ci.id as instance_id', 'ci.building_id', 'ci.level',
                'bl.output_json', 'bl.input_json', 'bl.maintenance_money_per_min', 'bl.maintenance_food_per_min'
            )
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

        // 逐建筑实例中间结构:M2 没有一个乘数是全局的(科技按分支、NPC/工具按实例、电力按建筑),
        // 所以产出/投入必须先落到"每栋一行",算完各自的乘区与满足率,最后才聚合成全城速率。
        // 每行结构:
        //   ['instanceId','buildingId','level',
        //    'grossOut' => [资源=>速率], 'grossIn' => [资源=>速率],
        //    'maintMoney', 'maintFood',
        //    'multipliers' => [七个乘区], 'recipeRate' => 原料满足率]
        $units = [];

        foreach ($levels as $lv) {
            $grossIn = [];
            foreach (json_decode($lv->input_json ?: '[]', true) as $i) {
                $res = $i['resource'];
                $grossIn[$res] = ($grossIn[$res] ?? 0) + (float) $i['rate_per_min'];
            }

            $grossOut = [];
            foreach (json_decode($lv->output_json ?: '[]', true) as $o) {
                $res = $o['resource']; $r = (float) $o['rate_per_min'];
                // 容量类产出不进 grossOut:它不是"每分钟入库的资源",在这里就提取成全城容量累计
                if ($res === '仓储容量') { $storageCap += $r; continue; }
                if ($res === '人口容量') { $populationCap += $r; continue; }
                if (in_array($res, SimConstants::CAPACITY_OUTPUTS, true)) { continue; } // 其他容量:M1 不结算
                $grossOut[$res] = ($grossOut[$res] ?? 0) + $r;
            }

            $units[] = [
                'instanceId'  => (int) $lv->instance_id,
                'buildingId'  => $lv->building_id,
                'level'       => (int) $lv->level,
                'grossOut'    => $grossOut,
                'grossIn'     => $grossIn,
                'maintMoney'  => (float) $lv->maintenance_money_per_min,
                'maintFood'   => (float) $lv->maintenance_food_per_min,
                'multipliers' => self::BASE_MULTIPLIERS,
                'recipeRate'  => 1.0,
            ];
        }

        // 维护:粮食与资金都不进配方,不受乘区与满足率影响(建筑闲置也照付)
        // 维护粮食计入粮食支出;缺粮时仍由下面落库处的 max(0,…) 夹住
        foreach ($units as $u) {
            if ($u['maintFood'] > 0) { $ratePerMin['粮食'] = ($ratePerMin['粮食'] ?? 0) - $u['maintFood']; }
            $maintenanceMoneyPerMin += $u['maintMoney'];
        }

        // 加工建筑限流:保守库存满足率(修 M1 经济漏洞——缺料照样出货 = 凭空造成品)
        // 两遍计算:先按库存算出每种原料的全局满足率,再让每栋建筑取其配方中最稀缺原料的满足率。
        // 保守性:不计入"本区间内上游同时在生产该原料"(如 P01 本区间产的面粉不能立刻喂给 P02),
        // 宁可少产不可多产;精确的分段结算留给 M2。
        //
        // 第一遍:汇总本区间各原料的总需求(多栋共享同一原料时天然合并,总消耗不会超过库存)。
        // 需求按"实例的乘数积"折算——乘区会同时放大投入与产出,不折算会低估或高估耗料。
        $demand = [];
        foreach ($units as $u) {
            if (! $u['grossIn']) { continue; }
            $mult = self::multiplierProduct($u['multipliers']);
            foreach ($u['grossIn'] as $res => $r) { $demand[$res] = ($demand[$res] ?? 0) + $r * $mult * $minutes; }
        }
        // 每种原料的全局满足率 = 库存 / 总需求,夹在 [0,1];
        // elapsed == 0 时 minutes=0 → 需求为 0 → 满足率取 1,返回的是"无约束名义速率",仅供前端显示
        $globalRate = [];
        foreach ($demand as $res => $need) {
            $globalRate[$res] = $need > 0
                ? max(0.0, min(1.0, (float) ($resources[$res] ?? 0) / $need))
                : 1.0;
        }
        // 第二遍:把配方满足率(取所有投入原料中最小的那个)写回该实例所在行
        foreach ($units as $k => $u) {
            if (! $u['grossIn']) { continue; }
            $recipeRate = 1.0;
            foreach (array_keys($u['grossIn']) as $res) { $recipeRate = min($recipeRate, $globalRate[$res] ?? 1.0); }
            $units[$k]['recipeRate'] = $recipeRate;
        }

        // 最终聚合:每行有效速率 = (grossOut − grossIn) × 乘数积 × 满足率,逐行并入全城净速率。
        // gross 产出与 gross 消耗分开累计、不提前合并成 net:
        // production_utilization、food_net_rate 的分子分母、§68 理论最大 delta 都要的是分开的值。
        $grossProduction = [];   // 资源 => 每分钟 gross 产出(已含乘数与满足率)
        $grossConsumption = [];  // 资源 => 每分钟 gross 配方消耗(已含乘数与满足率;不含维护与人口吃粮)
        foreach ($units as $u) {
            $factor = self::multiplierProduct($u['multipliers']) * $u['recipeRate'];
            foreach ($u['grossOut'] as $res => $r) {
                $eff = $r * $factor;
                $grossProduction[$res] = ($grossProduction[$res] ?? 0) + $eff;
                $ratePerMin[$res] = ($ratePerMin[$res] ?? 0) + $eff;
            }
            foreach ($u['grossIn'] as $res => $r) {
                $eff = $r * $factor;
                $grossConsumption[$res] = ($grossConsumption[$res] ?? 0) + $eff;
                $ratePerMin[$res] = ($ratePerMin[$res] ?? 0) - $eff;
            }
        }

        // 人口粮食消耗(人口取自锁到的城市行,不依赖事务外的 Eloquent 模型)
        $ratePerMin['粮食'] = ($ratePerMin['粮食'] ?? 0) - (int) $lockedCity->population * SimConstants::FOOD_PER_CAPITA_PER_MIN;

        // elapsed == 0:跳过写库,但速率/容量仍照常算出返回
        if ($elapsed > 0) {
            // 批量落库:city_resources 已有复合主键 (city_id, resource_id),一次 upsert 取代逐资源往返
            // (资源种类会涨到 31 种,逐条 updateOrInsert 每次快照要 30~60 次往返)
            $rows = [];
            foreach ($ratePerMin as $res => $rate) {
                $val = (float) ($resources[$res] ?? 0) + $rate * $minutes;
                $val = max(0, min($val, $storageCap));
                $rows[] = ['city_id' => $lockedCity->id, 'resource_id' => $res, 'amount' => $val];
                $resources[$res] = $val;
            }
            // 注意:不要用 upsert 的返回值做业务判断——MySQL 与 MariaDB 的受影响行数语义不同
            if ($rows) {
                DB::table('city_resources')->upsert($rows, ['city_id', 'resource_id'], ['amount']);
            }

            $money = max(0, $money - $maintenanceMoneyPerMin * $minutes);
            DB::table('cities')->where('id', $lockedCity->id)->update([
                'money'             => $money,
                'last_simulated_at' => $now,
            ]);
        }

        return [
            'ratesPerMin'             => $ratePerMin,
            'storageCapacity'         => $storageCap,
            'populationCapacity'      => $populationCap,
            'elapsedSeconds'          => $elapsed,
            'money'                   => $money,
            'resources'               => $resources,
            // 新增(不改动上面任何既有键的语义):全城 gross 产出/消耗速率,供 M2 各系统与 §68 检测层取用
            'grossProductionPerMin'   => $grossProduction,
            'grossConsumptionPerMin'  => $grossConsumption,
        ];
    }
}
