<?php

namespace App\Game\Simulation;

use App\Game\Building\ConstructionService;
use App\Game\Resource\ResourceCode;
use App\Game\Technology\TechService;
use App\Models\City;
use App\Support\GameSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Time Delta 懒结算:按 now - last_simulated_at 应用生产/消耗/维护/粮食,资源夹在 [0, 存储上限]
//
// M2-C1 起改为分段结算(CLAUDE §18):把经过时长切成等长的段,段内人口恒定、段末更新人口,
// 段间状态在内存滚动,全部段算完后一次性写库。人口变化会改变下一段的粮耗——这正是分段的意义。
class SimulationService
{
    // 逐建筑实例的乘区初始值(全部 1.0 = 无影响)。
    // M2 各生产系统(科技/NPC/工具/电力/物流/事件)各占一个乘区,只写自己那一格,互不覆盖。
    // C1 起 worker 一格由「已分配工人 / 该级需求工人」填充;
    // C4 起 logistics 一格由 §10.7 的运输负载填充;
    // B3 起 tech 一格由 §5 的「同分支每解锁一条科技 +2%」填充。其余四项(power/npc/tool/event)仍恒为 1.0。
    private const BASE_MULTIPLIERS = [
        'worker'    => 1.0, // 用工满足率(人力不足打折)
        'power'     => 1.0, // 电力满足率(按建筑)
        'logistics' => 1.0, // 物流满足率(C4 起由 transportLoad → logisticsFactor 填充)
        'tech'      => 1.0, // 科技加成(B3 起按建筑所属科技分支的已解锁条数)
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

    // 乘区连乘:把一栋建筑实例的七个乘区乘成一个系数,并夹在 §13 的生产倍率硬上限之下。
    // M2 各系统在此接入自己的乘区(只改 multipliers 里对应的一格,不要另起加法通道)。
    //
    // §13 原文:「NPC + 工具 + 科技 + 事件总生产倍率建议硬封顶在 2.75×;终局特殊建筑最多 3.25×」。
    // 封顶只落在这一处,各系统不得在自己内部再夹一次 —— 否则「谁先夹」会改变结果,也无法一处审计。
    // 夹的是乘数积本身(不含维护欠费率):欠费半停工是惩罚方向,不该被算进"加成上限"里。
    // $cap 默认普通档 2.75;终局特殊建筑(M2 尚无该标记位)由调用方传 MULTIPLIER_CAP_ENDGAME
    public static function multiplierProduct(array $multipliers, float $cap = SimConstants::MULTIPLIER_CAP): float
    {
        $product = 1.0;
        foreach ($multipliers as $m) { $product *= (float) $m; }

        // 加成永不超帽;打折方向(乘数积 < 1)不受影响
        return min($product, $cap);
    }

    // 单栋建筑的产出/投入总系数 = 七乘区的乘数积 × 维护欠费率。
    //
    // 维护欠费率(§10.5 半停工)刻意不占七乘区里的名额:§10.11 的生产总公式只认那七项,
    // 多塞一格会让「乘区」这个概念变质。它与乘数积同级(都在 recipeRate 之前折算原料需求),
    // 所以半停工的建筑吃料也同比例减半,不会出现「按满产吃料、按半产出货」的凭空损耗
    private static function unitFactor(array $u): float
    {
        return self::multiplierProduct($u['multipliers']) * (float) ($u['maintRate'] ?? 1.0);
    }

    // 可分配劳动力:availableWorkers = floor(population × 0.60)(v3.2 §10.4)
    public static function availableWorkers(int|float $population): int
    {
        return (int) floor(max(0, $population) * SimConstants::WORKER_RATIO);
    }

    // 人均税额(v3.2 §10.5):时代 I = 0.02,每进入下一个时代 ×1.5。
    // 即 taxPerCapitaPerMin = 0.02 × 1.5^(era_order − 1);era_order 小于 1(含列缺失兜底)一律按时代 I
    public static function taxPerCapitaPerMin(int $eraOrder): float
    {
        return SimConstants::TAX_PER_CAPITA_ERA_1
            * pow(SimConstants::TAX_ERA_MULTIPLIER, max(1, $eraOrder) - 1);
    }

    // 治理负载(v3.2 §10.5 / §10.6):governanceLoad = population / max(1, governanceCapacity)
    public static function governanceLoad(float $population, float $governanceCapacity): float
    {
        return max(0.0, $population) / max(1.0, $governanceCapacity);
    }

    // 治理效率四档(v3.2 §10.5 / §10.6):<= 0.80 → 1.00;0.80~1.00 → 0.90;1.00~1.25 → 0.70;> 1.25 → 0.50。
    // M2 它只作用于 taxIncome(§10.5 的税收公式);§10.6 里「腐败 / 治安 / 抗议事件权重」明确留 M3
    public static function governanceEfficiency(float $load): float
    {
        if ($load <= SimConstants::GOVERNANCE_LOAD_GOOD) { return SimConstants::GOVERNANCE_EFFICIENCY_GOOD; }
        if ($load <= SimConstants::GOVERNANCE_LOAD_TIGHT) { return SimConstants::GOVERNANCE_EFFICIENCY_TIGHT; }
        if ($load <= SimConstants::GOVERNANCE_LOAD_OVER) { return SimConstants::GOVERNANCE_EFFICIENCY_OVER; }

        return SimConstants::GOVERNANCE_EFFICIENCY_COLLAPSE;
    }

    // 运输负载(v3.2 §10.7):transportLoad = transportDemand / max(1, transportCapacity)。
    // 分母的 max(1, …) 是 v3.2 原文写法(与 governanceLoad 同一套),运输容量为 0 时不炸除零
    public static function transportLoad(float $transportDemand, float $transportCapacity): float
    {
        return max(0.0, $transportDemand) / max(1.0, $transportCapacity);
    }

    // 物流率 logisticsFactor(v3.2 §10.7 分档 + §3.3 clamp 口径合并):
    //   <= 1.00        → 1.00(§10.7 的 <=0.80 与 0.80~1.00「轻微运输延迟」两档都不降产)
    //   1.00 ~ 1.25    → 从 1.00 线性下降至 0.70
    //   > 1.25         → 接 §3.3 的 clamp(运输容量 / 运输需求, 0.25, 1) = clamp(1/load, 0.25, 0.70)
    //
    // 最后一档为什么用 §3.3 的比例式:§10.7 对 >1.25 只写了「最低 0.25 + 拥堵警报」,没给下降曲线;
    // §3.3 给的比例式在 load = 1.25 处恰好是 1/1.25 = 0.80,被 0.70 的上限压住 → 与上一档在拐点连续,
    // 且单调递减、到 load = 4 触及 0.25 下限。两条公式因此拼成一条无跳变的曲线,不需要再发明第三个常量
    public static function logisticsFactor(float $load): float
    {
        if ($load <= SimConstants::TRANSPORT_LOAD_TIGHT) { return SimConstants::LOGISTICS_FACTOR_MAX; }

        if ($load <= SimConstants::TRANSPORT_LOAD_OVER) {
            $span = SimConstants::TRANSPORT_LOAD_OVER - SimConstants::TRANSPORT_LOAD_TIGHT; // 0.25
            $drop = (SimConstants::LOGISTICS_FACTOR_MAX - SimConstants::LOGISTICS_FACTOR_AT_OVER)
                * ($load - SimConstants::TRANSPORT_LOAD_TIGHT) / $span;

            return SimConstants::LOGISTICS_FACTOR_MAX - $drop;
        }

        return max(SimConstants::LOGISTICS_FACTOR_MIN, min(SimConstants::LOGISTICS_FACTOR_AT_OVER, 1.0 / $load));
    }

    // 财政预警(v3.2 §10.5「财政储备 < 10分钟总维护 → 黄色预警;< 3分钟总维护 → 红色预警」)。
    // 口径:按结算后的资金与全城维护资金速率派生,不落库;维护速率为 0 的城市永远付得起 → none
    public static function fiscalWarning(float $money, float $maintenanceMoneyPerMin): string
    {
        if ($maintenanceMoneyPerMin <= 0) { return 'none'; }

        $minutes = max(0.0, $money) / $maintenanceMoneyPerMin;

        if ($minutes < SimConstants::FISCAL_WARNING_RED_MINUTES) { return 'red'; }
        if ($minutes < SimConstants::FISCAL_WARNING_YELLOW_MINUTES) { return 'yellow'; }

        return 'none';
    }

    // 锁内结算:把 [last_simulated_at, $now] 这段时间的产出/消耗/维护/人口结清并落库
    //
    // 前置约定:调用方必须已在事务内对该 cities 行 lockForUpdate,并把锁到的行原样传进来。
    // 本方法不自开事务、不再加锁;建筑实例与资源现值都在锁之后才读,确保拿到并发写入之后的最新值。
    //
    // 返回值除速率/容量/经过秒数外,另带结算后的最新 money / resources / population,
    // 供调用方(建造/升级/工人分配)直接做余额与劳动力校验,不必再查一次库,也避免误用结算前的旧值。
    public static function applyLocked(object $lockedCity, CarbonInterface $now): array
    {
        $lastSimulatedAt = Carbon::parse($lockedCity->last_simulated_at);
        $elapsed = max(0, $now->getTimestamp() - $lastSimulatedAt->getTimestamp());
        // 离线封顶:超过上限的部分不结算(但 last_simulated_at 仍推进到 $now,否则会积压反复重算)
        $elapsed = min($elapsed, SimConstants::MAX_OFFLINE_SECONDS);

        // ---- M2-C5 施工 / 升级懒完工(v3.2 §16.3),必须先于下面的实例查询 ----
        //
        // 把 construction_finished_at 到点的实例翻正(constructing → active;upgrading → active 且 level+1),
        // 并把「完工点已落在本次窗口起点之前」的实例清掉完工戳,从本次结算起计入生产。
        // 细节与「不写审计」的理由见 ConstructionService::settleFinished。
        // $settlementStart 与下面分段结算用的 $windowStart 是同一个时刻,这里提前算一次给完工判定用
        $settlementStart = $now->copy()->subSeconds($elapsed);
        ConstructionService::settleFinished((int) $lockedCity->id, $now, $settlementStart);

        // 锁后再读建筑实例的每级定义,消除"锁前读实例"的竞态
        // ci.id / ci.building_id / ci.level 一并取出:M2 的乘数按实例/按建筑生效,聚合前必须能区分是哪一栋
        // ci.assigned_workers 与 bl.worker_required:workerFactor 的分子分母(§10.4)
        //
        // 取两类实例(ci.status 一并取出,下面的循环按它分流):
        //   ① active 且完工戳已清 → 完整的生产集合(产出 / 吃料 / 占运力 / 交维护 / 提供容量)
        //   ② upgrading           → **只提供容量**,生产恒为零(v3.2 §3.2,详见循环里的注释)
        // constructing 仍然完全排除:那是一栋还没建成的楼,既不生产也不该提供任何容量
        $levels = DB::table('city_building_instances as ci')
            ->join('building_level_definition as bl', function ($j) {
                $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
            })
            ->where('ci.city_id', $lockedCity->id)
            ->where(function ($q) {
                $q->where(function ($active) {
                    // M2-C5:完工点必须已在本次窗口起点之前(戳已被 settleFinished 清成 NULL),
                    // 窗口中途才完工的实例本次不产出、下次结算才算 —— 见 ConstructionService::settleFinished
                    $active->where('ci.status', ConstructionService::STATUS_ACTIVE)
                        ->whereNull('ci.construction_finished_at');
                })
                    // upgrading 实例的完工戳恒在未来(到点就被 settleFinished 翻成 active 了),
                    // 所以这里不必再按戳过滤
                    ->orWhere('ci.status', ConstructionService::STATUS_UPGRADING);
            })
            ->select(
                'ci.id as instance_id', 'ci.building_id', 'ci.level', 'ci.assigned_workers', 'ci.status',
                'bl.output_json', 'bl.input_json', 'bl.worker_required',
                'bl.maintenance_money_per_min', 'bl.maintenance_food_per_min'
            )
            ->get();

        // 锁后再读资源现值,确保是并发写入之后的最新值
        // (必须先于速率计算:加工建筑的"库存满足率"要拿现值当分子)
        $resources = DB::table('city_resources')->where('city_id', $lockedCity->id)
            ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all();
        $money = (float) $lockedCity->money;

        $storageCap = SimConstants::BASE_STORAGE;
        $populationCap = 0.0;
        // 医疗容量 / 国防值:同样是容量类产出,M2-C2 起要用来算 health / security 与两项幸福覆盖加成(§10.2 / §10.8)。
        // 与仓储/人口容量一样在构建中间结构时提取到全局,不进 grossOut、不受乘区与满足率影响
        $medicalCapacity = 0.0;
        $defenseScore = 0.0;
        // 治理容量(§10.5 / §10.6):同样是容量类产出,用来算 governanceLoad → governanceEfficiency → taxIncome。
        //
        // 唯一来源 = 建筑 output_json 里的 governance_capacity 一条(内核 CAPACITY 机制已把它聚合成全城值)。
        // building_level_definition 另有一列 governance_bonus,与 output_json 是两套口径且数值并不相等
        // (A01 L2:output 108 / bonus 104;K01~K05 只有 bonus 没有 output),两边都读会双计。
        // 该口径分歧已进 backlog 待用户裁决,在裁决前这里坚持单一来源,绝不叠加 governance_bonus 列
        $governanceCapacity = 0.0;
        // 运输容量(§10.7):同样是容量类产出,M2-C4 起从「暂不结算仅显示」转为真实参与 ——
        // 它是 transportLoad 的分母,直接决定 logistics 乘区。唯一来源 = 建筑 output_json 的 transport_capacity
        $transportCapacity = 0.0;
        $maintenanceMoneyPerMin = 0.0;

        // 用工闸门总开关(game_settings.worker_gate_enabled,默认 true = 维持「没派工人就不生产」)。
        // 关掉后 worker 乘区恒为 1.0,全服产量立刻恢复满额,供运营救急。
        // 必须在建筑循环之外读一次:applyLocked 在事务内高频调用,逐实例查库不可接受
        // (GameSetting 本身也带请求级缓存,这里再提出循环是第二道保险)
        $workerGateEnabled = (bool) GameSetting::get(GameSetting::WORKER_GATE_ENABLED, true);

        // 科技乘区(§5,M2-B3):building_id => 乘数。同样必须在建筑循环之外查一次,
        // 循环内(以及分段循环内)零查库。城里一条科技都没解锁时返回空数组,乘区保持占位 1.0
        $techMultipliers = self::techMultipliers(
            (int) $lockedCity->id,
            $levels->pluck('building_id')->unique()->all()
        );

        // 逐建筑实例中间结构:M2 没有一个乘数是全局的(科技按分支、NPC/工具按实例、电力按建筑),
        // 所以产出/投入必须先落到"每栋一行",算完各自的乘区与满足率,最后才聚合成全城速率。
        // 建筑集合在整段结算内不变(建造/拆除都会先跑结算),所以这层只在循环外构建一次。
        // 每行结构:
        //   ['instanceId','buildingId','level',
        //    'grossOut' => [资源=>速率], 'grossIn' => [资源=>速率],
        //    'maintMoney', 'maintFood', 'multipliers' => [七个乘区], 'maintRate' => 维护欠费率]
        $units = [];

        foreach ($levels as $lv) {
            // 升级中的实例(v3.2 §3.2「Level 2/3 升级时建筑进入 upgrading 状态:生产建筑默认暂停生产;
            // 住宅只保留 50% 人口容量」)。落地成两条:
            //   ① 只走下面 output_json 里的容量提取,住宅那一项乘 50%(基数是**旧等级**,level 未 +1);
            //   ② 不进 $units → 不产出、不吃料、不占运输需求、也不计维护(升级期间不产不耗,一并不收维护费)。
            // constructing 在上面的查询里已被整体排除,连容量都不给。
            $upgrading = $lv->status === ConstructionService::STATUS_UPGRADING;

            $grossIn = [];
            if (! $upgrading) {
                foreach (json_decode($lv->input_json ?: '[]', true) as $i) {
                    $res = $i['resource'];
                    $grossIn[$res] = ($grossIn[$res] ?? 0) + (float) $i['rate_per_min'];
                }
            }

            $grossOut = [];
            foreach (json_decode($lv->output_json ?: '[]', true) as $o) {
                $res = $o['resource']; $r = (float) $o['rate_per_min'];
                // 容量类产出不进 grossOut:它不是"每分钟入库的资源",在这里就提取成全城容量累计
                if ($res === ResourceCode::STORAGE_CAPACITY) { $storageCap += $r; continue; }
                if ($res === ResourceCode::POPULATION_CAPACITY) {
                    // §3.2 明文的唯一打折项:升级中的住宅只保留旧等级容量的 50%
                    $populationCap += $upgrading ? $r * SimConstants::UPGRADING_HOUSING_CAPACITY_RATE : $r;
                    continue;
                }
                if ($res === ResourceCode::MEDICAL_CAPACITY) { $medicalCapacity += $r; continue; }
                if ($res === ResourceCode::DEFENSE_SCORE) { $defenseScore += $r; continue; }
                if ($res === ResourceCode::GOVERNANCE_CAPACITY) { $governanceCapacity += $r; continue; }
                if ($res === ResourceCode::TRANSPORT_CAPACITY) { $transportCapacity += $r; continue; }
                if (ResourceCode::isCapacity($res)) { continue; } // 其他容量(贸易/金融):M2-C4 阶段仍不结算
                // 非容量类产出:升级中一律不产(§3.2「生产建筑默认暂停生产」)
                if ($upgrading) { continue; }
                $grossOut[$res] = ($grossOut[$res] ?? 0) + $r;
            }

            // 升级中的实例到此为止:容量已按上面的规则计入全城,但绝不进生产集合
            if ($upgrading) { continue; }

            $multipliers = self::BASE_MULTIPLIERS;
            // 科技乘区(§5):= 1 + 0.02 × 该建筑所属分支的已解锁科技条数;
            // 没解锁(或该分支一条都没解锁)时 $techMultipliers 里没有这个键 → 保持 1.0。
            // 研究中(researching)的科技不算数,与建造科技闸门同一口径
            $multipliers['tech'] = (float) ($techMultipliers[$lv->building_id] ?? 1.0);
            // workerFactor = min(1, assignedWorkers / max(1, workerRequired))(§10.4);
            // worker_required = 0 的建筑(住宅/仓库等)不需要人,恒为 1.0;
            // 闸门关闭($workerGateEnabled = false)时同样恒为 1.0
            $workerRequired = (int) $lv->worker_required;
            $multipliers['worker'] = ($workerGateEnabled && $workerRequired > 0)
                ? min(1.0, (int) $lv->assigned_workers / $workerRequired)
                : 1.0;

            $units[] = [
                'instanceId'  => (int) $lv->instance_id,
                'buildingId'  => $lv->building_id,
                'level'       => (int) $lv->level,
                'grossOut'    => $grossOut,
                'grossIn'     => $grossIn,
                'maintMoney'  => (float) $lv->maintenance_money_per_min,
                'maintFood'   => (float) $lv->maintenance_food_per_min,
                'multipliers' => $multipliers,
                // 维护欠费率(§10.5):1.0 = 维护付得起;0.5 = 本段欠费半停工。
                // 它不占七乘区里的任何一格(七乘区是 §10.11 生产总公式的固定名单,不许扩名),
                // 而是逐段由 applyLocked 改写、在 unitFactor 里单独乘在乘数积之后
                'maintRate'   => 1.0,
            ];
        }

        // 维护资金:不进配方,不受乘区与满足率影响(建筑闲置也照付),整段恒定
        foreach ($units as $u) { $maintenanceMoneyPerMin += $u['maintMoney']; }

        // ---- 物流(v3.2 §10.7,M2-C4)----
        //
        // transportDemand = Σ(各生产建筑每分钟输入 + 输出) × distanceFactor
        //
        // 需求取「该级定义的基础输入/输出速率」而不是乘区折算后的速率,两个理由:
        //   ① logistics 本身就是七乘区之一,拿折算后的速率当分母会自己吃自己(收敛都不保证);
        //   ② §10.7 的字面口径就是建筑的每分钟输入 / 输出,即名义吞吐。
        // 容量类产出(人口/仓储/治理/运输/医疗/国防)在上面已被提走,不在 grossIn / grossOut 里,
        // 所以住宅、仓库、行政所、道路本身天然不占运力 —— 这正是「生产建筑」这个限定词的落地方式。
        $transportDemandPerMin = 0.0;
        foreach ($units as $u) {
            $transportDemandPerMin += array_sum($u['grossIn']) + array_sum($u['grossOut']);
        }
        // distanceFactor:M2 恒 1.0(§10.7「M2:distanceFactor = 1.0」),地图距离惩罚留 M3 大地图
        $transportDemandPerMin *= SimConstants::LOGISTICS_DISTANCE_FACTOR;

        // 时代闸门(**本次补充假设**,依据见 SimConstants::LOGISTICS_MIN_ERA_ORDER 的注释):
        // 时代 I 没有任何建筑能产出 transport_capacity(全表最早的运输建筑是时代 II 的 T02),
        // 若时代 I 照样计需求,所有时代 I 城市开局即重度拥堵、且无任何手段自救。
        // era_order 由时代升级(M2-B6)维护;列缺失 / 为空一律按时代 I 兜底(与人均税额同一约定)
        $eraOrder = (int) ($lockedCity->era_order ?? 1);
        if ($eraOrder < SimConstants::LOGISTICS_MIN_ERA_ORDER) { $transportDemandPerMin = 0.0; }

        // 负载 → 物流率。全城一个值:M2 distanceFactor 恒 1.0 且没有分区路网,
        // M3 大地图再改成按建筑到路网的距离逐栋算(那时这里换成逐 unit 计算即可)
        $transportLoad = self::transportLoad($transportDemandPerMin, $transportCapacity);
        $logisticsFactor = self::logisticsFactor($transportLoad);
        // 拥堵警报(§10.7「> 1.25 → 产生拥堵警报」/ §15 回归表「出现拥堵警报」)
        $transportCongestion = $transportLoad > SimConstants::TRANSPORT_LOAD_OVER;

        // 写进七乘区的 logistics 一格(§10.11 生产总公式点名的那一项),从占位 1.0 变为真实值。
        // 整段结算内建筑集合不变 → 物流率也不变,所以只在分段循环之外算一次、写一次(循环内零查库)
        foreach ($units as $i => $u) {
            $units[$i]['multipliers']['logistics'] = $logisticsFactor;
        }

        // ---- 分段结算 ----
        //
        // 段划分:segments = min(ceil(经过分钟 / 30), 24),每段等长(12h 封顶时恰 24 段 × 30min)。
        // elapsed 很短(<= 一段)时就一段,行为与单段结算一致 —— 守恒测试(单次 vs 分段)就是验这一点。
        $population = (float) $lockedCity->population;
        $totalMinutes = $elapsed / 60.0;
        $segments = $elapsed > 0
            ? min((int) ceil($totalMinutes / SimConstants::SEGMENT_MINUTES), SimConstants::MAX_SEGMENTS)
            : 1; // elapsed=0:跑一段 0 分钟,只为算出速率/容量返回给前端显示,不写库
        $segMinutes = $segments > 0 ? $totalMinutes / $segments : 0.0;

        // 结算窗口起点:封顶时结算的是「$now 往前 elapsed 秒」这一段,不是 last_simulated_at 起的全程
        $windowStart = $now->copy()->subSeconds($elapsed);
        // 粮食归零起点:内存里用「相对窗口起点的分钟偏移」滚动(可能为负 = 上次结算就已归零),
        // 全部段算完后再换算回绝对时间写库
        $foodZeroOffset = $lockedCity->food_zero_since !== null
            ? (Carbon::parse($lockedCity->food_zero_since)->getTimestamp() - $windowStart->getTimestamp()) / 60.0
            : null;
        // 粮食赤字起点(§10.1「连续赤字 >= 5 分钟 → happiness -1/分钟」):与归零起点同一套偏移量做法,
        // 跨段跨结算续算;赤字解除时清 null
        $foodDeficitOffset = $lockedCity->food_deficit_since !== null
            ? (Carbon::parse($lockedCity->food_deficit_since)->getTimestamp() - $windowStart->getTimestamp()) / 60.0
            : null;
        // 幸福度(§10.2):持久状态,与人口一样在内存里逐段滚动,全部段算完后一次写库
        $happiness = (float) $lockedCity->happiness;

        // 人均税额(§10.5):时代 I = 0.02,每进一个时代 ×1.5 → 0.02 × 1.5^(era_order − 1)。
        // era_order 由时代升级(M2-B6)维护;列尚未上线 / 值为空的城市按时代 I 兜底。
        // 整段结算内时代不变,所以在循环外算一次(分段循环内零查库、零幂运算)。
        // $eraOrder 在上面的物流块里已按同一套兜底口径取好,这里直接复用,避免两处各写一份兜底
        $taxPerCapita = self::taxPerCapitaPerMin($eraOrder);

        $ratePerMin = [];        // 资源 => 每分钟净速率(最后一段口径,返回给前端显示)
        $grossProduction = [];   // 资源 => 每分钟 gross 产出(已含乘数与满足率)
        $grossConsumption = [];  // 资源 => 每分钟 gross 配方消耗(不含维护与人口吃粮)
        $growthPerMin = 0.0;     // 人口名义增长(人/分钟,最后一段口径)
        $touched = [];           // 本次结算动过的资源键:落库时按它取值
        // 财政 / 治理的「最后一段口径」返回值(与 ratePerMin / growthPerMin 同一约定:
        // 它们描述的是最后一段实际生效的速率与状态,而不是结算后人口的重新推算)
        $governanceLoad = 0.0;
        $governanceEfficiency = SimConstants::GOVERNANCE_EFFICIENCY_GOOD;
        $taxIncomePerMin = 0.0;
        $maintenanceRate = 1.0;
        $maintenanceArrears = false;

        for ($s = 0; $s < $segments; $s++) {
            $segStartOffset = $s * $segMinutes;
            // 末段直接取总时长兜住浮点累加误差,保证 Σ段长 === 总时长(守恒的前提)
            $segEndOffset = $s === $segments - 1 ? $totalMinutes : ($s + 1) * $segMinutes;
            $span = $segEndOffset - $segStartOffset;

            // ---- 本段财政(§10.5 / §10.6):必须先于 segmentRates,因为欠费会打折本段产出 ----
            //
            // 治理效率与税收都按「段起人口」算 —— 与粮耗、幸福目标同一条「段内人口恒定」纪律
            $governanceLoad = self::governanceLoad($population, $governanceCapacity);
            $governanceEfficiency = self::governanceEfficiency($governanceLoad);
            // taxIncome = population × taxPerCapitaPerMin × governanceEfficiency(§10.5)。
            // M2 税率固定、玩家不可调(§10.5「M2:税率固定 / 玩家不可调」),M3 才开放税率政策
            $taxIncomePerMin = $population * $taxPerCapita * $governanceEfficiency;

            // 维护欠费判定(§10.5):段起资金 + 本段税收 若付不起本段全额维护 → 本段判定为欠费。
            // 等价于 §10.5 的「money <= 0 且存在无法支付的建筑维护」:付不起时资金必然被夹到 0
            $maintenanceDue = $maintenanceMoneyPerMin * $span;
            $fundsAvailable = $money + $taxIncomePerMin * $span;
            $maintenanceArrears = $maintenanceDue > 0 && $fundsAvailable + 1e-9 < $maintenanceDue;
            $maintenanceRate = $maintenanceArrears ? SimConstants::MAINTENANCE_ARREARS_FACTOR : 1.0;
            // 半停工只落在「有维护资金的建筑」上(住宅/仓库这类零维护建筑不可能欠费,恒 1.0)。
            // v3.2 只写了「对应欠费建筑 productionFactor *= 0.50」,没给「缺口如何分摊到具体哪几栋」的规则,
            // 这里按最简单也最保守的口径:本段一旦欠费,所有要交维护费的建筑一起半停工(见汇报的假设清单)
            foreach ($units as $i => $u) {
                $units[$i]['maintRate'] = $u['maintMoney'] > 0 ? $maintenanceRate : 1.0;
            }

            // 本段速率:满足率用段起库存、人口吃粮用段起人口(段内人口视为恒定)
            [$ratePerMin, $grossProduction, $grossConsumption] = self::segmentRates($units, $resources, $population, $span);

            $foodBefore = (float) ($resources[ResourceCode::FOOD] ?? 0);

            // 资源按段长线性推进,夹在 [0, storageCap]
            foreach ($ratePerMin as $res => $rate) {
                $val = (float) ($resources[$res] ?? 0) + $rate * $span;
                $resources[$res] = max(0, min($val, $storageCap));
                $touched[$res] = true;
            }

            // 资金结算:先收税再扣维护,夹在 0。
            // 付不起的那部分不记成负债,而是已经在上面转成了本段的半停工惩罚(§10.5 取代白嫖口径)
            $money = max(0, $fundsAvailable - $maintenanceDue);

            $foodNetRate = (float) ($ratePerMin[ResourceCode::FOOD] ?? 0);
            // 段起人口:幸福目标里的住房/覆盖/食物品质都按它算(与「段内人口恒定」同一纪律)
            $popAtSegmentStart = $population;

            // 段末人口更新(§10.1 / §10.3,顺序固定:归零饥荒 → 严重短缺迁出 → 正常增长)。
            // happinessFactor 用「段起幸福」:段内幸福同样视为恒定,段末才收敛到新值
            $step = self::stepPopulation(
                $population,
                $foodBefore,
                (float) ($resources[ResourceCode::FOOD] ?? 0),
                $foodNetRate,
                $populationCap,
                $happiness,
                $segStartOffset,
                $segEndOffset,
                $foodZeroOffset
            );
            $population = $step['population'];
            $growthPerMin = $step['growthPerMin'];
            $foodZeroOffset = $step['foodZeroOffset'];

            // 段末幸福更新(§10.2):先维护赤字计时,再合成目标幸福,最后按快落慢升收敛
            $foodDeficitOffset = self::stepFoodDeficit($foodDeficitOffset, $foodNetRate, $segStartOffset);
            $deficitMinutes = $foodDeficitOffset === null ? 0.0 : max(0.0, $segEndOffset - $foodDeficitOffset);
            $happinessTarget = self::happinessTarget(
                $popAtSegmentStart,
                $populationCap,
                $medicalCapacity,
                $defenseScore,
                $grossProduction,
                $deficitMinutes
            );
            $happiness = self::stepHappiness($happiness, $happinessTarget, $span);
        }

        // health / security(§10.8):派生值,不落库,按结算后的人口与全城容量现算
        $health = (int) round(self::coverage($medicalCapacity, $population) * 100);
        $security = (int) round(self::coverage($defenseScore, $population) * 100);

        // 财政预警(§10.5):按「结算后的资金」与全城维护资金速率派生,同样不落库。
        // 用结算后的资金而不是段起资金 —— 玩家看到的 HUD 资金就是这个值,预警必须和它同源
        $fiscalWarning = self::fiscalWarning($money, $maintenanceMoneyPerMin);

        // elapsed == 0:跳过写库,但速率/容量仍照常算出返回
        if ($elapsed > 0) {
            // 批量落库:city_resources 已有复合主键 (city_id, resource_id),一次 upsert 取代逐资源往返
            // (资源种类会涨到 31 种,逐条 updateOrInsert 每次快照要 30~60 次往返)
            $rows = [];
            foreach (array_keys($touched) as $res) {
                $rows[] = ['city_id' => $lockedCity->id, 'resource_id' => $res, 'amount' => $resources[$res]];
            }
            // 注意:不要用 upsert 的返回值做业务判断——MySQL 与 MariaDB 的受影响行数语义不同
            if ($rows) {
                DB::table('city_resources')->upsert($rows, ['city_id', 'resource_id'], ['amount']);
            }

            // 人口:内存里按 float 滚动,落库取 floor(cities.population 是 INT)。
            // 不足 1 人的零头在写库时丢弃,不做跨结算累积——多一个"人口小数"列不值得。
            DB::table('cities')->where('id', $lockedCity->id)->update([
                'money'             => $money,
                'population'        => (int) floor(max(0, $population)),
                // 幸福度按 float 落库(与人口不同:它本身就是 0~100 的连续量,取整会让快落慢升丢失斜率)
                'happiness'         => $happiness,
                'food_zero_since'   => $foodZeroOffset === null
                    ? null
                    : $windowStart->copy()->addSeconds((int) round($foodZeroOffset * 60))->format('Y-m-d H:i:s'),
                'food_deficit_since' => $foodDeficitOffset === null
                    ? null
                    : $windowStart->copy()->addSeconds((int) round($foodDeficitOffset * 60))->format('Y-m-d H:i:s'),
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
            // 全城 gross 产出/消耗速率,供 M2 各系统与 §68 检测层取用
            'grossProductionPerMin'   => $grossProduction,
            'grossConsumptionPerMin'  => $grossConsumption,
            // 人口:与落库值一致(floor);populationGrowthPerMin 为 §10.3 口径的名义增减(人/分钟,未夹容量)
            'population'              => (int) floor(max(0, $population)),
            'populationGrowthPerMin'  => $growthPerMin,
            // 民生三值(§10.2 / §10.8):happiness 是持久状态(已落库),health / security 是派生值(不落库)
            'happiness'               => $happiness,
            'health'                  => $health,
            'security'                => $security,
            // 医疗容量 / 国防值:综合面板与 M3 疾病/犯罪联动的数据基础
            'medicalCapacity'         => $medicalCapacity,
            'defenseScore'            => $defenseScore,
            // 财政 / 治理(§10.5 / §10.6),全部是「最后一段口径」的派生值,一个都不落库:
            //   governanceCapacity 全城治理容量(唯一来源 = output_json 的 governance_capacity)
            //   governanceLoad / governanceEfficiency 治理负载与四档效率
            //   taxIncomePerMin 本段税收速率(资金/分钟)
            //   maintenanceMoneyPerMin 全城维护资金速率(财政预警的分母,也是欠费判定的依据)
            //   maintenanceRate / maintenanceArrears 欠费半停工状态(§10.5 要求的 maintenanceArrears 等价状态)
            'governanceCapacity'      => $governanceCapacity,
            'governanceLoad'          => $governanceLoad,
            'governanceEfficiency'    => $governanceEfficiency,
            'taxIncomePerMin'         => $taxIncomePerMin,
            'maintenanceMoneyPerMin'  => $maintenanceMoneyPerMin,
            'maintenanceRate'         => $maintenanceRate,
            'maintenanceArrears'      => $maintenanceArrears,
            //   fiscalWarning 财政预警三态 'none' | 'yellow' | 'red'(§10.5:< 10 分钟维护 → 黄;< 3 分钟 → 红)
            'fiscalWarning'           => $fiscalWarning,
            // 物流(§10.7),同样全是派生值,不落库:
            //   transportCapacity 全城运输容量(唯一来源 = output_json 的 transport_capacity)
            //   transportDemandPerMin 运输需求 = Σ(生产建筑输入 + 输出) × distanceFactor(时代 I 恒 0,见上文闸门)
            //   transportLoad / logisticsFactor 负载与物流率(logistics 乘区里生效的就是它)
            //   transportCongestion 拥堵警报(负载 > 1.25)
            'transportCapacity'       => $transportCapacity,
            'transportDemandPerMin'   => $transportDemandPerMin,
            'transportLoad'           => $transportLoad,
            'logisticsFactor'         => $logisticsFactor,
            'transportCongestion'     => $transportCongestion,
        ];
    }

    // 科技乘区(v3.2 §5,M2-B3):返回 building_id => 乘数,只含真正拿到加成的建筑。
    //
    // 效果口径完全照 §5 科技表的 effect_code 列:50 条科技一律 `<branch>_base_efficiency_2pct`,
    // 即「解锁一条科技 → 该分支建筑基础效率 +2%」,同分支多条线性累加:
    //   multiplier = 1 + 0.02 × 该分支已解锁条数
    //
    // 建筑 → 分支不另立映射表,直接用定义数据推:
    //   building_definition.tech_id(§3.4 的 tech_id 列,94 栋全部非空)→ technology_definition.branch。
    // 即「解锁这栋楼的那条科技属于哪条分支,这栋楼就吃哪条分支的加成」——
    // 这样新增建筑只要填 tech_id 就自动归位,不必回来改代码(CLAUDE §13 数据驱动)。
    //
    // 两次查询都在 applyLocked 的准备段(分段循环之外)完成;
    // 一条科技都没解锁的城(绝大多数新城)在第一次查询之后就直接返回,不发第二条 SQL。
    private static function techMultipliers(int $cityId, array $buildingIds): array
    {
        if (! $buildingIds) {
            return [];
        }

        // 已解锁科技按分支计数(researching 不算解锁,与 BuildService 的科技闸门同一口径)
        $counts = [];
        $rows = DB::table('city_technologies as ct')
            ->join('technology_definition as td', 'ct.tech_id', '=', 'td.tech_id')
            ->where('ct.city_id', $cityId)
            ->where('ct.status', TechService::STATUS_UNLOCKED)
            ->groupBy('td.branch')
            ->selectRaw('td.branch as branch, count(*) as unlocked_count')
            ->get();
        foreach ($rows as $row) {
            $counts[$row->branch] = (int) $row->unlocked_count;
        }
        if (! $counts) {
            return [];
        }

        $branchOf = DB::table('building_definition as bd')
            ->join('technology_definition as td', 'bd.tech_id', '=', 'td.tech_id')
            ->whereIn('bd.building_id', $buildingIds)
            ->pluck('td.branch', 'bd.building_id')->all();

        $out = [];
        foreach ($branchOf as $buildingId => $branch) {
            $unlocked = $counts[$branch] ?? 0;
            if ($unlocked > 0) {
                $out[$buildingId] = 1.0 + $unlocked * SimConstants::TECH_BRANCH_EFFICIENCY_BONUS;
            }
        }

        return $out;
    }

    // 单段速率:给定段起库存与段起人口,算出本段的资源净速率与 gross 产出/消耗。
    // gross 产出与 gross 消耗分开累计、不提前合并成 net:
    // production_utilization、food_net_rate 的分子分母、§68 理论最大 delta 都要的是分开的值。
    private static function segmentRates(array $units, array $resources, float $population, float $minutes): array
    {
        $ratePerMin = [];

        // 维护粮食:与维护资金一样不进配方、不受乘区与满足率影响;缺粮时由落库处的 max(0,…) 夹住
        foreach ($units as $u) {
            if ($u['maintFood'] > 0) {
                $ratePerMin[ResourceCode::FOOD] = ($ratePerMin[ResourceCode::FOOD] ?? 0) - $u['maintFood'];
            }
        }

        // 加工建筑限流:保守库存满足率(修 M1 经济漏洞——缺料照样出货 = 凭空造成品)
        // 两遍计算:先按段起库存算出每种原料的全局满足率,再让每栋建筑取其配方中最稀缺原料的满足率。
        // 保守性:不计入"本段内上游同时在生产该原料"(如 P01 本段产的面粉不能立刻喂给 P02),
        // 宁可少产不可多产;段越短越接近真实(这也是分段结算顺带改善的一点)。
        //
        // 第一遍:汇总本段各原料的总需求(多栋共享同一原料时天然合并,总消耗不会超过库存)。
        // 需求按"实例的乘数积"折算——乘区会同时放大投入与产出,不折算会低估或高估耗料。
        $demand = [];
        foreach ($units as $u) {
            if (! $u['grossIn']) { continue; }
            $mult = self::unitFactor($u);
            foreach ($u['grossIn'] as $res => $r) { $demand[$res] = ($demand[$res] ?? 0) + $r * $mult * $minutes; }
        }
        // 每种原料的全局满足率 = 库存 / 总需求,夹在 [0,1];
        // minutes == 0 时需求为 0 → 满足率取 1,返回的是"无约束名义速率",仅供前端显示
        $globalRate = [];
        foreach ($demand as $res => $need) {
            $globalRate[$res] = $need > 0
                ? max(0.0, min(1.0, (float) ($resources[$res] ?? 0) / $need))
                : 1.0;
        }

        // 第二遍聚合:每栋有效速率 = (grossOut − grossIn) × 乘数积 × 维护欠费率 × 满足率(取配方中最小的那个)
        $grossProduction = [];
        $grossConsumption = [];
        foreach ($units as $u) {
            $recipeRate = 1.0;
            foreach (array_keys($u['grossIn']) as $res) { $recipeRate = min($recipeRate, $globalRate[$res] ?? 1.0); }
            $factor = self::unitFactor($u) * $recipeRate;

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

        // 人口粮食消耗:基础粮食消耗/分钟 = population × 0.03(§10.1),段内人口恒定
        $ratePerMin[ResourceCode::FOOD] = ($ratePerMin[ResourceCode::FOOD] ?? 0)
            - $population * SimConstants::FOOD_PER_CAPITA_PER_MIN;

        return [$ratePerMin, $grossProduction, $grossConsumption];
    }

    // 段末人口更新(v3.2 §10.1 粮食赤字三级后果 + §10.3 人口增长)。
    // 三个分支互斥,顺序固定,按「段末粮食库存」判定:
    //   1) 库存 == 0:维护 food_zero_since;归零满 10 分钟之后的时间按 -1.0%/分钟 复利扣人口
    //   2) 库存 < 3 分钟当前人口消耗:按 -0.5%/分钟 复利扣(迁出)
    //   3) 正常:按 §10.3 增长率复利增长,夹到人口容量
    // 偏移量($segStartOffset/$segEndOffset/$foodZeroOffset)统一是「相对结算窗口起点的分钟数」。
    private static function stepPopulation(
        float $population,
        float $foodAtSegmentStart,
        float $foodAtSegmentEnd,
        float $foodNetRate,
        float $populationCap,
        float $happiness,
        float $segStartOffset,
        float $segEndOffset,
        ?float $foodZeroOffset
    ): array {
        $span = $segEndOffset - $segStartOffset;

        if ($foodAtSegmentEnd <= 0.0) {
            // 归零起点:段起就是 0 → 取段起;段内耗尽 → 线性插值出耗尽时刻(净速率为负才有意义)
            if ($foodZeroOffset === null) {
                $foodZeroOffset = $segStartOffset;
                if ($foodAtSegmentStart > 0 && $foodNetRate < 0) {
                    $foodZeroOffset += min($span, $foodAtSegmentStart / (-$foodNetRate));
                }
            }

            // 饥荒时间 = 本段中落在「归零起点 + 10 分钟」之后的部分
            $famineFrom = max($segStartOffset, $foodZeroOffset + SimConstants::FOOD_ZERO_GRACE_MINUTES);
            $famineMinutes = max(0.0, $segEndOffset - $famineFrom);
            $newPopulation = self::applyLoss($population, SimConstants::FOOD_ZERO_LOSS_PER_MIN, $famineMinutes);

            return [
                'population'     => $newPopulation,
                // 名义速率取本段实际发生的平均值:没触发饥荒时为 0
                'growthPerMin'   => $famineMinutes > 0 ? $population * SimConstants::FOOD_ZERO_LOSS_PER_MIN : 0.0,
                'foodZeroOffset' => $foodZeroOffset,
            ];
        }

        // 库存 > 0:归零计时清零(§10.1 的饥荒判定要求「持续」归零)
        $foodZeroOffset = null;

        // 严重短缺:库存 < 3 分钟当前人口消耗(§10.1 用的是人口消耗口径,不含配方与维护)
        $shortageLine = $population * SimConstants::FOOD_PER_CAPITA_PER_MIN * SimConstants::FOOD_SHORTAGE_MINUTES;
        if ($foodAtSegmentEnd < $shortageLine) {
            return [
                'population'     => self::applyLoss($population, SimConstants::FOOD_SHORTAGE_LOSS_PER_MIN, $span),
                'growthPerMin'   => $population * SimConstants::FOOD_SHORTAGE_LOSS_PER_MIN,
                'foodZeroOffset' => null,
            ];
        }

        // 正常增长(§10.3):
        // rate = baseGrowth × housingFactor × foodFactor × happinessFactor × healthFactor
        $housingFactor = self::housingFactor($population, $populationCap);
        // foodFactor:本段粮食净速率 >= 0 → 1.0,< 0 → 0(粮食净变化为负立即停止人口增长,§10.1)
        $foodFactor = $foodNetRate >= 0 ? 1.0 : 0.0;
        // happinessFactor(§10.3 三段位):M2-C2 起由真实幸福度驱动。
        // 注意只作用于「正常增长」分支:迁出/饥荒两个分支按 §10.1 与幸福无关(上面已 return)
        $happinessFactor = self::happinessFactor($happiness);
        // healthFactor:§10.3 明确 M2 阶段恒为 1.0,M3 再接疾病/医疗
        $healthFactor = 1.0;

        $rate = SimConstants::BASE_GROWTH_PER_MIN * $housingFactor * $foodFactor * $happinessFactor * $healthFactor;
        $grown = $population * pow(1 + $rate, $span);
        // 夹到人口容量;但绝不因为容量低于现有人口而"夹掉"人口 ——
        // 超容时 housingFactor 已经是 0(不再增长),容量下降不该表现为人口凭空消失
        $newPopulation = max($population, min($grown, $populationCap));

        return [
            'population'     => $newPopulation,
            'growthPerMin'   => $population * $rate,
            'foodZeroOffset' => null,
        ];
    }

    // 人口损失:按每分钟比例复利衰减,并夹住人口下限 5(§10.1「人口短缺损失不能使人口低于 5」)。
    // 下限只对损失方向生效:本来就不足 5 人的城市不会被这条规则"补"上去
    private static function applyLoss(float $population, float $ratePerMin, float $minutes): float
    {
        if ($minutes <= 0) { return $population; }

        $lost = $population * pow(1 + $ratePerMin, $minutes);

        return max($lost, min($population, (float) SimConstants::MIN_POPULATION));
    }

    // housingFactor(§10.3 分段函数):
    //   housingUsage = population / max(1, populationCapacity)
    //   < 0.80 → 1.0;0.80 ~ 1.00 → 从 1.0 线性下降到 0.2;>= 1.00 → 0
    private static function housingFactor(float $population, float $populationCap): float
    {
        $usage = $population / max(1.0, $populationCap);

        if ($usage < SimConstants::HOUSING_USAGE_FULL) { return 1.0; }
        if ($usage >= 1.0) { return 0.0; }

        $span = 1.0 - SimConstants::HOUSING_USAGE_FULL; // 0.20
        $drop = (1.0 - SimConstants::HOUSING_FACTOR_AT_CAP) * ($usage - SimConstants::HOUSING_USAGE_FULL) / $span;

        return 1.0 - $drop;
    }

    // happinessFactor(§10.3 分段函数):
    //   >= 70 → 1.0;50 ~ 70 → clamp(0.5 + (happiness − 50) / 40, 0.5, 1.0);< 50 → 0
    public static function happinessFactor(float $happiness): float
    {
        if ($happiness >= SimConstants::HAPPINESS_FACTOR_FULL_AT) { return 1.0; }
        if ($happiness < SimConstants::HAPPINESS_FACTOR_ZERO_BELOW) { return 0.0; }

        $f = SimConstants::HAPPINESS_FACTOR_AT_FLOOR + ($happiness - SimConstants::HAPPINESS_FACTOR_ZERO_BELOW) / 40.0;

        return max(SimConstants::HAPPINESS_FACTOR_AT_FLOOR, min(1.0, $f));
    }

    // 容量覆盖率(0~1):容量 / 人口,夹在 [0,1]。
    // §10.2 的医疗加成、治安加成与 §10.8 的 health / security 全部走这一个口径,不写第二份。
    // 人口取 max(1, population):空城不该因为除数为 0 而炸,也不该被判成「无限覆盖」以外的怪值
    public static function coverage(float $capacity, float $population): float
    {
        return max(0.0, min(1.0, $capacity / max(1.0, $population)));
    }

    // 目标幸福(§10.2 合成式):
    //   60 + housingBonus + foodQualityBonus + medicalBonus + securityBonus + taxPenalty + shortagePenalty
    // 各分项都是「本段起始状态」的函数,段内恒定。最终夹在 [0,100]
    private static function happinessTarget(
        float $population,
        float $populationCap,
        float $medicalCapacity,
        float $defenseScore,
        array $grossProduction,
        float $deficitMinutes
    ): float {
        // 税率惩罚:M2 税率固定、玩家不可调,§10.2 明确 taxPenalty = 0。
        // M3 开放可调税率后在这里接「每 5% 税率 → -2 happiness」,占位保留以免将来漏项
        $taxPenalty = 0.0;

        // 粮食赤字惩罚(§10.1):连续赤字满 5 分钟起,每多 1 分钟目标 -1
        $shortagePenalty = -SimConstants::HAPPINESS_DEFICIT_PENALTY_PER_MIN
            * max(0.0, $deficitMinutes - SimConstants::FOOD_DEFICIT_GRACE_MINUTES);

        $target = SimConstants::HAPPINESS_BASE
            + self::housingHappinessBonus($population, $populationCap)
            + self::foodQualityHappinessBonus($population, $grossProduction)
            + SimConstants::HAPPINESS_COVERAGE_BONUS * self::coverage($medicalCapacity, $population)
            + SimConstants::HAPPINESS_COVERAGE_BONUS * self::coverage($defenseScore, $population)
            + $taxPenalty
            + $shortagePenalty;

        return max(SimConstants::HAPPINESS_MIN, min(SimConstants::HAPPINESS_MAX, $target));
    }

    // 住房幸福加成(§10.2):
    //   使用率 <= 0.90            → +10
    //   0.90 ~ 1.00              → 从 +10 线性降到 0
    //   > 1.00                   → 从 0 向 -15 收敛(超容 20% 触底,见 SimConstants 注释的补充假设)
    private static function housingHappinessBonus(float $population, float $populationCap): float
    {
        $usage = $population / max(1.0, $populationCap);
        $good = SimConstants::HAPPINESS_HOUSING_GOOD_USAGE;

        if ($usage <= $good) { return SimConstants::HAPPINESS_HOUSING_BONUS; }

        if ($usage <= 1.0) {
            // 0.90 → +10,1.00 → 0
            return SimConstants::HAPPINESS_HOUSING_BONUS * (1.0 - ($usage - $good) / (1.0 - $good));
        }

        $over = min(1.0, ($usage - 1.0) / SimConstants::HAPPINESS_HOUSING_OVER_SPAN);

        return SimConstants::HAPPINESS_HOUSING_OVER_PENALTY * $over;
    }

    // 食物品质幸福加成(§10.1 四档 → §10.2 加成),取满足条件的最高档:
    //   高品质粮食覆盖 > 50% → +15;加工食品覆盖 > 50% → +10;面粉/面包覆盖 > 30% → +5;否则 +0
    //
    // 覆盖率口径(本次补充假设):v3.2 只给了「加工食品可供给人口 / population」,没定义「可供给人口」,
    // 这里按人均粮耗折算 —— 可供给人口 = 该类食物 gross 产出速率 / 0.03(人均每分钟粮耗)。
    // 用 gross 产出而非库存:库存是一次性的,产能才代表「城市长期吃得好不好」
    private static function foodQualityHappinessBonus(float $population, array $grossProduction): float
    {
        $pop = max(1.0, $population);
        $rate = fn (string $res) => (float) ($grossProduction[$res] ?? 0);
        // 覆盖率 = 该类食物养得起的人数 / 当前人口,夹在 [0,1]
        $coverage = fn (float $r) => min(1.0, max(0.0, $r) / SimConstants::FOOD_PER_CAPITA_PER_MIN / $pop);

        if ($coverage($rate(ResourceCode::HIGH_QUALITY_FOOD)) > SimConstants::FOOD_QUALITY_HIGH_COVERAGE) {
            return SimConstants::FOOD_QUALITY_HIGH_BONUS;
        }
        if ($coverage($rate(ResourceCode::PROCESSED_FOOD)) > SimConstants::FOOD_QUALITY_PROCESSED_COVERAGE) {
            return SimConstants::FOOD_QUALITY_PROCESSED_BONUS;
        }
        $flourBread = $rate(ResourceCode::FLOUR) + $rate(ResourceCode::BREAD);
        if ($coverage($flourBread) > SimConstants::FOOD_QUALITY_FLOUR_BREAD_COVERAGE) {
            return SimConstants::FOOD_QUALITY_FLOUR_BREAD_BONUS;
        }

        return 0.0;
    }

    // 粮食赤字计时(§10.1「连续赤字 >= 5 分钟」):
    // 赤字 = 粮食净速率 < 0。段内速率恒定,所以整段要么全赤字要么全不赤字;
    // 净速率转正即视为赤字解除,计时清空(与 food_zero_since 同样的「持续」语义)
    private static function stepFoodDeficit(?float $deficitOffset, float $foodNetRate, float $segStartOffset): ?float
    {
        if ($foodNetRate >= 0) { return null; }

        return $deficitOffset ?? $segStartOffset;
    }

    // 幸福向目标收敛(§10.2 快落慢升):升 +0.5/分钟、降 -1.0/分钟,不越过目标,最后夹在 [0,100]
    private static function stepHappiness(float $happiness, float $target, float $minutes): float
    {
        if ($minutes > 0) {
            if ($target > $happiness) {
                $happiness = min($target, $happiness + SimConstants::HAPPINESS_RISE_PER_MIN * $minutes);
            } elseif ($target < $happiness) {
                $happiness = max($target, $happiness - SimConstants::HAPPINESS_FALL_PER_MIN * $minutes);
            }
        }

        return max(SimConstants::HAPPINESS_MIN, min(SimConstants::HAPPINESS_MAX, $happiness));
    }
}
