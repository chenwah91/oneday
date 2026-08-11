<?php

namespace App\Game\Simulation;

use App\Game\Building\ConstructionService;
use App\Game\Defense\DefenseService;
use App\Game\Modifier\ModifierBus;
use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\ConsumptionPoint;
use App\Game\Modifier\Providers\LogisticsMultiplierProvider;
use App\Game\Modifier\Providers\PowerMultiplierProvider;
use App\Game\Resource\ResourceCode;
use App\Models\City;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Time Delta 懒结算:按 now - last_simulated_at 应用生产/消耗/维护/粮食,资源夹在 [0, 存储上限]
//
// M2-C1 起改为分段结算(CLAUDE §18):把经过时长切成等长的段,段内人口恒定、段末更新人口,
// 段间状态在内存滚动,全部段算完后一次性写库。人口变化会改变下一段的粮耗——这正是分段的意义。
class SimulationService
{
    // 七乘区的名单、各格含义与初始值全部在 App\Game\Modifier\ModifierTarget(M3-D0.1)。
    //
    // M3 起「查数据 → 算乘数 → 填槽」的逻辑从这里搬进各自的 Provider,内核只做三件事:
    //   ModifierBus::default() → prepare(准备段一次性取数)→ multipliersFor(逐实例填七格)。
    // 于是 D1~D5 各系统接线时只新增 Provider 类,**不再改本文件**(backlog §10.2 纪律)。

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
                'bl.maintenance_money_per_min', 'bl.maintenance_food_per_min',
                // 耗电(M.1):v3.2 §3.5 把 power_per_min 与三项维护并列成一组「常态开销」,
                // 它是全城耗电需求的**唯一**口径(input_json 里的 electricity 是同一件事的 V2 遗留写法)
                'bl.power_per_min'
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
        // 电力装机容量(M.1 / §8 RS017 capacity_contract):唯一来源 = 建筑 output_json 的 electricity。
        // 它是 powerFactor 的分子,与 transportCapacity 在物流里的角色一一对应
        $powerCapacityPerMin = 0.0;
        // 贸易 / 金融容量(§5.4,W5 起):M2~W4 一直被 isCapacity() 整条丢弃 ——
        // C01~C04 + M01/M02 六栋建筑因此至今是纯负债(交维护费、不产生任何效果)。
        // 提取成全城值之后:
        //   trade_capacity   → 市场**单城成交量上限的城市侧分母**(MarketDefinition::cityWindowQuota,backlog §5.4)
        //                      + EVT_PORT_CONGESTION 的条件 metric;
        //   finance_capacity → 目前**只作读数回传**(§5.4 的金融玩法未定,不发明语义)。
        // 与其他容量类一样:不进 grossOut、不入 city_resources、不受乘区与满足率影响
        $tradeCapacity = 0.0;
        $financeCapacity = 0.0;
        $maintenanceMoneyPerMin = 0.0;

        // ---- M3-D0.1 modifier 总线 ----
        //
        // 七乘区各由一个 Provider 认领(worker / logistics / tech 已接线,power / npc / tool / event 占位恒 1.0)。
        // 取数一律在下面的 $bus->prepare()(准备段,锁内、分段循环之外)完成,
        // 逐实例的 multiplierFor() 是纯函数、循环内零查库 —— 与 M2 的 N+1 纪律一致。
        $bus = ModifierBus::default();

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
                    // 电力不再从库存扣(M.1 / 9.F4「电力做流量不做库存」)。
                    // 耗电的唯一口径是 power_per_min 那一列;input_json 里的 electricity 是
                    // V2 遗留的同一件事的第二种写法(36 行里 33 行两值完全相等,F08 / F09 / F10
                    // 三栋的 power_per_min 反而更高)。两处都读 = 双计,与 M2 踩过的
                    // governance_bonus / output_json 双口径是同一个坑,所以这里整条跳过。
                    // 顺带的口径后果:电力不再计入 §10.7 的运输需求 —— 电走电网不走车队
                    if ($res === ResourceCode::ELECTRICITY) { continue; }
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
                // 电力(M.1):§8 RS017 的 trade_mode = capacity_contract —— 它是**产能合约**不是库存资源。
                // 所以走与仓储 / 人口 / 治理 / 运输容量完全相同的通道:在这里提取成全城装机容量,
                // 不进 grossOut → 不入 city_resources、不占运力、不受乘区与满足率影响。
                // 「不受乘区与满足率影响」的代价是电站不派工 / 没煤也照发电,与仓储建筑不派工也给仓容
                // 是同一条既有口径(见 PowerMultiplierProvider 顶部的说明);升级中的电站同样按
                // 容量类口径保留 100%(§3.2 只点名住宅 50%,其余容量类不施加没写过的惩罚)
                if ($res === ResourceCode::ELECTRICITY) { $powerCapacityPerMin += $r; continue; }
                // 贸易 / 金融容量(W5 起真实提取,见上面两个变量的说明)
                if ($res === ResourceCode::TRADE_CAPACITY) { $tradeCapacity += $r; continue; }
                if ($res === ResourceCode::FINANCE_CAPACITY) { $financeCapacity += $r; continue; }
                // 兜底:将来若再出现新的容量类产出,在**登记 target + 接好消费点之前**一律不结算,
                // 免得它悄悄变成一种「能入库的资源」(容量是状态量,不是流量)
                if (ResourceCode::isCapacity($res)) { continue; }
                // 非容量类产出:升级中一律不产(§3.2「生产建筑默认暂停生产」)
                if ($upgrading) { continue; }
                $grossOut[$res] = ($grossOut[$res] ?? 0) + $r;
            }

            // 升级中的实例到此为止:容量已按上面的规则计入全城,但绝不进生产集合
            if ($upgrading) { continue; }

            $units[] = [
                'instanceId'  => (int) $lv->instance_id,
                'buildingId'  => $lv->building_id,
                'level'       => (int) $lv->level,
                'grossOut'    => $grossOut,
                'grossIn'     => $grossIn,
                'maintMoney'  => (float) $lv->maintenance_money_per_min,
                'maintFood'   => (float) $lv->maintenance_food_per_min,
                // 用工的分子分母(§10.4):WorkerMultiplierProvider 要,别的 Provider 也可能要,
                // 所以放进中间结构而不是在这里就算成乘数
                'workerRequired'  => (int) $lv->worker_required,
                'assignedWorkers' => (int) $lv->assigned_workers,
                // 耗电速率(M.1):PowerMultiplierProvider 用它聚合全城需求,
                // 并按 §3.3 的 `hasPowerDemand ? energyFactor : 1` 逐实例判定要不要打折。
                // upgrading 的实例根本进不到这里(§3.2 不产不耗),所以升级期间天然不占电
                'powerPerMin'     => (float) $lv->power_per_min,
                // 七乘区:本循环结束、容量与时代都定型之后,由 ModifierBus 一次性填满(见下)
                'multipliers' => [],
                // 维护欠费率(§10.5):1.0 = 维护付得起;0.5 = 本段欠费半停工。
                // 它不占七乘区里的任何一格(七乘区是 §10.11 生产总公式的固定名单,不许扩名),
                // 而是逐段由 applyLocked 改写、在 unitFactor 里单独乘在乘数积之后
                'maintRate'   => 1.0,
            ];
        }

        // 维护资金:不进配方,不受乘区与满足率影响(建筑闲置也照付),整段恒定
        foreach ($units as $u) { $maintenanceMoneyPerMin += $u['maintMoney']; }

        // 时代序号:era_order 由时代升级(M2-B6)维护;列缺失 / 为空一律按时代 I 兜底
        // (人均税额与物流时代闸门共用这一份兜底,不在两处各写一遍)
        $eraOrder = (int) ($lockedCity->era_order ?? 1);

        // ==== 非产量 target 的取数(D0.3):一趟查完,全部在分段循环之外 ====
        //
        // 七条 target 一次捞回(sumsMany = 三张表各查一次,不是每条 target 各查三次)。
        // 三个来源与逐条的 ConsumptionPoint::pct() 完全同口径:事件 modifier / 在编 NPC 特性 / 已装备工具,
        // 只认 scope=city;pct 侧只收 op=pct、flat 侧只收 op=flat(口径不符的行整条跳过)。
        // 用 sumsMany 而不是 pctMany,是因为治理容量(W6)要在**同一次读取**里同时拿 flat 与 pct。
        //
        // 位置固定在这里的理由与七乘区的准备段一字不差:
        //   ① 必须在容量提取**之后** —— 要乘的就是刚聚合出来的那三个全城容量;
        //   ② 必须在 $bus->prepare() **之前** —— 物流 Provider 拿走的运输容量必须是**乘过 pct 之后**的值,
        //      否则会出现「HUD 显示运输容量 −30%、物流乘区却按原值算」的两套真相;
        //   ③ 必须在分段循环之外 —— 循环内零查库(与七乘区、维护费减免同一条纪律)。
        $consumption = ConsumptionPoint::sumsMany([
            ModifierTarget::TRANSPORT_CAPACITY_PCT,
            ModifierTarget::TRADE_CAPACITY_PCT,
            ModifierTarget::FINANCE_CAPACITY_PCT,
            ModifierTarget::TAX_INCOME_PCT,
            ModifierTarget::MAINTENANCE_COST_PCT,
            ModifierTarget::GOVERNANCE_CAPACITY_FLAT,
            ModifierTarget::GOVERNANCE_CAPACITY_PCT,
        ], (int) $lockedCity->id, $now);
        // 下面各处仍按「一条 target 一个比例」读用,所以先把 pct 侧摊平成原来的形状 ——
        // 只有治理容量需要 flat 侧,它自己去 $consumption 里取(见下)
        $consumptionPct = array_map(
            static fn (array $sums): float => $sums[ModifierSpec::OP_PCT],
            $consumption
        );

        // ---- 容量类 pct 的**唯一消费点**(W5)----
        //
        // 夹取:三条都夹到 ≥ 0(负容量没有意义 —— 后台或事件把减益填成 −200% 也不该出现负数);
        // 上方向不夹:「运输容量 +30%」涨多少就是多少,§13 的帽只管产量乘区,不管容量。
        //
        // 乘完之后,下面这些地方自动同口径,不必各自再乘一次:
        //   物流负载的分母(transportLoad → logisticsFactor → logistics 乘区)、
        //   返回给前端的 transportCapacity、事件条件的 transport_capacity / trade_capacity、
        //   市场额度的城市侧分母(TradeService 读 $sim['tradeCapacity'])。
        $transportCapacity *= max(0.0, 1.0 + $consumptionPct[ModifierTarget::TRANSPORT_CAPACITY_PCT]);
        $tradeCapacity *= max(0.0, 1.0 + $consumptionPct[ModifierTarget::TRADE_CAPACITY_PCT]);
        $financeCapacity *= max(0.0, 1.0 + $consumptionPct[ModifierTarget::FINANCE_CAPACITY_PCT]);

        // ---- 治理容量 flat + pct 的**唯一消费点**(W6 清偿)----
        //
        // 合成顺序固定(镜像国防 DefenseService::evaluate,唯一一处,别处不许再算一遍):
        //     有效治理容量 = max(0, (建筑口径 + Σgovernance_capacity_flat) × (1 + Σgovernance_capacity_pct))
        // 先加后乘:N013 的「治理 +30」是一位官员带来的绝对治理力,N001 的「治理 +10%」
        // 是对**整套行政体系**的效率加成 —— 后者理应把前者也一起放大(与国防 flat/pct 同一条语义)。
        // 下限夹 0:EVT_CORRUPTION 之类把 pct 填成大负数也不该出现负容量(负容量会让 governanceLoad 变负)。
        //
        // ══ 建筑口径与有效值刻意分成两个变量 ═══════════════════════════════════════
        // $governanceCapacity(建筑口径)**保持不变**并原样返回 —— EraService 的时代门槛(DIM_GOVERNANCE)
        // 读的就是它。理由与国防 W4-B 逐字相同:一个 20 分钟的事件 buff / 一位随时可辞退的 NPC
        // 不该把城市顶过升代门槛(过了门槛人一走城市就"倒退")。时代要的是**常备治理力**,
        // 税收效率要的是**此刻的行政效率**,两者刻意不同源。
        // 这条由 GovernanceCapacityTest::test_era_gate_reads_building_capacity_not_effective 钉着。
        //
        // 作用面只有两处,都吃 $governanceCapacityEffective:
        //   ① 下面分段循环里的 governanceLoad(→ governanceEfficiency → taxIncome);
        //   ② 快照的 governance 块(capacity 给有效值、capacity_base 给建筑口径,与 defense 块同构)。
        // 威胁 / 幸福 / 治安一概不涉及治理容量(§10.2 / §10.8 没有治理项),所以没有第三处。
        $governanceFlat = $consumption[ModifierTarget::GOVERNANCE_CAPACITY_FLAT][ModifierSpec::OP_FLAT];
        $governancePct = $consumptionPct[ModifierTarget::GOVERNANCE_CAPACITY_PCT];
        $governanceCapacityEffective = max(0.0, ($governanceCapacity + $governanceFlat) * (1.0 + $governancePct));

        // ---- 国防读数统一(W4-B 留下的两处口径差,内核合并后在这里收口)----
        //
        // W4-B 交付时点名保留的差异:§10.8 的 security 覆盖率与 §10.2 的国防幸福加成读的是**建筑口径**,
        // 而威胁等级 / EVT_RAID 读的是**有效国防值**(建筑口径 + 工具/NPC flat)×(1 + NPC/事件 pct)。
        // 内核归本波次所有,所以在这里把那两处改读有效值 —— 玩家装了防御装备、招了军士之后,
        // 治安与幸福理应跟着动,否则「国防 108」与「治安按 100 算」就是两套真相。
        //
        // **时代门槛除外**:EraService 继续读建筑口径(常备国防)。一个 20 分钟的事件 buff
        // 不该把城市顶过升代门槛(buff 一过就"倒退"),这条由
        // DefenseThreatTest::test_era_gate_reads_building_score_not_effective_score 钉着 ——
        // 所以返回值里的 defenseScore 仍然是**建筑口径**(EraService 与 DefenseService 都拿它当基数)。
        //
        // 取数同样在分段循环之外一次(DefenseService::bonuses 要查三张表)。
        $effectiveDefenseScore = DefenseService::effectiveDefenseScore(
            $lockedCity, ['defenseScore' => $defenseScore], $now instanceof Carbon ? $now : Carbon::parse($now)
        );

        // ---- 准备段:各 Provider 一次性取数,然后逐实例填满七乘区(M3-D0.2 内核接线点)----
        //
        // 位置固定在这里的两个理由:
        //   ① 必须在建筑实例中间结构与全城容量都定型之后 —— 物流要按全城 grossIn/grossOut 聚合需求,
        //      再除以全城运输容量;
        //   ② 必须在分段循环之前 —— 整段结算内建筑集合不变,乘区也就不变,循环内零查库。
        // 建筑 ID 列表取自 $levels(含 upgrading 实例),与重构前科技乘区的入参口径一字不差。
        $bus->prepare(new ModifierContext(
            cityId: (int) $lockedCity->id,
            eraOrder: $eraOrder,
            buildingIds: $levels->pluck('building_id')->unique()->all(),
            capacities: [
                ModifierContext::CAP_STORAGE    => $storageCap,
                ModifierContext::CAP_POPULATION => $populationCap,
                ModifierContext::CAP_MEDICAL    => $medicalCapacity,
                ModifierContext::CAP_DEFENSE    => $defenseScore,
                // 治理容量给**有效值**(与 CAP_TRANSPORT 同口径:两者的 pct 都在内核里乘完了)。
                // 目前没有任何 Provider 读这一格,给有效值是为了「将来第一个读它的人拿到的就是真实生效的容量」
                ModifierContext::CAP_GOVERNANCE => $governanceCapacityEffective,
                ModifierContext::CAP_TRANSPORT  => $transportCapacity,
                ModifierContext::CAP_POWER      => $powerCapacityPerMin,
            ],
            city: $lockedCity,
            now: $now,
            totalMinutes: $elapsed / 60.0,
        ), $units);

        foreach ($units as $i => $u) {
            $units[$i]['multipliers'] = $bus->multipliersFor($u);
        }

        // 物流读数(§10.7):需求 / 负载 / 物流率 / 拥堵警报都由 LogisticsMultiplierProvider 在准备段算好,
        // 这里只取回来放进返回值给前端显示 —— 结算侧已经通过 logistics 那一格生效,不再二次使用
        /** @var LogisticsMultiplierProvider $logisticsProvider */
        $logisticsProvider = $bus->provider(ModifierTarget::SLOT_LOGISTICS);
        $transportDemandPerMin = $logisticsProvider->demandPerMin();
        $transportLoad = $logisticsProvider->load();
        $logisticsFactor = $logisticsProvider->factor();
        $transportCongestion = $logisticsProvider->congestion();

        // 电力读数(M.1 / §3.3):装机 / 可用 / 需求 / 余量 / 使用率 / 电力率 / 缺电警报,
        // 全部由 PowerMultiplierProvider 在准备段算好,这里只取回来放进返回值给前端与事件条件用 ——
        // 结算侧已经通过 power 那一格生效,不再二次使用(与物流读数逐字同构)
        /** @var PowerMultiplierProvider $powerProvider */
        $powerProvider = $bus->provider(ModifierTarget::SLOT_POWER);
        $powerDemandPerMin = $powerProvider->demandPerMin();
        $powerAvailablePerMin = $powerProvider->availablePerMin();
        $powerSparePerMin = $powerProvider->sparePerMin();
        $powerUsageRate = $powerProvider->usageRate();
        $powerFactor = $powerProvider->factor();
        $powerShortage = $powerProvider->shortage();
        $powerEventPct = $powerProvider->eventPct();

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
        // $eraOrder 在上面的准备段之前已按同一套兜底口径取好,这里直接复用,避免两处各写一份兜底
        $taxPerCapita = self::taxPerCapitaPerMin($eraOrder);

        // ==== M3 内核里唯一的「系统级常态开销」消费点(用户 2026-08-11 以内核所有者身份对 W2-A 一次性豁免)====
        //
        // 背景:§10.5 的 expenses 口径里,NPC 工资 / 口粮与建筑维护同级,但 D0 总线只有乘区与
        // happiness/security 两条 flat 通道,没有「按分钟扣钱扣粮」的出口。与其让 NPC(以及后面的
        // 工具维护、事件持续扣费)各自往内核里插一段,不如在 ModifierTarget 里登记两条**通用**支出
        // target,内核只在这里读一次 —— 新系统接入时改的是自己的 Provider,内核仍然一个字都不动。
        //
        // 纪律三条:
        //   ① 取值在**分段循环之外**,循环内零查库(与七乘区同一条纪律);
        //   ② 资金侧直接并进 $maintenanceMoneyPerMin —— 这样欠费判定、半停工、财政预警、
        //      返回给前端的维护速率四处自动同口径,不会出现「预警没算工资」这种两套真相;
        //   ③ 口粮侧作为参数交给 segmentRates,和 §10.1 的人均粮耗落在同一行,不另开扣粮路径。
        //
        // ---- 维护费减免(D0.3 登记的 maintenance_cost_pct,**唯一消费点就是这里**)----
        //
        // 投稿者:§6.3 的 NPC 特性(N017 −5% / N020 −10%)与 §7 的工程类工具(IT016 −8%),
        // 取数走 ConsumptionPoint(三个来源一次读齐),同样在**分段循环之外**取一次值。
        //
        // ══ 与欠费判定的叠加顺序(本波次定死,四处口径靠这一行统一)═══════════════
        //   ① 先按建筑维护费打折:$maintenanceMoneyPerMin ×= max(0, 1 + pct);
        //   ② 再并入总线的通用支出通道(NPC 工资)—— 工资**不打折**:
        //      maintenance_cost_pct 的登记说明是「建筑维护资金」,把它顺手作用到工资上
        //      等于让一条 target 有了两种语义,也会让「减免 10%」在有 NPC 的城市变成一个说不清的数;
        //   ③ 打折后的这个总额同时是:欠费判定的应付额、半停工的触发依据、财政预警的分母、
        //      以及返回给前端的 maintenance_money_per_min —— **四处同源**,不会出现两套真相。
        // 一句话:**折扣在前,欠费判定在后**。省钱先生效,省完还付不起才半停工。
        //
        // 夹取:factor 夹到 ≥ 0(负维护 = 白送钱,后台把减免填成 −200% 也不该变成收入来源);
        // 上方向不夹 —— 「维护费 +X%」将来若由负面事件投稿,涨多少就是多少
        // (取值已在上面的 pctMany 里一趟取回,这里只取用 —— 别再单独查一次,那是三条多余的往返)
        $maintenanceCostPct = $consumptionPct[ModifierTarget::MAINTENANCE_COST_PCT];
        $maintenanceMoneyPerMin *= max(0.0, 1.0 + $maintenanceCostPct);

        // ---- 税收修正(D0.3 的 tax_income_pct,**唯一消费点是下面循环里的税收那一行**)----
        //
        // 投稿者:§9.2 的 EVT_CRIME(−10%)/ EVT_CORRUPTION(−15%)与 §6.3 的 N013(税收 +8%)。
        // 夹到 ≥ 0:后台或事件把减益填成 −200% 也只是「收不上税」,绝不该变成**倒贴给玩家**。
        // 上方向不夹,与维护费一致。
        //
        // ⚠️ 它改的是**税收**不是**税率**:§10.5 明文「M2:税率固定 / 玩家不可调」,M3 也没开税率政策。
        // 所以 EVT_TAX_PROTEST(条件「税率偏高」)继续停用 —— 条件恒不成立,不靠这条 target 复活。
        $taxIncomeFactor = max(0.0, 1.0 + $consumptionPct[ModifierTarget::TAX_INCOME_PCT]);

        // 支出通道对整段窗口取一次值(NPC 在结算窗口内不增不减:招募/辞退各自的端点会先跑一次结算)。
        $maintenanceMoneyPerMin += $bus->flat(ModifierTarget::EXPENSE_MONEY_PER_MIN, 0.0, $totalMinutes);
        $expenseFoodPerMin = $bus->flat(ModifierTarget::EXPENSE_FOOD_PER_MIN, 0.0, $totalMinutes);

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
            // 治理效率与税收都按「段起人口」算 —— 与粮耗、幸福目标同一条「段内人口恒定」纪律。
            // 分母用**有效治理容量**(建筑口径 + flat,再乘 pct):W6 起行政 NPC 与 IT022 真正生效
            $governanceLoad = self::governanceLoad($population, $governanceCapacityEffective);
            $governanceEfficiency = self::governanceEfficiency($governanceLoad);
            // taxIncome = population × taxPerCapitaPerMin × governanceEfficiency × (1 + Σtax_income_pct)(§10.5)。
            // 税率仍然固定、玩家不可调(§10.5「M2:税率固定 / 玩家不可调」);
            // 最后那一项是**事件与 NPC 对税收本身**的修正(W5 接线,取值在循环外取一次)
            $taxIncomePerMin = $population * $taxPerCapita * $governanceEfficiency * $taxIncomeFactor;

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
            [$ratePerMin, $grossProduction, $grossConsumption] = self::segmentRates($units, $resources, $population, $span, $expenseFoodPerMin);

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
                // §10.2 的国防幸福加成同样改读**有效国防值**(与 security 同一处收口,理由见上面的说明)
                $effectiveDefenseScore,
                $grossProduction,
                $deficitMinutes,
                // flat 通道(M3-D0.2):持续型事件 / NPC 特性对幸福的直接冲击,改的是**目标值**,
                // 由 §10.2 的快落慢升自然收敛(瞬时型改当前值,由事件系统自己结算,不经这里)。
                // 按段取值:偏移量与 foodZeroOffset 同一套口径(相对结算窗口起点的分钟数),
                // D4 接入后用它与 modifier 的 starts_at / ends_at 求交。M3 W1 无投稿者 → 恒 0.0
                $bus->flat(ModifierTarget::HAPPINESS_FLAT, $segStartOffset, $segEndOffset)
            );
            $happiness = self::stepHappiness($happiness, $happinessTarget, $span);
        }

        // health / security(§10.8):派生值,不落库,按结算后的人口与全城容量现算
        $health = (int) round(self::coverage($medicalCapacity, $population) * 100);
        // security 另接 flat 通道(M3-D0.2):EVT_STRIKE 治安 -6、D5 的威胁等级等直接加减落在这里,
        // 覆盖率映射出来的 0~100 加上 flat 之后仍夹回 [0, 100]。
        // 取整段窗口([0, totalMinutes])的 flat:security 本身就是「结算后」的派生值,不分段。
        // M3 W1 无投稿者 → flat 恒 0.0,夹取也就恒等于原值
        // 分子取**有效国防值**(含工具/NPC flat 与 NPC/事件 pct):W4-B 交付时留的口径差在这里收口,
        // 快照的 defense 区块、事件条件的 threat_level、EVT_RAID 的损失公式与这里读的是同一个数
        $security = (int) round(max(0.0, min(100.0,
            self::coverage($effectiveDefenseScore, $population) * 100
            + $bus->flat(ModifierTarget::SECURITY_FLAT, 0.0, $totalMinutes)
        )));

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
            // 医疗容量 / 国防值:综合面板与 M3 疾病/犯罪联动的数据基础。
            // defenseScore 是**建筑口径**(容量提取的原值),刻意不换成有效值 ——
            // EraService 的时代门槛与 DefenseService::evaluate 都拿它当基数,
            // 换成有效值会让「临时 buff 顶过升代门槛」并让国防加成被算两次
            'medicalCapacity'         => $medicalCapacity,
            'defenseScore'            => $defenseScore,
            // 有效国防值(= DefenseService::effectiveDefenseScore 的结果):
            // 本次结算里 security 覆盖率与 §10.2 国防幸福加成用的就是它,回传只为让调用方看得见「用的是哪个数」
            'defenseScoreEffective'   => $effectiveDefenseScore,
            // 财政 / 治理(§10.5 / §10.6),全部是「最后一段口径」的派生值,一个都不落库:
            //   governanceCapacity 全城治理容量的**建筑口径**(唯一来源 = output_json 的 governance_capacity)。
            //     刻意不换成有效值:EraService 的时代门槛(DIM_GOVERNANCE)拿它当基数,
            //     换成有效值会让「临时 buff / 随时可辞退的 NPC 顶过升代门槛」(与 defenseScore 同一条口径)
            //   governanceCapacityEffective / Flat / Pct 有效治理容量与它的两段来源(W6):
            //     有效值 = max(0, (建筑口径 + Σflat) × (1 + Σpct)),governanceLoad 用的就是它
            //   governanceLoad / governanceEfficiency 治理负载与四档效率
            //   taxIncomePerMin 本段税收速率(资金/分钟)
            //   maintenanceMoneyPerMin 全城维护资金速率(财政预警的分母,也是欠费判定的依据)
            //   maintenanceRate / maintenanceArrears 欠费半停工状态(§10.5 要求的 maintenanceArrears 等价状态)
            'governanceCapacity'      => $governanceCapacity,
            'governanceCapacityEffective' => $governanceCapacityEffective,
            'governanceCapacityFlat'  => $governanceFlat,
            'governanceCapacityPct'   => $governancePct,
            'governanceLoad'          => $governanceLoad,
            'governanceEfficiency'    => $governanceEfficiency,
            'taxIncomePerMin'         => $taxIncomePerMin,
            //   taxIncomePct 税收修正合计(D0.3 的 tax_income_pct,−0.10 = 少收 10%)。
            //   已经作用在 taxIncomePerMin 上,给前端只为回答「为什么这段税收变少了」
            'taxIncomePct'            => $consumptionPct[ModifierTarget::TAX_INCOME_PCT],
            'maintenanceMoneyPerMin'  => $maintenanceMoneyPerMin,
            'maintenanceRate'         => $maintenanceRate,
            'maintenanceArrears'      => $maintenanceArrears,
            //   maintenanceCostPct 维护费减免的合计比例(D0.3 的 maintenance_cost_pct,
            //   −0.10 = 减免 10%)。已经作用在 maintenanceMoneyPerMin 上,给前端只为「为什么便宜了」
            'maintenanceCostPct'      => $maintenanceCostPct,
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
            // 容量类三条 pct(W5):容量值本身已经乘过它们,这里回传只为「为什么容量变了」说得清。
            //   transportCapacityPct  运输容量修正(事件 / 物流 NPC / IT018,含并入的「铁路容量」)
            //   tradeCapacity(+Pct)  贸易容量:市场单城成交量上限的城市侧分母(backlog §5.4)
            //   financeCapacity(+Pct)金融容量:目前只作读数,尚无消费者
            'transportCapacityPct'    => $consumptionPct[ModifierTarget::TRANSPORT_CAPACITY_PCT],
            'tradeCapacity'           => $tradeCapacity,
            'tradeCapacityPct'        => $consumptionPct[ModifierTarget::TRADE_CAPACITY_PCT],
            'financeCapacity'         => $financeCapacity,
            'financeCapacityPct'      => $consumptionPct[ModifierTarget::FINANCE_CAPACITY_PCT],
            // 电力(M.1 / §3.3 / §8 RS017),同样全是派生值,一个都不落库
            //(电力不进 city_resources —— 9.F4「流量不做库存」):
            //   powerCapacityPerMin   全城装机容量(唯一来源 = output_json 的 electricity)
            //   powerAvailablePerMin  事件减益(EVT_BLACKOUT)之后的可用发电
            //   powerDemandPerMin     全城耗电需求(唯一来源 = building_level_definition.power_per_min)
            //   powerSparePerMin      余量 = 可用 − 需求(§4 的 power_spare_per_min 特殊前置将来读它)
            //   powerUsageRate        电力使用率 = 需求 / max(1, **装机**)(EVT_BLACKOUT 的条件 metric)
            //   powerFactor           power 乘区里生效的就是它
            //   powerShortage         缺电警报(有需求且 factor < 1),与物流的 congestion 对称
            //   powerEventPct         本窗口内生效的电力事件减益合计(≤ 0,已按覆盖比例折算)
            'powerCapacityPerMin'     => $powerCapacityPerMin,
            'powerAvailablePerMin'    => $powerAvailablePerMin,
            'powerDemandPerMin'       => $powerDemandPerMin,
            'powerSparePerMin'        => $powerSparePerMin,
            'powerUsageRate'          => $powerUsageRate,
            'powerFactor'             => $powerFactor,
            'powerShortage'           => $powerShortage,
            'powerEventPct'           => $powerEventPct,
        ];
    }

    // 单段速率:给定段起库存与段起人口,算出本段的资源净速率与 gross 产出/消耗。
    // gross 产出与 gross 消耗分开累计、不提前合并成 net:
    // production_utilization、food_net_rate 的分子分母、§68 理论最大 delta 都要的是分开的值。
    // $expenseFoodPerMin = 总线通用支出通道的口粮侧(M3-D1:NPC 口粮),与人均粮耗同级、同一行扣
    private static function segmentRates(array $units, array $resources, float $population, float $minutes, float $expenseFoodPerMin = 0.0): array
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

        // 人口粮食消耗:基础粮食消耗/分钟 = population × 0.03(§10.1),段内人口恒定。
        // 另减总线支出通道的口粮(§6.3 NPC 的 food_per_min):NPC 吃的是同一仓粮食,
        // 与人口粮耗一样不进配方、不受乘区与满足率影响,缺粮时由落库处的 max(0,…) 夹住
        $ratePerMin[ResourceCode::FOOD] = ($ratePerMin[ResourceCode::FOOD] ?? 0)
            - $population * SimConstants::FOOD_PER_CAPITA_PER_MIN
            - $expenseFoodPerMin;

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
    //   + flatBonus(M3-D0.2 的 flat 通道:持续型事件 / NPC 特性的直接加减,接入前恒 0)
    // 各分项都是「本段起始状态」的函数,段内恒定。最终夹在 [0,100]
    private static function happinessTarget(
        float $population,
        float $populationCap,
        float $medicalCapacity,
        float $defenseScore,
        array $grossProduction,
        float $deficitMinutes,
        float $flatBonus = 0.0
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
            + $shortagePenalty
            + $flatBonus;

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
