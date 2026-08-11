<?php

namespace App\Game\Event;

// 随机事件的稳定 code 与 DSL allowlist(M3-D4,v3.2 §9)。
//
// 这个类是 events.json、Seeder、条件求值、效果应用、后台编辑五处的**共同词表**:
// 任何一处出现这里没登记的 metric / kind / status,都必须当场失败,不许静默跳过 ——
// M2 的 upgrade_to 断链就是「解析不到就静默变 NULL」造成的,36 条链丢了很久没人发现。
final class EventCode
{
    // ---------- 实例状态(§70 明文四态里的三态 + 过期)----------

    public const STATUS_ACTIVE = 'active';      // 生效中,可 resolve
    public const STATUS_RESOLVED = 'resolved';  // 已选择并结算(终态)
    public const STATUS_EXPIRED = 'expired';    // 过期作废(终态,由懒结算翻)

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_RESOLVED, self::STATUS_EXPIRED];

    // ---------- 事件正负(§13 帽修正方向的分流依据)----------
    //
    // positive = 正向:产量类效果一律**直接发资源**(grant_production_pct),不占 §13 的加成帽;
    // negative = 负向:走 event 乘区(值恒 <1.0,惩罚方向本来就不受帽约束)。
    // 用户 2026-08-10 拍板的 §13 修正方向(backlog §11.1 的方向④)就落在这条分流上。

    public const TYPE_POSITIVE = 'positive';
    public const TYPE_NEGATIVE = 'negative';

    public const TYPES = [self::TYPE_POSITIVE, self::TYPE_NEGATIVE];

    // ---------- 选项键 ----------

    public const OPTIONS = ['a', 'b', 'c'];

    // ---------- 条件 DSL 的 metric allowlist ----------
    //
    // 每一条都必须在 EventCondition::value() 里有对应实现;新增 metric 必须两边一起加。
    // 「当前系统读不出来」的条件(威胁等级 / 电力使用率 / 税率)不在这里 ——
    // 它们原样留在 condition_json.unmapped_zh,由 events.json 的 enabled=false 兜住(Fail Closed)。
    public const METRIC_BUILDING_COUNT = 'building_count';        // 已建成建筑数(按 category / series 过滤)
    public const METRIC_POPULATION = 'population';                // 结算后人口
    public const METRIC_RESOURCE_STOCK = 'resource_stock';        // 某资源库存(money 也走这里)
    public const METRIC_HAPPINESS = 'happiness';                  // 结算后幸福
    public const METRIC_SECURITY = 'security';                    // §10.8 派生治安
    public const METRIC_GOVERNANCE_LOAD = 'governance_load';      // §10.6 治理负载
    public const METRIC_TRANSPORT_CAPACITY = 'transport_capacity';// 全城运输容量
    public const METRIC_HOUSING_FREE = 'housing_free';            // 住房空余人数 = 人口容量 − 人口
    public const METRIC_HOUSING_FREE_RATE = 'housing_free_rate';  // 住房空余率 = 1 − 人口 / 人口容量
    public const METRIC_CONSTRUCTING_COUNT = 'constructing_count';// 在建 / 升级中的实例数
    public const METRIC_ASSIGNED_WORKERS = 'assigned_workers';    // 已派工人数(按 category / series 过滤)
    public const METRIC_NPC_SKILL_COUNT = 'npc_skill_count';      // 技能等级 ≥ 门槛的在编 NPC 数(门槛走后台设定)

    public const CONDITION_METRICS = [
        self::METRIC_BUILDING_COUNT,
        self::METRIC_POPULATION,
        self::METRIC_RESOURCE_STOCK,
        self::METRIC_HAPPINESS,
        self::METRIC_SECURITY,
        self::METRIC_GOVERNANCE_LOAD,
        self::METRIC_TRANSPORT_CAPACITY,
        self::METRIC_HOUSING_FREE,
        self::METRIC_HOUSING_FREE_RATE,
        self::METRIC_CONSTRUCTING_COUNT,
        self::METRIC_ASSIGNED_WORKERS,
        self::METRIC_NPC_SKILL_COUNT,
    ];

    // 比较运算符 allowlist(不接受任何自由文本)
    public const OPS = ['>', '>=', '<', '<=', '==', '!='];

    // 建筑过滤的作用域:building_definition 的两列
    public const SCOPE_CATEGORY = 'category';
    public const SCOPE_SERIES = 'series';

    public const BUILDING_SCOPES = [self::SCOPE_CATEGORY, self::SCOPE_SERIES];

    // ---------- 效果 DSL 的 kind allowlist ----------

    // 定额资源增减(负 = 成本)。money 也走这条
    public const EFFECT_RESOURCE_DELTA = 'resource_delta';

    // 按当前库存百分比增减(§9.2 的「损失粮食库存 8%~15%」)。value 固定值 / min-max 区间掷点
    public const EFFECT_RESOURCE_PCT_OF_STOCK = 'resource_pct_of_stock';

    // **正向事件专用**:按「当前 gross 产出速率 × 加成率 × 持续分钟」一次性发资源(§13 修正方向)
    public const EFFECT_GRANT_PRODUCTION_PCT = 'grant_production_pct';

    // 持续型 modifier → city_active_modifiers,由 EventMultiplierProvider 读回 event 乘区
    public const EFFECT_MODIFIER = 'modifier';

    // 幸福冲击:duration=0 → 改**当前值**;duration>0 → 改**目标值**(flat 通道)。D 区 D4 批准口径
    public const EFFECT_HAPPINESS = 'happiness';

    // 治安冲击:security 是 §10.8 的派生值,没有「当前值」可改 → 一律走 security_flat 通道
    public const EFFECT_SECURITY = 'security';

    // 人口按百分比增减(§9.2 的「人口+2%~5%」),夹在 [§10.1 人口下限, 人口容量]
    public const EFFECT_POPULATION_PCT = 'population_pct';

    // 在建 / 升级项目按剩余工期百分比延期(§9.2 的「进度回退 10%」)
    public const EFFECT_CONSTRUCTION_DELAY_PCT = 'construction_delay_pct';

    // ---- 以下只允许出现在**选项**里:它们改的是「本实例已经发生的效果」----

    public const EFFECT_LOSS_SCALE = 'loss_scale';                        // 损失 ×系数,差额退还
    public const EFFECT_LOSS_SET_PCT = 'loss_set_pct';                    // 损失改成固定比例,差额退还
    public const EFFECT_MODIFIER_SET_VALUE = 'modifier_set_value';        // 把本实例的 event 乘区值改成给定值
    public const EFFECT_FLAT_SET = 'flat_set';                            // 把本实例的幸福 / 治安 flat 改成给定值
    public const EFFECT_DURATION_SCALE = 'duration_scale';                // 剩余持续时间 ×系数
    public const EFFECT_DURATION_SET_MINUTES = 'duration_set_minutes';    // 剩余持续时间设为 N 分钟
    public const EFFECT_END_NOW = 'end_now';                              // 立即结束(减益即刻消失)
    public const EFFECT_ROLL_TAKE_MAX = 'roll_take_max';                  // 区间掷点提升到上限,差额补发
    public const EFFECT_CONSTRUCTION_DELAY_REVERT = 'construction_delay_revert'; // 撤销本实例造成的延期

    public const EFFECT_KINDS = [
        self::EFFECT_RESOURCE_DELTA,
        self::EFFECT_RESOURCE_PCT_OF_STOCK,
        self::EFFECT_GRANT_PRODUCTION_PCT,
        self::EFFECT_MODIFIER,
        self::EFFECT_HAPPINESS,
        self::EFFECT_SECURITY,
        self::EFFECT_POPULATION_PCT,
        self::EFFECT_CONSTRUCTION_DELAY_PCT,
        self::EFFECT_LOSS_SCALE,
        self::EFFECT_LOSS_SET_PCT,
        self::EFFECT_MODIFIER_SET_VALUE,
        self::EFFECT_FLAT_SET,
        self::EFFECT_DURATION_SCALE,
        self::EFFECT_DURATION_SET_MINUTES,
        self::EFFECT_END_NOW,
        self::EFFECT_ROLL_TAKE_MAX,
        self::EFFECT_CONSTRUCTION_DELAY_REVERT,
    ];

    // 只能出现在选项里的 kind(自动效果里出现即 seed 失败:没有「已发生的效果」可改)
    public const OPTION_ONLY_KINDS = [
        self::EFFECT_LOSS_SCALE,
        self::EFFECT_LOSS_SET_PCT,
        self::EFFECT_MODIFIER_SET_VALUE,
        self::EFFECT_FLAT_SET,
        self::EFFECT_DURATION_SCALE,
        self::EFFECT_DURATION_SET_MINUTES,
        self::EFFECT_END_NOW,
        self::EFFECT_ROLL_TAKE_MAX,
        self::EFFECT_CONSTRUCTION_DELAY_REVERT,
    ];

    // ---------- 权重的城市状态修正(backlog 9.D2 批准口径)按 category 分组 ----------
    //
    // 「粮食赤字对 food/agriculture 类 ×1.5」这类分组在这里落成明确的 category 名单,
    // 免得判定散在代码里各写一份字符串。
    public const CATEGORY_GROUP_FOOD = ['food', 'agriculture'];
    public const CATEGORY_GROUP_FISCAL = ['governance', 'economy'];
    public const CATEGORY_GROUP_GOVERNANCE = ['governance'];
    public const CATEGORY_GROUP_SECURITY = ['security'];
    public const CATEGORY_GROUP_CIVIL = ['civil'];
    public const CATEGORY_GROUP_DEFENSE = ['defense'];

    // 「灾害 / 国防类最多 1 个」(§9.1 并发上限的第二条)
    public const CATEGORY_GROUP_DISASTER_DEFENSE = ['disaster', 'defense'];
}
