<?php

namespace Tests\Feature\City;

use App\Game\Building\ConstructionService;
use App\Game\City\CityFactory;
use App\Game\Modifier\ConsumptionPoint;
use App\Game\Modifier\ModifierTarget;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// D0.3 两个悬空消费点的接线(W4-A):
//   maintenance_cost_pct  → SimulationService 财政块(建筑维护费打折)
//   construction_speed_pct → ConstructionService(建造 / 升级工期折减)
//
// 数据早已就位、只差读取方:
//   NPC  N017 维护 −5% / N020 维护 −10% / N008 建造 +8% / N030 建造 +25%
//   工具 IT016 维护 −8% / IT005 建造 +8% / IT013 建造 +15%
//
// 本文件锁死两件事:**数值算式**与**与欠费判定的先后顺序**。
class ConsumptionPointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- 登记表:两条已标记为已接线 ----

    public function test_registry_marks_both_targets_as_wired(): void
    {
        foreach ([ModifierTarget::MAINTENANCE_COST_PCT, ModifierTarget::CONSTRUCTION_SPEED_PCT] as $target) {
            $meta = ModifierTarget::CONSUMPTION_POINTS[$target];
            $this->assertTrue($meta['wired'] ?? false, "{$target} 应已标记为已接线");
            $this->assertNotEmpty($meta['consumer']);
        }

        $this->assertSame('App\Game\Simulation\SimulationService',
            ModifierTarget::CONSUMPTION_POINTS[ModifierTarget::MAINTENANCE_COST_PCT]['consumer']);
        $this->assertSame('App\Game\Building\ConstructionService',
            ModifierTarget::CONSUMPTION_POINTS[ModifierTarget::CONSTRUCTION_SPEED_PCT]['consumer']);
    }

    // ---- 取数:三个来源(工具 / NPC / 事件 modifier)都认,且只认 scope=city + op=pct ----

    public function test_consumption_point_sums_all_three_sources(): void
    {
        $city = $this->makeCity('cpsum', ['F02' => 1]);
        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');

        $this->assertSame(0.0, ConsumptionPoint::pct(ModifierTarget::MAINTENANCE_COST_PCT, (int) $city->id));

        // ① 已装备工具 IT016(−8%)
        $this->equipItem($city, 'IT016', $instanceId);
        $this->assertEqualsWithDelta(-0.08, ConsumptionPoint::pct(ModifierTarget::MAINTENANCE_COST_PCT, (int) $city->id), 1e-9);

        // ② 在编 NPC N020(−10%),idle 也算(工资是「雇着就要发」,减免同理)
        $this->hireNpc($city, 'N020');
        $this->assertEqualsWithDelta(-0.18, ConsumptionPoint::pct(ModifierTarget::MAINTENANCE_COST_PCT, (int) $city->id), 1e-9);

        // ③ 事件写的持续型 modifier(+20% 维护,负面事件方向)
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 1,
            'target' => ModifierTarget::MAINTENANCE_COST_PCT, 'scope' => 'city', 'scope_key' => null,
            'op' => 'pct', 'value' => 0.20,
            'starts_at' => now()->copy()->subMinute(), 'ends_at' => now()->copy()->addMinutes(10),
            'created_at' => now(),
        ]);
        $this->assertEqualsWithDelta(0.02, ConsumptionPoint::pct(ModifierTarget::MAINTENANCE_COST_PCT, (int) $city->id), 1e-9);

        // 已过期的 modifier 不再计入
        DB::table('city_active_modifiers')->update(['ends_at' => now()->copy()->subSecond()]);
        $this->assertEqualsWithDelta(-0.18, ConsumptionPoint::pct(ModifierTarget::MAINTENANCE_COST_PCT, (int) $city->id), 1e-9);

        // 损毁 / 未装备的工具不计入(与 ToolMultiplierProvider 同口径)
        DB::table('city_items')->where('city_id', $city->id)->update(['durability_left' => 0]);
        $this->assertEqualsWithDelta(-0.10, ConsumptionPoint::pct(ModifierTarget::MAINTENANCE_COST_PCT, (int) $city->id), 1e-9);
    }

    // ---- maintenance_cost_pct:精确基线 ----

    public function test_maintenance_discount_applies_to_building_maintenance(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // F02 ×1:维护 4/min。装 IT016(−8%)→ 3.68/min
        $city = $this->makeCity('cpmaint', ['F02' => 1], 100000.0);
        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');
        $this->equipItem($city, 'IT016', $instanceId);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(-0.08, $sim['maintenanceCostPct'], 1e-9);
        $this->assertEqualsWithDelta(3.68, $sim['maintenanceMoneyPerMin'], 0.0001, '4 × (1 − 0.08)');
        // 资金 = 100000 − 3.68 × 10 = 99963.2(不打折的话是 99960)
        $this->assertEqualsWithDelta(99963.2, $this->moneyOf($city), 0.0001);
        $this->assertFalse($sim['maintenanceArrears']);
    }

    // ---- 叠加顺序:**折扣在前、欠费判定在后** ----

    // 应付 40(不打折)/ 36.8(打折);手上 38 元。
    // 顺序对了 → 付得起,不半停工;顺序反了(先判欠费再打折)→ 会误判成欠费、产量腰斩
    public function test_discount_is_applied_before_the_arrears_check(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('cporder', ['F02' => 1], 38.0);
        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');
        $this->equipItem($city, 'IT016', $instanceId);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(3.68, $sim['maintenanceMoneyPerMin'], 0.0001);
        $this->assertFalse($sim['maintenanceArrears'], '打折后 36.8 <= 38 → 付得起');
        $this->assertSame(1.0, $sim['maintenanceRate']);
        // 粮食 = 0 + 14 × 1.0 × 10 = 140(半停工的话只有 70)
        $this->assertEqualsWithDelta(140.0, $this->amountOf($city, 'food'), 0.0001);
        // 资金 = 38 − 36.8 = 1.2
        $this->assertEqualsWithDelta(1.2, $this->moneyOf($city), 0.0001);
    }

    // 对照:同样装着 IT016,但钱少到连打折价都付不起 → 照样欠费半停工。
    // 折扣不是免死金牌,它只是把门槛从 40 降到 36.8
    public function test_discount_does_not_rescue_a_city_that_still_cannot_pay(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('cparr', ['F02' => 1], 30.0);
        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');
        $this->equipItem($city, 'IT016', $instanceId);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertTrue($sim['maintenanceArrears'], '30 < 36.8 → 仍然欠费');
        $this->assertSame(0.5, $sim['maintenanceRate']);
        // 粮食 = 14 × 0.5 × 10 = 70;资金夹到 0
        $this->assertEqualsWithDelta(70.0, $this->amountOf($city, 'food'), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->moneyOf($city), 0.0001);
    }

    // NPC 工资**不吃**这个折扣:maintenance_cost_pct 的登记语义是「建筑维护资金」。
    // N020 工资 15/min,自己还带 −10% 维护减免 → 4 × 0.90 + 15 = 18.6/min
    public function test_npc_wage_is_added_after_the_discount(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('cpwage', ['F02' => 1], 100000.0);
        $this->hireNpc($city, 'N020');

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(-0.10, $sim['maintenanceCostPct'], 1e-9);
        $this->assertEqualsWithDelta(18.6, $sim['maintenanceMoneyPerMin'], 0.0001,
            '4 × 0.90 = 3.6(建筑维护打折)+ 15(工资,不打折)');
        // 打折若误作用到工资上会是 (4 + 15) × 0.9 = 17.1 —— 这条断言就是拦它的
        $this->assertNotEqualsWithDelta(17.1, $sim['maintenanceMoneyPerMin'], 0.0001);
    }

    // ---- construction_speed_pct:精确时长 ----

    public function test_construction_speed_shortens_duration(): void
    {
        $city = $this->makeCity('cpspeed', ['F02' => 1]);
        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');

        // 没有任何投稿 → 原样
        $this->assertSame(1.0, ConstructionService::speedMultiplier((int) $city->id));
        $this->assertSame(51, ConstructionService::plannedSeconds((int) $city->id, 51));

        // IT005(+8%)→ 51 / 1.08 = 47.22 → 47
        $this->equipItem($city, 'IT005', $instanceId);
        $this->assertEqualsWithDelta(1.08, ConstructionService::speedMultiplier((int) $city->id), 1e-9);
        $this->assertSame(47, ConstructionService::plannedSeconds((int) $city->id, 51));

        // 再加 N030(+25%)→ 合计 +33% → 51 / 1.33 = 38.35 → 38
        $this->hireNpc($city, 'N030');
        $this->assertEqualsWithDelta(1.33, ConstructionService::speedMultiplier((int) $city->id), 1e-9);
        $this->assertSame(38, ConstructionService::plannedSeconds((int) $city->id, 51));

        // 安全夹取:极端负值不该把工期打成无穷 / 负数
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 1,
            'target' => ModifierTarget::CONSTRUCTION_SPEED_PCT, 'scope' => 'city', 'scope_key' => null,
            'op' => 'pct', 'value' => -5.0,
            'starts_at' => now()->copy()->subMinute(), 'ends_at' => now()->copy()->addMinutes(10),
            'created_at' => now(),
        ]);
        $this->assertSame(ConstructionService::CONSTRUCTION_SPEED_FLOOR, ConstructionService::speedMultiplier((int) $city->id));
        $this->assertSame(510, ConstructionService::plannedSeconds((int) $city->id, 51), '51 / 0.1');
    }

    // 端到端:走真实的 POST /api/city/build,完工时刻按折减后的工期算
    public function test_build_endpoint_uses_the_shortened_duration(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);

        $user = User::create(['username' => 'cpbuild', 'name' => 'cpbuild', 'email' => 'cpbuild@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($user);
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'II', 'era_order' => 2, 'money' => 100000]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'wood'], ['amount' => 1000]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'stone'], ['amount' => 1000]);
        $this->unlockTechFor($city->id, 'F02');

        // 建造工具 IT013(+15%)装在建城送的任意一栋楼上
        $host = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');
        $this->equipItem($city, 'IT013', $host);

        $this->actingAs($user)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 12, 'y' => 12])->assertOk();

        // F02 基础工期 51 秒 → 51 / 1.15 = 44.35 → 44
        $inst = DB::table('city_building_instances')->where('city_id', $city->id)->where('building_id', 'F02')->first();
        $this->assertSame(44, Carbon::parse($inst->construction_finished_at)->getTimestamp() - $base->getTimestamp());

        // 审计同时留下实际工期与定义工期(半年后要回答「他这栋楼为什么只花了 44 秒」)
        $meta = json_decode((string) DB::table('audit_logs')->where('action', 'BUILDING.BUILD')
            ->latest('id')->value('metadata_json'), true);
        $this->assertSame(44, $meta['durationSeconds']);
        $this->assertSame(51, $meta['baseDurationSeconds']);
    }

    // ---- 公共辅助 ----

    private function makeCity(string $un, array $buildings = [], float $money = 100000.0): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        $slot = 0;
        foreach ($buildings as $bid => $count) {
            $workers = (int) DB::table('building_level_definition')
                ->where('building_id', $bid)->where('level', 1)->value('worker_required');
            for ($i = 0; $i < $count; $i++) {
                CityBuildingInstance::create([
                    'city_id' => $city->id, 'building_id' => $bid, 'level' => 1,
                    'x' => 1 + ($slot % 4) * 4, 'y' => 1 + intdiv($slot, 4) * 4,
                    'status' => 'active', 'assigned_workers' => $workers,
                ]);
                $slot++;
            }
        }

        // 人口 0 / 时代 I:本文件验的是维护与工期,不要让人口吃粮、物流与电力混进算式
        DB::table('cities')->where('id', $city->id)->update([
            'population' => 0, 'money' => $money, 'era_key' => 'I', 'era_order' => 1,
        ]);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);

        return $city;
    }

    private function equipItem(City $city, string $itemId, int $instanceId): void
    {
        $durability = (float) DB::table('item_definition')->where('item_id', $itemId)->value('durability');

        DB::table('city_items')->insert([
            'city_id' => $city->id, 'item_id' => $itemId,
            'durability_left' => $durability, 'status' => 'equipped',
            'equipped_instance_id' => $instanceId,
            'acquired_source' => 'test', 'acquired_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function hireNpc(City $city, string $npcId): void
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        DB::table('city_npcs')->insert([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => 'idle', 'assigned_instance_id' => null,
            'acquired_source' => 'test', 'acquired_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function amountOf(City $city, string $resourceId): float
    {
        return (float) (DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', $resourceId)->value('amount') ?? 0);
    }

    private function moneyOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('money');
    }
}
