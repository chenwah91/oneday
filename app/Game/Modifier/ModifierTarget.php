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
    public const RESEARCH_SPEED_PCT = 'research_speed_pct';
    public const EVENT_LOSS_REDUCTION_PCT = 'event_loss_reduction_pct';

    // ---- 治理容量两条(M3-W6 清偿,口径镜像国防 flat/pct)----
    //
    // 为什么必须是两条:v3.2 原文里治理效果同样有**两种写法**,与国防一字不差 ——
    //   §6.3 N013「治理+30」/ N051「治理容量+20」/ N111「治理容量+22」→ governance_capacity_flat(op=flat)
    //   §6.3 N001「治理+10%」等 15 位行政 NPC / §7 IT022「治理效率+10%」
    //   / §9.2 EVT_CORRUPTION 选项 B「治理容量暂时-10%」                → governance_capacity_pct(op=pct)
    //
    // W1 登记时只开了 pct 一条,于是出现本项目最典型的一种「死 target」:
    //   ① pct 通道登记了却**没有任何消费点**(W5 核对全仓确认),15 处 pct 投稿一律静默失效;
    //   ② 3 处 flat 投稿为了有地方写,被塞进了 pct 这条 target(op=flat),
    //      而 ConsumptionPoint 只认 op=pct —— 就算 pct 有了消费点也仍然读不到。
    // 也就是说「登记了 ≠ 生效」,而且**投稿口径与 target 口径不符时是静默的**。
    // 拆成两条 + 迁移把 3 处 flat 投稿挪到 flat target,两个毛病一次清干净。
    //
    // 两条的**唯一消费点都是 SimulationService**(D0.3 纪律:一个 target 一个消费点)。
    // 合成顺序与国防逐字相同:
    //     有效治理容量 = max(0, (建筑口径 + Σflat) × (1 + Σpct))
    //
    // 为什么消费点是内核而不是读取侧(与国防相反):治理容量是 §10.5/§10.6 的
    // governanceLoad 的**分母**,内核当场就要用它算 governanceEfficiency → taxIncome。
    // 放读取侧只能改显示值,税收仍按建筑口径算 —— 那就是「HUD 说治理 +30%、税收却没动」的两套真相
    //(与 W5 运输容量必须在内核里乘、而不像国防那样在读取侧叠加,是同一条理由)。
    public const GOVERNANCE_CAPACITY_FLAT = 'governance_capacity_flat';
    public const GOVERNANCE_CAPACITY_PCT = 'governance_capacity_pct';

    // ---- 容量类三条 + 税收 + 市场价格(M3-W5 新增)----
    //
    // 为什么容量类要单独开 target,而不是复用七乘区:容量(运输 / 贸易 / 金融)是**状态量**,
    // 内核在填七乘区**之前**就把它从 output_json 提取成全城值 —— 乘区根本够不着它。
    // 这正是 EVT_ROUTE_BREAK / EVT_PORT_CONGESTION 与 10 个物流 NPC、IT018 一直停用 / 半哑的原因:
    // 数据早就写好了,缺的只是一条 target 与一个消费点。
    //
    // 「铁路容量」按语义并入 transport(§6.3 的 N022 / N074 / N134 写的是「铁路容量+X%」):
    // 项目里没有独立的铁路容量,§10.7 只有一条 transport_capacity,铁路是运输的一种形态。
    // 与其发明第二条 target(还得再发明一套「铁路负载」),不如按语义并入 —— 口径只写这一处。
    public const TRANSPORT_CAPACITY_PCT = 'transport_capacity_pct';
    public const TRADE_CAPACITY_PCT = 'trade_capacity_pct';
    public const FINANCE_CAPACITY_PCT = 'finance_capacity_pct';

    // 税收(§10.5 的 taxIncome):§9.2 里 EVT_CRIME「税收-10%」/ EVT_CORRUPTION「税收-15%」
    // 与 §6.3 N013「税收+8%」都作用于它。**注意它改的是税收本身,不是税率** ——
    // 税率在 M3 仍然固定不可调(§10.5 明文),EVT_TAX_PROTEST 因此继续停用(条件恒不成立)。
    public const TAX_INCOME_PCT = 'tax_income_pct';

    // 市场成交价(§8.1 的「事件乘数」位):EVT_OIL_SHOCK / EVT_SPECULATION 的价格冲击。
    //
    // ⚠️ 口径裁决(本波次定死,理由见 PriceEngine 顶部与 TradeService 的消费点注释):
    //   ① **全服定价不动**:PriceEngine 的价格是全服共享的纯函数,而这两条事件是**城市级**实例,
    //      让一座城市的事件去推全服价格,等于让玩家用自己的事件改别人的行情;
    //   ② 落地成**该城买入侧**的成交价加成(× (1 + pct)),卖出侧不动 ——
    //      两侧同步上抬会让「事件期间抛货、事件结束后买回」变成一台印钞机(§11.2 的反刷四件套同理)。
    //      方向上也自洽:两条都是 negative 事件,惩罚落在「买东西更贵」上。
    // scope 可以是 city(全市场)或 resource(某一种资源),两者相加。
    public const MARKET_PRICE_PCT = 'market_price_pct';

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
            // W7 清偿:消费点从登记时写的 MarketService(该类从未存在)落到**真实存在**的 TradeService。
            // 教训照抄一遍 —— 登记表里的 consumer 只有在真被测试断言过之后才等于事实
            'consumer' => 'App\Game\Market\TradeService',
            'wave'     => 'W7',
            'wired'    => true,
            'desc'     => '市场成交手续费:§6.3 的 7 位商人类 NPC(N046/N065/N086/N099/N114/N127/N146,'
                . 'specs 里是负值 = 减费)降低 fee_rate。'
                . '口径 = 定义表 fee_rate × 全局倍率 × max(0, 1 + Σpct),**买卖两侧共用同一个费率**;'
                . '消费点 TradeService::trade 的第 11 步(城市行锁内取值一次)。'
                . '费率夹到 ≥ 0 —— 负费率会让同窗往返转正,反套利闭式 净额 = −2Pq(s+f\') 靠这个夹子成立',
        ],
        // 治理容量两条(W6 清偿):消费点都是**结算内核的容量提取之后那一处**,与容量类三条同一处。
        // 两条共用同一次 ConsumptionPoint::sumsMany(三查一趟,分段循环之外),
        // op / scope 的判定口径与容量类逐字一致(flat 通道只收 flat、pct 通道只收 pct,只认 scope=city)。
        self::GOVERNANCE_CAPACITY_FLAT => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W6',
            'wired'    => true,
            'desc'     => '全城治理容量的绝对加成:§6.3 的 N013(+30)/ N051(+20)/ N111(+22)三位行政 NPC。'
                . '口径 = max(0, (建筑口径 + Σflat) × (1 + Σpct)),消费点在结算内核的容量提取之后',
        ],
        self::GOVERNANCE_CAPACITY_PCT => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W6',
            'wired'    => true,
            'desc'     => '全城治理容量的百分比加成:§6.3 十五位行政 NPC 的「治理 +X%」(N001/N026/N029/N035/'
                . 'N042/N058/N066/N077/N082/N086/N101/N118/N137/N142/N146)、§7 IT022「治理效率+10%」、'
                . '§9.2 EVT_CORRUPTION 选项 B「治理容量暂时 −10%」。'
                . '作用面 = governanceLoad → governanceEfficiency → taxIncome 与快照的 governance 块;'
                . '**时代门槛不吃它**(EraService 继续读建筑口径,理由见 SimulationService 的消费点注释)',
        ],
        self::RESEARCH_SPEED_PCT => [
            'consumer' => 'App\Game\Technology\TechService',
            'wave'     => 'W7',
            'wired'    => true,
            'desc'     => '研究时长:§6.3 的 6 位学者类 NPC(N048 +8% / N070 +16% / N080 +25% / '
                . 'N106 +8% / N130 +17% / N140 +28%)缩短 research_minutes。'
                . '口径与施工加速逐字一致 = 时长 ÷ (1 + Σpct)(速度口径,不是乘 (1 − pct):'
                . '后者在 Σpct ≥ 1 时会把时长算成 0 或负数),下限夹 TechService::RESEARCH_SPEED_FLOOR。'
                . '消费点 TechService::research 算 finished_at 的那一行 —— **锁内取一次、当场算死**,'
                . '不追溯已在研的项目(v3.2 附录 A.3)',
        ],
        self::EVENT_LOSS_REDUCTION_PCT => [
            'consumer' => 'App\Game\Event\EventService',
            'wave'     => 'W3-B',
            'wired'    => true,
            'desc'     => '负面事件的资源损失减免:防御工具与危机管理特性(N001 等)。'
                . '只减免**自动效果里的库存损失**,不减免选项里玩家自愿掏的钱;'
                . '减免后的比例落进 rolled.loss.pct,「损失减半」类选项按减免后的值继续算,不会双重减免',
        ],
        // 下面两条的消费点是**内核里唯一一处**为「系统级常态开销」开的口子
        // (用户 2026-08-11 以内核所有者身份对 W2-A 一次性豁免):
        // SimulationService::applyLocked 在分段循环之前一次性取值,资金侧并进全城维护速率、
        // 口粮侧并进人口粮耗基线,循环内零查库。新系统要扣常态开销一律投稿到这两个 target,
        // **不许再往内核里加第二处消费点**。
        self::EXPENSE_MONEY_PER_MIN => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W2-A',
            'wired'    => true,
            'desc'     => '系统级常态资金支出(资金/分钟):NPC 工资(§6.3 wage_per_min)是首个投稿者',
        ],
        self::EXPENSE_FOOD_PER_MIN => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W2-A',
            'wired'    => true,
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
        // 容量类三条(W5):消费点全部是**结算内核的容量提取之后那一处**,不是读取侧。
        //
        // 为什么必须在内核里乘、而不像国防那样在读取侧叠加:运输容量是 §10.7 物流乘区的分母,
        // 内核**当场就要用它**(transportLoad → logisticsFactor → 七乘区的 logistics 那一格)。
        // 放到读取侧只能改显示值,乘区仍按原容量算 —— 那就成了「HUD 说降了 30%、产量却没变」的两套真相。
        // 三条共用同一次 ConsumptionPoint::pctMany(三查一趟,分段循环之外),口径与维护费减免逐字一致。
        self::TRANSPORT_CAPACITY_PCT => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W5',
            'wired'    => true,
            'desc'     => '全城运输容量(含「铁路容量」,按语义并入):EVT_ROUTE_BREAK −30% / EVT_PORT_CONGESTION −25%、'
                . '§6.3 十位物流 NPC(N022/N069/N074/N084/N089/N126/N129/N134/N144/N149)与 §7 IT018。'
                . '口径 = 全城 transport_capacity × max(0, 1 + Σpct),乘在**物流负载的分母**上',
        ],
        self::TRADE_CAPACITY_PCT => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W5',
            'wired'    => true,
            'desc'     => '全城贸易容量:EVT_PORT_CONGESTION −25%、EVT_TRADE_BOOM 的「成交量 ±X%」。'
                . '口径 = 全城 trade_capacity × max(0, 1 + Σpct);贸易容量本身是市场单城成交量上限的城市侧分母'
                . '(backlog §5.4,落点 MarketDefinition::cityWindowQuota)',
        ],
        self::FINANCE_CAPACITY_PCT => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W5',
            'wired'    => true,
            'desc'     => '全城金融容量:与贸易容量同一处提取、同一处相乘。'
                . '⚠️ 金融容量目前**只作读数回传**(C03 银行是唯一来源且 §5.4 的金融玩法未定),'
                . '接线在这里是为了「有投稿就一定有人乘」,不是为了发明金融玩法',
        ],
        self::TAX_INCOME_PCT => [
            'consumer' => 'App\Game\Simulation\SimulationService',
            'wave'     => 'W5',
            'wired'    => true,
            'desc'     => '税收(§10.5 taxIncome = 人口 × 人均税额 × 治理效率):EVT_CRIME −10% / EVT_CORRUPTION −15%、'
                . '§6.3 N013「税收+8%」。口径 = 上式 × max(0, 1 + Σpct),消费点在分段循环内的税收那一行'
                . '(取值在循环外取一次)。**改的是税收不是税率** —— 税率仍固定不可调(§10.5)',
        ],
        self::MARKET_PRICE_PCT => [
            'consumer' => 'App\Game\Market\TradeService',
            'wave'     => 'W5',
            'wired'    => true,
            'desc'     => '市场成交价的事件冲击(§8.1 的「事件乘数」位):EVT_OIL_SHOCK +40%(石油/燃料)、'
                . 'EVT_SPECULATION +25%~50%(随机战略资源)。'
                . '口径 = **该城买入侧**成交价 × max(0, 1 + Σpct),全服定价与卖出价一律不动(理由见常量上方)',
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
