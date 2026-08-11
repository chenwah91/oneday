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

    // ---------- 设定类型 ----------

    // 布尔开关(true / false 二选一)
    public const TYPE_BOOL = 'bool';

    // 资源映射对象:{资源 code: 非负数量},逐键校验 code 合法 + 数量在 [0, MAX_RESOURCE_AMOUNT]
    public const TYPE_RESOURCE_MAP = 'resource_map';

    // 数值型规则参数(M3 起「系统规则数据后台可调」的载体,用户 2026-08-11 拍板):
    // 登记时必须带 'min'/'max' 两键,写入校验闭区间;只收真正的 int/float,字符串数字一律拒绝
    public const TYPE_NUMBER = 'number';

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
            'description' => '工人只减不增的操作永远放行:人口暴跌导致历史分配超上限时,玩家仍能撤人(关闭后撤人也要满足劳动力上限)',
        ],
        self::WORKER_GATE_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'description' => '没派工人就不生产的总开关:关闭后所有建筑的用工乘区恒为 1.0(运营救急用,会让全服产量立刻恢复满额)',
        ],
        self::INITIAL_RESOURCES => [
            'default'     => self::INITIAL_RESOURCES_DEFAULT,
            'type'        => self::TYPE_RESOURCE_MAP,
            'description' => '建城初始资源(含 money / knowledge):只影响此后新建的城市,不回填老城。数量上限 100 万,建议低于仓储上限 1000',
        ],

        // ---- M3-D3 市场全局参数(默认值 = 9.C 区已批准口径,逐条对照见交付汇报)----
        self::MARKET_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'description' => '市场总开关:关闭后所有买卖立即返回 MARKET_CLOSED(经济出事时一键停市),价目查询不受影响',
        ],
        self::MARKET_WINDOW_SECONDS => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 10,
            'max'         => 3600,
            'description' => '价格窗口(EPOCH)秒数:同一窗口内价格恒定,跨窗口才重新掷价。改动会让窗口编号整体平移(不影响历史流水)',
        ],
        self::MARKET_MA_WINDOWS => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 60,
            'description' => '供需移动平均的窗口数 N:取最近 N 个**已结束**窗口的全服买卖量。调大 = 价格更钝、更难被单人操纵',
        ],
        self::MARKET_SLIPPAGE_COEFFICIENT => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 5,
            'description' => '滑点系数 k:滑点率 = k × 本笔数量 / 有效流动性(买价上抬、卖价下压)。调到 0 等于关掉滑点,§13 明确禁止',
        ],
        // 默认值写整数 1 而不是 1.0:建表迁移(2026_08_10_500001)灌行时用的是不带
        // JSON_PRESERVE_ZERO_FRACTION 的 json_encode,1.0 会被写成 "1",读回来就成了 int ——
        // 「登记值是 float、落库值是 int」的类型漂移。写成 int 从源头上不给它漂的机会
        //(TYPE_NUMBER 读写都同时接受 int 与 float,运营改成 1.5 照样存得下)
        self::MARKET_FEE_RATE_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10,
            'description' => '手续费率全局倍率:实际费率 = 该资源定义的 fee_rate(§8 默认 0.03)× 本值。调到 0 等于免手续费,§13 明确禁止',
        ],
        self::MARKET_QUOTA_WINDOW_PCT => [
            'default'     => 0.1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.0001,
            'max'         => 1,
            'description' => '单城单窗成交量上限占有效流动性的比例(§8.1 建议 10%),买卖合并计入同一个额度',
        ],
        self::MARKET_QUOTA_HOURLY_MULTIPLE => [
            'default'     => 20,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 1000,
            'description' => '单城每小时成交量上限 = 本值 × 单窗上限。60 秒窗时一小时有 60 窗,取 20 是刻意留出的反刷空间',
        ],
        self::MARKET_PRICE_MIN_MULTIPLE => [
            'default'     => 0.45,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 1,
            'description' => '价格全局下限倍率:最终下限 = max(定义表 min_price, 基础价 × 本值)。默认 0.45 = §8 全表最宽档,等于「默认听定义表的」',
        ],
        self::MARKET_PRICE_MAX_MULTIPLE => [
            'default'     => 3.2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 100,
            'description' => '价格全局上限倍率:最终上限 = min(定义表 max_price, 基础价 × 本值)。默认 3.2 = §8 全表最宽档,等于「默认听定义表的」',
        ],
        // 同上:整数 1,避免 1.0 在「落库再读出」的往返里漂成 int
        self::MARKET_LIQUIDITY_MULTIPLIER => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0.01,
            'max'         => 100,
            'description' => '流动性全局倍率:有效流动性 = 该资源 base_liquidity × 本值。调小 = 滑点更狠且成交量上限更低(反刷总闸门)',
        ],
        self::MARKET_NOISE_FLOOR_PCT => [
            'default'     => 0.05,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'description' => '供需底噪比例:买量与卖量各加 有效流动性 × 本值,保证空服不会因 0/0 跳价,也稀释单人操纵价格的力度',
        ],
        self::MARKET_MAX_ORDER_QUANTITY => [
            'default'     => 1000000,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 100000000,
            'description' => '单笔交易数量的绝对上限:与成交量上限是两道独立的闸,专门挡「超大数字」类攻击输入',
        ],

        // ---- M3-D1 NPC 规则参数(默认值 = backlog §9 A 区已批准的建议默认值,逐条对照见交付汇报)----

        // A5 槽位
        self::NPC_SLOTS_PER_BUILDING => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 20,
            'description' => '单栋建筑(L1/L2)的 NPC 槽位数:派驻满了返回 NPC_SLOT_FULL。调到 0 等于全服禁止派驻(已派驻的不会被强制撤下)',
        ],
        self::NPC_SLOTS_PER_BUILDING_L3 => [
            'default'     => 3,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 20,
            'description' => 'L3 建筑的 NPC 槽位数(A5:满级建筑多一个槽)。判定按实例当前 level,升级中的实例按旧等级算',
        ],

        // A7 招募价格
        self::NPC_RECRUIT_PRICE_WAGE_MULTIPLIER => [
            'default'     => 200,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100000,
            'description' => '招募价格的工资系数:招募资金 = 该 NPC 的 wage_per_min × 本值 × 稀有度系数(A7)。等价于「预付多少分钟工资」',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_COMMON => [
            'default'     => 1.0,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'description' => '招募价格的稀有度系数:common(A7 = 1.0)',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_UNCOMMON => [
            'default'     => 1.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'description' => '招募价格的稀有度系数:uncommon(A7 = 1.5)',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_RARE => [
            'default'     => 2.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'description' => '招募价格的稀有度系数:rare(A7 = 2.5)',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_EPIC => [
            'default'     => 4,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'description' => '招募价格的稀有度系数:epic(A7 = 4)',
        ],
        self::NPC_RECRUIT_PRICE_RARITY_LEGENDARY => [
            'default'     => 8,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'description' => '招募价格的稀有度系数:legendary(A7 = 8)',
        ],

        // 稀有度掷点权重(A 区未给数值:§6.2 只说稀有度决定「招募难度」。
        // 默认取 60/25/10/4/1,即普通 60%、传奇 1%;权重之和不必等于 100,服务器按比例归一)
        self::NPC_RECRUIT_WEIGHT_COMMON => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'description' => '招募掷点权重:common。权重按候选池里实际存在的稀有度归一,全部为 0 时按稀有度从低到高回退',
        ],
        self::NPC_RECRUIT_WEIGHT_UNCOMMON => [
            'default'     => 25,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'description' => '招募掷点权重:uncommon',
        ],
        self::NPC_RECRUIT_WEIGHT_RARE => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'description' => '招募掷点权重:rare',
        ],
        self::NPC_RECRUIT_WEIGHT_EPIC => [
            'default'     => 4,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'description' => '招募掷点权重:epic',
        ],
        self::NPC_RECRUIT_WEIGHT_LEGENDARY => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000000,
            'description' => '招募掷点权重:legendary',
        ],

        // A1 自然增长
        self::NPC_NATURAL_GROWTH_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'description' => 'NPC 自然增长总开关(运营救急用):关闭后不再自动送人,已有 NPC 不受影响',
        ],
        self::NPC_NATURAL_GROWTH_WINDOW_MINUTES => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 10080,
            'description' => '自然增长的判定窗口(分钟,A1 = 60):每经过一个整窗掷一次点。离线期间按窗口数逐窗推进,不用一次性概率',
        ],
        self::NPC_NATURAL_GROWTH_CHANCE => [
            'default'     => 0.03,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'description' => '自然增长的单窗触发概率(A1 = 0.03 即 3%):掷中送 1 名 natural_growth 来源的 NPC',
        ],
        self::NPC_NATURAL_GROWTH_HOUSING_FREE_MIN => [
            'default'     => 0.05,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'description' => '自然增长的住房门槛(A1 = 0.05):住房空余率低于本值不再增长(空余率 = 1 − 人口 / 人口容量)',
        ],
        self::NPC_NATURAL_GROWTH_HAPPINESS_MIN => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'description' => '自然增长的幸福门槛(A1 = 60):幸福低于本值不再增长',
        ],
        self::NPC_NATURAL_GROWTH_CAP_PER_POPULATION => [
            'default'     => 500,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 1000000,
            'description' => '自然增长上限的人口分母(A1):上限 = floor(人口 / 本值) + 基数。调小 = 小城也能自然长出更多 NPC',
        ],
        self::NPC_NATURAL_GROWTH_CAP_BASE => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 10000,
            'description' => '自然增长上限的基数(A1 = 2):上限 = floor(人口 / 人口分母) + 本值。只约束自然增长来的 NPC,招募不受限',
        ],
        self::NPC_NATURAL_GROWTH_OFFLINE_MAX => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1000,
            'description' => '单次结算最多补算几名自然增长 NPC(A1 = 2):挂机 12 小时上线时的防雪崩上限',
        ],

        // A4 士气与离职
        self::NPC_MORALE_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'description' => 'NPC 士气总开关(运营救急用):关闭后士气不再涨跌、也不会有人因士气过低离职',
        ],
        self::NPC_MORALE_INITIAL => [
            'default'     => 70,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'description' => '新 NPC 的初始士气(A4 = 70)。只影响此后新增的 NPC,不回填已在城里的',
        ],
        self::NPC_MORALE_WAGE_ARREARS_PENALTY_PER_MIN => [
            'default'     => 2,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'description' => '发不出工资时的士气扣减(每分钟,A4 = 2)。§16.5:发不出工资要扣士气,不能让玩家白嫖劳动力',
        ],
        self::NPC_MORALE_LOW_HAPPINESS_THRESHOLD => [
            'default'     => 50,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'description' => '城市幸福低于本值时开始扣 NPC 士气(A4 = 50)',
        ],
        self::NPC_MORALE_LOW_HAPPINESS_PENALTY_PER_MIN => [
            'default'     => 1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'description' => '城市幸福低于阈值时的士气扣减(每分钟,A4 = 1)。可与欠薪扣减叠加',
        ],
        self::NPC_MORALE_RECOVER_PER_MIN => [
            'default'     => 0.5,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'description' => '一切正常(工资付得出、幸福达标)时的士气回升速度(每分钟,A4 = 0.5),上限 100',
        ],
        self::NPC_MORALE_LEAVE_THRESHOLD => [
            'default'     => 30,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100,
            'description' => '士气低于本值的 NPC 开始有离职风险(A4 = 30)。调到 0 等于永不离职',
        ],
        self::NPC_MORALE_LEAVE_CHANCE => [
            'default'     => 0.1,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 1,
            'description' => '低士气 NPC 的单窗离职概率(A4 = 0.1 即 10%)。掷中即 status=left,并写 NPC.LEAVE 审计',
        ],
        self::NPC_MORALE_LEAVE_WINDOW_MINUTES => [
            'default'     => 60,
            'type'        => self::TYPE_NUMBER,
            'min'         => 1,
            'max'         => 10080,
            'description' => '离职判定的窗口(分钟,A4 = 60):每经过一个整窗对低士气 NPC 掷一次点',
        ],

        // A6 XP
        self::NPC_XP_PER_MIN => [
            'default'     => 10,
            'type'        => self::TYPE_NUMBER,
            'min'         => 0,
            'max'         => 100000,
            'description' => '已派驻 NPC 的工作 XP 速率(每分钟,A6 = 10 XP / 60 秒)。未派驻的 NPC 不涨 XP;升级曲线见 npc_skill_level_curve',
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
