<?php

namespace App\Game\Definition;

// 定义表枚举值的英文 code 单一来源:建筑类别 / 建筑系列 / 等级成本类型 / 资源类别 / 科技分支。
//
// 依据 docs/templates/v3.2.md §0.2「Canonical English Game Data Standard」:
// 凡是程序读写、比较、索引、持久化的值一律英文,中文只作为显示名。
//
// 设计约定(见 docs/templates/enum-code-map.md):
//   - 每张表都是「英文 code => 中文显示名」,中文显示名同时也是旧数据里的历史值,
//     数据迁移用 array_flip 得到「中文 => code」的正向映射,不要在别处再抄一份;
//   - 各列是独立命名空间:类别 storage 与系列 storage 不冲突,科技分支 defense 与类别 defense 也不冲突;
//   - 前端显示名在 public/game/js/core/enum-names.js,改这里必须同步改那边与映射文档。
class EnumCode
{
    // ---- building_definition.category(12) ----

    public const BUILDING_CATEGORIES = [
        'housing'                 => '居住',
        'food_production'         => '粮食生产',
        'storage'                 => '仓储',
        'administration'          => '行政',
        'defense'                 => '国防',
        'transport'               => '运输',
        'raw_material_extraction' => '原料采集',
        'processing'              => '加工',
        'energy'                  => '能源',
        'commerce'                => '商贸',
        'research_education'      => '科研教育',
        'public_service'          => '公共服务',
    ];

    // ---- building_definition.series_key(29) ----

    public const BUILDING_SERIES = [
        'residence'                    => '住宅',
        'agriculture'                  => '农业',
        'storage'                      => '仓储',
        'governance'                   => '治理',
        'city_defense'                 => '城防',
        'land_transport'               => '陆路运输',
        'wood'                         => '木材',
        'stone'                        => '石料',
        'copper_mine'                  => '铜矿',
        'tin_mine'                     => '锡矿',
        'iron_mine'                    => '铁矿',
        'coal_mine'                    => '煤矿',
        'oil'                          => '石油',
        'rare_metals'                  => '稀有金属',
        'grain_processing'             => '粮食加工',
        'metal_processing'             => '金属加工',
        'building_material_processing' => '建材加工',
        'machinery_manufacturing'      => '机械制造',
        'food_processing'              => '食品加工',
        'petrochemical_processing'     => '石化加工',
        'high_tech'                    => '高科技',
        'basic_energy'                 => '基础能源',
        'electricity'                  => '电力',
        'market'                       => '市场',
        'finance'                      => '金融',
        'global_trade'                 => '全球贸易',
        'education'                    => '教育',
        'research'                     => '科研',
        'medical'                      => '医疗',
    ];

    // ---- building_level_definition.cost_type(3) ----

    public const COST_TYPE_BUILD = 'build';
    public const COST_TYPE_UPGRADE_L1_L2 = 'upgrade_l1_l2';
    public const COST_TYPE_UPGRADE_L2_L3 = 'upgrade_l2_l3';

    public const COST_TYPES = [
        self::COST_TYPE_BUILD         => '建造',
        self::COST_TYPE_UPGRADE_L1_L2 => 'L1→L2升级',
        self::COST_TYPE_UPGRADE_L2_L3 => 'L2→L3升级',
    ];

    // ---- resource_definition.category(6,v3.2 §0.2.1 固定项) ----

    public const RESOURCE_CATEGORIES = [
        'raw_material'   => '原料',
        'currency'       => '货币',
        'knowledge'      => '知识',
        'food'           => '食物',
        'energy'         => '能源',
        'processed_good' => '加工品',
    ];

    // ---- technology_definition.branch(5,v3.2 §0.2.1 固定项) ----

    public const TECH_BRANCHES = [
        'survival_agriculture'     => '生存/农业',
        'industry_processing'      => '工业/加工',
        'governance_science_trade' => '治理/科研/商贸',
        'storage_transport'        => '仓储/运输',
        'defense'                  => '国防',
    ];

    // 表名 => 「英文 code => 中文」,迁移与测试按这个清单逐列处理
    // 每项:[表名, 列名, 映射表]
    public const COLUMNS = [
        ['building_definition', 'category', self::BUILDING_CATEGORIES],
        ['building_definition', 'series_key', self::BUILDING_SERIES],
        ['building_level_definition', 'cost_type', self::COST_TYPES],
        ['resource_definition', 'category', self::RESOURCE_CATEGORIES],
        ['technology_definition', 'branch', self::TECH_BRANCHES],
    ];

    // 中文 => code(某一列的反表,数据迁移与一次性脚本用)
    public static function chineseTo(array $codeToChinese): array
    {
        return array_flip($codeToChinese);
    }
}
