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
