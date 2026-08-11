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

    // ---- 国防三条(M3-D5 W4-B 新增)----
    //
    // 为什么是三条而不是一条:v3.2 的原文里国防效果有**三种写法**,少一条就得靠发明语义去凑 ——
    //   §7 IT008「国防值 flat(+8)」/ §6.3 N010「国防值+12」        → defense_score_flat(op=flat)
    //   §6.3 N016「区域国防+15%」/ N027「国防+20%」/ §9.2「临时国防+25%」→ defense_score_pct(op=pct)
    //   §9.2 EVT_BORDER_TENSION「国防需求+30%」                     → threat_demand_pct(op=pct,抬的是分母)
    //
    // 三条的**唯一消费点都是 DefenseService**(D0.3 纪律:一个 target 一个消费点)。
    // 合成顺序固定:有效国防值 = max(0, (建筑口径 + Σflat) × (1 + Σpct));
    //              威胁需求   = §5.1「国防最低」× (1 + Σthreat_demand_pct)。
    //
    // 为什么消费点不是结算内核:defense_score 是容量类产出,内核在乘区之前就把它从 output_json
    // 提取成全城值(SimulationService 的 CAPACITY 提取),那一处**不动**——
    // 本波次与电力波次并行,内核归电力所有者(backlog §10.2 文件所有权互斥)。
    // 所以 flat/pct 一律在**读取侧**由 DefenseService 叠加,内核一个字未改。
    // 已知口径差(交付时点名):§10.8 的 security 覆盖率与 §10.2 的国防幸福加成仍读**建筑口径**,
    // 不含临时加成 —— 内核合并后由所有者在那两处改读 DefenseService::effectiveDefenseScore() 即可接上。
    public const DEFENSE_SCORE_FLAT = 'defense_score_flat';
    public const DEFENSE_SCORE_PCT = 'defense_score_pct';
    public const THREAT_DEMAND_PCT = 'threat_demand_pct';

    // 通用支出通道(M3-D1 W2-A 新增,op 一律 flat,单位是「每分钟」):
    // 系统级的常态开销 —— NPC 工资与口粮是第一个投稿者,D2 工具的维护、D4 事件的持续扣费同理。
    // 做成**通用**两条而不是 npc_wage_* 专用,是为了让内核那唯一一个消费点不必随系统增长
    public const EXPENSE_MONEY_PER_MIN = 'expense_money_per_min';
    public const EXPENSE_FOOD_PER_MIN = 'expense_food_per_min';

    // target => ['consumer' => 唯一消费点, 'wave' => 计划接线的波次, 'desc' => 中文说明,
    //            'wired' => 是否已接线(false = 数据已就位但还没有读取方,效果暂时不生效)]
    //
    // 'consumer' 写到类名一级即可(方法名会随实现调整,写死反而会过期)。
    // **W1-A 只建表不接线**:接线各由对应波次在自己的文件里做,本波次不碰那些文件。
    // 'wired' 由接线的那一波次改成 true —— 后台与测试据此区分「还没接」与「接了但没效果」。
    // 三个来源(事件 modifier / NPC 特性 / 已装备工具)的取数统一走 ConsumptionPoint::pct()
    public const CONSUMPTION_POINTS = [
        self::CONSTRUCTION_SPEED_PCT => [
            'consumer' => 'App\Game\Building\ConstructionService',
            'wave'     => 'W4-A',
            'wired'    => true,
            'desc'     => '建造 / 升级工期:工具 IT005 / IT013 与 NPC N008 / N030 的建造技能加速施工。'
                . '口径 = 工期 ÷ (1 + pct),消费点 ConstructionService::plannedSeconds(建造与升级两处调用)',
        ],
        self::MAINTENANCE_COST_PCT => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W4-A',
            'wired'    => true,
            'desc'     => '建筑维护资金:NPC N017 / N020 与工具 IT016 的维护减免。'
                . '口径 = 全城建筑维护速率 × max(0, 1 + pct),**折扣在前、欠费判定在后**;'
                . 'NPC 工资走 EXPENSE_MONEY_PER_MIN,不吃这个折扣',
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
        // 国防三条(W4-B):消费点全部是 DefenseService,**读取侧**聚合,内核容量提取不动。
        // 三条共用同一次 DefenseService::evaluate() —— 快照的 defense 块、事件条件的 threat_level、
        // EVT_RAID 的损失公式读到的是同一份数,不存在「两处各加一次」的可能。
        //
        // 取数为什么不走 ConsumptionPoint::pct():那是**逐 target 的 pct 专用**入口(三源 × 每 target 三查),
        // 而国防要在同一次读取里同时拿 flat + 两条 pct,且 evaluate() 挂在每次快照上。
        // DefenseService::bonuses() 一趟三查把三条 target 一起捞回来,
        // op / scope 的判定口径与 ConsumptionPoint 逐字一致(只认 scope=city,flat 通道只收 flat、pct 只收 pct)。
        self::DEFENSE_SCORE_FLAT => [
            'consumer' => 'App\Game\Defense\DefenseService',
            'wave'     => 'W4-B',
            'wired'    => true,
            'desc'     => '全城国防值的绝对加成:IT008 防御装备(+8)与 N010 军士(+12)是首批投稿者。'
                . '口径 = (建筑口径 + Σflat) × (1 + Σpct),消费点 DefenseService::evaluate()',
        ],
        self::DEFENSE_SCORE_PCT => [
            'consumer' => 'App\Game\Defense\DefenseService',
            'wave'     => 'W4-B',
            'wired'    => true,
            'desc'     => '全城国防值的百分比加成:N016(+15%)/ N027(+20%)特性与 EVT_RAID 动员守军(+25%)',
        ],
        self::THREAT_DEMAND_PCT => [
            'consumer' => 'App\Game\Defense\DefenseService',
            'wave'     => 'W4-B',
            'wired'    => true,
            'desc'     => '威胁需求(§5.1「国防最低」)的百分比抬升:EVT_BORDER_TENSION 的「国防需求+30%」。'
                . '口径 = §5.1 国防最低 × 全局倍率 × (1 + Σpct),抬的是覆盖率的分母',
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
