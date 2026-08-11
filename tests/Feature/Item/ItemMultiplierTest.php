<?php

namespace Tests\Feature\Item;

use App\Game\City\CityFactory;
use App\Game\Item\ItemCode;
use App\Game\Modifier\ModifierBus;
use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierTarget;
use App\Game\Simulation\SimConstants;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// tool 乘区的黄金样本(v3.2 §7 + backlog §4.3):
// 同类只取最高 / 不同类相乘 / 作用域命中判定 / §13 总帽仍然只夹一次。
class ItemMultiplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // resource 作用域:「木材产量 +8%」落到产木材的建筑上
    public function test_resource_scoped_tool_applies_to_producer(): void
    {
        [$city, $lumber] = $this->cityWith(['R01' => 1]);
        $this->equip($city, 'IT001', $lumber);

        $this->assertEqualsWithDelta(1.08, $this->toolMultiplier($city, $lumber), 0.0001);
    }

    // 同一件工具装在不产该资源的楼上 = 不生效(乘区恒 1.0),而不是「白加 8%」
    public function test_resource_scoped_tool_does_not_apply_elsewhere(): void
    {
        [$city, $farm] = $this->cityWith(['F02' => 1]);
        $this->equip($city, 'IT001', $farm);

        $this->assertEqualsWithDelta(1.0, $this->toolMultiplier($city, $farm), 0.0001);
    }

    // building_category 作用域:农业工具落到 food_production 的所有建筑
    public function test_category_scoped_tool_applies_by_building_category(): void
    {
        [$city, $farm] = $this->cityWith(['F02' => 1]);
        $this->equip($city, 'IT003', $farm);

        $this->assertEqualsWithDelta(1.10, $this->toolMultiplier($city, $farm), 0.0001);
    }

    // §7 明文:**同一建筑内同 category 只取最高值**(防止堆低级工具)。
    // IT003 +10% 与 IT011 +16% 同为 agriculture_tool → 只有 16% 生效,不是 1.10 × 1.16
    public function test_same_category_takes_the_highest_only(): void
    {
        [$city, $farm] = $this->cityWith(['F02' => 1]);
        $this->equip($city, 'IT003', $farm);
        $this->equip($city, 'IT011', $farm);

        $this->assertEqualsWithDelta(1.16, $this->toolMultiplier($city, $farm), 0.0001);
    }

    // backlog §4.3:不同 category 相乘。
    // K01 学堂产 knowledge:IT014(academic_item +10%)× IT021(research_tool +22%)= 1.342
    public function test_different_categories_multiply(): void
    {
        [$city, $school] = $this->cityWith(['K01' => 1]);
        $this->equip($city, 'IT014', $school);
        $this->equip($city, 'IT021', $school);

        $this->assertEqualsWithDelta(1.342, $this->toolMultiplier($city, $school), 0.0001);
    }

    // 「取最高」按**对这栋楼的实际贡献**取:装错地方的高级工具不该把同类里真正生效的顶掉。
    // IT006(矿业 +14%,只作用于金属/燃料)与 IT009(矿业 +18%)都装在学堂上 → 两者都不生效
    public function test_highest_is_measured_by_actual_contribution(): void
    {
        [$city, $school] = $this->cityWith(['K01' => 1]);
        $this->equip($city, 'IT006', $school);
        $this->equip($city, 'IT014', $school);

        // 矿业工具在学堂上贡献 0,只剩 academic_item 的 +10%
        $this->assertEqualsWithDelta(1.10, $this->toolMultiplier($city, $school), 0.0001);
    }

    // 非产量类效果(建造速度 / 维护成本 / 治理容量)**不进 tool 乘区** —— 它们各有消费点
    public function test_non_production_effects_do_not_enter_the_tool_slot(): void
    {
        [$city, $farm] = $this->cityWith(['F02' => 1]);
        $this->equip($city, 'IT005', $farm);  // construction_speed_pct
        $this->equip($city, 'IT016', $farm);  // maintenance_cost_reduction_pct

        $this->assertEqualsWithDelta(1.0, $this->toolMultiplier($city, $farm), 0.0001);
    }

    // 未装备(stored)与已损毁(broken)的工具一律不给加成
    public function test_stored_and_broken_items_give_no_bonus(): void
    {
        [$city, $farm] = $this->cityWith(['F02' => 1]);

        // stored:造出来了但没装
        $this->putItem($city, 'IT003');
        $this->assertEqualsWithDelta(1.0, $this->toolMultiplier($city, $farm), 0.0001);

        // 装上但耐久已归零(理论上会被耐久结算翻成 broken,这里直接构造极端状态)
        $itemId = $this->equip($city, 'IT011', $farm);
        DB::table('city_items')->where('id', $itemId)->update(['durability_left' => 0]);
        $this->assertEqualsWithDelta(1.0, $this->toolMultiplier($city, $farm), 0.0001);
    }

    // §13 的总帽仍然只在 SimulationService::multiplierProduct() 夹一次:
    // 工具侧不设第二道帽(Provider 交出的是未夹的原值),乘积才由内核统一封顶
    public function test_tool_slot_does_not_clamp_itself_but_product_does(): void
    {
        [$city, $school] = $this->cityWith(['K01' => 1]);
        $this->equip($city, 'IT014', $school);
        $this->equip($city, 'IT021', $school);

        $tool = $this->toolMultiplier($city, $school);
        $this->assertEqualsWithDelta(1.342, $tool, 0.0001, '工具侧不许自己夹帽');

        // 与一个极端的 npc 乘区一起进内核 → 由 multiplierProduct 夹到 §13 的 2.75
        $this->assertEqualsWithDelta(
            SimConstants::MULTIPLIER_CAP,
            SimulationService::multiplierProduct(['tool' => $tool, 'npc' => 3.0]),
            0.0001
        );
    }

    // ---- 夹具 ----

    // 建一座只含指定建筑的城市,返回 [city, 第一栋的实例 id]
    private function cityWith(array $buildings): array
    {
        static $seq = 0;
        $seq++;
        $un = 'toolm' . $seq;

        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = City::find(CityFactory::createForUser($u)->id);
        DB::table('cities')->where('id', $city->id)->update(['era_order' => 10, 'money' => 1000000]);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        $first = null;
        $x = 1;
        foreach ($buildings as $buildingId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $id = (int) CityBuildingInstance::create([
                    'city_id' => $city->id, 'building_id' => $buildingId, 'level' => 1,
                    'x' => $x, 'y' => 1, 'status' => 'active', 'assigned_workers' => 0,
                ])->id;
                $first ??= $id;
                $x += 3;
            }
        }

        return [$city->fresh(), $first];
    }

    private function putItem(City $city, string $itemId): int
    {
        $durability = DB::table('item_definition')->where('item_id', $itemId)->value('durability');

        return (int) DB::table('city_items')->insertGetId([
            'city_id' => $city->id, 'item_id' => $itemId,
            'durability_left' => $durability, 'status' => ItemCode::STATUS_STORED,
            'equipped_instance_id' => null, 'acquired_source' => ItemCode::SOURCE_CRAFT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function equip(City $city, string $itemId, int $instanceId): int
    {
        $id = $this->putItem($city, $itemId);
        DB::table('city_items')->where('id', $id)->update([
            'status' => ItemCode::STATUS_EQUIPPED, 'equipped_instance_id' => $instanceId,
        ]);

        return $id;
    }

    // 直接跑一遍总线的准备段,取出该实例的 tool 乘区值(不经内核的分段结算,断言更干净)
    private function toolMultiplier(City $city, int $instanceId): float
    {
        $bus = ModifierBus::default();

        $rows = DB::table('city_building_instances as ci')
            ->join('building_level_definition as bl', function ($j) {
                $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
            })
            ->where('ci.city_id', $city->id)
            ->get(['ci.id as instance_id', 'ci.building_id', 'bl.output_json']);

        $units = [];
        foreach ($rows as $row) {
            $outputs = [];
            foreach (json_decode($row->output_json ?: '[]', true) ?: [] as $output) {
                $outputs[$output['resource']] = (float) $output['rate_per_min'];
            }
            $units[] = [
                'instanceId' => (int) $row->instance_id,
                'buildingId' => $row->building_id,
                'grossOut'   => $outputs,
                'grossIn'    => [],
            ];
        }

        $bus->prepare(new ModifierContext(
            cityId: (int) $city->id,
            eraOrder: (int) $city->era_order,
            buildingIds: $rows->pluck('building_id')->unique()->all(),
            capacities: [],
            city: DB::table('cities')->where('id', $city->id)->first(),
            now: now(),
            totalMinutes: 1.0,
        ), $units);

        $unit = collect($units)->firstWhere('instanceId', $instanceId);

        return $bus->multipliersFor($unit)[ModifierTarget::SLOT_TOOL];
    }
}
