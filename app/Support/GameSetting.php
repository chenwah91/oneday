<?php

namespace App\Support;

use App\Game\Resource\ResourceCode;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

// 后台可配规则开关(game_settings 表的读写入口)。
//
// 与 Definition 数值的分工:
//   - building_level_definition 等 = 「游戏数值」,改动要 bump game_data_version(AdminDefinitionController);
//   - game_settings              = 「规则开关」,决定某条规则要不要生效,用于运营救急,不动数值版本。
//
// 三条纪律:
//   1. Allowlist(CLAUDE §45):只有 DEFINITIONS 里登记过的 key 才能读写,未知 key 一律拒绝,
//      不允许后台随手造一个 key 出来(否则代码里没人读它,只会变成误导运营的死配置);
//   2. 请求级缓存:SimulationService::applyLocked 在事务内被高频调用,每实例查一次库不可接受。
//      一次请求内整表只查一次,之后全部走 Context 缓存(用 Context 而非类内 static:
//      Context 跟随请求生命周期,测试里每个用例重建 Application 会自动清空,不会跨用例串味);
//   3. 缺行/缺表不改变游戏行为:get 永远能回退到 DEFINITIONS 里登记的默认值。
final class GameSetting
{
    // ---------- 已登记的开关 ----------

    // 工人「只减不增」的操作永远放行(v3.2 §10.4 的宽松执行,用户 2026-08-10 拍板)
    public const WORKER_ASSIGN_ALLOW_DECREASE_ALWAYS = 'worker_assign_allow_decrease_always';

    // 「没派工人就不生产」的总开关:关掉后 workerFactor 恒为 1.0
    public const WORKER_GATE_ENABLED = 'worker_gate_enabled';

    // 建城初始资源(对象型):{resource_code: 数量},含 money 与 knowledge
    public const INITIAL_RESOURCES = 'initial_resources';

    // ---------- M3-D3 市场(用户 2026-08-11 拍板「所有可调参数逐条登记」)----------
    //
    // 分工:**逐资源**的数值(基础价 / 波动率 / 弹性 / 手续费率 / 硬价格区间 / 流动性)在
    // market_definition 定义表里,后台按行改;这里登记的是**全局**规则参数 ——
    // 「一改就影响全市场」的那一档,经济出事时运营要能一秒调,不必逐行改 26 行定义。
    // 两者刻意不重叠:同一个数不允许有两个来源(fee / 价格夹取是「定义值 × 全局系数」的关系,见下)。

    // 全市场总开关:关掉后 buy / sell 一律 MARKET_CLOSED(§11.2 的一键停市)
    public const MARKET_ENABLED = 'market_enabled';

    // 价格窗口(EPOCH)秒数(9.C2 批准 60 秒)
    public const MARKET_WINDOW_SECONDS = 'market_window_seconds';

    // 移动平均窗口数 N(§8.1「最近 10 个市场价格窗口的移动平均」)
    public const MARKET_MA_WINDOWS = 'market_ma_windows';

    // 滑点系数 k:slippage = k × 成交量 / 流动性(9.C4 批准 k = 0.5)
    public const MARKET_SLIPPAGE_COEFFICIENT = 'market_slippage_coefficient';

    // 手续费率**全局倍率**:实际费率 = market_definition.fee_rate × 本值。
    // 做成倍率而不是全局费率,是为了不与定义表的逐资源 fee_rate 形成双来源
    public const MARKET_FEE_RATE_MULTIPLIER = 'market_fee_rate_multiplier';

    // 单城单窗成交量上限比例(§8.1「不超过该资源市场流动性的 10%」)
    public const MARKET_QUOTA_WINDOW_PCT = 'market_quota_window_pct';

    // 单城每小时成交量上限 = 本值 × 单窗上限(9.C7 批准 20 倍,不是 60 倍,刻意留反刷空间)
    public const MARKET_QUOTA_HOURLY_MULTIPLE = 'market_quota_hourly_multiple';

    // 价格夹取的**全局**倍率下限 / 上限:最终区间 = [基础价 × 下限, 基础价 × 上限] 与
    // 定义表 [min_price, max_price] 的**交集**。默认取 §8 全表最宽的比例(0.45 / 3.2),
    // 等价于「默认由 §8 逐行的 min/max 说了算」;运营要全局收紧时改这两个数即可
    public const MARKET_PRICE_MIN_MULTIPLE = 'market_price_min_multiple';
    public const MARKET_PRICE_MAX_MULTIPLE = 'market_price_max_multiple';

    // 流动性全局倍率:有效流动性 = market_definition.base_liquidity × 本值。
    // 调小 = 滑点更狠 + 成交量上限更低(反刷的总闸门)
    public const MARKET_LIQUIDITY_MULTIPLIER = 'market_liquidity_multiplier';

    // 供需底噪比例(9.C3):demand / supply 各加 base_liquidity × 本值,
    // 保证空服时 (0−0)/0 不会让价格跳变,也让单个玩家很难独自把价格拉满
    public const MARKET_NOISE_FLOOR_PCT = 'market_noise_floor_pct';

    // 单笔数量硬上限(§69「防止负数 / NaN / 超大数字」的绝对天花板,与流动性上限是两道独立的闸)
    public const MARKET_MAX_ORDER_QUANTITY = 'market_max_order_quantity';

    // ---- 贸易容量 → 成交量上限的城市侧分母(backlog §5.4,W5 接线)----
    //
    // 单城单窗上限 = min(流动性口径, 贸易吞吐口径),其中
    //   贸易吞吐口径 =(基础额度 + 全城 trade_capacity)× 系数 × 窗口分钟数。
    // 为什么要乘窗口分钟数:trade_capacity 是**每分钟**的吞吐率(§3.5 的容量类产出都是 /min),
    // 而额度是「一个窗口内能换手多少」——窗长可调,两者必须按时间对齐。
    //
    // 基础额度存在的理由(交付口径,不要随手调成 0):没建市场建筑的城市**不该被禁市** ——
    // 新号一栋商贸建筑都没有,禁市等于把市场这条路整条堵死;
    // 但额度必须小到「想做大宗买卖就得建市场」,C 系列六栋建筑才第一次有意义。
    public const MARKET_TRADE_CAPACITY_BASE_PER_MIN = 'market_trade_capacity_base_per_min';
    public const MARKET_TRADE_CAPACITY_FACTOR = 'market_trade_capacity_factor';

    // ---------- M3-D1 NPC 规则参数(backlog §9 A 区批准的建议默认值)----------
    //
    // 用户 2026-08-11 拍板的「后台强大」原则:NPC 的可调数值一条都不许硬编码死。
    // 下面每一条都对应 A 区表格里的一行,默认值 = A 区的建议默认值,读取一律走本类的请求级缓存。
    //
    // 不在这里的两类数:
    //   ① §6.4 / §13 的帽(单 NPC 1.60 / NPC 侧 1.50)是数值规格不是运营参数 → SimConstants;
    //   ② §6.1~§6.3 的逐 NPC 数值(工资 / 口粮 / 初始技能 / 稀有度)是 Definition → npc_definition 表,
    //      改它要 bump game_data_version(AdminDefinitionController),与规则开关分属两条路径。

    // A5 单建筑 NPC 槽位数
    public const NPC_SLOTS_PER_BUILDING = 'npc_slots_per_building';
    public const NPC_SLOTS_PER_BUILDING_L3 = 'npc_slots_per_building_l3';

    // A7 招募价格 = wage_per_min × 工资系数 × 稀有度系数
    public const NPC_RECRUIT_PRICE_WAGE_MULTIPLIER = 'npc_recruit_price_wage_multiplier';
    public const NPC_RECRUIT_PRICE_RARITY_COMMON = 'npc_recruit_price_rarity_common';
    public const NPC_RECRUIT_PRICE_RARITY_UNCOMMON = 'npc_recruit_price_rarity_uncommon';
    public const NPC_RECRUIT_PRICE_RARITY_RARE = 'npc_recruit_price_rarity_rare';
    public const NPC_RECRUIT_PRICE_RARITY_EPIC = 'npc_recruit_price_rarity_epic';
    public const NPC_RECRUIT_PRICE_RARITY_LEGENDARY = 'npc_recruit_price_rarity_legendary';

    // 招募掷点的稀有度权重(A 区未给数,§6.2「稀有度……主要决定……招募难度」的落地)
    public const NPC_RECRUIT_WEIGHT_COMMON = 'npc_recruit_weight_common';
    public const NPC_RECRUIT_WEIGHT_UNCOMMON = 'npc_recruit_weight_uncommon';
    public const NPC_RECRUIT_WEIGHT_RARE = 'npc_recruit_weight_rare';
    public const NPC_RECRUIT_WEIGHT_EPIC = 'npc_recruit_weight_epic';
    public const NPC_RECRUIT_WEIGHT_LEGENDARY = 'npc_recruit_weight_legendary';

    // A1 自然增长
    public const NPC_NATURAL_GROWTH_ENABLED = 'npc_natural_growth_enabled';
    public const NPC_NATURAL_GROWTH_WINDOW_MINUTES = 'npc_natural_growth_window_minutes';
    public const NPC_NATURAL_GROWTH_CHANCE = 'npc_natural_growth_chance';
    public const NPC_NATURAL_GROWTH_HOUSING_FREE_MIN = 'npc_natural_growth_housing_free_min';
    public const NPC_NATURAL_GROWTH_HAPPINESS_MIN = 'npc_natural_growth_happiness_min';
    public const NPC_NATURAL_GROWTH_CAP_PER_POPULATION = 'npc_natural_growth_cap_per_population';
    public const NPC_NATURAL_GROWTH_CAP_BASE = 'npc_natural_growth_cap_base';
    public const NPC_NATURAL_GROWTH_OFFLINE_MAX = 'npc_natural_growth_offline_max';

    // A4 士气与离职
    public const NPC_MORALE_ENABLED = 'npc_morale_enabled';
    public const NPC_MORALE_INITIAL = 'npc_morale_initial';
    public const NPC_MORALE_WAGE_ARREARS_PENALTY_PER_MIN = 'npc_morale_wage_arrears_penalty_per_min';
    public const NPC_MORALE_LOW_HAPPINESS_THRESHOLD = 'npc_morale_low_happiness_threshold';
    public const NPC_MORALE_LOW_HAPPINESS_PENALTY_PER_MIN = 'npc_morale_low_happiness_penalty_per_min';
    public const NPC_MORALE_RECOVER_PER_MIN = 'npc_morale_recover_per_min';
    public const NPC_MORALE_LEAVE_THRESHOLD = 'npc_morale_leave_threshold';
    public const NPC_MORALE_LEAVE_CHANCE = 'npc_morale_leave_chance';
    public const NPC_MORALE_LEAVE_WINDOW_MINUTES = 'npc_morale_leave_window_minutes';

    // A6 工作 XP 速率
    public const NPC_XP_PER_MIN = 'npc_xp_per_min';

    // ---------- M3-D2 工具 / 道具规则参数(backlog §9 B 区批准的建议默认值)----------
    //
    // 同 NPC 的分工:**逐工具**的数值(耐久点数 / 效果值 / 拆解基数)在 item_definition 定义表里,
    // 后台按行改并 bump game_data_version;这里登记的是**全局**规则参数 ——
    // 「一改就影响全服工具」的那一档(槽位数 / 每档几分钟扣 1 点 / 预警阈值 / 两个总开关)。
    // 两者刻意不重叠:同一个数不允许有两个来源。
    //
    // 不在这里的两类数:
    //   ① §7 的耐久档位(normal / industrial)是**定义数据**(由 category 决定,见 B1)→ item_definition;
    //   ② §13 的乘区总帽仍然只在 SimulationService::multiplierProduct(),工具侧不设第二道帽。

    // 制作总开关:关掉后 craft 一律 ITEM_CRAFT_DISABLED(与 market_enabled 同款的一键停机)
    public const ITEM_CRAFT_ENABLED = 'item_craft_enabled';

    // 耐久总开关:关掉后耐久不再随工作分钟递减(运营救急:耐久算错时先止血再修)
    public const ITEM_DURABILITY_ENABLED = 'item_durability_enabled';

    // B2 单栋建筑装备槽位数
    public const ITEM_SLOTS_PER_BUILDING = 'item_slots_per_building';

    // §7 + B1 两个耐久档「多少分钟工作扣 1 点」
    public const ITEM_DURABILITY_MINUTES_NORMAL = 'item_durability_minutes_normal';
    public const ITEM_DURABILITY_MINUTES_INDUSTRIAL = 'item_durability_minutes_industrial';

    // B4 耐久预警阈值(剩余耐久比例低于本值即在快照里打标)
    public const ITEM_DURABILITY_WARNING_PCT = 'item_durability_warning_pct';

    // ---------- M3-D4 随机事件全局参数(backlog §9 D 区批准的建议默认值)----------
    //
    // 分工与市场 / NPC / 工具完全一致,只是这次用户点名加了一条硬约束(2026-08-10 拍板③):
    // **所有事件必须在管理员后台可设定**。落地成两条互不重叠的路径:
    //   ① **逐事件**的开关 / 权重 / 持续时间 / 冷却 / 效果强度 → event_definition 表,后台按行改(bump 数值版本);
    //   ② **全局**的触发频率 / 并发上限 / 离线补算上限 / 权重三修正系数 → 这里,一改影响全服。
    // 同一个数不允许有两个来源:基础权重在定义表,乘在它上面的三个修正系数在这里。

    // 全事件总开关:关掉后不再触发新事件(已生效的实例照常到期,不强制清场)
    public const EVENT_ENABLED = 'event_enabled';

    // 资格窗口秒数(§9.1「60 秒资格窗口」)。与市场共用同一个 EPOCH 原点(Unix 0),窗长各自定义(9.D5 批准)
    public const EVENT_WINDOW_SECONDS = 'event_window_seconds';

    // 单窗基础触发概率(§9.1 建议 8%)
    public const EVENT_TRIGGER_CHANCE = 'event_trigger_chance';

    // 并发上限(§9.1「同一时间最多 3 个,其中灾害/国防类最多 1 个」)
    public const EVENT_MAX_ACTIVE = 'event_max_active';
    public const EVENT_MAX_ACTIVE_DISASTER = 'event_max_active_disaster';

    // 离线补算上限(9.D3 批准:离线期间最多补 3 次)
    public const EVENT_OFFLINE_MAX_TRIGGERS = 'event_offline_max_triggers';

    // 难度修正(§9.1 权重公式的第三个系数;9.D2 批准 M3 恒 1.0,留位给将来的难度选择)
    public const EVENT_DIFFICULTY_MULTIPLIER = 'event_difficulty_multiplier';

    // 城市状态修正(§9.1 权重公式的第二个系数,9.D2 批准的七条)
    public const EVENT_WEIGHT_FOOD_DEFICIT = 'event_weight_food_deficit';
    public const EVENT_WEIGHT_FISCAL_DEFICIT = 'event_weight_fiscal_deficit';
    public const EVENT_WEIGHT_GOVERNANCE_OVERLOAD = 'event_weight_governance_overload';
    public const EVENT_WEIGHT_LOW_SECURITY = 'event_weight_low_security';
    public const EVENT_WEIGHT_HIGH_HAPPINESS = 'event_weight_high_happiness';
    public const EVENT_WEIGHT_HIGH_HEALTH = 'event_weight_high_health';
    public const EVENT_WEIGHT_DEFENSE_OK = 'event_weight_defense_ok';

    // 上面七条修正各自的判定阈值(9.D2 原文里的「治安<65 / happiness ≥75 / health ≥80」)
    public const EVENT_LOW_SECURITY_THRESHOLD = 'event_low_security_threshold';
    public const EVENT_HIGH_HAPPINESS_THRESHOLD = 'event_high_happiness_threshold';
    public const EVENT_HIGH_HEALTH_THRESHOLD = 'event_high_health_threshold';
    public const EVENT_DEFENSE_OK_SECURITY_MIN = 'event_defense_ok_security_min';
    public const EVENT_GOVERNANCE_OVERLOAD_LOAD = 'event_governance_overload_load';

    // 瞬时治安冲击的持续时长:security 是 §10.8 的派生值,没有「当前值」可改,
    // 只能走 security_flat 通道,而 flat 必须有起止 → duration=0 的事件用这个时长
    public const EVENT_INSTANT_SECURITY_MINUTES = 'event_instant_security_minutes';

    // duration=0 且带选项的事件,留给玩家做选择的时长(过了自动作废)
    public const EVENT_CHOICE_WINDOW_MINUTES = 'event_choice_window_minutes';

    // 「高技能 NPC」的等级门槛(§6 未定义,EVT_BRAIN_DRAIN 的条件要用)
    public const EVENT_NPC_HIGH_SKILL_LEVEL = 'event_npc_high_skill_level';

    // 「国防达标」的判定门槛(M3-D5 W4-B 起生效):**威胁档序号** ≤ 本值即视为达标。
    // 序号 = DefenseService::LEVEL_RANKS(low 0 / medium 1 / high 2),默认 0 = 只有「安全」档算达标。
    // 它取代了上面 event_defense_ok_security_min 的治安代理判定;
    // 9.D2 的系数(event_weight_defense_ok = 0.5)与 category 分组(CATEGORY_GROUP_DEFENSE)一个没动
    public const EVENT_DEFENSE_OK_MAX_THREAT_RANK = 'event_defense_ok_max_threat_rank';

    // ---------- M3-M.1 电力规则参数(v3.2 §3.3 energyFactor + §8 RS017 capacity_contract)----------
    //
    // 分工与前面四个系统完全一致:**逐建筑**的发电量 / 耗电量是定义数据
    // (building_level_definition 的 output_json.electricity 与 power_per_min 两列,改它要 bump 数值版本);
    // 这里登记的是**全局**曲线参数 —— 「一改就影响全服电网」的那一档。
    // 同一个数不允许有两个来源:发电与耗电的数值一个都不在这里。

    // 电力总开关:关掉后 power 乘区恒 1.0(= 接入前的历史行为),运营救急用
    public const POWER_GATE_ENABLED = 'power_gate_enabled';

    // 电力率下限:§3.3「energyFactor = clamp(powerReceived / powerDemand, 0, 1)」的下界,
    // 与物流的 0.25 不同,电力**没有**下限保护(§15 回归表要求「获取电力为 0 → 产出为 0」)
    public const POWER_FACTOR_MIN = 'power_factor_min';

    // 满供拐点:覆盖率(可用发电 / 耗电需求)≥ 本值即视为满供,不打折。
    // 默认 1.00 = §3.3 的纯线性口径(无宽限档);想给一档「轻微缺电不降产」把它调到 0.95 即可
    public const POWER_FULL_SUPPLY_RATIO = 'power_full_supply_ratio';

    // 起算时代:低于本时代序号的城市一律不计电力需求(与物流的 LOGISTICS_MIN_ERA_ORDER 同款闸门)。
    // 默认 8 = 全表最早的发电建筑 E03 与最早的耗电建筑 F08 / P07 / P08 都在时代 VIII
    public const POWER_MIN_ERA_ORDER = 'power_min_era_order';

    // ---------- M3-D5 国防联动规则参数(backlog §9 E 区批准口径 + v3.2 §17 国防行)----------
    //
    // 分工照旧,且这一组刻意**不含任何数值表**:
    //   ① 威胁需求的九档数字在 EraService::REQUIREMENTS(= §5.1「国防最低」),**单一来源**,
    //      这里只给一个全局倍率让运营调难度,绝不复制第二份九档数字;
    //   ② 逐事件的开关 / 权重 / 效果强度仍在 event_definition 表(后台按行改 + bump 数值版本);
    //   ③ 这里登记的是「一改就影响全服国防判定」的那一档:分档阈值 + RAID 损失公式的四个系数。

    // 威胁分档的两个覆盖率阈值(E1 的判定口径)。coverage = 有效国防值 / 威胁需求:
    //   coverage ≥ SAFE → low(安全);SAFE > coverage ≥ TENSE → medium(紧张);低于 TENSE → high(危险)
    public const DEFENSE_THREAT_COVERAGE_SAFE = 'defense_threat_coverage_safe';
    public const DEFENSE_THREAT_COVERAGE_TENSE = 'defense_threat_coverage_tense';

    // 威胁需求的全局倍率:威胁需求 = §5.1「国防最低」× 本值 × (1 + Σthreat_demand_pct)
    public const DEFENSE_THREAT_DEMAND_MULTIPLIER = 'defense_threat_demand_multiplier';

    // EVT_RAID 损失公式(9.E2 的 clamp 口径 + §17「事件损失倍率」的威胁档缩放):
    //   缺口率 = clamp(1 − coverage, 0, 1)
    //   损失率 = clamp(缺口率 × 基础倍率 × 威胁档倍率, 0, 上限)
    public const DEFENSE_RAID_LOSS_BASE_MULTIPLIER = 'defense_raid_loss_base_multiplier';
    public const DEFENSE_RAID_LOSS_MAX_PCT = 'defense_raid_loss_max_pct';
    public const DEFENSE_RAID_LOSS_MULT_MEDIUM = 'defense_raid_loss_mult_medium';
    public const DEFENSE_RAID_LOSS_MULT_HIGH = 'defense_raid_loss_mult_high';

    // ==========================================================================================
    // W11-A 大扩展:内核数值规则参数(默认值一律 = 扩展前的现行常量值,零行为变化)
    // ==========================================================================================
    //
    // 这一批与上面五个系统的分工完全一致,只是把「一直写死在 SimConstants / 各服务类常量里」的
    // 内核曲线搬成后台可调。搬迁纪律三条:
    //   ① 默认值逐条 = 被替换的那个常量的现行值 —— 由 GameSettingDefaultsTest 一条不漏地钉着;
    //   ② 常量本身**不删**,继续作为「登记默认值的出处」留在 SimConstants(单一来源仍在那里),
    //      代码里的消费点改读 GameSetting::get();
    //   ③ 仍然不开放的数(§13 硬帽、各 SPEED_FLOOR、MIN_POPULATION 等)见交付汇报的「拒绝开放清单」——
    //      它们是数值体系的天花板或安全夹子,改它们要走迁移 / 数值版本,不是运营旋钮。

    // ---- core:内核基础 ----
    public const MAX_OFFLINE_SECONDS = 'max_offline_seconds';
    public const SEGMENT_MINUTES = 'segment_minutes';

    // ---- population:人口 / 劳动力 / 粮食(v3.2 §10.1 / §10.3 / §10.4)----
    public const FOOD_PER_CAPITA_PER_MIN = 'food_per_capita_per_min';
    public const WORKER_RATIO = 'worker_ratio';
    public const POPULATION_BASE_GROWTH_PER_MIN = 'population_base_growth_per_min';
    public const FOOD_SHORTAGE_MINUTES = 'food_shortage_minutes';
    public const FOOD_SHORTAGE_LOSS_PER_MIN = 'food_shortage_loss_per_min';
    public const FOOD_ZERO_GRACE_MINUTES = 'food_zero_grace_minutes';
    public const FOOD_ZERO_LOSS_PER_MIN = 'food_zero_loss_per_min';
    public const HOUSING_USAGE_FULL = 'housing_usage_full';
    public const HOUSING_FACTOR_AT_CAP = 'housing_factor_at_cap';
    public const HAPPINESS_FACTOR_ZERO_BELOW = 'happiness_factor_zero_below';
    public const HAPPINESS_FACTOR_FULL_AT = 'happiness_factor_full_at';
    public const HAPPINESS_FACTOR_AT_FLOOR = 'happiness_factor_at_floor';
    public const INITIAL_POPULATION = 'initial_population';
    public const BASE_STORAGE = 'base_storage';

    // ---- happiness:幸福度合成式(v3.2 §10.2)----
    public const HAPPINESS_BASE = 'happiness_base';
    public const HAPPINESS_RISE_PER_MIN = 'happiness_rise_per_min';
    public const HAPPINESS_FALL_PER_MIN = 'happiness_fall_per_min';
    public const HAPPINESS_HOUSING_BONUS = 'happiness_housing_bonus';
    public const HAPPINESS_HOUSING_GOOD_USAGE = 'happiness_housing_good_usage';
    public const HAPPINESS_HOUSING_OVER_PENALTY = 'happiness_housing_over_penalty';
    public const HAPPINESS_HOUSING_OVER_SPAN = 'happiness_housing_over_span';
    // 原 SimConstants::HAPPINESS_COVERAGE_BONUS 一个常量喂两行(医疗 / 治安),
    // 搬成设定时拆成两键:运营要单独加强医疗而不动治安时,不必再改代码
    public const HAPPINESS_MEDICAL_BONUS = 'happiness_medical_bonus';
    public const HAPPINESS_SECURITY_BONUS = 'happiness_security_bonus';
    public const FOOD_QUALITY_FLOUR_BREAD_COVERAGE = 'food_quality_flour_bread_coverage';
    public const FOOD_QUALITY_FLOUR_BREAD_BONUS = 'food_quality_flour_bread_bonus';
    public const FOOD_QUALITY_PROCESSED_COVERAGE = 'food_quality_processed_coverage';
    public const FOOD_QUALITY_PROCESSED_BONUS = 'food_quality_processed_bonus';
    public const FOOD_QUALITY_HIGH_COVERAGE = 'food_quality_high_coverage';
    public const FOOD_QUALITY_HIGH_BONUS = 'food_quality_high_bonus';
    public const FOOD_DEFICIT_GRACE_MINUTES = 'food_deficit_grace_minutes';
    public const HAPPINESS_DEFICIT_PENALTY_PER_MIN = 'happiness_deficit_penalty_per_min';

    // ---- fiscal:税收 / 维护 / 财政预警(v3.2 §10.5)----
    public const TAX_PER_CAPITA_ERA_1 = 'tax_per_capita_era_1';
    public const TAX_ERA_MULTIPLIER = 'tax_era_multiplier';
    public const MAINTENANCE_ARREARS_FACTOR = 'maintenance_arrears_factor';
    public const MAINTENANCE_ENABLED = 'maintenance_enabled';
    public const FISCAL_WARNING_YELLOW_MINUTES = 'fiscal_warning_yellow_minutes';
    public const FISCAL_WARNING_RED_MINUTES = 'fiscal_warning_red_minutes';

    // ---- governance:治理负载四档(v3.2 §10.5 / §10.6)----
    public const GOVERNANCE_LOAD_GOOD = 'governance_load_good';
    public const GOVERNANCE_LOAD_TIGHT = 'governance_load_tight';
    public const GOVERNANCE_LOAD_OVER = 'governance_load_over';
    public const GOVERNANCE_EFFICIENCY_GOOD = 'governance_efficiency_good';
    public const GOVERNANCE_EFFICIENCY_TIGHT = 'governance_efficiency_tight';
    public const GOVERNANCE_EFFICIENCY_OVER = 'governance_efficiency_over';
    public const GOVERNANCE_EFFICIENCY_COLLAPSE = 'governance_efficiency_collapse';

    // ---- logistics:运输负载与物流率(v3.2 §10.7)----
    public const LOGISTICS_GATE_ENABLED = 'logistics_gate_enabled';
    public const LOGISTICS_MIN_ERA_ORDER = 'logistics_min_era_order';
    public const TRANSPORT_LOAD_TIGHT = 'transport_load_tight';
    public const TRANSPORT_LOAD_OVER = 'transport_load_over';
    public const LOGISTICS_FACTOR_AT_OVER = 'logistics_factor_at_over';

    // ---- tech:科技加成与研究(v3.2 §5)----
    public const TECH_BRANCH_EFFICIENCY_BONUS = 'tech_branch_efficiency_bonus';
    public const RESEARCH_PARALLEL_LIMIT = 'research_parallel_limit';
    public const TECH_RESEARCH_MINUTES_MULTIPLIER = 'tech_research_minutes_multiplier';
    public const TECH_KNOWLEDGE_COST_MULTIPLIER = 'tech_knowledge_cost_multiplier';

    // ---- npc:§6.4 的两个合成参数(逐 NPC 数值仍在 npc_definition)----
    public const NPC_TOTAL_CAP = 'npc_total_cap';
    public const NPC_JOB_MISMATCH_RATE = 'npc_job_mismatch_rate';

    // ---- building:建造 / 升级 / 返还(v3.2 §3.2 / §10.9 / §16.3)----
    public const CONSTRUCTION_DURATION_MULTIPLIER = 'construction_duration_multiplier';
    public const BUILD_COST_MULTIPLIER = 'build_cost_multiplier';
    public const UPGRADE_COST_MULTIPLIER = 'upgrade_cost_multiplier';
    public const DEMOLISH_REFUND_RATE = 'demolish_refund_rate';
    public const CANCEL_REFUND_RATE = 'cancel_refund_rate';
    public const UPGRADING_HOUSING_CAPACITY_RATE = 'upgrading_housing_capacity_rate';

    // ---- market:全局波动倍率 ----
    public const MARKET_VOLATILITY_MULTIPLIER = 'market_volatility_multiplier';

    // ---- event:全局效果强度 ----
    public const EVENT_EFFECT_MULTIPLIER_GLOBAL = 'event_effect_multiplier_global';

    // ---- defense:总开关 ----
    public const DEFENSE_GATE_ENABLED = 'defense_gate_enabled';

    // ---------- 设定类型 ----------

    // 布尔开关(true / false 二选一)
    public const TYPE_BOOL = 'bool';

    // 资源映射对象:{资源 code: 非负数量},逐键校验 code 合法 + 数量在 [0, MAX_RESOURCE_AMOUNT]
    public const TYPE_RESOURCE_MAP = 'resource_map';

    // 数值型规则参数(M3 起「系统规则数据后台可调」的载体,用户 2026-08-11 拍板):
    // 登记时必须带 'min'/'max' 两键,写入校验闭区间;只收真正的 int/float,字符串数字一律拒绝。
    //
    // 三个可选修饰(W11-A 起):
    //   'integer' => true   该键语义是「条数 / 分钟数 / 次数 / 序号」,写入只收整数(3.5 段没有意义);
    //   'depends' => ['lte' => '另一个 key'] / ['gte' => '另一个 key']
    //                       跨键约束,set() 在类型校验之后、写库之前拿另一键的**当前生效值**比较,
    //                       违反一律 VALIDATION_ERROR。防的是「上限低于下限」这类自相矛盾的配置;
    //   'deprecated' => true 已停用的死键(代码里没有任何消费点),后台渲染成只读并置底。
    public const TYPE_NUMBER = 'number';

    // ---------- 分组(后台设置页按组渲染;每键必填,新增键必须归到下面某一组)----------
    //
    // 分组只影响后台的展示顺序与折叠,不参与任何游戏判定 —— 换组不会改变任何数值行为。
    public const GROUP_CORE = 'core';               // 内核基础:离线封顶 / 分段 / 建城初始资源
    public const GROUP_POPULATION = 'population';   // 人口 / 劳动力 / 粮食赤字三级后果 / 住房因子
    public const GROUP_HAPPINESS = 'happiness';     // 幸福度合成式与快落慢升
    public const GROUP_FISCAL = 'fiscal';           // 税收 / 维护 / 财政预警
    public const GROUP_GOVERNANCE = 'governance';   // 治理负载四档与效率
    public const GROUP_LOGISTICS = 'logistics';     // 运输负载与物流率
    public const GROUP_TECH = 'tech';               // 科技加成与研究
    public const GROUP_NPC = 'npc';                 // NPC 招募 / 士气 / 加成
    public const GROUP_BUILDING = 'building';       // 建造 / 升级 / 拆除 / 返还
    public const GROUP_MARKET = 'market';           // 市场定价与反刷
    public const GROUP_ITEM = 'item';               // 工具制作与耐久
    public const GROUP_EVENT = 'event';             // 随机事件触发与权重
    public const GROUP_POWER = 'power';             // 电力曲线
    public const GROUP_DEFENSE = 'defense';         // 国防威胁与劫掠损失

    // 全部合法分组(all() 的渲染顺序也按这个数组;后台按此顺序出折叠面板)
    public const GROUPS = [
        self::GROUP_CORE,
        self::GROUP_POPULATION,
        self::GROUP_HAPPINESS,
        self::GROUP_FISCAL,
        self::GROUP_GOVERNANCE,
        self::GROUP_LOGISTICS,
        self::GROUP_TECH,
        self::GROUP_NPC,
        self::GROUP_BUILDING,
        self::GROUP_MARKET,
        self::GROUP_ITEM,
        self::GROUP_EVENT,
        self::GROUP_POWER,
        self::GROUP_DEFENSE,
    ];

    // 跨键约束的两种关系('depends' 的键名)
    public const DEPENDS_LTE = 'lte';   // 本键 <= 目标键的当前生效值
    public const DEPENDS_GTE = 'gte';   // 本键 >= 目标键的当前生效值

    // 对象型设定的单键数量上限(防止后台一次发出天文数字把经济打穿)
    public const MAX_RESOURCE_AMOUNT = 1000000;

    // 对象型设定的最大键数(allowlist 本身已把 code 限死在 31 种库存资源内,这里只是第二道保险)
    private const MAX_RESOURCE_KEYS = 40;

    // 建城初始资源的登记默认值(= 现行硬编码初始资源的区间中点 + knowledge 100)。
    //
    // 为什么是中点:SimConstants::START_RESOURCES / START_MONEY 是随机区间
    // (money 200~400、wood 200~400、stone 100~200、food 300~500),对象型设定只存一个定值,
    // 取中点是对「现行初始资源」最中性的折算,不偏向送多也不偏向送少。
    //
    // 为什么加 knowledge 100:时代 I 的 5 项科技各要 20~30 知识,时代 I 又没有任何建筑产知识 →
    // 新号研究不了科技、也就建不了任何建筑(STATUS「新号开局硬锁」)。100 知识够研 3~4 条,
    // 玩家能自己走通「研究 → 建造 → 派工」的第一圈。**这是测试期数值,正式上线前另调。**
    //
    // 注意:数量应低于 SimConstants::BASE_STORAGE(1000),否则新城建成时资源已超仓储上限,
    // 首次结算会被夹掉一部分。后台调大时要一并考虑仓储。
    public const INITIAL_RESOURCES_DEFAULT = [
        ResourceCode::MONEY     => 300,
        ResourceCode::WOOD      => 300,
        ResourceCode::STONE     => 150,
        ResourceCode::FOOD      => 400,
        ResourceCode::KNOWLEDGE => 100,
    ];

    // key => [默认值, 类型, 中文说明]。
    // default 必须与「该设定未接入前的历史行为」一致(初始资源是唯一例外:用户拍板测试期送知识解锁新号硬锁)。
    // type 决定校验路径:bool 走布尔分支,resource_map 走逐键 allowlist + 数值范围校验(见 castValue)。
    public const DEFINITIONS = [
        self::WORKER_ASSIGN_ALLOW_DECREASE_ALWAYS => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_POPULATION,
            'description' => '工人只减不增的操作永远放行:人口暴跌导致历史分配超上限时,玩家仍能撤人(关闭后撤人也要满足劳动力上限)',
        ],
        self::WORKER_GATE_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_POPULATION,
            'description' => '没派工人就不生产的总开关:关闭后所有建筑的用工乘区恒为 1.0(运营救急用,会让全服产量立刻恢复满额)',
        ],
        self::INITIAL_RESOURCES => [
            'default'     => self::INITIAL_RESOURCES_DEFAULT,
            'type'        => self::TYPE_RESOURCE_MAP,
            'group'       => self::GROUP_CORE,
            'description' => '建城初始资源(含 money / knowledge):只影响此后新建的城市,不回填老城。数量上限 100 万,建议低于仓储上限 1000',
        ],

        // ---- M3-D3 市场全局参数(默认值 = 9.C 区已批准口径,逐条对照见交付汇报)----
        self::MARKET_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_MARKET,
            'description' => '市场总开关:关闭后所有买卖立即返回 MARKET_CLOSED(经济出事时一键停市),价目查询不受影响',
        ],
        self::MARKET_WINDOW_SECONDS => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 10,
            'max'         => 3600,
            'integer'     => true,
            'group'       => self::GROUP_MARKET,
            'description' => '价格窗口(EPOCH)秒数:同一窗口内价格恒定,跨窗口才重新掷价。改动会让窗口编号整体平移(不影响历史流水)',
        ],
        self::MARKET_MA_WINDOWS => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 60,
            'integer'     => true,
            'group'       => self::GROUP_MARKET,
            'description' => '供需移动平均的窗口数 N:取最近 N 个**已结束**窗口的全服买卖量。调大 = 价格更钝、更难被单人操纵',
        ],
        self::MARKET_SLIPPAGE_COEFFICIENT => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            // 下限刻意不是 0:§13 的四道反套利机制(手续费 / 移动平均 / 滑点 / 成交量上限)不许被后台关停,
            // 光在 description 里写「明确禁止」拦不住手滑。0.01 已经小到几乎不影响手感,但往返永远亏
            'min'         => 0.01,
            'max'         => 5,
            'group'       => self::GROUP_MARKET,
            'description' => '滑点系数 k:滑点率 = k × 本笔数量 / 有效流动性(买价上抬、卖价下压)。'
                . '§13 不许关停滑点,所以下限锁在 0.01(想让滑点近似消失就填 0.01,但永远关不掉)',
        ],
        // 默认值写整数 1 而不是 1.0:建表迁移(2026_08_10_500001)灌行时用的是不带
        // JSON_PRESERVE_ZERO_FRACTION 的 json_encode,1.0 会被写成 "1",读回来就成了 int ——
        // 「登记值是 float、落库值是 int」的类型漂移。写成 int 从源头上不给它漂的机会
        //(TYPE_NUMBER 读写都同时接受 int 与 float,运营改成 1.5 照样存得下)
        self::MARKET_FEE_RATE_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            // 下限同 market_slippage_coefficient:§13 的四机制不许被后台关停(免手续费 = 往返零成本)
            'min'         => 0.01,
            'max'         => 10,
            'group'       => self::GROUP_MARKET,
            'description' => '手续费率全局倍率:实际费率 = 该资源定义的 fee_rate(§8 默认 0.03)× 本值。'
                . '§13 不许免手续费,所以下限锁在 0.01(费率可以低到几乎免费,但永远不为 0)',
        ],
        self::MARKET_QUOTA_WINDOW_PCT => [
            'default'     => 0.1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.0001,
            'max'         => 1,
            'group'       => self::GROUP_MARKET,
            'description' => '单城单窗成交量上限占有效流动性的比例(§8.1 建议 10%),买卖合并计入同一个额度',
        ],
        self::MARKET_QUOTA_HOURLY_MULTIPLE => [
            'default'     => 20,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 1000,
            'group'       => self::GROUP_MARKET,
            'description' => '单城每小时成交量上限 = 本值 × 单窗上限。60 秒窗时一小时有 60 窗,取 20 是刻意留出的反刷空间',
        ],
        self::MARKET_PRICE_MIN_MULTIPLE => [
            'default'     => 0.45,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 1,
            'depends'     => [self::DEPENDS_LTE => self::MARKET_PRICE_MAX_MULTIPLE],
            'group'       => self::GROUP_MARKET,
            'description' => '价格全局下限倍率:最终下限 = max(定义表 min_price, 基础价 × 本值)。默认 0.45 = §8 全表最宽档,等于「默认听定义表的」',
        ],
        self::MARKET_PRICE_MAX_MULTIPLE => [
            'default'     => 3.2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 100,
            'group'       => self::GROUP_MARKET,
            'description' => '价格全局上限倍率:最终上限 = min(定义表 max_price, 基础价 × 本值)。默认 3.2 = §8 全表最宽档,等于「默认听定义表的」',
        ],
        // 同上:整数 1,避免 1.0 在「落库再读出」的往返里漂成 int
        self::MARKET_LIQUIDITY_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 100,
            'group'       => self::GROUP_MARKET,
            'description' => '流动性全局倍率:有效流动性 = 该资源 base_liquidity × 本值。调小 = 滑点更狠且成交量上限更低(反刷总闸门)',
        ],
        self::MARKET_NOISE_FLOOR_PCT => [
            'default'     => 0.05,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_MARKET,
            'description' => '供需底噪比例:买量与卖量各加 有效流动性 × 本值,保证空服不会因 0/0 跳价,也稀释单人操纵价格的力度',
        ],
        self::MARKET_MAX_ORDER_QUANTITY => [
            'default'     => 1000000,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 100000000,
            'group'       => self::GROUP_MARKET,
            'description' => '单笔交易数量的绝对上限:与成交量上限是两道独立的闸,专门挡「超大数字」类攻击输入',
        ],
        // 贸易容量 → 城市侧成交量上限(backlog §5.4,W5)。
        // 默认 200/分钟的取法:C01 村落市场 L1 的 trade_capacity 正好是 100/分钟 ——
        // 基础额度取它的两倍,意味着「一栋市场 = 额度 +50%,四栋 C01 = 翻倍」,建市场立刻看得见;
        // 同时 200/分钟远高于开局产能(时代 I 的粮食产出个位数/分钟),新号不会被这条卡住。
        self::MARKET_TRADE_CAPACITY_BASE_PER_MIN => [
            'default'     => 200,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'group'       => self::GROUP_MARKET,
            'description' => '没有贸易容量时的基础成交额度(数量/分钟):单城单窗上限 = min(流动性口径,(本值 + 全城贸易容量) × 系数 × 窗口分钟数)。'
                . '调到 0 = 没建市场建筑的城市**完全不能交易**(慎用:新号会被整条堵死)',
        ],
        self::MARKET_TRADE_CAPACITY_FACTOR => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'group'       => self::GROUP_MARKET,
            'description' => '贸易吞吐口径的系数:调大 = 贸易建筑更值钱(城市侧那一层更难成为瓶颈),调小 = 大宗交易更依赖建市场。'
                . '调到 0 会让全服额度归零(= 全市场停市),要停市请用 market_enabled',
        ],

        // ---- M3-D1 NPC 规则参数(默认值 = backlog §9 A 区已批准的建议默认值,逐条对照见交付汇报)----

        // A5 槽位
        self::NPC_SLOTS_PER_BUILDING => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 20,
            'integer'     => true,
            'group'       => self::GROUP_NPC,
            'description' => '单栋建筑(L1/L2)的 NPC 槽位数:派驻满了返回 NPC_SLOT_FULL。调到 0 等于全服禁止派驻(已派驻的不会被强制撤下)',
        ],
        self::NPC_SLOTS_PER_BUILDING_L3 => [
            'default'     => 3,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 20,
            'integer'     => true,
            'group'       => self::GROUP_NPC,
            'description' => 'L3 建筑的 NPC 槽位数(A5:满级建筑多一个槽)。判定按实例当前 level,升级中的实例按旧等级算',
        ],

        // A7 招募价格
        self::NPC_RECRUIT_PRICE_WAGE_MULTIPLIER => [
            'default'     => 200,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100000,
            'group'       => self::GROUP_NPC,
            'description' => '招募价格的工资系数:招募资金 = 该 NPC 的 wage_per_min × 本值 × 稀有度系数(A7)。等价于「预付多少分钟工资」',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_COMMON => [
            'default'     => 1.0,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'group'       => self::GROUP_NPC,
            'description' => '招募价格的稀有度系数:common(A7 = 1.0)',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_UNCOMMON => [
            'default'     => 1.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'group'       => self::GROUP_NPC,
            'description' => '招募价格的稀有度系数:uncommon(A7 = 1.5)',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_RARE => [
            'default'     => 2.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'group'       => self::GROUP_NPC,
            'description' => '招募价格的稀有度系数:rare(A7 = 2.5)',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_EPIC => [
            'default'     => 4,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'group'       => self::GROUP_NPC,
            'description' => '招募价格的稀有度系数:epic(A7 = 4)',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_LEGENDARY => [
            'default'     => 8,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'group'       => self::GROUP_NPC,
            'description' => '招募价格的稀有度系数:legendary(A7 = 8)',
        ],

        // 稀有度掷点权重(A 区未给数值:§6.2 只说稀有度决定「招募难度」。
        // 默认取 60/25/10/4/1,即普通 60%、传奇 1%;权重之和不必等于 100,服务器按比例归一)
        self::NPC_RECRUIT_WEIGHT_COMMON => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'group'       => self::GROUP_NPC,
            'description' => '招募掷点权重:common。权重按候选池里实际存在的稀有度归一,全部为 0 时按稀有度从低到高回退',
        ],
        self::NPC_RECRUIT_WEIGHT_UNCOMMON => [
            'default'     => 25,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'group'       => self::GROUP_NPC,
            'description' => '招募掷点权重:uncommon',
        ],
        self::NPC_RECRUIT_WEIGHT_RARE => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'group'       => self::GROUP_NPC,
            'description' => '招募掷点权重:rare',
        ],
        self::NPC_RECRUIT_WEIGHT_EPIC => [
            'default'     => 4,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'group'       => self::GROUP_NPC,
            'description' => '招募掷点权重:epic',
        ],
        self::NPC_RECRUIT_WEIGHT_LEGENDARY => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'group'       => self::GROUP_NPC,
            'description' => '招募掷点权重:legendary',
        ],

        // A1 自然增长
        self::NPC_NATURAL_GROWTH_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_NPC,
            'description' => 'NPC 自然增长总开关(运营救急用):关闭后不再自动送人,已有 NPC 不受影响',
        ],
        self::NPC_NATURAL_GROWTH_WINDOW_MINUTES => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 10080,
            'integer'     => true,
            'group'       => self::GROUP_NPC,
            'description' => '自然增长的判定窗口(分钟,A1 = 60):每经过一个整窗掷一次点。离线期间按窗口数逐窗推进,不用一次性概率',
        ],
        self::NPC_NATURAL_GROWTH_CHANCE => [
            'default'     => 0.03,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_NPC,
            'description' => '自然增长的单窗触发概率(A1 = 0.03 即 3%):掷中送 1 名 natural_growth 来源的 NPC',
        ],
        self::NPC_NATURAL_GROWTH_HOUSING_FREE_MIN => [
            'default'     => 0.05,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_NPC,
            'description' => '自然增长的住房门槛(A1 = 0.05):住房空余率低于本值不再增长(空余率 = 1 − 人口 / 人口容量)',
        ],
        self::NPC_NATURAL_GROWTH_HAPPINESS_MIN => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_NPC,
            'description' => '自然增长的幸福门槛(A1 = 60):幸福低于本值不再增长',
        ],
        self::NPC_NATURAL_GROWTH_CAP_PER_POPULATION => [
            'default'     => 500,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 1000000,
            'group'       => self::GROUP_NPC,
            'description' => '自然增长上限的人口分母(A1):上限 = floor(人口 / 本值) + 基数。调小 = 小城也能自然长出更多 NPC',
        ],
        self::NPC_NATURAL_GROWTH_CAP_BASE => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10000,
            'integer'     => true,
            'group'       => self::GROUP_NPC,
            'description' => '自然增长上限的基数(A1 = 2):上限 = floor(人口 / 人口分母) + 本值。只约束自然增长来的 NPC,招募不受限',
        ],
        self::NPC_NATURAL_GROWTH_OFFLINE_MAX => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'integer'     => true,
            'group'       => self::GROUP_NPC,
            'description' => '单次结算最多补算几名自然增长 NPC(A1 = 2):挂机 12 小时上线时的防雪崩上限',
        ],

        // A4 士气与离职
        self::NPC_MORALE_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_NPC,
            'description' => 'NPC 士气总开关(运营救急用):关闭后士气不再涨跌、也不会有人因士气过低离职',
        ],
        self::NPC_MORALE_INITIAL => [
            'default'     => 70,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_NPC,
            'description' => '新 NPC 的初始士气(A4 = 70)。只影响此后新增的 NPC,不回填已在城里的',
        ],
        self::NPC_MORALE_WAGE_ARREARS_PENALTY_PER_MIN => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_NPC,
            'description' => '发不出工资时的士气扣减(每分钟,A4 = 2)。§16.5:发不出工资要扣士气,不能让玩家白嫖劳动力',
        ],
        self::NPC_MORALE_LOW_HAPPINESS_THRESHOLD => [
            'default'     => 50,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_NPC,
            'description' => '城市幸福低于本值时开始扣 NPC 士气(A4 = 50)',
        ],
        self::NPC_MORALE_LOW_HAPPINESS_PENALTY_PER_MIN => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_NPC,
            'description' => '城市幸福低于阈值时的士气扣减(每分钟,A4 = 1)。可与欠薪扣减叠加',
        ],
        self::NPC_MORALE_RECOVER_PER_MIN => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_NPC,
            'description' => '一切正常(工资付得出、幸福达标)时的士气回升速度(每分钟,A4 = 0.5),上限 100',
        ],
        self::NPC_MORALE_LEAVE_THRESHOLD => [
            'default'     => 30,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'depends'     => [self::DEPENDS_LTE => self::NPC_MORALE_INITIAL],
            'group'       => self::GROUP_NPC,
            'description' => '士气低于本值的 NPC 开始有离职风险(A4 = 30)。调到 0 等于永不离职',
        ],
        self::NPC_MORALE_LEAVE_CHANCE => [
            'default'     => 0.1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_NPC,
            'description' => '低士气 NPC 的单窗离职概率(A4 = 0.1 即 10%)。掷中即 status=left,并写 NPC.LEAVE 审计',
        ],
        self::NPC_MORALE_LEAVE_WINDOW_MINUTES => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 10080,
            'integer'     => true,
            'group'       => self::GROUP_NPC,
            'description' => '离职判定的窗口(分钟,A4 = 60):每经过一个整窗对低士气 NPC 掷一次点',
        ],

        // A6 XP
        self::NPC_XP_PER_MIN => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100000,
            'group'       => self::GROUP_NPC,
            'description' => '已派驻 NPC 的工作 XP 速率(每分钟,A6 = 10 XP / 60 秒)。未派驻的 NPC 不涨 XP;升级曲线见 npc_skill_level_curve',
        ],

        // ---- M3-D2 工具规则参数(默认值 = backlog §9 B 区已批准口径,逐条对照见交付汇报)----

        self::ITEM_CRAFT_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_ITEM,
            'description' => '工具制作总开关:关闭后所有 craft 立即返回 ITEM_CRAFT_DISABLED(经济出事时一键停产),已制作的工具不受影响',
        ],
        self::ITEM_DURABILITY_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_ITEM,
            'description' => '工具耐久总开关:关闭后耐久不再随工作分钟递减(运营救急用),已损毁的工具不会因此复活',
        ],
        self::ITEM_SLOTS_PER_BUILDING => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 20,
            'integer'     => true,
            'group'       => self::GROUP_ITEM,
            'description' => '单栋建筑的工具装备槽位数(B2 = 2):装满了返回 ITEM_SLOT_FULL。同 category 只有效果最高的那件生效,第二件不报错也不生效(§7)',
        ],
        self::ITEM_DURABILITY_MINUTES_NORMAL => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 100000,
            'integer'     => true,
            'group'       => self::GROUP_ITEM,
            'description' => '普通档工具「多少分钟工作扣 1 点耐久」(§7 = 10)。只算建筑真正在工作的分钟:停产 / 缺料 / 欠费半停工都不扣',
        ],
        self::ITEM_DURABILITY_MINUTES_INDUSTRIAL => [
            'default'     => 20,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 100000,
            'integer'     => true,
            'group'       => self::GROUP_ITEM,
            'description' => '工业/电子档工具「多少分钟工作扣 1 点耐久」(§7 = 20)。档位划分见 B1,写在 item_definition.durability_tier 上',
        ],
        self::ITEM_DURABILITY_WARNING_PCT => [
            'default'     => 0.2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_ITEM,
            'description' => '耐久预警阈值(B4 = 0.2 即剩余 20%):快照里给低于本值的已装备工具打 durability_warning 标记,供前端提示玩家提前补件',
        ],

        // ---- M3-D4 随机事件全局参数(默认值 = 9.D 区已批准口径,逐条对照见交付汇报)----
        self::EVENT_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_EVENT,
            'description' => '随机事件总开关:关闭后不再触发任何新事件(已生效的实例照常到期消退,不强制清场)。事件出问题时的一键止血',
        ],
        self::EVENT_WINDOW_SECONDS => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 10,
            'max'         => 3600,
            'integer'     => true,
            'group'       => self::GROUP_EVENT,
            'description' => '资格窗口秒数(§9.1 = 60):每经过一个整窗掷一次触发点。与市场共用 EPOCH 原点(Unix 0),窗长各自定义(9.D5)',
        ],
        self::EVENT_TRIGGER_CHANCE => [
            'default'     => 0.08,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_EVENT,
            'description' => '单个资格窗口的基础触发概率(§9.1 = 0.08 即 8%)。调到 0 等于停掉触发,但不影响已生效实例',
        ],
        self::EVENT_MAX_ACTIVE => [
            'default'     => 3,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 20,
            'integer'     => true,
            'group'       => self::GROUP_EVENT,
            'description' => '单城同时生效的事件上限(§9.1 = 3):已满时该窗掷中也不触发,不排队、不补发',
        ],
        self::EVENT_MAX_ACTIVE_DISASTER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 20,
            'integer'     => true,
            'depends'     => [self::DEPENDS_LTE => self::EVENT_MAX_ACTIVE],
            'group'       => self::GROUP_EVENT,
            'description' => '灾害 / 国防类事件的同时生效上限(§9.1 = 1),在总上限之内再收一道',
        ],
        self::EVENT_OFFLINE_MAX_TRIGGERS => [
            'default'     => 3,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'integer'     => true,
            'group'       => self::GROUP_EVENT,
            'description' => '单次结算最多补算几次触发(9.D3 = 3):挂机 12 小时上线时的防雪崩上限,超出的窗口仍逐窗推进冷却但不再生成事件',
        ],
        self::EVENT_DIFFICULTY_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'group'       => self::GROUP_EVENT,
            'description' => '权重公式的难度修正(§9.1 第三个系数,9.D2 批准 M3 恒 1.0):乘在每个候选事件的权重上,不改变触发概率本身',
        ],
        self::EVENT_WEIGHT_FOOD_DEFICIT => [
            'default'     => 1.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '城市状态修正:粮食赤字时,food / agriculture 类事件的权重 ×本值(9.D2 = 1.5)',
        ],
        self::EVENT_WEIGHT_FISCAL_DEFICIT => [
            'default'     => 1.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '城市状态修正:财政赤字(fiscal_warning 非 none)时,governance / economy 类事件的权重 ×本值(9.D2 = 1.5)',
        ],
        self::EVENT_WEIGHT_GOVERNANCE_OVERLOAD => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '城市状态修正:治理超载时,governance 类事件的权重 ×本值(9.D2 = 2.0)',
        ],
        self::EVENT_WEIGHT_LOW_SECURITY => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '城市状态修正:治安低于阈值时,security 类事件的权重 ×本值(9.D2 = 2.0)',
        ],
        self::EVENT_WEIGHT_HIGH_HAPPINESS => [
            'default'     => 0.7,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '城市状态修正:幸福达标时,**全部负面事件**的权重 ×本值(9.D2 = 0.7)。这是「把城市经营好就少挨打」的唯一通道',
        ],
        self::EVENT_WEIGHT_HIGH_HEALTH => [
            'default'     => 0.6,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '城市状态修正:健康达标时,civil 类事件的权重 ×本值(9.D2 = 0.6)',
        ],
        self::EVENT_WEIGHT_DEFENSE_OK => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '城市状态修正:国防达标时,defense 类事件的权重 ×本值(9.D2 = 0.5)。D5 威胁等级落地前用治安值作代理指标(见下一项)',
        ],
        self::EVENT_LOW_SECURITY_THRESHOLD => [
            'default'     => 65,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '「治安偏低」的判定阈值(9.D2 原文 = 65):低于本值时 security 类事件权重被放大',
        ],
        self::EVENT_HIGH_HAPPINESS_THRESHOLD => [
            'default'     => 75,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '「幸福达标」的判定阈值(9.D2 原文 = 75):达到本值时全部负面事件权重被压低',
        ],
        self::EVENT_HIGH_HEALTH_THRESHOLD => [
            'default'     => 80,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '「健康达标」的判定阈值(9.D2 原文 = 80):达到本值时 civil 类事件权重被压低',
        ],
        self::EVENT_DEFENSE_OK_SECURITY_MIN => [
            'default'     => 65,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'deprecated'  => true,
            'group'       => self::GROUP_EVENT,
            'description' => '【已停用,保留登记】D5 落地前的「国防达标」治安代理阈值。W4-B 起判定改读威胁档(见 event_defense_ok_max_threat_rank),本项不再被任何代码读取;保留登记只为不让后台出现无主残留行,是否删行请运营决定',
        ],
        self::EVENT_GOVERNANCE_OVERLOAD_LOAD => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_EVENT,
            'description' => '「治理超载」的判定阈值:治理负载(人口/治理容量)超过本值即视为超载(§10.6 的 1.00 档)',
        ],
        self::EVENT_INSTANT_SECURITY_MINUTES => [
            'default'     => 15,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 1440,
            'integer'     => true,
            'group'       => self::GROUP_EVENT,
            'description' => '瞬时治安冲击的持续时长:治安是 §10.8 的派生值,没有「当前值」可改,只能走 security_flat 通道,而 flat 必须有起止 → duration=0 的事件按本值给一个时长',
        ],
        self::EVENT_CHOICE_WINDOW_MINUTES => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 10080,
            'integer'     => true,
            'group'       => self::GROUP_EVENT,
            'description' => 'duration=0 且带选项的事件,留给玩家做选择的时长(分钟):过期自动作废,选项不再可领(§70)',
        ],
        self::EVENT_NPC_HIGH_SKILL_LEVEL => [
            'default'     => 6,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 10,
            'integer'     => true,
            'group'       => self::GROUP_EVENT,
            'description' => '「高技能 NPC」的等级门槛(§6 未定义,EVT_BRAIN_DRAIN 的触发条件要用):技能等级 ≥ 本值的在编 NPC 计入',
        ],

        // ---- M3-M.1 电力曲线参数(默认值 = v3.2 §3.3 原文口径,逐条对照见交付汇报)----
        self::POWER_GATE_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_POWER,
            'description' => '电力总开关:关闭后 power 乘区恒为 1.0(缺电不再打折产量),运营救急用。发电 / 耗电读数照常显示',
        ],
        self::POWER_FACTOR_MIN => [
            'default'     => 0,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'depends'     => [self::DEPENDS_LTE => self::POWER_FULL_SUPPLY_RATIO],
            'group'       => self::GROUP_POWER,
            'description' => '电力率下限:§3.3 的 clamp 下界 = 0(与物流的 0.25 不同,电力没有下限保护 —— §15 要求「获取电力为 0 → 产出为 0」)。调高等于给缺电城市兜底',
        ],
        self::POWER_FULL_SUPPLY_RATIO => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.1,
            'max'         => 1,
            'group'       => self::GROUP_POWER,
            'description' => '满供拐点:电力覆盖率(可用发电 / 耗电需求)≥ 本值即视为满供不打折。默认 1.00 = §3.3 的纯线性口径;调到 0.95 等于给「轻微缺电」加一档宽限',
        ],
        self::POWER_MIN_ERA_ORDER => [
            'default'     => 8,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 10,
            'integer'     => true,
            'group'       => self::GROUP_POWER,
            'description' => '电力起算时代序号:低于本时代的城市不计电力需求(与物流的时代闸门同款)。默认 8 = 全表最早的发电建筑 E03 与最早的耗电建筑 F08/P07/P08 都在时代 VIII',
        ],

        // ---- M3-D5 国防联动参数(默认值 = backlog §9 E 区已批准口径,逐条对照见交付汇报)----
        self::DEFENSE_THREAT_COVERAGE_SAFE => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_DEFENSE,
            'description' => '威胁分档:国防覆盖率(有效国防值 / 威胁需求)≥ 本值 = 安全档 low。默认 1.00 = 「达到 §5.1 的国防最低即安全」(E1)',
        ],
        self::DEFENSE_THREAT_COVERAGE_TENSE => [
            'default'     => 0.6,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'depends'     => [self::DEPENDS_LTE => self::DEFENSE_THREAT_COVERAGE_SAFE],
            'group'       => self::GROUP_DEFENSE,
            'description' => '威胁分档:覆盖率 ≥ 本值(且低于安全档阈值)= 紧张档 medium,低于本值 = 危险档 high。EVT_RAID 的触发条件是「≥ 紧张」,调高本值等于让危险档更容易出现',
        ],
        self::DEFENSE_THREAT_DEMAND_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_DEFENSE,
            'description' => '威胁需求全局倍率:威胁需求 = §5.1「国防最低」× 本值 ×(1 + 事件抬升)。九档数字只在 EraService::REQUIREMENTS 一处,这里是运营调难度的唯一旋钮',
        ],
        self::DEFENSE_RAID_LOSS_BASE_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'group'       => self::GROUP_DEFENSE,
            'description' => 'EVT_RAID 损失基础倍率:损失率 = clamp(缺口率 × 本值 × 威胁档倍率, 0, 上限),缺口率 = clamp(1 − 覆盖率, 0, 1)。默认 1.0 = 9.E2 原式',
        ],
        self::DEFENSE_RAID_LOSS_MAX_PCT => [
            'default'     => 0.3,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_DEFENSE,
            'description' => 'EVT_RAID 单次库存损失率上限(9.E2 = 0.30 即 30%):无论威胁档多差,一次劫掠最多损失非资金库存的这个比例',
        ],
        self::DEFENSE_RAID_LOSS_MULT_MEDIUM => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'group'       => self::GROUP_DEFENSE,
            'description' => 'EVT_RAID 威胁档倍率:紧张档 medium(默认 1.0,即 9.E2 的原式)。安全档恒 0(不该被劫掠)',
        ],
        self::DEFENSE_RAID_LOSS_MULT_HIGH => [
            'default'     => 1.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'group'       => self::GROUP_DEFENSE,
            'description' => 'EVT_RAID 威胁档倍率:危险档 high(默认 1.5,§17「事件损失倍率随国防缺口放大」的落地)。放大后仍受上限夹取',
        ],
        self::EVENT_DEFENSE_OK_MAX_THREAT_RANK => [
            'default'     => 0,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 2,
            'integer'     => true,
            'group'       => self::GROUP_EVENT,
            'description' => '「国防达标」的威胁档门槛:威胁档序号(low 0 / medium 1 / high 2)≤ 本值即达标,defense 类事件权重 ×event_weight_defense_ok。默认 0 = 只有安全档算达标',
        ],

        // ==================================================================================
        // W11-A 内核数值规则参数(默认值 = 扩展前的现行常量值,逐条对照见 GameSettingDefaultsTest)
        // ==================================================================================

        // ---- core:内核基础 ----
        self::MAX_OFFLINE_SECONDS => [
            'default'     => 43200,
            'type'        => self::TYPE_NUMBER,
            'min'         => 600,
            'max'         => 604800,
            'integer'     => true,
            'group'       => self::GROUP_CORE,
            'description' => '单次结算最多补算多少秒离线时间(默认 43200 = 12 小时):超出的部分直接作废,时间戳照常推进不积压。'
                . '调大 = 长期挂机一次上线收得更多(也更容易一次性把仓库塞满);同时被 NPC / 工具 / 事件三条离线补算共用,四处口径一致',
        ],
        self::SEGMENT_MINUTES => [
            'default'     => 30,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 240,
            'integer'     => true,
            'group'       => self::GROUP_CORE,
            'description' => '分段结算的段长(分钟,默认 30):段内人口恒定、段末才更新人口与幸福。'
                . '调小 = 离线收益更接近逐分钟真实值(人口增长复利更细),但单次结算的循环段数变多;段数另有 240 段的硬上限保护',
        ],

        // ---- population:人口 / 劳动力 / 粮食(v3.2 §10.1 / §10.3 / §10.4)----
        self::FOOD_PER_CAPITA_PER_MIN => [
            'default'     => 0.03,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.001,
            'max'         => 1,
            'group'       => self::GROUP_POPULATION,
            'description' => '人均粮食消耗(每分钟,§10.1 = 0.03):同时是三处的口径 —— 人口吃粮、'
                . '「严重短缺线 = 人均粮耗 × food_shortage_minutes」、以及食物品质覆盖率的分母(可供给人口 = 产量 / 本值)。'
                . '调大 = 养同样的人口要更多粮田,食物品质加成也更难拿满',
        ],
        self::WORKER_RATIO => [
            'default'     => 0.6,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.05,
            'max'         => 1,
            'group'       => self::GROUP_POPULATION,
            'description' => '劳动力比例(§10.4):可分配工人 = floor(人口 × 本值)。调小 = 同样人口能派的工人变少,'
                . '已超编的历史分配不会被强制撤下(撤人永远放行,见 worker_assign_allow_decrease_always),但新增派工会被上限挡住',
        ],
        self::POPULATION_BASE_GROWTH_PER_MIN => [
            'default'     => 0.002,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 0.1,
            'group'       => self::GROUP_POPULATION,
            'description' => '人口基础增长率(每分钟,§10.3 = 0.002 即 0.2%/分):实际增长率 = 本值 × 住房因子 × 粮食因子 × 幸福因子。'
                . '按段复利,调大会让人口更快顶到住房容量(顶到之后住房因子归零,自动停增)',
        ],
        self::FOOD_SHORTAGE_MINUTES => [
            'default'     => 3,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 60,
            'group'       => self::GROUP_POPULATION,
            'description' => '严重短缺的判定线(§10.1 = 3):粮食库存 < 本值 × 当前人口每分钟粮耗 即判定为严重短缺,按 food_shortage_loss_per_min 迁出人口。'
                . '调大 = 更早进入迁出状态(玩家的缓冲期变短)',
        ],
        self::FOOD_SHORTAGE_LOSS_PER_MIN => [
            'default'     => -0.005,
            'type'        => self::TYPE_NUMBER,
            'min'         => -1,
            'max'         => 0,
            'group'       => self::GROUP_POPULATION,
            'description' => '严重短缺时的人口迁出率(每分钟,§10.1 = −0.005 即 −0.5%/分,按段复利)。'
                . '必须是负数或 0;人口下限 5 由内核夹住,再狠也不会把城市清空',
        ],
        self::FOOD_ZERO_GRACE_MINUTES => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1440,
            'group'       => self::GROUP_POPULATION,
            'description' => '粮食归零后的饥荒宽限时长(分钟,§10.1 = 10):持续归零满本值之后的时间才按 food_zero_loss_per_min 扣人口。'
                . '调到 0 = 一断粮立刻饿死人(不留缓冲);中途补上粮食即清零重计',
        ],
        self::FOOD_ZERO_LOSS_PER_MIN => [
            'default'     => -0.01,
            'type'        => self::TYPE_NUMBER,
            'min'         => -1,
            'max'         => 0,
            'group'       => self::GROUP_POPULATION,
            'description' => '饥荒(粮食归零且过了宽限期)时的人口损失率(每分钟,§10.1 = −0.01 即 −1%/分,按段复利)。'
                . '与迁出率互斥:归零走这条,没归零但低于短缺线走 food_shortage_loss_per_min',
        ],
        self::HOUSING_USAGE_FULL => [
            'default'     => 0.8,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_POPULATION,
            'description' => '住房因子的第一个拐点(§10.3 = 0.80):住房使用率(人口 / 人口容量)低于本值时人口增长不打折,'
                . '本值 ~ 1.00 之间从 1.0 线性降到 housing_factor_at_cap,满容后归 0。调低 = 更早开始降速,逼玩家提前建房',
        ],
        self::HOUSING_FACTOR_AT_CAP => [
            'default'     => 0.2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_POPULATION,
            'description' => '住房使用率刚好达到 100% 时的增长因子(§10.3 = 0.2):这是线性段的终点值,不是超容后的值(超容恒 0)',
        ],
        self::HAPPINESS_FACTOR_ZERO_BELOW => [
            'default'     => 50,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_POPULATION,
            'description' => '幸福因子的下拐点(§10.3 = 50):幸福低于本值时人口完全停止增长(因子 0)。'
                . '本值 ~ happiness_factor_full_at 之间由 happiness_factor_at_floor 线性升到 1.0',
        ],
        self::HAPPINESS_FACTOR_FULL_AT => [
            'default'     => 70,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'depends'     => [self::DEPENDS_GTE => self::HAPPINESS_FACTOR_ZERO_BELOW],
            'group'       => self::GROUP_POPULATION,
            'description' => '幸福因子的上拐点(§10.3 = 70):幸福达到本值即满速增长(因子 1.0)。'
                . '必须 ≥ happiness_factor_zero_below,否则线性段会反向;两点之间的斜率由这两个值与 happiness_factor_at_floor 共同派生,不另设常量',
        ],
        self::HAPPINESS_FACTOR_AT_FLOOR => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_POPULATION,
            'description' => '幸福刚好达到下拐点时的增长因子(§10.3 = 0.5):线性段的起点值。'
                . '调高 = 低幸福城市也能慢慢长人口,调到 0 会让下拐点上下变成硬断崖',
        ],
        self::INITIAL_POPULATION => [
            'default'     => 30,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 100000,
            'integer'     => true,
            'group'       => self::GROUP_POPULATION,
            'description' => '建城初始人口(§10.4 = 30):只影响此后新建的城市,不回填老城'
                . '(M2 那次一次性存档回填仍按当时的 30 走,是历史迁移不受本键影响)。'
                . '调大要一并考虑开局粮食够不够吃(人均粮耗 × 人口)与住房容量',
        ],
        self::BASE_STORAGE => [
            'default'     => 1000,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10000000,
            'group'       => self::GROUP_POPULATION,
            'description' => '无仓储建筑时的基础仓储容量(默认 1000):全城仓储上限 = 本值 + 各仓储建筑产出之和,所有库存资源都夹在 [0, 上限]。'
                . '必须高于 initial_resources 的各项数量,否则新城建成时资源已超上限,首次结算就被夹掉一部分',
        ],

        // ---- happiness:幸福度合成式(v3.2 §10.2)----
        self::HAPPINESS_BASE => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '基线幸福 / 新城初始幸福(§10.2 = 60):目标幸福 = 本值 + 住房加成 + 食物品质加成 + 医疗加成 + 治安加成 + 赤字惩罚 + 事件/NPC 加减,最终夹在 [0, 100]。'
                . '调它等于整体平移全服幸福,会连带影响人口增长(幸福因子)与事件权重(高幸福压低负面事件)',
        ],
        self::HAPPINESS_RISE_PER_MIN => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '幸福回升的最大速度(每分钟,§10.2「慢升」= 0.5):当前幸福向目标收敛时的上行速率上限,不会越过目标值',
        ],
        self::HAPPINESS_FALL_PER_MIN => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '幸福下跌的最大速度(每分钟,§10.2「快落」= 1.0):默认是回升速度的两倍,「城市搞砸得快、修复得慢」正是靠这条不对称',
        ],
        self::HAPPINESS_HOUSING_BONUS => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => -100,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '住房宽裕时的幸福加成(§10.2 = +10):住房使用率 ≤ happiness_housing_good_usage 时吃满,'
                . '之后到 100% 之间线性降到 0,超容再向 happiness_housing_over_penalty 收敛',
        ],
        self::HAPPINESS_HOUSING_GOOD_USAGE => [
            'default'     => 0.9,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '「住房宽裕」的使用率上限(§10.2 = 0.90):低于本值吃满住房加成。'
                . '注意它与人口增长的 housing_usage_full(0.80)是两条独立曲线 —— 前者管幸福,后者管增长速度',
        ],
        self::HAPPINESS_HOUSING_OVER_PENALTY => [
            'default'     => -15,
            'type'        => self::TYPE_NUMBER,
            'min'         => -100,
            'max'         => 0,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '住房超容时的幸福惩罚下限(§10.2 = −15):超容比例达到 happiness_housing_over_span 时吃满这个惩罚。必须是负数或 0',
        ],
        self::HAPPINESS_HOUSING_OVER_SPAN => [
            'default'     => 0.2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 5,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '住房超容惩罚吃满所需的超容比例(默认 0.20 = 超容 20% 触底)。'
                . '§10.2 只写了「向 −15 收敛」没给斜率,这是补充假设的落点;调小 = 一超容幸福就塌',
        ],
        self::HAPPINESS_MEDICAL_BONUS => [
            'default'     => 5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '医疗满覆盖时的幸福加成(§10.2 = +5):实际加成 = 本值 × 医疗覆盖率(医疗容量 / 人口,夹在 [0,1])。'
                . '与治安加成是两条独立的键(原先共用一个常量),想单独强化医疗时不必动治安',
        ],
        self::HAPPINESS_SECURITY_BONUS => [
            'default'     => 5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '治安满覆盖时的幸福加成(§10.2 = +5):实际加成 = 本值 × 国防覆盖率(有效国防值 / 人口,夹在 [0,1])。'
                . '分子取的是**有效**国防值(含工具 / NPC / 事件加成),与快照的 defense 区块同源',
        ],
        self::FOOD_QUALITY_FLOUR_BREAD_COVERAGE => [
            'default'     => 0.3,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '面粉/面包档的覆盖率门槛(§10.1 = 0.30):面粉与面包的产能覆盖率**超过**本值即拿 food_quality_flour_bread_bonus。'
                . '覆盖率 = 该类产出速率 / 人均粮耗 / 人口。三档取满足条件的最高一档,不叠加',
        ],
        self::FOOD_QUALITY_FLOUR_BREAD_BONUS => [
            'default'     => 5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '面粉/面包档的幸福加成(§10.2 = +5)。三档互斥,只取最高的那一档',
        ],
        self::FOOD_QUALITY_PROCESSED_COVERAGE => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '加工食品档的覆盖率门槛(§10.1 = 0.50):加工食品产能覆盖率超过本值即拿 food_quality_processed_bonus',
        ],
        self::FOOD_QUALITY_PROCESSED_BONUS => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '加工食品档的幸福加成(§10.2 = +10)',
        ],
        self::FOOD_QUALITY_HIGH_COVERAGE => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '高品质粮食档的覆盖率门槛(§10.1 = 0.50):高品质粮食产能覆盖率超过本值即拿 food_quality_high_bonus(最高档)',
        ],
        self::FOOD_QUALITY_HIGH_BONUS => [
            'default'     => 15,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '高品质粮食档的幸福加成(§10.2 = +15,三档里的最高档)',
        ],
        self::FOOD_DEFICIT_GRACE_MINUTES => [
            'default'     => 5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1440,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '粮食赤字的幸福惩罚宽限(分钟,§10.1 = 5):粮食净速率为负连续满本值之后,每多 1 分钟目标幸福 −happiness_deficit_penalty_per_min。'
                . '净速率转正即清零重计(与「归零饥荒」是两套独立计时)',
        ],
        self::HAPPINESS_DEFICIT_PENALTY_PER_MIN => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'group'       => self::GROUP_HAPPINESS,
            'description' => '粮食赤字超过宽限后,每分钟对**目标幸福**的扣减(§10.1 = 1.0)。'
                . '扣的是目标不是当前值,所以实际下跌速度仍受 happiness_fall_per_min 限制',
        ],

        // ---- fiscal:税收 / 维护 / 财政预警(v3.2 §10.5)----
        self::TAX_PER_CAPITA_ERA_1 => [
            'default'     => 0.02,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'group'       => self::GROUP_FISCAL,
            'description' => '时代 I 的人均税额(资金/人/分钟,§10.5 = 0.02):税收 = 人口 × 本值 × tax_era_multiplier^(时代−1) × 治理效率 × (1 + 事件税收修正)。'
                . '调它等于全时代等比例改税基',
        ],
        self::TAX_ERA_MULTIPLIER => [
            'default'     => 1.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 5,
            'group'       => self::GROUP_FISCAL,
            'description' => '每进一个时代人均税额的倍率(§10.5 = 1.5):人均税额 = tax_per_capita_era_1 × 本值^(时代序号−1)。'
                . '指数关系 —— 调到 2.0 时时代 X 的税基会变成默认口径的约 26 倍,改前务必按最高时代算一遍',
        ],
        self::MAINTENANCE_ARREARS_FACTOR => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_FISCAL,
            'description' => '维护欠费时的半停工系数(§10.5 = 0.50):本段付不起维护费时,所有**要交维护费**的建筑产出与吃料同乘本值'
                . '(零维护的住宅 / 仓库不受影响)。调到 1.0 等于欠费无惩罚(回到白嫖口径),调到 0 = 欠费即全停产',
        ],
        self::MAINTENANCE_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_FISCAL,
            'description' => '建筑维护总开关(运营止血阀):关闭后全服建筑不再扣维护资金与维护粮食,'
                . '也就永远不会欠费半停工、财政预警恒 none。NPC 工资与口粮**不受本开关影响**(那是另一条支出通道)',
        ],
        self::FISCAL_WARNING_YELLOW_MINUTES => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1440,
            'group'       => self::GROUP_FISCAL,
            'description' => '黄色财政预警的阈值(分钟,§10.5 = 10):结算后的资金撑不过本值分钟的全城维护即报黄警。'
                . '维护速率为 0 的城市恒 none(付不起是不可能的)',
        ],
        self::FISCAL_WARNING_RED_MINUTES => [
            'default'     => 3,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1440,
            'depends'     => [self::DEPENDS_LTE => self::FISCAL_WARNING_YELLOW_MINUTES],
            'group'       => self::GROUP_FISCAL,
            'description' => '红色财政预警的阈值(分钟,§10.5 = 3):必须 ≤ 黄警阈值,否则「红比黄宽」会让黄警永远显示不出来',
        ],

        // ---- governance:治理负载四档(v3.2 §10.5 / §10.6)----
        self::GOVERNANCE_LOAD_GOOD => [
            'default'     => 0.8,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'group'       => self::GROUP_GOVERNANCE,
            'description' => '治理负载的第一档上界(§10.6 = 0.80):负载(人口 / 有效治理容量)≤ 本值 → 治理效率取 governance_efficiency_good。'
                . '治理效率目前只作用于税收',
        ],
        self::GOVERNANCE_LOAD_TIGHT => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'depends'     => [self::DEPENDS_GTE => self::GOVERNANCE_LOAD_GOOD],
            'group'       => self::GROUP_GOVERNANCE,
            'description' => '治理负载的第二档上界(§10.6 = 1.00):负载 ≤ 本值 → governance_efficiency_tight。'
                . '必须 ≥ governance_load_good;它同时是事件「治理超载」判定阈值 event_governance_overload_load 的对应档,两处要一起调',
        ],
        self::GOVERNANCE_LOAD_OVER => [
            'default'     => 1.25,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'depends'     => [self::DEPENDS_GTE => self::GOVERNANCE_LOAD_TIGHT],
            'group'       => self::GROUP_GOVERNANCE,
            'description' => '治理负载的第三档上界(§10.6 = 1.25):负载 ≤ 本值 → governance_efficiency_over,超过则 governance_efficiency_collapse。必须 ≥ governance_load_tight',
        ],
        self::GOVERNANCE_EFFICIENCY_GOOD => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 2,
            'group'       => self::GROUP_GOVERNANCE,
            'description' => '治理效率:宽裕档(§10.6 = 1.00,即不打折)。四档效率直接乘在税收上',
        ],
        self::GOVERNANCE_EFFICIENCY_TIGHT => [
            'default'     => 0.9,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 2,
            'group'       => self::GROUP_GOVERNANCE,
            'description' => '治理效率:偏紧档(§10.6 = 0.90)',
        ],
        self::GOVERNANCE_EFFICIENCY_OVER => [
            'default'     => 0.7,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 2,
            'group'       => self::GROUP_GOVERNANCE,
            'description' => '治理效率:超载档(§10.6 = 0.70)',
        ],
        self::GOVERNANCE_EFFICIENCY_COLLAPSE => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 2,
            'group'       => self::GROUP_GOVERNANCE,
            'description' => '治理效率:崩溃档(§10.6 = 0.50,负载超过 governance_load_over 时)',
        ],

        // ---- logistics:运输负载与物流率(v3.2 §10.7)----
        self::LOGISTICS_GATE_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_LOGISTICS,
            'description' => '物流总开关(运营救急用):关闭后 logistics 乘区恒为 1.0(拥堵不再降产),'
                . '运输需求 / 负载 / 拥堵警报的读数照常显示,方便一边止血一边排查',
        ],
        self::LOGISTICS_MIN_ERA_ORDER => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 10,
            'integer'     => true,
            'group'       => self::GROUP_LOGISTICS,
            'description' => '物流起算时代序号(默认 2):低于本时代的城市一律不计运输需求。'
                . '默认 2 是因为全表最早的运输建筑 T02 在时代 II —— 调到 1 会让所有时代 I 城市开局即重度拥堵且无法自救',
        ],
        self::TRANSPORT_LOAD_TIGHT => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'group'       => self::GROUP_LOGISTICS,
            'description' => '运输负载的降产起点(§10.7 = 1.00):负载(运输需求 / 有效运输容量)≤ 本值 → 物流率 1.00 不打折;'
                . '本值 ~ transport_load_over 之间线性降到 logistics_factor_at_over',
        ],
        self::TRANSPORT_LOAD_OVER => [
            'default'     => 1.25,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'depends'     => [self::DEPENDS_GTE => self::TRANSPORT_LOAD_TIGHT],
            'group'       => self::GROUP_LOGISTICS,
            'description' => '运输负载的拥堵拐点(§10.7 = 1.25):超过本值即报拥堵警报,并接 §3.3 的比例式继续下降(下限 0.25)。必须 ≥ transport_load_tight',
        ],
        self::LOGISTICS_FACTOR_AT_OVER => [
            'default'     => 0.7,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_LOGISTICS,
            'description' => '负载恰好在拥堵拐点时的物流率(§10.7 = 0.70):它既是线性段的终点,也是拥堵段的上限夹取值,两段靠它连续',
        ],

        // ---- tech:科技加成与研究(v3.2 §5)----
        self::TECH_BRANCH_EFFICIENCY_BONUS => [
            'default'     => 0.02,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 0.5,
            'group'       => self::GROUP_TECH,
            'description' => '每解锁一条科技给该分支建筑的效率加成(§5 = 0.02 即 +2%,同分支线性累加)。'
                . '满解锁一条分支 10 条 = 1.20×。调大要留意 §13 的 2.75× 生产倍率硬帽 —— 科技吃得多,留给 NPC / 工具 / 事件的余量就少',
        ],
        self::RESEARCH_PARALLEL_LIMIT => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 10,
            'integer'     => true,
            'group'       => self::GROUP_TECH,
            'description' => '同时可在研的科技项数(默认 1 = v3.2 的单线研究):达到上限后再下单返回 RESEARCH_IN_PROGRESS。'
                . '调大 = 玩家可并行推多条分支,时代推进会明显加快',
        ],
        self::TECH_RESEARCH_MINUTES_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 100,
            'group'       => self::GROUP_TECH,
            'description' => '研究时长全局倍率(默认 1 = 定义表原值):实际工期 = 定义 research_minutes × 本值 ÷ (1 + 研究加速)。'
                . '只影响**此后**开始的研究,已在研的项目按下单时算死的完工时刻走(不追溯)',
        ],
        self::TECH_KNOWLEDGE_COST_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 100,
            'group'       => self::GROUP_TECH,
            'description' => '研究知识花费全局倍率(默认 1 = 定义表原值):实际扣费 = 定义 knowledge_cost × 本值,向上取整。'
                . '调低 = 全服科技变便宜(新号解锁硬锁的救急旋钮)',
        ],

        // ---- npc:§6.4 的两个合成参数 ----
        self::NPC_TOTAL_CAP => [
            'default'     => 1.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 5,
            'group'       => self::GROUP_NPC,
            'description' => 'NPC 侧总帽(§6.4 原文 1.90,用户 2026-08-11 收紧到 1.50):一栋建筑上全部 NPC 连乘之后夹到本值。'
                . '它与 §13 的 2.75× 总帽是两回事(后者在乘区连乘处夹一次)。调高会挤占事件 / 工具的余量,让正向事件对强城市失效',
        ],
        self::NPC_JOB_MISMATCH_RATE => [
            'default'     => 0.25,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_NPC,
            'description' => '岗位不匹配时主技能加成的折扣(§6.4 = 0.25):派错岗位的 NPC 只发挥 25% 的主技能加成。'
                . '调到 0 = 派错岗位完全无加成(白养),调到 1 = 岗位匹配失去意义',
        ],

        // ---- building:建造 / 升级 / 返还(v3.2 §3.2 / §10.9 / §16.3)----
        self::CONSTRUCTION_DURATION_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 100,
            'group'       => self::GROUP_BUILDING,
            'description' => '建造 / 升级工期全局倍率(默认 1 = 定义表原值):实际工期 = 定义 duration_seconds × 本值 ÷ (1 + 施工加速)。'
                . '只影响此后下单的工程,已在建 / 在升级的实例按下单时算死的完工时刻走',
        ],
        self::BUILD_COST_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 100,
            'group'       => self::GROUP_BUILDING,
            'description' => '建造成本全局倍率(默认 1 = 定义表原值):L1 建造的**资金与材料同乘**本值,取整向上(对玩家不利的保守方向)。'
                . '拆除返还按同一倍率折算,防止「便宜建、按原价退」的套利;幂等重放不重算',
        ],
        self::UPGRADE_COST_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 100,
            'group'       => self::GROUP_BUILDING,
            'description' => '升级成本全局倍率(默认 1 = 定义表原值):L2 / L3 升级的资金与材料同乘本值,取整向上。'
                . '取消升级的返还按同一倍率折算,理由同 build_cost_multiplier',
        ],
        self::DEMOLISH_REFUND_RATE => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'depends'     => [self::DEPENDS_LTE => self::CANCEL_REFUND_RATE],
            'group'       => self::GROUP_BUILDING,
            'description' => '拆除返还比例(§10.9 = 0.50):按已完工等级的累计建造材料折算,资金不返还,数量向下取整。'
                . '必须 ≤ cancel_refund_rate —— §10.9 明文「拆除返还低于升级取消返还,防止拆建套利」',
        ],
        self::CANCEL_REFUND_RATE => [
            'default'     => 0.7,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_BUILDING,
            'description' => '取消未完工工程的返还比例(§3.2 / §16.3 = 0.70):取消升级、以及拆除一栋还在施工的建筑走这一档,资金不返还。'
                . '调低到 demolish_refund_rate 以下会被跨键校验拒绝',
        ],
        self::UPGRADING_HOUSING_CAPACITY_RATE => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'group'       => self::GROUP_BUILDING,
            'description' => '住宅升级期间保留的人口容量比例(§3.2 = 0.50,基数是**旧等级**的容量)。'
                . '只作用于住宅(产人口容量的建筑);其余容量类升级期间保留 100%。调到 1.0 等于升级期间零代价',
        ],

        // ---- market:全局波动倍率 ----
        self::MARKET_VOLATILITY_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'group'       => self::GROUP_MARKET,
            'description' => '价格波动全局倍率(默认 1 = 定义表原值):实际扰动幅度 = 该资源 volatility × 本值,扰动仍由服务器密钥确定性派生。'
                . '调到 0 = 价格只随供需漂移、完全不抖(市场变无聊但一分钱也刷不出来);调大会让价格更快撞上夹取区间',
        ],

        // ---- event:全局效果强度 ----
        self::EVENT_EFFECT_MULTIPLIER_GLOBAL => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'group'       => self::GROUP_EVENT,
            'description' => '事件效果强度全局倍率(默认 1):最终强度 = 该事件定义的 effect_multiplier × 本值,只乘**效果数值**不乘时长。'
                . '调到 0 = 事件照常触发但一律零效果(事件算错时的止血阀,比整条停用更温和)',
        ],

        // ---- defense:总开关 ----
        self::DEFENSE_GATE_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'group'       => self::GROUP_DEFENSE,
            'description' => '国防威胁总开关(运营救急用):关闭后威胁档恒为安全(low)、EVT_RAID 一律不造成损失。'
                . '国防值 / 需求 / 覆盖率的读数照常显示,方便一边止血一边排查',
        ],
    ];

    // 请求级缓存键(整表一次读入的 key => 值 映射)
    private const CACHE_KEY = 'game_settings';

    // 读取一个开关。未登记的 key、库里没有的行、值解析失败,一律回退到默认值(Fail Safe:
    // 配置读不出来时维持历史行为,而不是让游戏内核崩掉或静默换一套规则)。
    // $default 显式传入时优先于 DEFINITIONS 里的默认值。
    public static function get(string $key, mixed $default = null): mixed
    {
        $values = self::load();

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return $default ?? (self::DEFINITIONS[$key]['default'] ?? null);
    }

    // 写入一个开关:事务 + 审计(ADMIN.CONFIG_CHANGE)。返回 ['before' => …, 'after' => …]
    // $by = 操作管理员的 users.id;$reason 进审计 reason_code(列宽 80,调用方须先校验长度)
    public static function set(string $key, mixed $value, ?int $by, ?string $reason = null): array
    {
        if (! isset(self::DEFINITIONS[$key])) {
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        $value = self::castValue($key, $value);
        // 跨键约束:类型与区间都过了之后、写库之前再拦一道。
        // 比较用的是另一键的**当前生效值**(get(),含库里已改过的值),不是它的登记默认值 ——
        // 否则「先把上限调高、再调下限」这条正常操作路径会被自己的默认值挡住
        self::assertDepends($key, $value);

        $result = DB::transaction(function () use ($key, $value, $by, $reason) {
            // lockForUpdate:锁住该行直到提交,防止并发改同一开关时 before/after 审计值出现丢失更新
            $row = DB::table('game_settings')->where('setting_key', $key)->lockForUpdate()->first();
            $before = $row ? self::decode($row->value_json, $key) : self::DEFINITIONS[$key]['default'];

            $payload = [
                'value_json'  => json_encode($value, JSON_UNESCAPED_UNICODE),
                'description' => self::DEFINITIONS[$key]['description'],
                'updated_by'  => $by,
                'updated_at'  => now(),
            ];

            if ($row) {
                DB::table('game_settings')->where('setting_key', $key)->update($payload);
            } else {
                // 缺行(库比代码旧)时补写,而不是报错:新开关上线后第一次改动即建行
                DB::table('game_settings')->insert(['setting_key' => $key] + $payload);
            }

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type'    => 'admin',
                'actor_id'      => $by,
                'user_id'       => $by,
                'entity_type'   => 'game_setting',
                'entity_id'     => $key,
                'reason_code'   => $reason,
                'before_json'   => [$key => $before],
                'after_json'    => [$key => $value],
                'metadata_json' => ['description' => self::DEFINITIONS[$key]['description']],
            ]);

            return ['before' => $before, 'after' => $value];
        });

        // 失效缓存:本请求后续的结算必须读到刚写入的新值。
        // 只 forget 不回填 —— 万一外层事务回滚,下次 load() 重新读库,不会残留一个根本没落库的值。
        self::flush();

        return $result;
    }

    // 跨键约束校验('depends'):违反一律 VALIDATION_ERROR,绝不「自动纠正后继续」——
    // 悄悄改掉运营填的数,只会让后台显示的值与实际生效的值变成两套真相(CLAUDE §52 同一条纪律)。
    //
    // 只对 TYPE_NUMBER 有意义(bool / resource_map 没有大小关系),目标键未登记时视为无约束。
    private static function assertDepends(string $key, mixed $value): void
    {
        $depends = self::DEFINITIONS[$key]['depends'] ?? null;
        if (! is_array($depends) || ! is_int($value) && ! is_float($value)) {
            return;
        }

        foreach ($depends as $relation => $otherKey) {
            if (! isset(self::DEFINITIONS[$otherKey])) {
                continue;
            }
            $other = self::get($otherKey);
            if (! is_int($other) && ! is_float($other)) {
                continue;
            }

            $ok = match ($relation) {
                self::DEPENDS_LTE => $value <= $other,
                self::DEPENDS_GTE => $value >= $other,
                default           => true,
            };
            if (! $ok) {
                throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
            }
        }
    }

    // 后台设置页用:已登记开关的当前值 + 说明 + 最后修改人/时间。
    // 库里存在但代码里已不再登记的历史 key 追加在后面并标记 registered=false(不可编辑),
    // 让运营看得见「这行是残留」,而不是被静默隐藏。
    public static function all(): array
    {
        $rows = DB::table('game_settings')->get()->keyBy('setting_key');
        $list = [];

        foreach (self::DEFINITIONS as $key => $meta) {
            $row = $rows[$key] ?? null;
            $list[] = [
                'setting_key' => $key,
                'value'       => $row ? self::decode($row->value_json, $key) : $meta['default'],
                'default'     => $meta['default'],
                'type'        => $meta['type'],
                'description' => $meta['description'],
                'updated_by'  => $row?->updated_by === null ? null : (int) $row->updated_by,
                'updated_at'  => $row?->updated_at,
                'registered'  => true,
                // 分组:后台按 GameSetting::GROUPS 的顺序出折叠面板,同组的键排在一起
                'group'       => $meta['group'],
                // 整数键:后台把输入框的 step 设成 1,别让运营填出 3.5 条
                'integer'     => (bool) ($meta['integer'] ?? false),
                // 死键:代码里已无任何消费点,后台渲染成只读并置底(别让运营以为改了有用)
                'deprecated'  => (bool) ($meta['deprecated'] ?? false),
                // 跨键约束:['lte' => '另一个 key'] / ['gte' => …],后台可据此在前端先给提示
                'depends'     => $meta['depends'] ?? null,
                // 编辑器元数据:对象型给可选键清单,数值型给闭区间——后台据此渲染
                // 「键/值表格 + 追加下拉」或「带范围校验的数字输入」,不必让运营手写 JSON
                'options'     => $meta['type'] === self::TYPE_RESOURCE_MAP ? self::resourceMapOptions() : null,
                'min_value'   => match ($meta['type']) {
                    self::TYPE_NUMBER       => $meta['min'],
                    self::TYPE_RESOURCE_MAP => 0,
                    default                 => null,
                },
                'max_value'   => match ($meta['type']) {
                    self::TYPE_NUMBER       => $meta['max'],
                    self::TYPE_RESOURCE_MAP => self::MAX_RESOURCE_AMOUNT,
                    default                 => null,
                },
            ];
        }

        foreach ($rows as $key => $row) {
            if (isset(self::DEFINITIONS[$key])) {
                continue;
            }
            $list[] = [
                'setting_key' => (string) $key,
                'value'       => json_decode((string) $row->value_json, true),
                'default'     => null,
                'type'        => null,
                'description' => (string) $row->description,
                'updated_by'  => $row->updated_by === null ? null : (int) $row->updated_by,
                'updated_at'  => $row->updated_at,
                'registered'  => false,
                // 残留行没有登记元数据:分组给 null,后台把它们单独归到「未登记」一栏
                'group'       => null,
                'integer'     => false,
                'deprecated'  => false,
                'depends'     => null,
                'options'     => null,
                'min_value'   => null,
                'max_value'   => null,
            ];
        }

        return $list;
    }

    // 清空请求级缓存(写入后、测试里改库后调用)
    public static function flush(): void
    {
        Context::forget(self::CACHE_KEY);
    }

    // 整表一次读入(每请求只查一次库)
    private static function load(): array
    {
        if (Context::has(self::CACHE_KEY)) {
            return Context::get(self::CACHE_KEY);
        }

        $values = [];
        foreach (DB::table('game_settings')->get(['setting_key', 'value_json']) as $row) {
            $values[(string) $row->setting_key] = self::decode($row->value_json, (string) $row->setting_key);
        }

        Context::add(self::CACHE_KEY, $values);

        return $values;
    }

    // 解析存储值:解析不出来时回退默认值(脏数据不该让内核换一套规则)
    private static function decode(?string $json, string $key): mixed
    {
        $value = json_decode((string) $json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::DEFINITIONS[$key]['default'] ?? null;
        }

        return self::castValue($key, $value, self::DEFINITIONS[$key]['default'] ?? null);
    }

    // 按登记类型规整取值。类型不符时:$fallback 给了就回退,没给则视为非法输入拒绝(写入路径)
    private static function castValue(string $key, mixed $value, mixed $fallback = null): mixed
    {
        $type = self::DEFINITIONS[$key]['type'] ?? null;

        if ($type === self::TYPE_BOOL) {
            if (is_bool($value)) {
                return $value;
            }
            if ($fallback !== null) {
                return (bool) $fallback;
            }
            // 写入路径:只收真正的 true/false,不做 "1"/"on"/"yes" 的模糊解释
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        if ($type === self::TYPE_RESOURCE_MAP) {
            $clean = self::normalizeResourceMap($value);
            if ($clean !== null) {
                return $clean;
            }
            if (is_array($fallback)) {
                // 读取路径:库里是脏值 → 回退登记默认值(Fail Safe,不让脏配置改变开局)
                return $fallback;
            }
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        if ($type === self::TYPE_NUMBER) {
            $meta = self::DEFINITIONS[$key];
            // 与 resource_map 同一纪律:只收真正的数字,"0.05"/true/null 一律不做模糊解释
            $valid = (is_int($value) || is_float($value))
                && (! is_float($value) || is_finite($value))
                && $value >= $meta['min'] && $value <= $meta['max'];
            // 'integer' 标记:该键的语义是「条数 / 分钟数 / 秒数 / 次数 / 序号」,3.5 条没有意义。
            // 收 3.0(float 但整值)是刻意的 —— JSON 里 3.0 与 3 无法区分,拒绝它等于拒绝合法输入
            if ($valid && ($meta['integer'] ?? false) && is_float($value) && floor($value) !== $value) {
                $valid = false;
            }
            if ($valid) {
                return $value;
            }
            if ($fallback !== null) {
                // 读取路径:脏值回退登记默认值(Fail Safe,规则参数读不出来时维持默认行为)
                return $fallback;
            }
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        return $value;
    }

    // 对象型设定的逐键校验。合法返回规整后的映射,非法返回 null(由调用方决定回退还是拒绝)。
    //
    // 拒绝的输入:非对象 / 空对象 / 数组式 JSON(["wood"]) / 键数超限 /
    //            未登记的资源 code / 容量类 code(不是库存资源,进不了 city_resources)/
    //            非数值(字符串 "100"、true、null)/ 嵌套对象 / 负数 / 超过 100 万 / NaN·INF
    private static function normalizeResourceMap(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            return null;
        }
        if (count($value) > self::MAX_RESOURCE_KEYS) {
            return null;
        }

        $clean = [];
        foreach ($value as $code => $amount) {
            $code = (string) $code;

            // allowlist:必须是 ResourceCode 登记过的库存资源;容量类(governance_capacity 等)不是库存资源
            if (! isset(ResourceCode::CHINESE_NAMES[$code]) || ResourceCode::isCapacity($code)) {
                return null;
            }

            // 只收真正的数字。字符串 "100" 一律拒绝:模糊解释会让「后台填错」变成静默生效
            if (! is_int($amount) && ! is_float($amount)) {
                return null;
            }
            if (is_float($amount) && ! is_finite($amount)) {
                return null;
            }
            if ($amount < 0 || $amount > self::MAX_RESOURCE_AMOUNT) {
                return null;
            }

            $clean[$code] = $amount;
        }

        return $clean;
    }

    // 对象型设定可用的资源 code 清单(后台编辑器的下拉来源:code + 中文显示名)
    public static function resourceMapOptions(): array
    {
        $options = [];
        foreach (ResourceCode::CHINESE_NAMES as $code => $name) {
            if (ResourceCode::isCapacity($code)) {
                continue;
            }
            $options[] = ['code' => $code, 'name' => $name];
        }

        return $options;
    }
}
