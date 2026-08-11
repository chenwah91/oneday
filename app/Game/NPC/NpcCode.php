<?php

namespace App\Game\NPC;

// NPC 系统的 code 常量与固定映射(v3.2 §6)。
//
// 与 ResourceCode / EnumCode 同一纪律:业务代码里不许再出现 'assigned'、'legendary' 这类字面量,
// 全部从这里取;code 一律英文 snake_case,中文只作为显示文案存在定义表的 *_desc_zh 列。
final class NpcCode
{
    // ---- 运行时状态(city_npcs.status,varchar(16))----
    //
    //   idle     已招募、未派驻(照样发工资吃口粮 —— 这是「辞退」这个动作存在的意义)
    //   assigned 已派驻到某栋建筑实例(assigned_instance_id 非空)
    //   left     已离场(主动辞退 / 士气过低自行离职);行保留只为可追溯,不再参与任何结算
    public const STATUS_IDLE = 'idle';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_LEFT = 'left';

    // 仍在城里的两个状态(结算与工资口径都按它取)
    public const ACTIVE_STATUSES = [self::STATUS_IDLE, self::STATUS_ASSIGNED];

    // ---- 稀有度(npc_definition.rarity,§6.3)----
    //
    // §6.2:「稀有度不直接增加乘区,主要决定初始技能值、特殊特性与招募难度」——
    // 所以它只出现在两处:招募时的服务器权威掷点权重、招募价格系数。绝不进乘区。
    public const RARITY_COMMON = 'common';
    public const RARITY_UNCOMMON = 'uncommon';
    public const RARITY_RARE = 'rare';
    public const RARITY_EPIC = 'epic';
    public const RARITY_LEGENDARY = 'legendary';

    // 由低到高。招募掷点在候选池为空时按这个顺序回退,保证低时代城市也抽得到人
    public const RARITIES = [
        self::RARITY_COMMON,
        self::RARITY_UNCOMMON,
        self::RARITY_RARE,
        self::RARITY_EPIC,
        self::RARITY_LEGENDARY,
    ];

    // ---- 获取来源(npc_definition.recruit_source)----
    //
    // §6.3 的 recruit_desc_zh 是自然语言(「学堂/招募」「兵营训练」「终局事件」…),
    // 原文保留在 recruit_desc_zh 列,这里只留**驱动规则的四个英文 code**:
    //   initial        开局赠送(N001),不可付费招募
    //   natural_growth 自然增长(N002~N005),不可付费招募,由 NpcRuntimeService 按 A1 的窗口掷点
    //   recruit        可付费招募(培训 / 市场吸引 / 学堂 / 兵营 / 行政 / 大学 / 银行 / 研究院 / 科研中心 / 军事设施 / 终局招募)
    //   event          事件产出(N029 终局事件),不可付费招募,等 D4 事件系统发放
    public const SOURCE_INITIAL = 'initial';
    public const SOURCE_NATURAL_GROWTH = 'natural_growth';
    public const SOURCE_RECRUIT = 'recruit';
    public const SOURCE_EVENT = 'event';

    public const SOURCES = [
        self::SOURCE_INITIAL,
        self::SOURCE_NATURAL_GROWTH,
        self::SOURCE_RECRUIT,
        self::SOURCE_EVENT,
    ];

    // ---- 技能 id(§6.1 的 12 条)----
    public const SKILL_GATHERING = 'SKILL_GATHERING';
    public const SKILL_AGRICULTURE = 'SKILL_AGRICULTURE';
    public const SKILL_MINING = 'SKILL_MINING';
    public const SKILL_PROCESSING = 'SKILL_PROCESSING';
    public const SKILL_CONSTRUCTION = 'SKILL_CONSTRUCTION';
    public const SKILL_COMMERCE = 'SKILL_COMMERCE';
    public const SKILL_ADMIN = 'SKILL_ADMIN';
    public const SKILL_RESEARCH = 'SKILL_RESEARCH';
    public const SKILL_MEDICINE = 'SKILL_MEDICINE';
    public const SKILL_ENGINEERING = 'SKILL_ENGINEERING';
    public const SKILL_LOGISTICS = 'SKILL_LOGISTICS';
    public const SKILL_DEFENSE = 'SKILL_DEFENSE';

    // ---- A3:建筑 → 岗位对口技能 ----
    //
    // §6.4 要判定「岗位匹配」,但 building_definition 没有 required_skill 列。
    // backlog §9 A3 批准的口径是**按 category 映射**;这里按项目里实际存在的 12 个 category 落地
    // (A3 原文用的是若干规划中的分类名,与库里的实际取值并不同名,以库里的为准)。
    //
    // 不匹配不是「无效」:§6.4 明文「岗位不匹配 = 主技能加成 × 0.25」,仍有四分之一效果。
    public const REQUIRED_SKILL_BY_CATEGORY = [
        'food_production'          => self::SKILL_AGRICULTURE,
        'raw_material_extraction'  => self::SKILL_MINING,
        'processing'               => self::SKILL_PROCESSING,
        'commerce'                 => self::SKILL_COMMERCE,
        'administration'           => self::SKILL_ADMIN,
        'research_education'       => self::SKILL_RESEARCH,
        'public_service'           => self::SKILL_MEDICINE,
        'energy'                   => self::SKILL_ENGINEERING,
        'transport'                => self::SKILL_LOGISTICS,
        'defense'                  => self::SKILL_DEFENSE,
        // 本次补充假设(A3 没给这两类):
        //   storage 与 transport 同属科技分支 storage_transport,仓储岗归物流技能;
        //   housing 不产任何流量资源(只产 population_capacity,不进 grossOut),
        //     给它挂一个对口技能没有任何数值意义,所以留空 = 一律按不匹配的 ×0.25 计。
        'storage'                  => self::SKILL_LOGISTICS,
        'housing'                  => null,
    ];

    // raw_material_extraction 的 series 细分:采集/伐木(木材、石料)归 SKILL_GATHERING,
    // 真正的矿井/油井归 SKILL_MINING。
    // 本次补充假设:库里没有 gathering 这个 category,不细分的话 §6.1 的 SKILL_GATHERING
    // 会变成一条永远匹配不到岗位的死技能(N002/N003/N004 三个 NPC 直接废掉)。
    public const GATHERING_SERIES = ['wood', 'stone'];

    // 某个建筑(category + series)的对口技能;没有对口技能返回 null
    public static function requiredSkill(?string $category, ?string $seriesKey = null): ?string
    {
        if ($category === 'raw_material_extraction' && $seriesKey !== null && in_array($seriesKey, self::GATHERING_SERIES, true)) {
            return self::SKILL_GATHERING;
        }

        return self::REQUIRED_SKILL_BY_CATEGORY[$category] ?? null;
    }
}
