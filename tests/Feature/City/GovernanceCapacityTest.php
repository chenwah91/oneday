<?php

namespace Tests\Feature\City;

use App\Game\City\EraService;
use App\Game\Item\ItemCode;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcCode;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Event\EventTestCase;

// M3-W6:治理容量死 target 清偿(governance_capacity_flat + governance_capacity_pct)。
//
// 这一波清的是本项目最典型的一种缺陷 ——「登记了 ≠ 生效」:
//   ① pct 通道登记在册却没有任何消费点 → 15 位行政 NPC 与 IT022 的治理加成静默失效;
//   ② 3 位写 op=flat 的 NPC(N013 / N051 / N111)被塞进 pct 这条 target → 连 pct 接了消费点也读不到。
// 两个毛病都是**静默**的:数值不会报错,只会悄悄不对。所以用例分四层:
//   ① 消费点层:三个来源(事件 modifier / 在编 NPC / 已装备工具)各验一遍,漏一个 = 那类投稿静默失效;
//   ② 合成顺序层:(建筑口径 + Σflat) × (1 + Σpct) —— 顺序写反会得到另一个数,用例直接钉死;
//   ③ 作用面层:黄金样本用**精确值**验 governanceLoad → governanceEfficiency → taxIncome 的整条链;
//   ④ 假失败层:口径不符的投稿必须**整条跳过**(那正是①②两个毛病的根),时代门槛必须继续读建筑口径。
//
// 常用素材:
//   A01 行政所 L1 治理容量 80 / 工人 5 / 维护资金 7      A01 L2 治理容量 108
//   N001 领袖(治理 +10% pct,工资 0)                    N013 行政(治理 +30 flat,税收 +8%,工资 8)
//   N051 行政(治理容量 +20 flat,工资 8)                N111 行政(治理容量 +22 flat,工资 7)
//   IT022 城市规划工具(治理效率 +10% pct)
class GovernanceCapacityTest extends EventTestCase
{
    use RefreshDatabase;

    // A01 行政所 L1 的治理容量(building_levels.json;容量类产出在乘区之前提取,不派工也计)
    private const A01_GOVERNANCE = 80.0;

    // 默认关掉**全部**事件:本文件验的是消费点与合成口径,一条随机抽中的 EVT_CORRUPTION
    // 会往同一条 governance_capacity_pct / tax_income_pct 上再投一份,把精确值断言算歪
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('event_definition')->update(['enabled' => false]);
        \App\Game\Event\EventDefinition::flush();
    }

    // ---------- ① 消费点层:三个来源各验一遍 ----------

    // 接线之前:A01 的 80 就是全部,治理加成一律不生效(这条同时是「建筑口径」的基准)
    public function test_building_capacity_is_the_base(): void
    {
        [$city] = $this->makeCity('govbase', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');

        $sim = $this->runSettle($city, 10);

        $this->assertEqualsWithDelta(self::A01_GOVERNANCE, $sim['governanceCapacity'], 1e-6);
        // 没有任何投稿 → 有效值 === 建筑口径,flat / pct 都是 0
        $this->assertEqualsWithDelta(self::A01_GOVERNANCE, $sim['governanceCapacityEffective'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacityFlat'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacityPct'], 1e-6);
    }

    // 来源①:事件写下的持续型 modifier(EVT_CORRUPTION 选项 B 就是这条路径)
    public function test_pct_from_event_modifier(): void
    {
        [$city] = $this->makeCity('govevt', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');
        $this->addModifier($city, ModifierTarget::GOVERNANCE_CAPACITY_PCT, ModifierSpec::OP_PCT, -0.10);

        $sim = $this->runSettle($city, 10);

        // 80 × (1 − 0.10) = 72
        $this->assertEqualsWithDelta(-0.10, $sim['governanceCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(72.0, $sim['governanceCapacityEffective'], 1e-6);
        // 建筑口径不受影响 —— 时代门槛读的就是它
        $this->assertEqualsWithDelta(80.0, $sim['governanceCapacity'], 1e-6);
    }

    // 来源②:在编 NPC 的特性。N051 是本波次从 pct target 迁到 flat target 的三位之一
    public function test_flat_from_npc_trait(): void
    {
        [$city] = $this->makeCity('govnpc', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N051'); // 治理容量 +20(flat)

        $sim = $this->runSettle($city, 10);

        $this->assertEqualsWithDelta(20.0, $sim['governanceCapacityFlat'], 1e-6);
        $this->assertEqualsWithDelta(100.0, $sim['governanceCapacityEffective'], 1e-6);
    }

    // 来源③:已装备且耐久 > 0 的工具(IT022「治理效率 +10%」,W1 起就写好了 spec、一直没有消费点)
    public function test_pct_from_equipped_item(): void
    {
        [$city] = $this->makeCity('govitem', ['population' => 40, 'era_order' => 1]);
        $instanceId = $this->addBuilding($city, 'A01');
        $this->addItem($city, 'IT022', ItemCode::STATUS_EQUIPPED, $instanceId);

        $sim = $this->runSettle($city, 10);

        // 80 × 1.10 = 88
        $this->assertEqualsWithDelta(0.10, $sim['governanceCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(88.0, $sim['governanceCapacityEffective'], 1e-6);
    }

    // ---------- ② 合成顺序层 ----------

    // 顺序固定:先加 flat 再乘 pct。写反(先乘再加)会得到 118 而不是 121 —— 这条用例就是拿来防写反的
    public function test_flat_applies_before_pct(): void
    {
        [$city] = $this->makeCity('govorder', ['population' => 40, 'era_order' => 4]);
        $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N013'); // 治理 +30(flat)+ 税收 +8%
        $this->addNpc($city, 'N001'); // 治理 +10%(pct)

        $sim = $this->runSettle($city, 10);

        $this->assertEqualsWithDelta(30.0, $sim['governanceCapacityFlat'], 1e-6);
        $this->assertEqualsWithDelta(0.10, $sim['governanceCapacityPct'], 1e-6);
        // (80 + 30) × 1.10 = 121;顺序写反 = 80 × 1.10 + 30 = 118
        $this->assertEqualsWithDelta(121.0, $sim['governanceCapacityEffective'], 1e-6, '顺序必须是「先加 flat 再乘 pct」');
    }

    // 三个来源同时在场:各数一次,不重不漏
    public function test_all_three_sources_compose_once(): void
    {
        [$city] = $this->makeCity('govall', ['population' => 40, 'era_order' => 4]);
        $instanceId = $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N051');                                     // flat +20
        $this->addItem($city, 'IT022', ItemCode::STATUS_EQUIPPED, $instanceId); // pct +0.10
        $this->addModifier($city, ModifierTarget::GOVERNANCE_CAPACITY_PCT, ModifierSpec::OP_PCT, 0.05);

        $sim = $this->runSettle($city, 10);

        $this->assertEqualsWithDelta(20.0, $sim['governanceCapacityFlat'], 1e-6);
        $this->assertEqualsWithDelta(0.15, $sim['governanceCapacityPct'], 1e-6);
        // (80 + 20) × 1.15 = 115
        $this->assertEqualsWithDelta(115.0, $sim['governanceCapacityEffective'], 1e-6);
    }

    // 下限夹 0:后台 / 事件把 pct 填成大负数只会让治理容量归零,绝不出现负容量
    // (负容量会让 governanceLoad 变负,四档判定整个失去意义)
    public function test_effective_capacity_is_clamped_at_zero(): void
    {
        [$city] = $this->makeCity('govclamp', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');
        $this->addModifier($city, ModifierTarget::GOVERNANCE_CAPACITY_PCT, ModifierSpec::OP_PCT, -2.0);

        $sim = $this->runSettle($city, 10);

        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacityEffective'], 1e-6);
        // 容量 0 → 负载 = 人口 / max(1, 0) = 40 > 1.25 → 效率 0.50(崩溃档),不是负数、不是除零
        $this->assertEqualsWithDelta(40.0, $sim['governanceLoad'], 1e-6);
        $this->assertEqualsWithDelta(0.50, $sim['governanceEfficiency'], 1e-6);
    }

    // ---------- ③ 作用面层:黄金样本(精确值) ----------

    // **黄金样本 1** —— N001「治理 +10%」派驻前后的 load / efficiency / taxIncome / 资金精确值。
    //
    // 城市:A01 ×1(治理容量 80,维护 7/min)、人口 70、时代 I(人均税额 0.02)、资金 10000。
    // 刻意不摆住宅:人口容量 0 → housingFactor 0 → 整段人口恒定,税基与负载才干净。
    // N001 工资 0/min(§6.3 初始领袖)→ 维护速率不受招募影响,资金差额只来自税收。
    //
    //   派驻前:容量 80        → 负载 70/80 = 0.875 ∈ (0.80, 1.00] → 效率 0.90
    //           税收 = 70 × 0.02 × 0.90 = 1.26/min
    //           资金 = 10000 + 1.26×10 − 7×10 = 9942.6
    //   派驻后:容量 (80+0)×1.10 = 88 → 负载 70/88 = 0.7954545… ≤ 0.80 → 效率 1.00(升一档)
    //           税收 = 70 × 0.02 × 1.00 = 1.40/min
    //           资金 = 10000 + 1.40×10 − 7×10 = 9944.0
    //
    // 差额 1.4 元不大,但它证明的是**整条链**:target → 消费点 → 负载 → 四档效率 → 税收 → 落库资金。
    // 清偿之前这条链在 N001 那一段是断的(容量恒 80,两次结果完全一样)
    public function test_golden_sample_n001_before_and_after_assignment(): void
    {
        // ---- 派驻前 ----
        [$before] = $this->makeCity('govg1a', ['population' => 70, 'era_order' => 1, 'money' => 10000]);
        $this->addBuilding($before, 'A01');
        $this->setResource($before, 'food', 500);

        $simBefore = $this->runSettle($before, 10);

        $this->assertEqualsWithDelta(80.0, $simBefore['governanceCapacityEffective'], 1e-6);
        $this->assertEqualsWithDelta(0.875, $simBefore['governanceLoad'], 1e-9);
        $this->assertEqualsWithDelta(0.90, $simBefore['governanceEfficiency'], 1e-9);
        $this->assertEqualsWithDelta(1.26, $simBefore['taxIncomePerMin'], 1e-9);
        $this->assertEqualsWithDelta(9942.6, $this->moneyOf($before), 1e-6);

        // ---- 派驻后(同样的城市配置,多一位 N001)----
        [$after] = $this->makeCity('govg1b', ['population' => 70, 'era_order' => 1, 'money' => 10000]);
        $this->addBuilding($after, 'A01');
        $this->setResource($after, 'food', 500);
        $this->addNpc($after, 'N001', NpcCode::STATUS_IDLE);

        $simAfter = $this->runSettle($after, 10);

        $this->assertEqualsWithDelta(88.0, $simAfter['governanceCapacityEffective'], 1e-6);
        $this->assertEqualsWithDelta(70.0 / 88.0, $simAfter['governanceLoad'], 1e-9);
        $this->assertEqualsWithDelta(1.00, $simAfter['governanceEfficiency'], 1e-9);
        $this->assertEqualsWithDelta(1.40, $simAfter['taxIncomePerMin'], 1e-9);
        $this->assertEqualsWithDelta(9944.0, $this->moneyOf($after), 1e-6);

        // 建筑口径两边都是 80 —— 涨的是有效值,不是建筑
        $this->assertEqualsWithDelta(80.0, $simBefore['governanceCapacity'], 1e-6);
        $this->assertEqualsWithDelta(80.0, $simAfter['governanceCapacity'], 1e-6);
    }

    // **黄金样本 2** —— N013「治理 +30 / 税收 +8%」:同一位 NPC 同时投稿两条 target,
    // 一条走 governance_capacity_flat(W6 新增)、一条走 tax_income_pct(W5 已接)。
    //
    // 城市:A01 ×1(80)、人口 110、时代 IV(人均税额 0.02 × 1.5³ = 0.0675)、资金 10000。
    //   派驻前:容量 80  → 负载 110/80 = 1.375 > 1.25 → 效率 0.50
    //           税收 = 110 × 0.0675 × 0.50 = 3.7125/min
    //   派驻后:容量 110 → 负载 110/110 = 1.00 ≤ 1.00 → 效率 0.90(跳两档)
    //           税收 = 110 × 0.0675 × 0.90 × 1.08 = 7.21710/min
    // 资金侧要扣 N013 的工资 8/min(走总线 EXPENSE_MONEY_PER_MIN,并进全城维护速率):
    //           派驻前 10000 + 3.7125×10 − 7×10        = 9967.125 → 落库 9967.13
    //           派驻后 10000 + 7.2171×10 − (7+8)×10    = 9922.171 → 落库 9922.17
    //          (cities.money 是两位小数的 DECIMAL,第三位由数据库四舍五入 —— 断言写落库值)
    // 注意方向:招这一位反而更亏钱 —— 治理加成在这个人口规模下抵不过 8/min 的工资,
    // 这正是 §6.3 工资口径想要的取舍,用例把它钉成明确数字而不是"大概变多"
    public function test_golden_sample_n013_flat_and_tax_pct_together(): void
    {
        [$before] = $this->makeCity('govg2a', ['population' => 110, 'era_order' => 4, 'money' => 10000]);
        $this->addBuilding($before, 'A01');
        $this->setResource($before, 'food', 800);

        $simBefore = $this->runSettle($before, 10);

        $this->assertEqualsWithDelta(1.375, $simBefore['governanceLoad'], 1e-9);
        $this->assertEqualsWithDelta(0.50, $simBefore['governanceEfficiency'], 1e-9);
        $this->assertEqualsWithDelta(3.7125, $simBefore['taxIncomePerMin'], 1e-9);
        $this->assertEqualsWithDelta(9967.13, $this->moneyOf($before), 1e-6);

        [$after] = $this->makeCity('govg2b', ['population' => 110, 'era_order' => 4, 'money' => 10000]);
        $this->addBuilding($after, 'A01');
        $this->setResource($after, 'food', 800);
        $this->addNpc($after, 'N013', NpcCode::STATUS_IDLE);

        $simAfter = $this->runSettle($after, 10);

        $this->assertEqualsWithDelta(110.0, $simAfter['governanceCapacityEffective'], 1e-6);
        $this->assertEqualsWithDelta(1.0, $simAfter['governanceLoad'], 1e-9);
        $this->assertEqualsWithDelta(0.90, $simAfter['governanceEfficiency'], 1e-9);
        $this->assertEqualsWithDelta(0.08, $simAfter['taxIncomePct'], 1e-9);
        $this->assertEqualsWithDelta(7.2171, $simAfter['taxIncomePerMin'], 1e-9);
        $this->assertEqualsWithDelta(15.0, $simAfter['maintenanceMoneyPerMin'], 1e-9, 'A01 维护 7 + N013 工资 8');
        $this->assertEqualsWithDelta(9922.17, $this->moneyOf($after), 1e-6);
    }

    // 快照的 governance 块:capacity 给有效值、capacity_base 给建筑口径(与 defense 块同构)
    public function test_snapshot_exposes_governance_block(): void
    {
        [$city, $user] = $this->makeCity('govsnap', ['population' => 40, 'era_order' => 4]);
        $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N051'); // flat +20
        $this->addNpc($city, 'N001'); // pct +10%

        $res = $this->actingAs($user)->getJson('/api/city');
        $res->assertOk();

        $gov = $res->json('data.city.governance');
        // (80 + 20) × 1.10 = 110
        $this->assertEqualsWithDelta(110.0, (float) $gov['capacity'], 1e-6);
        $this->assertEqualsWithDelta(80.0, (float) $gov['capacity_base'], 1e-6);
        $this->assertEqualsWithDelta(20.0, (float) $gov['flat'], 1e-6);
        $this->assertEqualsWithDelta(0.10, (float) $gov['pct'], 1e-6);
        // 负载用的是有效值:40 / 110
        $this->assertEqualsWithDelta(40.0 / 110.0, (float) $gov['load'], 1e-9);
    }

    // ---------- ④ 假失败层 ----------

    // **假失败 1** —— 时代门槛必须继续读**建筑口径**,不吃临时加成。
    //
    // 时代 II → III 要求治理容量 120。A01 ×1 建筑口径 80(不达标),
    // 但 N013(+30)+ N001(+10%)把有效值抬到 121(达标线以上)。
    // 门槛若误读有效值,玩家就能靠「招两个人升代、升完就辞退」白嫖时代 ——
    // 与 DefenseThreatTest 的 era gate 用例是同一条纪律
    public function test_era_gate_reads_building_capacity_not_effective(): void
    {
        [$city] = $this->makeCity('govera', ['population' => 40, 'era_order' => 2]);
        $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N013'); // flat +30
        $this->addNpc($city, 'N001'); // pct +10%

        $sim = $this->runSettle($city, 10);

        // 有效值确实过线了(121 >= 120)
        $this->assertEqualsWithDelta(121.0, $sim['governanceCapacityEffective'], 1e-6);

        $rows = EraService::evaluate((int) $city->id, 2, $sim);
        $governance = collect($rows)->firstWhere('dimension', EraService::DIM_GOVERNANCE);

        $this->assertSame(120.0, $governance['required']);
        $this->assertEqualsWithDelta(80.0, $governance['current'], 1e-6, '时代门槛读建筑口径');
        $this->assertFalse($governance['met'], '临时加成不得顶过升代门槛');
    }

    // **假失败 2** —— op 与 target 口径不符的投稿必须**整条跳过**,不许猜语义。
    //
    // 这正是清偿前的病根:N013 写的是 op=flat,却挂在 governance_capacity_pct 上 ——
    // 那时它既进不了 pct 通道(只收 op=pct),也没有 flat 通道可进,于是静默失效。
    // 清偿之后两条通道各守各的口径:
    //   pct target + op=flat  → 跳过(否则 30 会被当成 +3000% 读进来)
    //   flat target + op=pct  → 跳过(否则 0.10 会被当成 +0.1 点容量)
    public function test_mismatched_op_rows_are_ignored(): void
    {
        [$city] = $this->makeCity('govmis', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');
        // 口径写反的两行:一行 pct target 配 flat op,一行 flat target 配 pct op
        $this->addModifier($city, ModifierTarget::GOVERNANCE_CAPACITY_PCT, ModifierSpec::OP_FLAT, 30.0);
        $this->addModifier($city, ModifierTarget::GOVERNANCE_CAPACITY_FLAT, ModifierSpec::OP_PCT, 0.10);

        $sim = $this->runSettle($city, 10);

        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacityFlat'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(80.0, $sim['governanceCapacityEffective'], 1e-6, '口径不符的行一条都不许生效');
    }

    // **假失败 3** —— 已过期 / 尚未开始的 modifier 不计入(与国防、容量类三条同一条时间窗口口径)
    public function test_expired_modifier_is_not_counted(): void
    {
        [$city] = $this->makeCity('govexp', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');

        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => ModifierTarget::GOVERNANCE_CAPACITY_PCT, 'scope' => 'city', 'scope_key' => null,
            'op' => ModifierSpec::OP_PCT, 'value' => 0.50,
            'starts_at' => now()->copy()->subHours(2),
            'ends_at'   => now()->copy()->subHour(), // 一小时前就过期了
            'created_at' => now(),
        ]);

        $sim = $this->runSettle($city, 10);

        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(80.0, $sim['governanceCapacityEffective'], 1e-6);
    }

    // 未在编的 NPC(已离职)与未装备 / 已损毁的工具都不投稿 —— 与 ConsumptionPoint 其余 target 同口径
    public function test_inactive_npc_and_unequipped_item_do_not_count(): void
    {
        [$city] = $this->makeCity('govidle', ['population' => 40, 'era_order' => 4]);
        $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N051', NpcCode::STATUS_LEFT);
        $this->addItem($city, 'IT022', ItemCode::STATUS_STORED, null);

        $sim = $this->runSettle($city, 10);

        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacityFlat'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(80.0, $sim['governanceCapacityEffective'], 1e-6);
    }

    // ---------- 复活层:EVT_CORRUPTION 选项 B 的治理减益真的落地 ----------

    // W6 之前这一条写在 unmapped_zh 里(「治理容量暂时-10%」没有消费点,接线要连 flat 通道一起设计)。
    // 现在它是一条可执行 modifier:选 B 之后写一行 governance_capacity_pct,
    // 下一次结算的有效治理容量与税收立刻跟着掉。整条链走真实事件引擎,不是夹具。
    //
    // W10(用户 2026-08-12 拍板):数值由 −10% 改为 **−5%** —— 原文是「事件期 −10% +
    // 事后 +5% 持续 30 分钟」,事后补偿那一半没有延迟起效的 kind 可挂,净额折算为当期 −5%
    // (照 EVT_PORT_CONGESTION 选项 B 的折算先例,好处与代价两侧一起落地)。
    public function test_corruption_option_b_writes_governance_modifier(): void
    {
        // 治理负载 > 0.80 是 EVT_CORRUPTION 的触发条件:不摆 A01,容量 0 → 负载 = 人口,必然成立
        [$city] = $this->makeCity('govcorr', ['era_order' => 5, 'population' => 200]);
        $this->addBuilding($city, 'A01'); // 容量 80,人口 200 → 负载 2.5 > 0.80
        $this->onlyEnable('EVT_CORRUPTION');

        $before = $this->runSettle($city, 1);
        $this->assertEqualsWithDelta(80.0, $before['governanceCapacityEffective'], 1e-6);

        $instance = $this->activeInstances($city)->first();
        $this->assertNotNull($instance, 'EVT_CORRUPTION 必须能触发(条件:治理负载 > 0.80)');

        \App\Game\Event\EventService::resolve($city->fresh(), (int) $instance->id, 'b', null, null);

        // 选项 B 写下的那一行:target / op / scope 三项都要对得上,否则消费点读不到(那正是清偿前的病根)
        $row = DB::table('city_active_modifiers')
            ->where('city_id', $city->id)
            ->where('source_id', $instance->id)
            ->where('target', ModifierTarget::GOVERNANCE_CAPACITY_PCT)
            ->first();

        $this->assertNotNull($row, '选项 B 必须写下一行 governance_capacity_pct');
        $this->assertSame(ModifierSpec::OP_PCT, $row->op);
        $this->assertSame(ModifierSpec::SCOPE_CITY, $row->scope);
        $this->assertEqualsWithDelta(-0.05, (float) $row->value, 1e-6);

        // 下一次结算立刻吃到:80 × 0.95 = 76
        $after = $this->runSettle($city, 2);
        $this->assertEqualsWithDelta(-0.05, $after['governanceCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(76.0, $after['governanceCapacityEffective'], 1e-6);
        // 建筑口径不动 —— 时代门槛照旧读 80
        $this->assertEqualsWithDelta(80.0, $after['governanceCapacity'], 1e-6);
    }

    // ---------- 定义数据:三位 flat NPC 已迁到新 target ----------

    // 迁移把 N013 / N051 / N111 从 governance_capacity_pct(op=flat)挪到 governance_capacity_flat。
    // 数值一个没动 —— 挪的是 target 名字,不是数值
    public function test_flat_npcs_are_migrated_to_flat_target(): void
    {
        $expected = ['N013' => 30.0, 'N051' => 20.0, 'N111' => 22.0];

        foreach ($expected as $npcId => $value) {
            $trait = json_decode((string) DB::table('npc_definition')->where('npc_id', $npcId)->value('trait_json'), true);
            $specs = collect($trait['specs'] ?? [])
                ->filter(fn ($s) => str_starts_with((string) $s['target'], 'governance_capacity'))
                ->values();

            $this->assertCount(1, $specs, "{$npcId} 只该有一条治理投稿");
            $this->assertSame(ModifierTarget::GOVERNANCE_CAPACITY_FLAT, $specs[0]['target'], "{$npcId} 必须挂 flat target");
            $this->assertSame(ModifierSpec::OP_FLAT, $specs[0]['op']);
            $this->assertEqualsWithDelta($value, (float) $specs[0]['value'], 1e-9);
        }

        // 反向:库里不许再出现「pct target 配 flat op」的行(清偿的就是这个)
        $bad = DB::table('npc_definition')
            ->where('trait_json', 'like', '%"governance_capacity_pct"%')
            ->get(['npc_id', 'trait_json'])
            ->filter(function ($row) {
                foreach (json_decode((string) $row->trait_json, true)['specs'] ?? [] as $s) {
                    if (($s['target'] ?? '') === ModifierTarget::GOVERNANCE_CAPACITY_PCT
                        && ($s['op'] ?? '') !== ModifierSpec::OP_PCT) {
                        return true;
                    }
                }

                return false;
            });

        $this->assertTrue($bad->isEmpty(), 'governance_capacity_pct 上不许再有 op=flat 的投稿');
    }

    // 两条 target 都已登记为「已接线」,消费点是结算内核
    public function test_both_targets_are_registered_and_wired(): void
    {
        foreach ([ModifierTarget::GOVERNANCE_CAPACITY_FLAT, ModifierTarget::GOVERNANCE_CAPACITY_PCT] as $target) {
            $this->assertContains($target, ModifierTarget::all());
            $entry = ModifierTarget::CONSUMPTION_POINTS[$target];
            $this->assertTrue($entry['wired'] ?? false, "{$target} 必须标记为已接线");
            $this->assertSame('App\Game\Simulation\SimulationService', $entry['consumer']);
        }
    }

    // ---------- 登记纪律:剩下还有几条死 target,必须写在册上 ----------

    // 「登记了 ≠ 生效」是本项目反复踩的坑,而且**是静默的**。这条用例把「还没接线的 target」
    // 钉成一份明确名单,让它不能再靠没人看见活着:
    //   · 接线了某一条 → 这里变红,提醒把 `wired` 改成 true 并从名单里划掉;
    //   · 新登记了一条没有消费点的 target → 这里也变红,逼作者显式承认「这条暂时不生效」。
    //
    // **W7 收官时名单已清空**:最后两条(market_fee_pct → TradeService 的手续费、
    // research_speed_pct → TechService 的 finished_at)在 W7 一并接线,登记表 19 条全部 wired。
    // 名单空了不等于这条用例可以删 —— 它现在的作用是**守住零**:
    // 以后任何人新登记一条没有消费点的 target,这里立刻变红,逼他显式承认「这条暂时不生效」。
    public function test_remaining_unwired_targets_are_exactly_the_known_list(): void
    {
        $unwired = [];
        foreach (ModifierTarget::CONSUMPTION_POINTS as $target => $entry) {
            if (! ($entry['wired'] ?? false)) {
                $unwired[] = $target;
            }
        }
        sort($unwired);

        $this->assertSame(
            [],
            $unwired,
            '登记表里出现了没有消费点的 target:要么接线并把 wired 改成 true,要么在本用例里显式登记它'
        );

        // 未接线的条目必须在 desc 里说清楚,后台与后来人据此区分「还没接」与「接了但没效果」
        foreach ($unwired as $target) {
            $this->assertStringContainsString(
                '尚无消费点',
                ModifierTarget::CONSUMPTION_POINTS[$target]['desc'],
                "{$target} 未接线就必须在 desc 里写明"
            );
        }

        // 反过来也守一道:已接线的条目里不许再出现「尚无消费点」这句话(改了 wired 却忘了改 desc,
        // 后台面板会照着 desc 显示,那就成了「代码接了、说明还写着没接」的第三种真相)
        foreach (ModifierTarget::CONSUMPTION_POINTS as $target => $entry) {
            if ($entry['wired'] ?? false) {
                $this->assertStringNotContainsString('尚无消费点', $entry['desc'], "{$target} 已接线,desc 不该再写「尚无消费点」");
            }
        }
    }

    // ---------- 测试夹具 ----------

    private function moneyOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('money');
    }

    // 直接落一行 city_npcs(招募链路本身在 NPC 用例里验)
    private function addNpc(City $city, string $npcId, string $status = NpcCode::STATUS_IDLE): int
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        return (int) DB::table('city_npcs')->insertGetId([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => $status, 'assigned_instance_id' => null,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // 直接落一行 city_items(制作 / 装备链路本身在工具用例里验)
    private function addItem(City $city, string $itemId, string $status, ?int $instanceId): int
    {
        $durability = (float) DB::table('item_definition')->where('item_id', $itemId)->value('durability');

        return (int) DB::table('city_items')->insertGetId([
            'city_id' => $city->id, 'item_id' => $itemId,
            'durability_left' => $durability,
            'status' => $status,
            'equipped_instance_id' => $status === ItemCode::STATUS_EQUIPPED ? $instanceId : null,
            'acquired_source' => ItemCode::SOURCE_CRAFT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // 直接写一行生效中的 city_active_modifiers(事件写的那种)
    private function addModifier(City $city, string $target, string $op, float $value, int $minutes = 30): void
    {
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => $target, 'scope' => 'city', 'scope_key' => null,
            'op' => $op, 'value' => $value,
            'starts_at' => now()->copy()->subMinute(),
            'ends_at' => now()->copy()->addMinutes($minutes),
            'created_at' => now(),
        ]);
    }
}
