<?php

namespace App\Game\Simulation;

use App\Game\Resource\ResourceCode;

// 模拟系统常量:地图尺寸、初始人口/资源区间、容量产出类型等
class SimConstants
{
    // 人均粮食消耗(每分钟)
    // 依据 v3.1 §10.1「基础粮食消耗/分钟 = population × 0.03」;
    // 此前实现写的是 0.1(偏离规格 3.3 倍),M2 资源 code 迁移时一并修正
    public const FOOD_PER_CAPITA_PER_MIN = 0.03;

    // 最大离线结算时长(秒):超过此时长的离线段按此上限结算,防止长期挂机一次性暴收
    // 依据 CLAUDE §18 参考值 12h/24h 取 12h,最终数值待用户确认,可调
    public const MAX_OFFLINE_SECONDS = 43200;

    // 基础仓储容量(无仓储类建筑时的默认上限)
    // 注意:必须大于 START_RESOURCES 各资源上限(wood 400/food 500),
    // 否则新城建成时资源已超过上限,首次结算会被夹到 200 而丢失资源(见 P4 Task3 调试记录)
    public const BASE_STORAGE = 1000;

    // 地图宽高(格)
    public const MAP_W = 20;
    public const MAP_H = 20;

    // 建城初始人口
    // v3.2 §10.4「M2 接入劳动力系统时的存档兼容:现有新城/初始城人口 10 → 30」
    public const START_POPULATION = 30;

    // 劳动力比例:availableWorkers = floor(population × 0.60)(v3.2 §10.4)
    public const WORKER_RATIO = 0.60;

    // 人口基础增长率(每分钟):所有因子为 1.0 时的上限基准 0.2%/min(v3.2 §10.3)
    public const BASE_GROWTH_PER_MIN = 0.002;

    // 分段结算的段长(分钟):离线时长按此切段,段内人口恒定、段末更新人口(CLAUDE §18 分段结算)
    // 12h 封顶 ÷ 30min = 24 段,恰好等于 MAX_SEGMENTS
    public const SEGMENT_MINUTES = 30;

    // 单次结算的最大段数:12h/30min = 24。段数上限保证最坏情况下的循环次数可控
    public const MAX_SEGMENTS = 24;

    // ---- 粮食赤字三级后果(v3.2 §10.1,数值不得在代码里改,要调先提 game_data_version)----

    // 粮食库存低于「几分钟的当前人口消耗」判定为严重短缺(§10.1「粮食库存 < 3 分钟当前人口消耗」)
    public const FOOD_SHORTAGE_MINUTES = 3;

    // 严重短缺:population -0.5%/分钟(迁出)
    public const FOOD_SHORTAGE_LOSS_PER_MIN = -0.005;

    // 粮食库存归零后的宽限时长(分钟):持续归零 >= 10 分钟才开始按饥荒扣人口
    public const FOOD_ZERO_GRACE_MINUTES = 10;

    // 饥荒:population -1.0%/分钟
    public const FOOD_ZERO_LOSS_PER_MIN = -0.01;

    // 人口下限:人口短缺损失不能使人口低于 5(§10.1;只约束损失方向)
    public const MIN_POPULATION = 5;

    // 住房因子分段函数的两个拐点(§10.3 housingFactor):
    // 使用率 < 0.80 → 1.0;0.80~1.00 → 从 1.0 线性下降到 0.2;>= 1.00 → 0
    public const HOUSING_USAGE_FULL = 0.80;
    public const HOUSING_FACTOR_AT_CAP = 0.2;

    // ---- 幸福度 Happiness(v3.2 §10.2,数值同样不得在代码里改,要调先提 game_data_version)----

    // 基线幸福 / 新城初始幸福(§10.2「baseHappiness = 60」)
    public const HAPPINESS_BASE = 60.0;

    // 夹取区间(§10.2「happiness = clamp(happiness, 0, 100)」)
    public const HAPPINESS_MIN = 0.0;
    public const HAPPINESS_MAX = 100.0;

    // 快落慢升(§10.2「下降最大速度 = -1.0 / 分钟;恢复最大速度 = +0.5 / 分钟」)
    public const HAPPINESS_RISE_PER_MIN = 0.5;
    public const HAPPINESS_FALL_PER_MIN = 1.0;

    // 住房加成(§10.2):使用率 <= 0.90 → +10;0.90~1.00 线性降到 0;超容后向 -15 收敛
    public const HAPPINESS_HOUSING_BONUS = 10.0;
    public const HAPPINESS_HOUSING_GOOD_USAGE = 0.90;
    public const HAPPINESS_HOUSING_OVER_PENALTY = -15.0;
    // 超容多少比例时惩罚吃满 -15。v3.2 只写「向 -15 收敛」没给斜率,
    // 这里沿用 housingFactor 同款 0.20 跨度(超容 20% 触底),属于本次补充假设
    public const HAPPINESS_HOUSING_OVER_SPAN = 0.20;

    // 覆盖类加成(§10.2 医疗 / 治安同一映射:满覆盖 +5,不足按比例)
    public const HAPPINESS_COVERAGE_BONUS = 5.0;

    // 食物品质四档(§10.1 覆盖率阈值 → §10.2 幸福加成)
    public const FOOD_QUALITY_FLOUR_BREAD_COVERAGE = 0.30;  // 面粉/面包覆盖 > 30% → +5
    public const FOOD_QUALITY_FLOUR_BREAD_BONUS = 5.0;
    public const FOOD_QUALITY_PROCESSED_COVERAGE = 0.50;    // 加工食品覆盖 > 50% → +10
    public const FOOD_QUALITY_PROCESSED_BONUS = 10.0;
    public const FOOD_QUALITY_HIGH_COVERAGE = 0.50;         // 高品质粮食覆盖 > 50% → +15
    public const FOOD_QUALITY_HIGH_BONUS = 15.0;

    // 粮食赤字惩罚(§10.1「连续赤字 >= 5 分钟 → happiness -1/分钟,直到赤字解除」)
    public const FOOD_DEFICIT_GRACE_MINUTES = 5;
    public const HAPPINESS_DEFICIT_PENALTY_PER_MIN = 1.0;

    // happinessFactor 分段(§10.3):>= 70 → 1.0;50~70 → 0.5 线性升到 1.0;< 50 → 0
    public const HAPPINESS_FACTOR_ZERO_BELOW = 50.0;
    public const HAPPINESS_FACTOR_FULL_AT = 70.0;
    public const HAPPINESS_FACTOR_AT_FLOOR = 0.5;

    // ---- 财政 / 治理(v3.2 §10.5 财政、§10.6 治理,数值同样不得在代码里改)----

    // 时代 I 的人均税额(资金 / 人 / 分钟)(§10.5「时代 I:taxPerCapitaPerMin = 0.02」)
    public const TAX_PER_CAPITA_ERA_1 = 0.02;

    // 每进入下一个时代人均税额 ×1.5(§10.5「每进入下一个时代:taxPerCapitaPerMin × 1.5」)
    // 即 taxPerCapitaPerMin = 0.02 × 1.5^(era_order − 1):I=0.02、II=0.03、III=0.045…
    public const TAX_ERA_MULTIPLIER = 1.5;

    // 治理效率四档(§10.5「治理效率」与 §10.6「治理」是同一张表,不写第二份):
    //   governanceLoad = population / max(1, governanceCapacity)
    //   <= 0.80 → 1.00;0.80~1.00 → 0.90;1.00~1.25 → 0.70;> 1.25 → 0.50
    // 档位边界取「上界闭区间」(load 恰为 0.80 / 1.00 / 1.25 时归入惩罚较轻的那一档),
    // 与 §10.3 housingFactor 的 `< 0.80` 写法一致:v3.2 只写了第一档的 `<=`,其余靠这条约定补齐
    public const GOVERNANCE_LOAD_GOOD = 0.80;
    public const GOVERNANCE_LOAD_TIGHT = 1.00;
    public const GOVERNANCE_LOAD_OVER = 1.25;
    public const GOVERNANCE_EFFICIENCY_GOOD = 1.00;
    public const GOVERNANCE_EFFICIENCY_TIGHT = 0.90;
    public const GOVERNANCE_EFFICIENCY_OVER = 0.70;
    public const GOVERNANCE_EFFICIENCY_COLLAPSE = 0.50;

    // 维护欠费半停工(§10.5「对应欠费建筑 productionFactor *= 0.50」):
    // 取代 M1 的「money = max(0, money) 然后继续满产」白嫖口径
    public const MAINTENANCE_ARREARS_FACTOR = 0.50;

    // 财政预警两档(§10.5「财政储备 < 10分钟总维护 → 黄色预警;< 3分钟总维护 → 红色预警」)。
    // 分母是全城维护资金速率;维护为 0 的城市不可能欠费,恒 none
    public const FISCAL_WARNING_YELLOW_MINUTES = 10.0;
    public const FISCAL_WARNING_RED_MINUTES = 3.0;

    // ---- 物流(v3.2 §10.7 物流 + §3.3 等级状态公式,数值同样不得在代码里改)----

    // 距离系数(§10.7「M2:distanceFactor = 1.0」):地图距离惩罚留到 M3 大地图深化。
    // 保留成常量而不是直接写 1.0,是为了 M3 接大地图时只换这一处的取值来源
    public const LOGISTICS_DISTANCE_FACTOR = 1.0;

    // 运输负载分档拐点(§10.7):
    //   transportLoad = transportDemand / max(1, transportCapacity)
    //   <= 0.80        → logisticsFactor = 1.00
    //   0.80 ~ 1.00    → 轻微运输延迟(§10.7 只写了「延迟」没写降产,所以仍是 1.00)
    //   1.00 ~ 1.25    → 从 1.00 线性下降至 0.70
    //   > 1.25         → 继续下降但不低于 0.25,并产生拥堵警报
    public const TRANSPORT_LOAD_FREE = 0.80;
    public const TRANSPORT_LOAD_TIGHT = 1.00;
    public const TRANSPORT_LOAD_OVER = 1.25;

    // 物流率的三个锚点(§10.7 + §3.3「clamp(availableTransportCapacity / transportDemand, 0.25, 1)」):
    // 上限 1.00、负载 1.25 处 0.70、下限 0.25(§15 回归表「物流率不低于 0.25」)
    public const LOGISTICS_FACTOR_MAX = 1.00;
    public const LOGISTICS_FACTOR_AT_OVER = 0.70;
    public const LOGISTICS_FACTOR_MIN = 0.25;

    // 物流需求的起算时代(**本次补充假设,见 SimulationService::applyLocked 的注释**):
    // v3.2 全表最早的运输建筑是 T02(时代 II / TECH_II_LOG),时代 I 没有任何建筑能产运输容量;
    // §5.1「I→II 升级后新增核心 = 农田、市场、基础运输」也把运输写成时代 II 才有的东西。
    // 若时代 I 照样计需求,全部时代 I 城市会被判定重度拥堵且无法自救,与 §13「物流是可经营的瓶颈」相悖
    public const LOGISTICS_MIN_ERA_ORDER = 2;

    // ---- 科技加成(v3.2 §5 科技树的 effect_code 列,M2-B3)----

    // v3.2 §5 的 50 条科技,effect_code 清一色是 `<branch>_base_efficiency_2pct`
    // (sustainability / industry / civilization / logistics / defense 五条分支各 10 条),
    // 即「每解锁一条科技 → 该科技所属分支的建筑基础效率 +2%」。
    // 全表同构,所以不需要逐科技的效果表:一个常量 + 分支归属就够了。
    //
    // 建筑属于哪条分支不另立映射表,而是走既有定义数据:
    //   building_definition.tech_id(94 栋全部非空)→ technology_definition.branch
    // 也就是「解锁这栋楼的那条科技在哪条分支,这栋楼就在哪条分支」(CLAUDE §13 数据驱动)。
    //
    // 满解锁一条分支 = 10 × 2% → 该分支建筑 1.20×,远在 §13 的 2.75× 硬帽之下;
    // 帽仍由 multiplierProduct 统一夹,这里不自己夹第二次
    public const TECH_BRANCH_EFFICIENCY_BONUS = 0.02;

    // ---- 建筑升级期间(v3.2 §3.2)----

    // 「Level 2/3 升级时建筑进入 upgrading 状态:生产建筑默认暂停生产;
    //   住宅只保留 50% 人口容量,避免升级期间无风险」(§3.2 原文)。
    // 折算基数是**旧等级**的容量:level 列要到升级完工才 +1(见 ConstructionService::settleFinished)。
    //
    // 产 population_capacity 的建筑恰好就是 H01~H10 住宅(全 94 栋里只有 H 系产这项,且只产这一项),
    // 所以「按产出类型判定」与「按住宅判定」在 v3.2 数据下完全等价,不必再引入 category 判断。
    //
    // **本次补充假设**:§3.2 只点名住宅,其余容量类(仓储 / 治理 / 运输 / 医疗 / 国防)升级期间
    // 保留 100% —— 没有明文就不施加惩罚(保守方向:不凭空发明数值,也不让玩家被没写过的规则罚)
    public const UPGRADING_HOUSING_CAPACITY_RATE = 0.50;

    // ---- §13 生产倍率硬上限(防爆)----

    // 「NPC + 工具 + 科技 + 事件总生产倍率建议硬封顶在 2.75×;终局特殊建筑最多 3.25×」(§13 原文)。
    // 落点唯一:SimulationService::multiplierProduct(),各系统不得在自己内部另夹一次
    public const MULTIPLIER_CAP = 2.75;

    // 终局特殊建筑的放宽上限。M2 还没有「终局特殊建筑」这个标记位(定义表无该列),
    // 所以现在没有任何建筑走这一档;M3 补标记后由调用方把它传进 multiplierProduct()
    public const MULTIPLIER_CAP_ENDGAME = 3.25;

    // 容量类产出(建筑等级定义中的产出类型)
    // 单一来源是 ResourceCode::CAPACITY,这里只做别名,避免调用方两处引用不一致
    public const CAPACITY_OUTPUTS = ResourceCode::CAPACITY;

    // 建城初始资源区间 [下限, 上限],区间内随机取整数
    public const START_RESOURCES = [
        ResourceCode::WOOD  => [200, 400],  // 木材
        ResourceCode::STONE => [100, 200],  // 石料
        ResourceCode::FOOD  => [300, 500],  // 粮食
    ];

    // 建城初始资金区间 [下限, 上限]
    public const START_MONEY = [200, 400];
}
