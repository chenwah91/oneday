<?php

namespace Tests\Feature\Npc;

use App\Game\City\CityFactory;
use App\Game\NPC\NpcCode;
use App\Game\Simulation\SimConstants;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// npc 乘区(v3.2 §6.4)与工资 / 口粮支出通道的黄金样本。
//
// 本文件的城市一律:人口 0(排除吃粮与税收)、资金 100000(排除维护欠费半停工)、
// 停在时代 I(物流乘区恒 1.0)、工人补满(worker 乘区恒 1.0)、不解锁任何科技(tech 恒 1.0)。
// 于是唯一会动的乘区就是 npc —— 每条断言的差值都只能由 NPC 造成。
//
// 主力建筑 F02 农田:category = food_production(对口 SKILL_AGRICULTURE),粮食 14/min,L1 需 4 人。
class NpcMultiplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- 对照组:没有 NPC = 接入前的历史行为 ----

    public function test_no_npc_keeps_multiplier_at_one(): void
    {
        [$city, $instanceId] = $this->makeCityWithFarm('npcnone');
        $sim = $this->simulateMinutes($city, 10);

        $this->assertEqualsWithDelta(14.0, $sim['grossProductionPerMin']['food'], 0.0001);
        // 没有 NPC 就没有工资与口粮支出
        $this->assertEqualsWithDelta(4.0, $sim['maintenanceMoneyPerMin'], 0.0001); // F02 自身维护 4/min
        $this->assertSame(0, $instanceId <=> $instanceId);
    }

    // ---- 岗位匹配 / 不匹配 / 特性 ----

    // N005 农夫:主技能 SKILL_AGRICULTURE、初始 3 级(曲线 0.07)、特性 food_production +10%。
    // 派到 F02(对口)→ 1 + 0.07 + 0.10 = 1.17
    public function test_matched_npc_applies_full_primary_bonus_and_trait(): void
    {
        [$city, $instanceId] = $this->makeCityWithFarm('npcmatch');
        $this->putNpc($city, 'N005', $instanceId);

        $sim = $this->simulateMinutes($city, 10);

        $this->assertEqualsWithDelta(14.0 * 1.17, $sim['grossProductionPerMin']['food'], 0.0001);
    }

    // 岗位不匹配:主技能加成 ×0.25(§6.4),且 building_category 作用域的特性不命中。
    // N005 派到 R01 伐木营地(category = raw_material_extraction)→ 1 + 0.07×0.25 = 1.0175
    public function test_mismatched_npc_gets_quarter_primary_bonus_only(): void
    {
        [$city, $instanceId] = $this->makeCityWith('npcmismatch', 'R01');
        $this->putNpc($city, 'N005', $instanceId);

        $sim = $this->simulateMinutes($city, 10);

        // R01 木材 10/min
        $this->assertEqualsWithDelta(10.0 * 1.0175, $sim['grossProductionPerMin']['wood'], 0.0001);
    }

    // 资源作用域的特性:N004「木材产量 +8%」按「这栋楼产不产 wood」判定,与 category 无关。
    // N004 主技能 SKILL_GATHERING、2 级(0.035);R01 的 series = wood → 对口技能正是 SKILL_GATHERING。
    // 1 + 0.035 + 0.08 = 1.115
    public function test_resource_scoped_trait_applies_to_producing_building(): void
    {
        [$city, $instanceId] = $this->makeCityWith('npcresource', 'R01');
        $this->putNpc($city, 'N004', $instanceId);

        $sim = $this->simulateMinutes($city, 10);

        $this->assertEqualsWithDelta(10.0 * 1.115, $sim['grossProductionPerMin']['wood'], 0.0001);
    }

    // ---- 两层帽 ----

    // NPC 侧总帽 1.50(用户 2026-08-11 把 §6.4 建议的 1.90 收紧到 1.50)。
    // 两个满级 N005:每人 1 + 0.315 + 0.10 = 1.415,连乘 2.002 → 夹到 1.50
    public function test_npc_slot_is_capped_at_total_cap(): void
    {
        [$city, $instanceId] = $this->makeCityWithFarm('npccap');
        $this->putNpc($city, 'N005', $instanceId, 10);
        $this->putNpc($city, 'N005', $instanceId, 10);

        $sim = $this->simulateMinutes($city, 10);

        $this->assertSame(1.50, SimConstants::NPC_TOTAL_CAP);
        $this->assertEqualsWithDelta(14.0 * 1.50, $sim['grossProductionPerMin']['food'], 0.0001);
    }

    // 单 NPC 单建筑帽 1.60(§6.4):N028 满级(0.315)+ 知识 +40% 特性 = 1.715 → 先夹到 1.60。
    // K01 学堂 category = research_education(对口 SKILL_RESEARCH),产 knowledge。
    //
    // 注意两层帽的先后:单 NPC 夹到 1.60 之后,还要过 NPC 侧总帽 1.50 ——
    // 用户把总帽从 1.90 收到 1.50 之后,1.60 这一层在**单人**场景下已经被总帽完全遮住,
    // 只在「一个人的原始因子超过 1.60、且同栋楼还有第二个人」时才看得出差别。
    // 所以这里两层分开断言:内层直接查 NpcBonus,外层看结算结果。
    public function test_single_npc_is_capped_at_single_building_cap(): void
    {
        [$city, $instanceId] = $this->makeCityWith('npcsinglecap', 'K01');
        $this->putNpc($city, 'N028', $instanceId, 10);

        $curve = DB::table('npc_skill_level_curve')->pluck('primary_bonus', 'level')
            ->map(fn ($b) => (float) $b)->all();
        $def = DB::table('npc_definition')->where('npc_id', 'N028')->first();
        $factor = \App\Game\NPC\NpcBonus::forNpc([
            'primary_skill_id' => $def->primary_skill_id,
            'skill_level'      => 10,
            'specs'            => \App\Game\NPC\NpcBonus::specsFromJson($def->trait_json),
        ], [
            'category' => 'research_education', 'series_key' => 'education',
            'instance_id' => $instanceId, 'outputs' => ['knowledge' => true],
        ], $curve);

        // 内层:1 + 0.315 + 0.40 = 1.715 → 夹到 1.60
        $this->assertSame(1.60, SimConstants::NPC_SINGLE_BUILDING_CAP);
        $this->assertEqualsWithDelta(1.60, $factor, 0.0001);

        // 外层:再过 NPC 侧总帽 1.50,进结算的就是 1.50
        $sim = $this->simulateMinutes($city, 10);
        $base = (float) $this->outputRate('K01', 1, 'knowledge');
        $this->assertEqualsWithDelta($base * SimConstants::NPC_TOTAL_CAP, $sim['grossProductionPerMin']['knowledge'], 0.0001);
    }

    // 未派驻的 NPC 不给任何建筑加成(但照样发工资 —— 见下面的工资用例)
    public function test_idle_npc_does_not_boost_any_building(): void
    {
        [$city, $instanceId] = $this->makeCityWithFarm('npcidle');
        $this->putNpc($city, 'N005', null);

        $sim = $this->simulateMinutes($city, 10);

        $this->assertEqualsWithDelta(14.0, $sim['grossProductionPerMin']['food'], 0.0001);
        // 但工资照收:N005 wage 2/min
        $this->assertEqualsWithDelta(4.0 + 2.0, $sim['maintenanceMoneyPerMin'], 0.0001);
    }

    // ---- 工资 / 口粮:内核唯一的通用支出消费点 ----

    // 工资并进全城维护速率 → 欠费判定 / 财政预警 / 扣款三处自动同口径
    public function test_wage_is_merged_into_maintenance_rate_and_deducted(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeBareCity('npcwage');
        DB::table('cities')->where('id', $city->id)->update(['money' => 1000, 'population' => 0]);
        $this->putNpc($city->fresh(), 'N006', null); // wage 2/min,food 1/min

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        // 没有建筑 → 建筑维护 0;支出通道只有 NPC 工资 2/min
        $this->assertEqualsWithDelta(2.0, $sim['maintenanceMoneyPerMin'], 0.0001);
        // 人口 0 → 无税收;10 分钟扣 20
        $this->assertEqualsWithDelta(980.0, $sim['money'], 0.0001);
    }

    // 口粮与人均粮耗同级:不进配方、不受乘区与满足率影响
    public function test_food_upkeep_is_deducted_from_food_rate(): void
    {
        [$city, $instanceId] = $this->makeCityWithFarm('npcfood');
        $this->putNpc($city, 'N006', $instanceId); // food 1/min;主技能 PROCESSING → 与 F02 不对口

        $sim = $this->simulateMinutes($city, 10);

        // 产 14 × (1 + 0.07×0.25) = 14.245;口粮 -1 → 净 13.245
        $this->assertEqualsWithDelta(14.0 * 1.0175, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(14.0 * 1.0175 - 1.0, $sim['ratesPerMin']['food'], 0.0001);
    }

    // 辞退(status = left)之后既不加成也不再收工资 —— left 的行只为可追溯而保留
    public function test_left_npc_stops_costing_and_boosting(): void
    {
        [$city, $instanceId] = $this->makeCityWithFarm('npcleft');
        $npcId = $this->putNpc($city, 'N005', $instanceId);
        DB::table('city_npcs')->where('id', $npcId)
            ->update(['status' => NpcCode::STATUS_LEFT, 'assigned_instance_id' => null]);

        $sim = $this->simulateMinutes($city, 10);

        $this->assertEqualsWithDelta(14.0, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $sim['maintenanceMoneyPerMin'], 0.0001);
    }

    // ---- 夹帽只落在一处:§13 的总帽仍由 multiplierProduct 夹 ----

    public function test_npc_cap_does_not_replace_global_cap(): void
    {
        // NPC 侧内部帽 1.50 与 §13 的 2.75 是两件事,常量各自独立
        $this->assertSame(2.75, SimConstants::MULTIPLIER_CAP);
        $this->assertSame(1.50, SimConstants::NPC_TOTAL_CAP);
        // 七格全部顶满时仍由 multiplierProduct 夹到 2.75
        $this->assertSame(2.75, SimulationService::multiplierProduct(
            ['worker' => 1.0, 'power' => 1.0, 'logistics' => 1.0, 'tech' => 1.20,
                'npc' => 1.50, 'tool' => 1.18, 'event' => 1.30]
        ));
    }

    // ---- 夹具 ----

    private function makeBareCity(string $un): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);
        DB::table('cities')->where('id', $city->id)->update(['population' => 0, 'money' => 100000]);

        return $city->fresh();
    }

    private function makeCityWith(string $un, string $buildingId): array
    {
        $city = $this->makeBareCity($un);
        $workers = (int) DB::table('building_level_definition')
            ->where('building_id', $buildingId)->where('level', 1)->value('worker_required');
        $inst = CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => $buildingId, 'level' => 1,
            'x' => 1, 'y' => 1, 'status' => 'active', 'assigned_workers' => $workers,
        ]);

        return [$city->fresh(), (int) $inst->id];
    }

    private function makeCityWithFarm(string $un): array
    {
        return $this->makeCityWith($un, 'F02');
    }

    // 测试夹具:直接落一行 city_npcs(正常路径要走招募端点,那条链在 NpcApiTest 里单独验)
    private function putNpc(City $city, string $npcId, ?int $instanceId, ?int $level = null): int
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        return (int) DB::table('city_npcs')->insertGetId([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => $level ?? (int) $def->initial_skill_level,
            'xp' => 0, 'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => $instanceId === null ? NpcCode::STATUS_IDLE : NpcCode::STATUS_ASSIGNED,
            'assigned_instance_id' => $instanceId,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function simulateMinutes(City $city, int $minutes): array
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        DB::table('cities')->where('id', $city->id)->update(['last_simulated_at' => $base]);
        Carbon::setTestNow($base->copy()->addMinutes($minutes));

        return SimulationService::simulate($city->fresh());
    }

    private function outputRate(string $buildingId, int $level, string $resource): float
    {
        $json = DB::table('building_level_definition')
            ->where('building_id', $buildingId)->where('level', $level)->value('output_json');
        foreach (json_decode($json ?: '[]', true) as $o) {
            if ($o['resource'] === $resource) {
                return (float) $o['rate_per_min'];
            }
        }

        return 0.0;
    }
}
