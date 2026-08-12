<?php

namespace Tests\Feature\Event;

use App\Game\Event\EventDefinition;
use App\Game\Event\EventService;
use App\Game\Item\ItemCode;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcCode;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// M3-W5:容量类(运输 / 贸易 / 金融)、税收、市场价格三组 target 的接线回归。
//
// 这一波的主线是「数据早就写好了,缺的只是一条 target 与一个消费点」——
// 所以用例分两层:
//   ① **消费点层**:直接写一行 modifier / 装一件工具 / 招一个 NPC,断言内核那一处确实乘了它
//      (三个来源都验,漏一个就等于那类投稿静默失效);
//   ② **复活层**:让事件真的触发一次,断言减益落库、选项能把它调回来。
// 另有一组「口径」用例钉住三条容易被后人改坏的边界:容量夹 ≥ 0、税收夹 ≥ 0、
// 时代门槛继续读建筑口径(不吃临时国防 buff)。
class CapacityTaxPriceTest extends EventTestCase
{
    use RefreshDatabase;

    // T02 驿道 L1 的运输容量(building_levels.json,不派工也计 —— 容量类产出在乘区之前提取)
    private const T02_TRANSPORT = 140.0;

    // 默认关掉**全部**事件:①层的用例验的是消费点本身,一条随机抽中的 EVT_CRIME
    // 会往同一条 tax_income_pct 上再投一份,把断言算歪;而「抽不抽得中」取决于 city_id
    // (掷点种子是 city_id + 窗口号)—— 也就是说不关它,用例会随执行顺序偶发变红。
    // ②层的复活用例各自调 onlyEnable() 把自己那一条打开(它会先把所有事件关掉再开指定的)
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('event_definition')->update(['enabled' => false]);
        EventDefinition::flush();
    }

    // C01 村落市场 L1 / C02 城镇市场 L1 的贸易容量;C03 银行 L1 的金融容量
    private const C01_TRADE = 100.0;
    private const C02_TRADE = 450.0;
    private const C03_FINANCE = 800.0;

    // ---------- ① 消费点层:内核确实提取 + 确实乘 pct ----------

    // 贸易 / 金融容量以前被 isCapacity() 整条丢弃(六栋 C 系列建筑是纯负债),W5 起提取成全城值
    public function test_kernel_extracts_trade_and_finance_capacity(): void
    {
        [$city] = $this->makeCity('capext');
        $this->addBuilding($city, 'C01');
        $this->addBuilding($city, 'C03');

        $sim = $this->runSettle($city, 5);

        $this->assertEqualsWithDelta(self::C01_TRADE, $sim['tradeCapacity'], 1e-6);
        $this->assertEqualsWithDelta(self::C03_FINANCE, $sim['financeCapacity'], 1e-6);
        // 容量类不入库存:市场额度与事件条件读的是上面两个读数,不是 city_resources
        $this->assertSame(0.0, $this->resourceOf($city, 'trade_capacity'));
    }

    // 三个来源逐个验:事件 modifier / 在编 NPC 特性 / 已装备工具
    public function test_transport_capacity_pct_from_all_three_sources(): void
    {
        [$city] = $this->makeCity('captr');
        $this->addBuilding($city, 'T02');

        $this->assertEqualsWithDelta(self::T02_TRANSPORT, $this->runSettle($city, 1)['transportCapacity'], 1e-6);

        // ① 事件写下的持续型 modifier:-30%
        $this->addModifier($city, ModifierTarget::TRANSPORT_CAPACITY_PCT, -0.30);
        $this->assertEqualsWithDelta(self::T02_TRANSPORT * 0.70, $this->runSettle($city, 2)['transportCapacity'], 1e-6);

        // ② 在编 NPC:N022「铁路容量+15%」(按语义并入 transport)
        $this->addNpc($city, 'N022');
        $this->assertEqualsWithDelta(self::T02_TRANSPORT * 0.85, $this->runSettle($city, 3)['transportCapacity'], 1e-6);

        // ③ 已装备工具:IT018「运输容量+15%」
        $this->addItem($city, 'IT018');
        $this->assertEqualsWithDelta(self::T02_TRANSPORT * 1.00, $this->runSettle($city, 4)['transportCapacity'], 1e-6);
    }

    // 运输容量是 §10.7 物流负载的**分母** —— 减益必须当场改变 logistics 那一格,
    // 而不是只改一个给前端看的读数(这正是「消费点必须在内核」的理由)
    public function test_transport_capacity_pct_moves_the_logistics_slot(): void
    {
        [$city] = $this->makeCity('caplog', ['era_order' => 5]);
        $this->addBuilding($city, 'T02');
        // 有产出的建筑才有运输需求(§10.7 需求 = 生产建筑的投入 + 产出)
        $this->addBuilding($city, 'F02', 6);

        $before = $this->runSettle($city, 1);
        $this->addModifier($city, ModifierTarget::TRANSPORT_CAPACITY_PCT, -0.90);
        $after = $this->runSettle($city, 2);

        $this->assertGreaterThan($before['transportLoad'], $after['transportLoad'], '容量被砍,负载必须上升');
        $this->assertLessThanOrEqual($before['logisticsFactor'], $after['logisticsFactor']);
        $this->assertEqualsWithDelta(-0.90, $after['transportCapacityPct'], 1e-6);
    }

    // 贸易 / 金融两条 pct 与运输同一处相乘
    public function test_trade_and_finance_capacity_pct(): void
    {
        [$city] = $this->makeCity('captf');
        $this->addBuilding($city, 'C01');
        $this->addBuilding($city, 'C03');

        $this->addModifier($city, ModifierTarget::TRADE_CAPACITY_PCT, -0.25);
        $this->addModifier($city, ModifierTarget::FINANCE_CAPACITY_PCT, 0.50);

        $sim = $this->runSettle($city, 2);

        $this->assertEqualsWithDelta(self::C01_TRADE * 0.75, $sim['tradeCapacity'], 1e-6);
        $this->assertEqualsWithDelta(self::C03_FINANCE * 1.50, $sim['financeCapacity'], 1e-6);
    }

    // 夹取:容量与税收都夹到 ≥ 0 —— 后台/事件把减益填成 -200% 也不该出现负容量或倒贴税
    public function test_capacity_and_tax_never_go_negative(): void
    {
        [$city] = $this->makeCity('capclamp');
        $this->addBuilding($city, 'T02');
        $this->addBuilding($city, 'A01'); // 行政所:提供治理容量,税收才算得出非零值

        $this->addModifier($city, ModifierTarget::TRANSPORT_CAPACITY_PCT, -2.0);
        $this->addModifier($city, ModifierTarget::TAX_INCOME_PCT, -3.0);

        $sim = $this->runSettle($city, 2);

        $this->assertSame(0.0, $sim['transportCapacity']);
        $this->assertSame(0.0, $sim['taxIncomePerMin']);
    }

    // 税收:NPC(N013 +8%)与事件 modifier(-10%)投到同一条 target,内核按 (1 + Σpct) 乘一次
    public function test_tax_income_pct_from_npc_and_event(): void
    {
        [$city] = $this->makeCity('captax');
        $this->addBuilding($city, 'A01');

        $base = $this->runSettle($city, 1)['taxIncomePerMin'];
        $this->assertGreaterThan(0.0, $base);

        $this->addNpc($city, 'N013');
        $withNpc = $this->runSettle($city, 2);
        $this->assertEqualsWithDelta(0.08, $withNpc['taxIncomePct'], 1e-6);
        $this->assertEqualsWithDelta($base * 1.08, $withNpc['taxIncomePerMin'], 1e-4);

        $this->addModifier($city, ModifierTarget::TAX_INCOME_PCT, -0.10);
        $both = $this->runSettle($city, 3);
        // 两条相加再乘一次(不是各乘一次):0.08 + (-0.10) = -0.02
        $this->assertEqualsWithDelta(-0.02, $both['taxIncomePct'], 1e-6);
        $this->assertEqualsWithDelta($base * 0.98, $both['taxIncomePerMin'], 1e-4);
    }

    // ---------- ② 复活层:六条事件真的跑得起来 ----------

    // EVT_ROUTE_BREAK:自动效果砍 30% 运输容量,选项 A「紧急维护」把减益减半
    public function test_route_break_cuts_transport_and_option_a_halves_it(): void
    {
        [$city, $user] = $this->makeCity('caprb');
        $this->addBuilding($city, 'T03'); // 315 > 300,满足条件
        $this->onlyEnable('EVT_ROUTE_BREAK');

        $this->runSettle($city, 1);
        $instance = $this->activeInstances($city)->first();
        $this->assertNotNull($instance, 'EVT_ROUTE_BREAK 必须能触发(条件:运输容量 > 300)');

        $after = $this->runSettle($city, 2);
        $this->assertEqualsWithDelta(-0.30, $after['transportCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(315.0 * 0.70, $after['transportCapacity'], 1e-6);

        // 选项 A:资金 -500 + 减益 ×0.5
        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => (int) $instance->id, 'choice' => 'a',
        ])->assertOk();

        $resolved = $this->runSettle($city, 3);
        $this->assertEqualsWithDelta(-0.15, $resolved['transportCapacityPct'], 1e-6);
    }

    // EVT_CRIME:税收 -10% + 随机库存损失(掷点落 rolled,选项路径只读不重掷)
    public function test_crime_cuts_tax_and_steals_stock(): void
    {
        [$city] = $this->makeCity('capcrime');
        $this->addBuilding($city, 'A01');
        $this->setResource($city, 'wood', 500);
        $this->onlyEnable('EVT_CRIME');

        $this->runSettle($city, 1);
        $instance = $this->activeInstances($city)->first();
        $this->assertNotNull($instance, 'EVT_CRIME 必须能触发(条件:治安 < 65)');

        $rolled = json_decode((string) $instance->rolled_json, true);
        $this->assertArrayHasKey('loss', $rolled, '随机库存损失必须在触发时掷点并落库');
        $this->assertLessThan(0.0, (float) $rolled['loss']['pct']);
        $this->assertGreaterThanOrEqual(-0.08, (float) $rolled['loss']['pct']);
        $this->assertLessThanOrEqual(-0.03, (float) $rolled['loss']['pct']);
        // 被偷的是「当前有库存的非资金资源」之一
        $this->assertNotSame('money', $rolled['loss']['resource']);

        $after = $this->runSettle($city, 2);
        $this->assertEqualsWithDelta(-0.10, $after['taxIncomePct'], 1e-6);
    }

    // EVT_CORRUPTION:税收 -15% 与维护 +8% 两条一起生效(维护那一条的消费点是 W4 接的,这里验没接错)
    public function test_corruption_cuts_tax_and_raises_maintenance(): void
    {
        [$city] = $this->makeCity('capcorr', ['era_order' => 5]);
        $this->addBuilding($city, 'T02'); // 有维护费的建筑,维护 +8% 才看得出来
        $this->onlyEnable('EVT_CORRUPTION');

        $before = $this->runSettle($city, 1);
        $this->assertNotNull($this->activeInstances($city)->first(), 'EVT_CORRUPTION 必须能触发(条件:治理负载 > 0.80)');

        $after = $this->runSettle($city, 2);
        $this->assertEqualsWithDelta(-0.15, $after['taxIncomePct'], 1e-6);
        $this->assertEqualsWithDelta(0.08, $after['maintenanceCostPct'], 1e-6);
        $this->assertEqualsWithDelta($before['maintenanceMoneyPerMin'] * 1.08, $after['maintenanceMoneyPerMin'], 1e-6);
    }

    // M3-W10:EVT_CORRUPTION 选项 A「调查」是**确定性**解决 ——
    // 结算后税收与维护两条减益必须双双归零(原文的「50% 立即解决」没有概率 kind,
    // 落地前这个选项只扣钱不办事)。这条验的是行为不是形状:形状在 EventDefinitionTest
    public function test_corruption_investigate_option_clears_both_penalties(): void
    {
        [$city] = $this->makeCity('corrinv', ['era_order' => 5]);
        $this->addBuilding($city, 'T02');
        $this->onlyEnable('EVT_CORRUPTION');

        $this->runSettle($city, 1);
        $instance = $this->activeInstances($city)->first();
        $this->assertNotNull($instance, 'EVT_CORRUPTION 必须能触发(条件:治理负载 > 0.80)');

        $during = $this->runSettle($city, 2);
        $this->assertEqualsWithDelta(-0.15, $during['taxIncomePct'], 1e-6);
        $this->assertEqualsWithDelta(0.08, $during['maintenanceCostPct'], 1e-6);

        EventService::resolve($city->fresh(), (int) $instance->id, 'a', null, null);

        // 两条减益归零:modifier 行还在(实例没结束),但值被 scale 成 0
        $rows = DB::table('city_active_modifiers')->where('city_id', $city->id)
            ->where('source_type', 'event')->where('source_id', $instance->id)
            ->pluck('value', 'target');
        $this->assertEqualsWithDelta(0.0, (float) $rows[ModifierTarget::TAX_INCOME_PCT], 1e-9);
        $this->assertEqualsWithDelta(0.0, (float) $rows[ModifierTarget::MAINTENANCE_COST_PCT], 1e-9);

        $after = $this->runSettle($city, 3);
        $this->assertEqualsWithDelta(0.0, $after['taxIncomePct'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $after['maintenanceCostPct'], 1e-6);

        // 代价照收:资金 -900 / 知识 -50(「立即解决」不是免费的)。
        // 读审计 delta 而不是比资金余额 —— resolve 内部会先跑一次结算,
        // 税收与维护同时在动,拿余额相减断言只会验出「这段时间的净流水」
        $delta = json_decode((string) DB::table('audit_logs')->where('action', 'EVENT.RESOLVE')
            ->latest('id')->value('delta_json'), true);
        $this->assertEqualsWithDelta(-900.0, (float) $delta['money'], 0.01);
        $this->assertEqualsWithDelta(-50.0, (float) $delta['knowledge'], 0.01);
    }

    // EVT_PORT_CONGESTION:条件读的是**全城贸易容量**(以前根本没有这个读数)
    public function test_port_congestion_condition_reads_trade_capacity(): void
    {
        [$city] = $this->makeCity('capport', ['era_order' => 7]);
        $this->onlyEnable('EVT_PORT_CONGESTION');

        // 只有 1 栋 C02(450)→ 不满足「贸易容量 > 800」
        $this->addBuilding($city, 'C02');
        $this->runSettle($city, 1);
        $this->assertCount(0, $this->activeInstances($city), '贸易容量 450 不该触发港口拥堵');

        // 第二栋 C02 → 900 > 800
        $this->addBuilding($city, 'C02');
        $this->runSettle($city, 2);
        $this->assertNotNull($this->activeInstances($city)->first(), '贸易容量 900 必须触发港口拥堵');

        $after = $this->runSettle($city, 3);
        $this->assertEqualsWithDelta(-0.25, $after['tradeCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(-0.25, $after['transportCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(self::C02_TRADE * 2 * 0.75, $after['tradeCapacity'], 1e-6);
    }

    // M3-W10:EVT_PORT_CONGESTION 选项 A「加班疏港」追加两条归零 = 拥堵立即解除。
    // 与选项 B 的区别在代价:B 用运输容量打折换取贸易解除,A 用真金白银 + 维护 +10% 换两条全清
    public function test_port_congestion_overtime_option_clears_the_congestion(): void
    {
        [$city] = $this->makeCity('capportovertime', ['era_order' => 7]);
        $this->onlyEnable('EVT_PORT_CONGESTION');
        $this->addBuilding($city, 'C02');
        $this->addBuilding($city, 'C02');

        $this->runSettle($city, 1);
        $instance = $this->activeInstances($city)->first();
        $this->assertNotNull($instance, '贸易容量 900 必须触发港口拥堵');

        $during = $this->runSettle($city, 2);
        $this->assertEqualsWithDelta(-0.25, $during['tradeCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(-0.25, $during['transportCapacityPct'], 1e-6);

        EventService::resolve($city->fresh(), (int) $instance->id, 'a', null, null);

        $after = $this->runSettle($city, 3);
        $this->assertEqualsWithDelta(0.0, $after['tradeCapacityPct'], 1e-6, '加班疏港后贸易减益必须清零');
        $this->assertEqualsWithDelta(0.0, $after['transportCapacityPct'], 1e-6, '加班疏港后运输减益必须清零');
        // 代价照收:资金 -600 + 维护 +10%(归零只作用于点名的两条 target)。
        // 资金看审计 delta:resolve 内部会先结算一次,余额相减验的是净流水不是这次代价
        $delta = json_decode((string) DB::table('audit_logs')->where('action', 'EVENT.RESOLVE')
            ->latest('id')->value('delta_json'), true);
        $this->assertEqualsWithDelta(-600.0, (float) $delta['money'], 0.01);
        $this->assertEqualsWithDelta(0.10, $after['maintenanceCostPct'], 1e-6);
    }

    // EVT_SPECULATION:随机战略资源的价格冲击落成一行 resource 作用域的 market_price_pct
    public function test_speculation_writes_resource_scoped_price_modifier(): void
    {
        [$city] = $this->makeCity('capspec', ['era_order' => 7]);
        $this->addBuilding($city, 'C03'); // finance 系列 ≥ 1
        $this->onlyEnable('EVT_SPECULATION');

        $this->runSettle($city, 1);
        $this->assertNotNull($this->activeInstances($city)->first(), 'EVT_SPECULATION 必须能触发(条件:银行 ≥ 1)');

        $row = DB::table('city_active_modifiers')->where('city_id', $city->id)
            ->where('target', ModifierTarget::MARKET_PRICE_PCT)->first();

        $this->assertNotNull($row, '价格冲击必须落成一行 market_price_pct');
        $this->assertSame(ModifierSpec::SCOPE_RESOURCE, $row->scope);
        $this->assertContains($row->scope_key, ['steel', 'oil', 'rare_metals', 'advanced_materials', 'electronic_components']);
        // 区间掷点:0.25 ~ 0.50,掷出来的数写进 modifier 行本身(选项路径只读不重掷)
        $this->assertGreaterThanOrEqual(0.25, (float) $row->value);
        $this->assertLessThanOrEqual(0.50, (float) $row->value);
    }

    // EVT_TAX_PROTEST 维持停用:税率固定不可调,条件恒不成立 —— tax_income_pct 上线也不复活它
    public function test_tax_protest_stays_disabled(): void
    {
        $row = DB::table('event_definition')->where('event_id', 'EVT_TAX_PROTEST')->first();

        $this->assertSame(0, (int) $row->enabled);
        $this->assertNotNull($row->disabled_reason);
    }

    // ---------- ③ 口径:国防读数统一(W4-B 留下的两处差) ----------

    // §10.8 的 security 覆盖率改读**有效国防值**:装上 IT008(+8)之后治安必须跟着涨
    public function test_security_reads_effective_defense_score(): void
    {
        [$city] = $this->makeCity('capdef', ['population' => 100]);
        $this->addBuilding($city, 'D01'); // 建筑口径 25

        $before = $this->runSettle($city, 1);
        $this->assertSame(25, $before['security'], 'round(25 / 100 × 100)');

        $this->addItem($city, 'IT008'); // 国防 flat +8
        $after = $this->runSettle($city, 2);

        $this->assertSame(33, $after['security'], '(25 + 8) / 100 → 33');
        // 建筑口径本身不动:时代门槛与 DefenseService 的基数都取它
        $this->assertEqualsWithDelta(25.0, $after['defenseScore'], 1e-6);
        $this->assertEqualsWithDelta(33.0, $after['defenseScoreEffective'], 1e-6);
    }

    // 时代门槛**除外**:临时 buff 不该顶升代门槛(W4-B 的裁决,这里再钉一次)
    public function test_era_gate_still_reads_building_defense(): void
    {
        [$city, $user] = $this->makeCity('capera', ['era_order' => 3]);
        for ($i = 0; $i < 4; $i++) {
            $this->addBuilding($city, 'D01'); // 建筑口径 100
        }
        $this->addModifier($city, ModifierTarget::DEFENSE_SCORE_PCT, 1.0, ModifierSpec::OP_PCT); // 临时 ×2

        $res = $this->actingAs($user)->getJson('/api/city')->assertOk();

        $row = collect($res->json('data.city.era.next.requirements'))->firstWhere('dimension', 'defense');
        $this->assertEqualsWithDelta(100.0, $row['current'], 1e-6, '时代门槛必须继续读建筑口径');
        $this->assertEqualsWithDelta(200.0, $res->json('data.city.defense.defense_score'), 1e-6);
    }

    // ---------- 夹具 ----------

    private function addModifier(City $city, string $target, float $value, string $op = ModifierSpec::OP_PCT, int $minutes = 60): void
    {
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => $target, 'scope' => ModifierSpec::SCOPE_CITY, 'scope_key' => null,
            'op' => $op, 'value' => $value,
            'starts_at' => now()->copy()->subMinutes(10),
            'ends_at' => now()->copy()->addMinutes($minutes),
            'created_at' => now(),
        ]);
    }

    private function addNpc(City $city, string $npcId): void
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        DB::table('city_npcs')->insert([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => NpcCode::STATUS_IDLE, 'assigned_instance_id' => null,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addItem(City $city, string $itemId): void
    {
        $durability = (float) DB::table('item_definition')->where('item_id', $itemId)->value('durability');

        DB::table('city_items')->insert([
            'city_id' => $city->id, 'item_id' => $itemId,
            'durability_left' => $durability, 'status' => ItemCode::STATUS_EQUIPPED,
            'equipped_instance_id' => (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id'),
            'acquired_source' => ItemCode::SOURCE_CRAFT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
