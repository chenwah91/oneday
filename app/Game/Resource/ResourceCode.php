<?php

namespace App\Game\Resource;

// 资源 code 常量:全项目引用资源的唯一入口,禁止再在业务代码里写资源名字面量。
//
// code 取值以 docs/templates/v3.2.md §0.2.1「Canonical resource codes」权威表为准。
//
// 设计约定(见 docs/templates/resource-code-map.md):
//   - 主键/JSON 键一律用这里的英文 code(snake_case),中文名只作为显示名存在 resource_definition.name;
//   - 容量类"资源"不是库存资源(不进 city_resources、不在 resource_definition),
//     但会出现在 building_level_definition.output_json 里,所以同样在这里定义;
//   - CHINESE_NAMES 是「code → 中文显示名」的单一来源,数据迁移的反向映射也从这里取,
//     不要在别处再抄一份。
class ResourceCode
{
    // ---- 库存资源(31 种,与 database/data/resources.json 一一对应) ----

    public const MONEY = 'money';                            // 资金
    public const WOOD = 'wood';                              // 木材
    public const BERRIES = 'berries';                        // 浆果
    public const FUEL = 'fuel';                              // 燃料
    public const STONE = 'stone';                            // 石料
    public const FLOUR = 'flour';                            // 面粉
    public const FOOD = 'food';                              // 粮食
    public const BREAD = 'bread';                            // 面包
    public const COPPER = 'copper';                          // 铜
    public const TIN = 'tin';                                // 锡
    public const CLAY = 'clay';                              // 黏土
    public const BRONZE = 'bronze';                          // 青铜
    public const BRICK = 'brick';                            // 砖
    public const KNOWLEDGE = 'knowledge';                    // 知识
    public const IRON = 'iron';                              // 铁
    public const COAL = 'coal';                              // 煤炭
    public const IRON_TOOLS = 'iron_tools';                  // 铁制工具
    public const SAND_GRAVEL = 'sand_gravel';                // 砂石
    public const GLASS = 'glass';                            // 玻璃
    public const STEEL = 'steel';                            // 钢铁
    public const MACHINERY = 'machinery';                    // 机械
    public const ELECTRICITY = 'electricity';                // 电力
    public const OIL = 'oil';                                // 石油
    public const CEMENT = 'cement';                          // 水泥
    public const ELECTRONIC_COMPONENTS = 'electronic_components'; // 电子元件
    public const PLASTIC = 'plastic';                        // 塑料
    public const PROCESSED_FOOD = 'processed_food';          // 加工食品
    public const MEDICINE = 'medicine';                      // 药品
    public const RARE_METALS = 'rare_metals';                // 稀有金属
    public const ADVANCED_MATERIALS = 'advanced_materials';  // 先进材料
    public const HIGH_QUALITY_FOOD = 'high_quality_food';      // 高品质粮食

    // ---- 容量类产出(8 种,不是库存资源) ----

    public const POPULATION_CAPACITY = 'population_capacity'; // 人口容量
    public const STORAGE_CAPACITY = 'storage_capacity';       // 仓储容量
    public const GOVERNANCE_CAPACITY = 'governance_capacity'; // 治理容量
    public const TRANSPORT_CAPACITY = 'transport_capacity';   // 运输容量
    public const DEFENSE_SCORE = 'defense_score';             // 国防值
    public const TRADE_CAPACITY = 'trade_capacity';           // 贸易容量
    public const FINANCE_CAPACITY = 'finance_capacity';       // 金融容量
    public const MEDICAL_CAPACITY = 'medical_capacity';       // 医疗容量

    // 容量类产出清单(取代原 SimConstants::CAPACITY_OUTPUTS 的语义)
    public const CAPACITY = [
        self::POPULATION_CAPACITY,
        self::STORAGE_CAPACITY,
        self::GOVERNANCE_CAPACITY,
        self::TRANSPORT_CAPACITY,
        self::DEFENSE_SCORE,
        self::TRADE_CAPACITY,
        self::FINANCE_CAPACITY,
        self::MEDICAL_CAPACITY,
    ];

    // code → 中文显示名(库存资源 + 容量类,共 39 条)
    // 数据迁移用它反推「中文 → code」映射,前端显示名以 /api/definitions/resources 为准
    public const CHINESE_NAMES = [
        self::MONEY              => '资金',
        self::WOOD               => '木材',
        self::BERRIES            => '浆果',
        self::FUEL               => '燃料',
        self::STONE              => '石料',
        self::FLOUR              => '面粉',
        self::FOOD               => '粮食',
        self::BREAD              => '面包',
        self::COPPER             => '铜',
        self::TIN                => '锡',
        self::CLAY               => '黏土',
        self::BRONZE             => '青铜',
        self::BRICK              => '砖',
        self::KNOWLEDGE          => '知识',
        self::IRON               => '铁',
        self::COAL               => '煤炭',
        self::IRON_TOOLS         => '铁制工具',
        self::SAND_GRAVEL        => '砂石',
        self::GLASS              => '玻璃',
        self::STEEL              => '钢铁',
        self::MACHINERY          => '机械',
        self::ELECTRICITY        => '电力',
        self::OIL                => '石油',
        self::CEMENT             => '水泥',
        self::ELECTRONIC_COMPONENTS => '电子元件',
        self::PLASTIC            => '塑料',
        self::PROCESSED_FOOD     => '加工食品',
        self::MEDICINE           => '药品',
        self::RARE_METALS        => '稀有金属',
        self::ADVANCED_MATERIALS => '先进材料',
        self::HIGH_QUALITY_FOOD  => '高品质粮食',

        self::POPULATION_CAPACITY => '人口容量',
        self::STORAGE_CAPACITY    => '仓储容量',
        self::GOVERNANCE_CAPACITY => '治理容量',
        self::TRANSPORT_CAPACITY  => '运输容量',
        self::DEFENSE_SCORE       => '国防值',
        self::TRADE_CAPACITY      => '贸易容量',
        self::FINANCE_CAPACITY    => '金融容量',
        self::MEDICAL_CAPACITY    => '医疗容量',
    ];

    // 中文名 → code(CHINESE_NAMES 的反表,数据迁移与一次性脚本用)
    public static function chineseToCode(): array
    {
        return array_flip(self::CHINESE_NAMES);
    }

    // 是否容量类产出
    public static function isCapacity(string $code): bool
    {
        return in_array($code, self::CAPACITY, true);
    }
}
