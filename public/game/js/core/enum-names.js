// 枚举显示名:定义表里的分类/系列/成本类型/资源类别/科技分支已全部改成英文 code
// (v3.2 §0.2 Canonical English Game Data Standard),中文只用于展示。
//
// 这些值不像资源名那样有 /api/definitions/* 接口可查(服务器只存 code,没有对应的中文列),
// 所以在前端补一张小表。数据源:docs/templates/enum-code-map.md,
// 后端单一来源:app/Game/Definition/EnumCode.php —— 三处改动必须同步。
//
// 查不到时一律回落成 code 本身,宁可显示 code 也不要显示空白。

// building_definition.category(12)
export const BUILDING_CATEGORY_NAMES = {
    housing: '居住',
    food_production: '粮食生产',
    storage: '仓储',
    administration: '行政',
    defense: '国防',
    transport: '运输',
    raw_material_extraction: '原料采集',
    processing: '加工',
    energy: '能源',
    commerce: '商贸',
    research_education: '科研教育',
    public_service: '公共服务',
};

// building_definition.series_key(29)
export const BUILDING_SERIES_NAMES = {
    residence: '住宅',
    agriculture: '农业',
    storage: '仓储',
    governance: '治理',
    city_defense: '城防',
    land_transport: '陆路运输',
    wood: '木材',
    stone: '石料',
    copper_mine: '铜矿',
    tin_mine: '锡矿',
    iron_mine: '铁矿',
    coal_mine: '煤矿',
    oil: '石油',
    rare_metals: '稀有金属',
    grain_processing: '粮食加工',
    metal_processing: '金属加工',
    building_material_processing: '建材加工',
    machinery_manufacturing: '机械制造',
    food_processing: '食品加工',
    petrochemical_processing: '石化加工',
    high_tech: '高科技',
    basic_energy: '基础能源',
    electricity: '电力',
    market: '市场',
    finance: '金融',
    global_trade: '全球贸易',
    education: '教育',
    research: '科研',
    medical: '医疗',
};

// building_level_definition.cost_type(3)
export const COST_TYPE_NAMES = {
    build: '建造',
    upgrade_l1_l2: 'L1→L2升级',
    upgrade_l2_l3: 'L2→L3升级',
};

// resource_definition.category(6)
export const RESOURCE_CATEGORY_NAMES = {
    raw_material: '原料',
    currency: '货币',
    knowledge: '知识',
    food: '食物',
    energy: '能源',
    processed_good: '加工品',
};

// technology_definition.branch(5)
export const TECH_BRANCH_NAMES = {
    survival_agriculture: '生存/农业',
    industry_processing: '工业/加工',
    governance_science_trade: '治理/科研/商贸',
    storage_transport: '仓储/运输',
    defense: '国防',
};

// ---- NPC(v3.2 §6)----
// 这几张表的后端单一来源是 app/Game/NPC/NpcCode.php(不是 EnumCode.php),改动时两边同步。
// 与上面的表同一条纪律:服务器只存英文 code,中文只用于展示,查不到一律回落 code。

// city_npcs.status(3):left 不会出现在快照里(ACTIVE_STATUSES 已过滤),列出只为兜底
export const NPC_STATUS_NAMES = {
    idle: '闲置',
    assigned: '派驻中',
    left: '已离场',
};

// npc_definition.rarity(5)。§6.2:稀有度只决定初始技能值、特殊特性与招募难度,不进乘区
export const NPC_RARITY_NAMES = {
    common: '普通',
    uncommon: '优秀',
    rare: '稀有',
    epic: '史诗',
    legendary: '传说',
};

// npc_definition.primary_skill_id(§6.1 的 12 条)
export const NPC_SKILL_NAMES = {
    SKILL_GATHERING: '采集',
    SKILL_AGRICULTURE: '农业',
    SKILL_MINING: '采矿',
    SKILL_PROCESSING: '加工',
    SKILL_CONSTRUCTION: '建筑',
    SKILL_COMMERCE: '商贸',
    SKILL_ADMIN: '行政',
    SKILL_RESEARCH: '科研',
    SKILL_MEDICINE: '医疗',
    SKILL_ENGINEERING: '工程',
    SKILL_LOGISTICS: '物流',
    SKILL_DEFENSE: '国防',
};

// ---- 工具 / 道具(v3.2 §7)----
// 后端单一来源是 app/Game/Item/ItemCode.php(状态 / 耐久档 / 耐久口径 / 获取来源)
// 与 database/data/items.json(category / effect_code),改动时同步。
// 同一条纪律:服务器只存英文 code,中文只用于展示,查不到一律回落 code。

// city_items.status(3):broken 不出现在快照 list 里(ACTIVE_STATUSES 已过滤),列出只为兜底
export const ITEM_STATUS_NAMES = {
    stored: '库存中',
    equipped: '已装备',
    broken: '已损毁',
};

// item_definition.category(15)
export const ITEM_CATEGORY_NAMES = {
    gathering_tool: '采集工具',
    hunting_tool: '狩猎工具',
    agriculture_tool: '农业工具',
    processing_tool: '加工工具',
    construction_tool: '建筑工具',
    mining_tool: '采矿工具',
    defense_equipment: '防御装备',
    medical_item: '医疗道具',
    academic_item: '学术道具',
    commerce_item: '商贸道具',
    engineering_tool: '工程工具',
    industrial_tool: '工业工具',
    logistics_tool: '物流工具',
    research_tool: '科研设备',
    planning_tool: '规划工具',
};

// item_definition.effect_code(19):效果的中文说法。
// 数值(effect_value / unit)由服务器下发,这里只翻译「加成的是什么」
export const ITEM_EFFECT_NAMES = {
    wood_output_pct: '木材产量',
    hunting_output_pct: '狩猎产量',
    agriculture_output_pct: '农业产量',
    milling_efficiency_pct: '粮食加工效率',
    construction_speed_pct: '建造速度',
    mining_output_pct: '采矿产量',
    bronze_output_pct: '青铜产量',
    defense_score_flat: '国防值',
    iron_processing_efficiency_pct: '铁加工效率',
    disease_recovery_pct: '疾病康复率',
    knowledge_output_pct: '知识产量',
    money_efficiency_pct: '资金效率',
    maintenance_cost_reduction_pct: '维护费减免',
    industry_output_pct: '工业产量',
    transport_capacity_pct: '运输容量',
    downtime_reduction_pct: '停机时间减少',
    medical_efficiency_pct: '医疗效率',
    governance_efficiency_pct: '治理效率',
    megaproject_speed_pct: '巨型工程速度',
};

// item_definition.durability_tier(2):每档实际几分钟扣 1 点是后台设定,不在前端写死,
// 所以这里只给档位名,不给分钟数
export const ITEM_TIER_NAMES = {
    normal: '普通耐久',
    industrial: '工业耐久',
};

// item_definition.durability_mode(2)
export const ITEM_DURABILITY_MODE_NAMES = {
    work_minutes: '按工作时间消耗',
    uses: '按使用次数消耗',
};

// city_items.acquired_source(3)
export const ITEM_SOURCE_NAMES = {
    craft: '制作',
    admin: '管理员补发',
    event: '事件产出',
};

// 每件工具的「装备对象」(v3.2 §7 表的 equip_target_desc_zh 列,24 行照抄)。
//
// 为什么这张表在前端:§7 里工具**没有中文名**,只有 name_key(`item.IT001.name`,无译文表)
// 与 equip_target_desc_zh 两列;而 equip_target_desc_zh **不在城市快照的 items 契约里**
// (ItemService::toContract 只给 item_id / name_key / category / 效果 / 耐久)。
// 玩家侧又没有工具定义端点 —— 三处都拿不到,只剩「IT001」这种裸 code 可显示。
// 所以照 enum-names.js 的既有做法在前端补一张小表,显示名 = 类别 +(装备对象)。
// **这是契约缺口的临时补位,不是新数据源**:后端补上玩家侧工具定义端点(带 equip_target_desc_zh)
// 之后,这张表应当删掉改读服务器。缺口已写进 W7 交付汇报
export const ITEM_EQUIP_TARGET_NAMES = {
    IT001: '伐木工', IT002: '猎人', IT003: '农夫', IT004: '磨坊工',
    IT005: '建筑工', IT006: '矿工', IT007: '铸造师', IT008: '青铜卫士',
    IT009: '矿工', IT010: '铁匠', IT011: '农夫', IT012: '医师',
    IT013: '建筑工', IT014: '学者/教授', IT015: '银行家', IT016: '机械师',
    IT017: '工业工程师', IT018: '铁路调度员', IT019: '自动化工程师', IT020: '外科医生',
    IT021: '研究科学家', IT022: '城市规划师', IT023: '首席科学家', IT024: '超级工程总监',
};

// ---- 随机事件(v3.2 §9)----
// 后端单一来源是 app/Game/Event/EventCode.php(状态 / 正负)与 database/data/events.json(category)。
// 事件名(name_zh)与各段描述由服务器随事件下发,前端不再存一份 —— 这里只补枚举值

// city_events.status(3)
export const EVENT_STATUS_NAMES = {
    active: '生效中',
    resolved: '已结算',
    expired: '已过期',
};

// event_definition.event_type(2):正向事件直接发资源,负向事件走 event 乘区(§13 分流)
export const EVENT_TYPE_NAMES = {
    positive: '正向',
    negative: '负向',
};

// event_definition.category(19)
export const EVENT_CATEGORY_NAMES = {
    agriculture: '农业',
    disaster: '灾害',
    food: '粮食',
    industry: '工业',
    civil: '民生',
    economy: '经济',
    logistics: '物流',
    market: '市场',
    population: '人口',
    security: '治安',
    defense: '国防',
    technology: '科技',
    energy: '能源',
    npc: '人才',
    governance: '治理',
    construction: '建设',
    resource: '资源',
    trade: '贸易',
    endgame: '终局',
};

// ---- 国防威胁等级(v3.2 §11,后端单一来源 app/Game/Defense/DefenseService::LEVEL_NAMES_ZH)----
// 快照的 defense 块已经带了 threat_level_zh,这张表只作兜底(老响应 / 字段缺失时)
export const THREAT_LEVEL_NAMES = {
    low: '安全',
    medium: '紧张',
    high: '危险',
};

function lookup(table, code) {
    if (!code) return '';
    return table[code] || code;
}

export function categoryName(code) {
    return lookup(BUILDING_CATEGORY_NAMES, code);
}

export function seriesName(code) {
    return lookup(BUILDING_SERIES_NAMES, code);
}

export function costTypeName(code) {
    return lookup(COST_TYPE_NAMES, code);
}

export function resourceCategoryName(code) {
    return lookup(RESOURCE_CATEGORY_NAMES, code);
}

export function techBranchName(code) {
    return lookup(TECH_BRANCH_NAMES, code);
}

export function npcStatusName(code) {
    return lookup(NPC_STATUS_NAMES, code);
}

export function npcRarityName(code) {
    return lookup(NPC_RARITY_NAMES, code);
}

export function npcSkillName(code) {
    return code ? lookup(NPC_SKILL_NAMES, code) : '无主技能';
}

export function itemStatusName(code) {
    return lookup(ITEM_STATUS_NAMES, code);
}

export function itemCategoryName(code) {
    return lookup(ITEM_CATEGORY_NAMES, code);
}

export function itemEffectName(code) {
    return lookup(ITEM_EFFECT_NAMES, code);
}

export function itemTierName(code) {
    return lookup(ITEM_TIER_NAMES, code);
}

export function itemDurabilityModeName(code) {
    return lookup(ITEM_DURABILITY_MODE_NAMES, code);
}

export function itemSourceName(code) {
    return lookup(ITEM_SOURCE_NAMES, code);
}

// 工具显示名:类别(装备对象)。两段都是 §7 的原文,前端不发明工具名。
// 装备对象查不到时只显示类别,类别也查不到时回落 item_id —— 一路兜底,绝不显示空白
export function itemDisplayName(itemId, category) {
    const target = ITEM_EQUIP_TARGET_NAMES[itemId] || '';
    const cat = category ? itemCategoryName(category) : '';
    if (cat && target) return cat + '(' + target + ')';
    return cat || target || itemId || '';
}

// 单件工具的效果描述:「木材产量 +8%」/「国防值 +8」。
// unit 决定百分比还是绝对值(§7 的 percent / flat 两种),数值一律用服务器下发的 effect_value
export function itemEffectText(effectCode, effectValue, unit) {
    const name = effectCode ? itemEffectName(effectCode) : '';
    if (!name) return '';
    const v = Number(effectValue) || 0;
    const sign = v >= 0 ? '+' : '';
    return name + ' ' + sign + v + (unit === 'percent' ? '%' : '');
}

export function eventStatusName(code) {
    return lookup(EVENT_STATUS_NAMES, code);
}

export function eventTypeName(code) {
    return lookup(EVENT_TYPE_NAMES, code);
}

export function eventCategoryName(code) {
    return lookup(EVENT_CATEGORY_NAMES, code);
}

export function threatLevelName(code) {
    return lookup(THREAT_LEVEL_NAMES, code);
}
