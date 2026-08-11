<?php

namespace App\Game\Item;

use App\Support\GameSetting;

// 工具 / 道具系统的 code 常量与固定映射(v3.2 §7 + backlog §9 B 区)。
//
// 与 ResourceCode / NpcCode 同一纪律:业务代码里不许再出现 'equipped'、'industrial' 这类字面量,
// 全部从这里取;code 一律英文 snake_case,中文只作为显示文案存在定义表的 *_desc_zh 列。
final class ItemCode
{
    // ---- 运行时状态(city_items.status,varchar(16))----
    //
    //   stored    已制作、未装备(躺在城市仓库里,不扣耐久、不产生任何加成)
    //   equipped  已装备到某栋建筑实例(equipped_instance_id 非空),按「工作分钟」扣耐久
    //   broken    耐久归零后损毁(backlog §9 B4 已批:损毁消失,需重新制作)。
    //             行保留只为可追溯(与 NpcCode::STATUS_LEFT 同一处理),不再参与任何结算
    public const STATUS_STORED = 'stored';
    public const STATUS_EQUIPPED = 'equipped';
    public const STATUS_BROKEN = 'broken';

    // 仍然「在城里、还能用」的两个状态(快照与装备校验按它取)
    public const ACTIVE_STATUSES = [self::STATUS_STORED, self::STATUS_EQUIPPED];

    // ---- 耐久档位(item_definition.durability_tier,§7 + B1)----
    //
    // §7:「普通工具建议每 10 分钟工作消耗 1 点耐久;工业/电子设备每 20 分钟消耗 1 点耐久」。
    // B1 已批的划分口径:20 分钟档 = category ∈ {industrial_tool, engineering_tool, logistics_tool,
    // planning_tool, research_tool(min_era ≥ IX)};其余 10 分钟档。
    //
    // **档位是定义数据,分钟数是运营参数**:档位随 §7 的 category 落在 item_definition 上,
    // 每档几分钟扣 1 点则登记成 game_settings(后台可调)。两者刻意分开,同一个数不会有两个来源。
    public const TIER_NORMAL = 'normal';
    public const TIER_INDUSTRIAL = 'industrial';

    public const TIERS = [self::TIER_NORMAL, self::TIER_INDUSTRIAL];

    // ---- 耐久口径(item_definition.durability_mode)----
    //
    //   work_minutes 按「建筑实际工作的分钟」扣(§7 的主口径)
    //   uses         一次性消耗品:durability 即使用次数(B1 明文的 medical_item),
    //                **不随时间递减** —— 医疗类效果的消费点尚未登记(见 items.json 的 unmapped_zh),
    //                在「使用」这个动作存在之前,它们只会静静躺在城里,绝不会被时间吃掉
    public const DURABILITY_MODE_WORK_MINUTES = 'work_minutes';
    public const DURABILITY_MODE_USES = 'uses';

    public const DURABILITY_MODES = [self::DURABILITY_MODE_WORK_MINUTES, self::DURABILITY_MODE_USES];

    // ---- 获取来源(city_items.acquired_source)----
    //
    //   craft 玩家在城里合成(§7 的 crafting_source_desc_zh 一列全部是「在某栋建筑制作」)
    //   admin 管理员补发(留位:走 ADMIN.COMPENSATION 同款流程,本波次不实现)
    //   event 事件产出(留位:等 D4 事件系统)
    public const SOURCE_CRAFT = 'craft';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_EVENT = 'event';

    public const SOURCES = [self::SOURCE_CRAFT, self::SOURCE_ADMIN, self::SOURCE_EVENT];

    // 某个耐久档「多少分钟扣 1 点」。两个数都后台可调(B1 批准的 10 / 20 是默认值)。
    // 返回值下限 1 分钟:调成 0 会让一次结算把耐久瞬间扣成负数(除零 / 无穷大)
    public static function minutesPerDurabilityPoint(string $tier): float
    {
        $key = $tier === self::TIER_INDUSTRIAL
            ? GameSetting::ITEM_DURABILITY_MINUTES_INDUSTRIAL
            : GameSetting::ITEM_DURABILITY_MINUTES_NORMAL;

        return max(1.0, (float) GameSetting::get($key));
    }
}
