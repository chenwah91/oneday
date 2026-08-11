<?php

namespace App\Game\Event;

use App\Game\City\EraService;
use App\Game\Defense\DefenseService;
use App\Game\Resource\ResourceCode;
use App\Support\GameSetting;
use Illuminate\Support\Facades\DB;

// 事件的资格判定与权重合成(v3.2 §9.1 + backlog 9.D2 批准口径)。
//
// §9.1 的权重公式:候选权重 = baseWeight × 条件修正 × 城市状态修正 × 难度修正
//   · 条件修正   = 满足 1.0 / 不满足 0(9.D2 批准的**硬门槛**语义 —— 不满足就根本不进候选池);
//   · 城市状态修正 = 9.D2 的七条(粮食赤字 / 财政赤字 / 治理超载 / 低治安 / 高幸福 / 高健康 / 国防达标),
//                   每一条的系数与阈值都在 game_settings 里,运营可逐条调;
//   · 难度修正   = 全局设定,M3 恒 1.0(留位)。
//
// 性能纪律:整个判定过程**零逐事件查库**。
// snapshot() 一次性把这座城市的全部指标算出来(3 条查询 + $sim 里现成的值),
// 之后 30 个定义的条件判定与权重合成都是纯内存运算 —— 与 D0 Provider 的
// 「prepare 取数、循环内零查库」是同一条纪律。
final class EventCondition
{
    // ---------- 城市指标快照 ----------

    // 把条件判定要用的全部指标算成一个数组。$sim = 本次 SimulationService::applyLocked 的结果
    // (幸福 / 人口 / 治安 / 治理负载 / 财政预警全部取它,不再另算一份,避免两套口径)
    public static function snapshot(object $lockedCity, array $sim): array
    {
        $cityId = (int) $lockedCity->id;

        // ① 建筑聚合:一次查回「按 category / series 的已建成实例数与已派工人数」。
        //    只算 active 的实例 —— 在建 / 升级中的楼不产出,也不该让「农业建筑≥3」提前成立
        $buildings = DB::table('city_building_instances as ci')
            ->join('building_definition as bd', 'ci.building_id', '=', 'bd.building_id')
            ->where('ci.city_id', $cityId)
            ->where('ci.status', 'active')
            ->get(['bd.category', 'bd.series_key', 'ci.assigned_workers']);

        $countByCategory = [];
        $countBySeries = [];
        $workersByCategory = [];
        $workersBySeries = [];
        foreach ($buildings as $b) {
            $countByCategory[$b->category] = ($countByCategory[$b->category] ?? 0) + 1;
            $countBySeries[$b->series_key] = ($countBySeries[$b->series_key] ?? 0) + 1;
            $workersByCategory[$b->category] = ($workersByCategory[$b->category] ?? 0) + (int) $b->assigned_workers;
            $workersBySeries[$b->series_key] = ($workersBySeries[$b->series_key] ?? 0) + (int) $b->assigned_workers;
        }

        // ② 在建 / 升级中的实例数(EVT_BUILD_ACCIDENT 的条件)
        $constructing = (int) DB::table('city_building_instances')
            ->where('city_id', $cityId)
            ->whereIn('status', ['constructing', 'upgrading'])
            ->count();

        // ③ 高技能 NPC 数(EVT_BRAIN_DRAIN 的条件)。门槛走后台设定 —— §6 没有定义「高技能」。
        //    表可能还不存在(NPC 波次未落地的库),缺表按 0 处理而不是让整次结算炸掉
        $highSkillNpcs = 0;
        if (DB::getSchemaBuilder()->hasTable('city_npcs')) {
            $highSkillNpcs = (int) DB::table('city_npcs')
                ->where('city_id', $cityId)
                ->whereIn('status', ['idle', 'assigned'])
                ->where('skill_level', '>=', (int) GameSetting::get(GameSetting::EVENT_NPC_HIGH_SKILL_LEVEL))
                ->count();
        }

        $population = (float) ($sim['population'] ?? $lockedCity->population);
        $populationCapacity = (float) ($sim['populationCapacity'] ?? 0.0);

        // ④ 国防读数(M3-D5 W4-B):威胁需求 / 有效国防值 / 覆盖率 / 威胁档一次算齐。
        //    整块原样带走 —— EVT_RAID 的损失公式(EventEffect)与权重的「国防达标」修正
        //    都读这一份,和快照的 defense 区块同源同值,不在事件侧另算一次
        $defense = DefenseService::evaluate($lockedCity, $sim);

        return [
            'city_id'    => $cityId,
            'era_order'  => (int) ($lockedCity->era_order ?? 1),
            'population' => $population,
            'population_capacity' => $populationCapacity,
            'happiness'  => (float) ($sim['happiness'] ?? $lockedCity->happiness),
            'health'     => (float) ($sim['health'] ?? 0),
            'security'   => (float) ($sim['security'] ?? 0),
            'governance_load'    => (float) ($sim['governanceLoad'] ?? 0),
            'transport_capacity' => (float) ($sim['transportCapacity'] ?? 0),
            // 贸易容量(W5):内核已在容量提取处聚合并乘过 trade_capacity_pct,这里只取不再算第二份。
            // 内核未接入的库(或 elapsed=0 的极端调用)缺键 → 0.0,EVT_PORT_CONGESTION 的条件自然不成立
            'trade_capacity'     => (float) ($sim['tradeCapacity'] ?? 0),
            'storage_capacity'   => (float) ($sim['storageCapacity'] ?? 0),
            'money'      => (float) ($sim['money'] ?? $lockedCity->money),
            'resources'  => $sim['resources'] ?? [],
            'gross_production' => $sim['grossProductionPerMin'] ?? [],
            // 粮食赤字:取「最后一段的粮食净速率 < 0」——与 §10.1 的赤字计时同一个判定量
            'food_deficit'   => (float) ($sim['ratesPerMin'][ResourceCode::FOOD] ?? 0) < 0,
            // 财政赤字:直接用 §10.5 的三态预警,不在这里另立一套阈值
            'fiscal_deficit' => ($sim['fiscalWarning'] ?? 'none') !== 'none',
            'building_count_category' => $countByCategory,
            'building_count_series'   => $countBySeries,
            'workers_category'        => $workersByCategory,
            'workers_series'          => $workersBySeries,
            'constructing_count'      => $constructing,
            'npc_skill_count'         => $highSkillNpcs,
            // 威胁等级(M3-D5 W4-B):档序号 + 整块读数。
            // threat_rank 供条件判定(「威胁等级≥中」)与权重的「国防达标」修正比较;
            // defense 整块供 EVT_RAID 的损失公式取覆盖率,免得它再算一次(两处必须同值)
            'threat_rank'             => (int) $defense['threat_rank'],
            'threat_level'            => (string) $defense['threat_level'],
            'defense'                 => $defense,
            // 电力使用率(M.1 W4-A):内核已经在 $sim 里算好,这里只取不再算第二份。
            // 电力系统未接入的库(或 elapsed=0 的极端调用)缺键 → 0.0,即「不缺电」,EVT_BLACKOUT 不成立
            'power_usage_rate'        => (float) ($sim['powerUsageRate'] ?? 0),
        ];
    }

    // ---------- 条件判定 ----------

    // 定义的全部条件是否成立(9.D2:硬门槛,一条不成立就整条不成立)。
    // condition_json.unmapped_zh 里的条目**不参与判定** —— 它们承接不了,
    // 而带着无法判定条件的事件在 events.json 里一律 enabled=false(Fail Closed),
    // 所以不存在「因为条件被忽略而误触发」的口子。
    public static function satisfied(array $definition, array $metrics): bool
    {
        foreach ($definition['condition_json']['all'] ?? [] as $c) {
            if (! self::compare(self::value($c, $metrics), (string) $c['op'], (float) $c['value'])) {
                return false;
            }
        }

        return true;
    }

    // 单条条件的实测值。metric 已由 Seeder 做过 allowlist,这里 default 分支只是
    // 「库里被人手改成未知 metric」的兜底:返回 NAN 让比较恒为 false(Fail Closed)
    public static function value(array $condition, array $metrics): float
    {
        $keys = $condition['keys'] ?? [];
        $scope = (string) ($condition['scope'] ?? EventCode::SCOPE_CATEGORY);

        return match ((string) $condition['metric']) {
            EventCode::METRIC_BUILDING_COUNT => self::sumBy(
                $scope === EventCode::SCOPE_SERIES ? $metrics['building_count_series'] : $metrics['building_count_category'],
                $keys
            ),
            EventCode::METRIC_ASSIGNED_WORKERS => self::sumBy(
                $scope === EventCode::SCOPE_SERIES ? $metrics['workers_series'] : $metrics['workers_category'],
                $keys
            ),
            EventCode::METRIC_POPULATION      => $metrics['population'],
            EventCode::METRIC_RESOURCE_STOCK  => self::stock((string) $condition['resource'], $metrics),
            EventCode::METRIC_HAPPINESS       => $metrics['happiness'],
            EventCode::METRIC_SECURITY        => $metrics['security'],
            EventCode::METRIC_GOVERNANCE_LOAD => $metrics['governance_load'],
            EventCode::METRIC_TRANSPORT_CAPACITY => $metrics['transport_capacity'],
            // 贸易容量(W5):EVT_PORT_CONGESTION 的「贸易容量>800」
            EventCode::METRIC_TRADE_CAPACITY     => (float) ($metrics['trade_capacity'] ?? 0),
            // 住房空余:绝对人数与比率两个口径(§9.2 里两种写法都出现过)
            EventCode::METRIC_HOUSING_FREE      => max(0.0, $metrics['population_capacity'] - $metrics['population']),
            EventCode::METRIC_HOUSING_FREE_RATE => $metrics['population_capacity'] > 0
                ? max(0.0, 1.0 - $metrics['population'] / $metrics['population_capacity'])
                : 0.0,
            EventCode::METRIC_CONSTRUCTING_COUNT => (float) $metrics['constructing_count'],
            EventCode::METRIC_NPC_SKILL_COUNT    => (float) $metrics['npc_skill_count'],
            // 威胁等级按**档序号**比较(low 0 / medium 1 / high 2):
            // §9.2 EVT_RAID 的「威胁等级≥中」= threat_level >= 1
            EventCode::METRIC_THREAT_LEVEL       => (float) $metrics['threat_rank'],
            // 电力使用率(M.1 W4-A):EVT_BLACKOUT 的「电力使用率>85%」
            EventCode::METRIC_POWER_USAGE_RATE   => (float) ($metrics['power_usage_rate'] ?? 0),
            default => NAN,
        };
    }

    // 资金走 cities.money(它不在 city_resources 里),其余资源走库存表
    private static function stock(string $code, array $metrics): float
    {
        if ($code === ResourceCode::MONEY) {
            return $metrics['money'];
        }

        return (float) ($metrics['resources'][$code] ?? 0.0);
    }

    private static function sumBy(array $map, array $keys): float
    {
        $sum = 0.0;
        foreach ($keys as $key) {
            $sum += (float) ($map[$key] ?? 0);
        }

        return $sum;
    }

    // NAN 一律返回 false:未知 metric 不该让条件「碰巧成立」
    private static function compare(float $actual, string $op, float $expected): bool
    {
        if (is_nan($actual)) {
            return false;
        }

        return match ($op) {
            '>'  => $actual > $expected,
            '>=' => $actual >= $expected,
            '<'  => $actual < $expected,
            '<=' => $actual <= $expected,
            '==' => abs($actual - $expected) < 1e-9,
            '!=' => abs($actual - $expected) >= 1e-9,
            default => false,
        };
    }

    // ---------- 权重合成(§9.1 三个修正系数)----------

    // 返回 [权重, 三个修正系数的明细]。明细进 EVENT.TRIGGER 的审计 metadata:
    // 「为什么偏偏抽中这一条」半年后要能回答得出来
    public static function weight(array $definition, array $metrics): array
    {
        // ① 条件修正:硬门槛(9.D2 批准)。0 表示直接出局,后面两个系数不必再算
        if (! self::satisfied($definition, $metrics)) {
            return [0.0, ['condition' => 0.0, 'state' => 1.0, 'difficulty' => 1.0]];
        }

        // 时代门槛与条件同级:没到时代的事件不进候选池(§9.2 的 min_era 列)
        $orders = EraService::orders();
        if (($orders[$definition['min_era']] ?? PHP_INT_MAX) > $metrics['era_order']) {
            return [0.0, ['condition' => 0.0, 'state' => 1.0, 'difficulty' => 1.0, 'era_blocked' => true]];
        }

        $state = self::stateMultiplier($definition, $metrics);
        $difficulty = (float) GameSetting::get(GameSetting::EVENT_DIFFICULTY_MULTIPLIER);

        $weight = max(0.0, $definition['base_weight']) * $state * $difficulty;

        return [$weight, ['condition' => 1.0, 'state' => round($state, 6), 'difficulty' => $difficulty]];
    }

    // ② 城市状态修正:9.D2 批准的七条,逐条乘。
    //
    // 「乘」而不是「取最大」:同时粮食赤字 + 幸福达标时,两个方向的修正都应该体现
    //(灾年确实更容易出粮食事件,但城市幸福高又确实压得住一部分),取最大会让其中一条静默失效。
    public static function stateMultiplier(array $definition, array $metrics): float
    {
        $category = $definition['category'];
        $isNegative = $definition['event_type'] === EventCode::TYPE_NEGATIVE;
        $m = 1.0;

        if ($metrics['food_deficit'] && in_array($category, EventCode::CATEGORY_GROUP_FOOD, true)) {
            $m *= (float) GameSetting::get(GameSetting::EVENT_WEIGHT_FOOD_DEFICIT);
        }
        if ($metrics['fiscal_deficit'] && in_array($category, EventCode::CATEGORY_GROUP_FISCAL, true)) {
            $m *= (float) GameSetting::get(GameSetting::EVENT_WEIGHT_FISCAL_DEFICIT);
        }
        if ($metrics['governance_load'] > (float) GameSetting::get(GameSetting::EVENT_GOVERNANCE_OVERLOAD_LOAD)
            && in_array($category, EventCode::CATEGORY_GROUP_GOVERNANCE, true)) {
            $m *= (float) GameSetting::get(GameSetting::EVENT_WEIGHT_GOVERNANCE_OVERLOAD);
        }
        if ($metrics['security'] < (float) GameSetting::get(GameSetting::EVENT_LOW_SECURITY_THRESHOLD)
            && in_array($category, EventCode::CATEGORY_GROUP_SECURITY, true)) {
            $m *= (float) GameSetting::get(GameSetting::EVENT_WEIGHT_LOW_SECURITY);
        }
        // 高幸福压**全部**负面事件(9.D2 原文「happiness ≥75 对全部负面 ×0.7」)
        if ($isNegative && $metrics['happiness'] >= (float) GameSetting::get(GameSetting::EVENT_HIGH_HAPPINESS_THRESHOLD)) {
            $m *= (float) GameSetting::get(GameSetting::EVENT_WEIGHT_HIGH_HAPPINESS);
        }
        if ($metrics['health'] >= (float) GameSetting::get(GameSetting::EVENT_HIGH_HEALTH_THRESHOLD)
            && in_array($category, EventCode::CATEGORY_GROUP_CIVIL, true)) {
            $m *= (float) GameSetting::get(GameSetting::EVENT_WEIGHT_HIGH_HEALTH);
        }
        // 「国防达标」(9.D2 的第七条):W4-B 起改读 D5 的**威胁档**,不再用治安覆盖值作代理。
        // 达标 = 威胁档序号 ≤ 后台门槛(默认 0,即只有「安全」档算达标)。
        // 系数(event_weight_defense_ok = 0.5)与 category 分组(CATEGORY_GROUP_DEFENSE)一个没动 ——
        // 换的只是「怎么判定达标」这一句;旧的 event_defense_ok_security_min 就此停用(登记保留)
        if ($metrics['threat_rank'] <= (float) GameSetting::get(GameSetting::EVENT_DEFENSE_OK_MAX_THREAT_RANK)
            && in_array($category, EventCode::CATEGORY_GROUP_DEFENSE, true)) {
            $m *= (float) GameSetting::get(GameSetting::EVENT_WEIGHT_DEFENSE_OK);
        }

        return $m;
    }
}
