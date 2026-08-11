<?php

namespace App\Game\Modifier;

// M3-D0.1 / D0.3:modifier 的 target 名单。
//
// 三类 target 分开登记,谁也不许串门:
//   ① 七乘区(SLOTS)      —— v3.2 §10.11 生产总公式点名的固定七项,**名单不许扩**。
//                            每一格恰好一个 Provider 认领(见 ModifierBus),乘积由
//                            SimulationService::multiplierProduct() 统一夹 §13 帽。
//   ② flat 通道(FLAT_TARGETS)—— 事件 / NPC 特性对「幸福」「治安」的直接冲击(D0.2)。
//                            它不是乘区,是加法通道,所以不进 SLOTS,也不受 §13 帽约束。
//   ③ 非产量 target(CONSUMPTION_POINTS)—— §6 技能 / §7 工具 / §9 事件里作用于建造速度、
//                            维护成本、市场手续费、治理容量、研究速度、事件损失减免的那一半效果。
//                            它们**不进七乘区**(一条产量管线接不住),各自有唯一消费点。
//
// 为什么要有这份名单:M3 的 NPC / 工具 / 市场 / 事件 / 电力 / 国防六个系统都会往内核塞效果。
// 没有名单时,每个系统都会「顺手在 SimulationService 里加一格」,七乘区会在半年内变成十五乘区,
// §13 的硬帽也就名存实亡。有了名单 + Provider 注册制,新系统只新增 Provider 类,不改内核。
final class ModifierTarget
{
    // ---- ① 七乘区(§10.11 生产总公式的固定名单)----
    //
    // 顺序即 SLOTS 的顺序,也是 SimulationService 里 `multipliers` 数组的键顺序。
    // 乘法可交换,顺序不影响结果;固定下来只是为了 diff 与调试时肉眼可比。

    public const SLOT_WORKER = 'worker';        // 用工满足率(人力不足打折)
    public const SLOT_POWER = 'power';          // 电力满足率(按建筑)
    public const SLOT_LOGISTICS = 'logistics';  // 物流满足率(§10.7 运输负载)
    public const SLOT_TECH = 'tech';            // 科技加成(§5 同分支每解锁一条 +2%)
    public const SLOT_NPC = 'npc';              // NPC 加成(按实例,§6.4)
    public const SLOT_TOOL = 'tool';            // 工具加成(按实例,§7 同类取最高)
    public const SLOT_EVENT = 'event';          // 事件加成(§9 持续型 modifier)

    public const SLOTS = [
        self::SLOT_WORKER,
        self::SLOT_POWER,
        self::SLOT_LOGISTICS,
        self::SLOT_TECH,
        self::SLOT_NPC,
        self::SLOT_TOOL,
        self::SLOT_EVENT,
    ];

    // ---- ② flat 通道(D0.2)----
    //
    // 事件对幸福 / 治安的冲击走这里,不占乘区。两条口径(D 区裁决 D4):
    //   duration = 0 的瞬时型 → 改当前值(由事件系统自己在结算时一次性改,不经本通道);
    //   duration > 0 的持续型 → 改**目标值**,由 §10.2 的快落慢升自然收敛 —— 就是本通道。
    // M3 W1 没有任何 Provider 往这两个 target 投稿,总和恒为 0.0(= 接入前的历史行为)。

    public const HAPPINESS_FLAT = 'happiness_flat';  // 进 happinessTarget() 的合成式
    public const SECURITY_FLAT = 'security_flat';    // 进 §10.8 的 security 派生值

    public const FLAT_TARGETS = [
        self::HAPPINESS_FLAT,
        self::SECURITY_FLAT,
    ];

    // ---- ③ 非产量 target 与消费点登记表(D0.3)----
    //
    // 纪律:**每个 target 只有一个消费点**。同一个 target 被两处读取 = 双计,
    // 与 M2 踩过的 governance_bonus / output_json 双口径是同一个坑。
    // 新增非产量效果时,先在这里登记 target 与它唯一的消费点,再去那个消费点接线。

    public const CONSTRUCTION_SPEED_PCT = 'construction_speed_pct';
    public const MAINTENANCE_COST_PCT = 'maintenance_cost_pct';
    public const MARKET_FEE_PCT = 'market_fee_pct';
    public const GOVERNANCE_CAPACITY_PCT = 'governance_capacity_pct';
    public const RESEARCH_SPEED_PCT = 'research_speed_pct';
    public const EVENT_LOSS_REDUCTION_PCT = 'event_loss_reduction_pct';

    // 通用支出通道(M3-D1 W2-A 新增,op 一律 flat,单位是「每分钟」):
    // 系统级的常态开销 —— NPC 工资与口粮是第一个投稿者,D2 工具的维护、D4 事件的持续扣费同理。
    // 做成**通用**两条而不是 npc_wage_* 专用,是为了让内核那唯一一个消费点不必随系统增长
    public const EXPENSE_MONEY_PER_MIN = 'expense_money_per_min';
    public const EXPENSE_FOOD_PER_MIN = 'expense_food_per_min';

    // target => ['consumer' => 唯一消费点, 'wave' => 计划接线的波次, 'desc' => 中文说明]
    //
    // 'consumer' 写到类名一级即可(方法名会随实现调整,写死反而会过期)。
    // **W1-A 只建表不接线**:接线各由对应波次在自己的文件里做,本波次不碰那些文件。
    public const CONSUMPTION_POINTS = [
        self::CONSTRUCTION_SPEED_PCT => [
            'consumer' => 'App\Game\Building\ConstructionService',
            'wave'     => 'W3-A',
            'desc'     => '建造 / 升级工期:工具 IT0xx 与 NPC 建造技能加速施工',
        ],
        self::MAINTENANCE_COST_PCT => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W3-A',
            'desc'     => '建筑维护资金:NPC 技能等级的维护减免(§6.2 maintenance_reduction_cap)',
        ],
        self::MARKET_FEE_PCT => [
            'consumer' => 'App\Game\Market\MarketService',
            'wave'     => 'W2-B',
            'desc'     => '市场成交手续费:商人类 NPC 与贸易工具降低 fee_rate',
        ],
        self::GOVERNANCE_CAPACITY_PCT => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W2-A',
            'desc'     => '全城治理容量:N001 等行政 NPC 的「治理 +10%」类特性',
        ],
        self::RESEARCH_SPEED_PCT => [
            'consumer' => 'App\Game\Technology\TechService',
            'wave'     => 'W2-A',
            'desc'     => '研究时长:学者类 NPC 与研究工具缩短 research_minutes',
        ],
        self::EVENT_LOSS_REDUCTION_PCT => [
            'consumer' => 'App\Game\Event\EventService',
            'wave'     => 'W3-B',
            'desc'     => '负面事件的资源损失减免:防御工具与危机管理特性',
        ],
        // 下面两条的消费点是**内核里唯一一处**为「系统级常态开销」开的口子
        // (用户 2026-08-11 以内核所有者身份对 W2-A 一次性豁免):
        // SimulationService::applyLocked 在分段循环之前一次性取值,资金侧并进全城维护速率、
        // 口粮侧并进人口粮耗基线,循环内零查库。新系统要扣常态开销一律投稿到这两个 target,
        // **不许再往内核里加第二处消费点**。
        self::EXPENSE_MONEY_PER_MIN => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W2-A',
            'desc'     => '系统级常态资金支出(资金/分钟):NPC 工资(§6.3 wage_per_min)是首个投稿者',
        ],
        self::EXPENSE_FOOD_PER_MIN => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W2-A',
            'desc'     => '系统级常态口粮支出(粮食/分钟):NPC 口粮(§6.3 food_per_min)是首个投稿者',
        ],
    ];

    // 全部已登记 target(三类合并),供 ModifierSpec 做 allowlist 校验
    public static function all(): array
    {
        return array_merge(self::SLOTS, self::FLAT_TARGETS, array_keys(self::CONSUMPTION_POINTS));
    }

    public static function isSlot(string $target): bool
    {
        return in_array($target, self::SLOTS, true);
    }
}
