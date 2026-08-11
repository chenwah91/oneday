<?php

namespace Tests\Feature\Item;

use App\Game\City\CityFactory;
use App\Game\Item\ItemCode;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 工具三个端点的完整安全链(CLAUDE §42 / §83):
// 制作扣材料 / 时代与建筑前置 / 越权 / 幂等 / Revision / 装备互斥 / 槽位上限 / 卸下 / 快照契约。
class ItemApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ---- 制作 ----

    public function test_craft_deducts_materials_creates_item_and_bumps_revision(): void
    {
        [$u, $city] = $this->makePlayer('crafter', eraOrder: 1);
        $this->giveResources($city, ['wood' => 100, 'stone' => 100]);

        $res = $this->actingAs($u)->postJson('/api/city/item/craft', ['item_id' => 'IT001']);

        $res->assertOk();
        $row = DB::table('city_items')->where('city_id', $city->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('IT001', $row->item_id);
        $this->assertSame(ItemCode::STATUS_STORED, $row->status);
        $this->assertSame(ItemCode::SOURCE_CRAFT, $row->acquired_source);
        // 新造的工具满耐久(§7 IT001 = 60)
        $this->assertEqualsWithDelta(60.0, (float) $row->durability_left, 0.01);

        // 成本 = §7 的 wood 4 / stone 2 / money 2,服务器算,客户端不参与
        $resources = DB::table('city_resources')->where('city_id', $city->id)
            ->pluck('amount', 'resource_id');
        $this->assertEqualsWithDelta(96.0, (float) $resources['wood'], 0.01);
        $this->assertEqualsWithDelta(98.0, (float) $resources['stone'], 0.01);
        $this->assertSame(1, (int) $city->fresh()->revision);

        // 审计:delta 带材料变化,metadata 带耐久上限与档位
        $audit = DB::table('audit_logs')->where('action', 'ITEM.CRAFT')->latest('id')->first();
        $this->assertNotNull($audit);
        $delta = json_decode($audit->delta_json, true);
        $this->assertSame(-4.0, (float) $delta['wood']);
        $this->assertSame(60, json_decode($audit->metadata_json, true)['durability']);
    }

    public function test_craft_rejects_unknown_item(): void
    {
        [$u] = $this->makePlayer('unknown');

        $this->actingAs($u)->postJson('/api/city/item/craft', ['item_id' => 'IT999'])
            ->assertStatus(404)->assertJson(['error' => 'NOT_FOUND']);
        $this->assertSame(0, DB::table('city_items')->count());
    }

    public function test_craft_rejects_when_era_too_low(): void
    {
        // IT003 是时代 II 的工具,新城时代 I 做不了
        [$u, $city] = $this->makePlayer('lowera', eraOrder: 1);
        $this->giveResources($city, ['wood' => 100, 'stone' => 100]);

        $this->actingAs($u)->postJson('/api/city/item/craft', ['item_id' => 'IT003'])
            ->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);
        $this->assertSame(0, DB::table('city_items')->count());
    }

    public function test_craft_rejects_when_materials_insufficient(): void
    {
        [$u, $city] = $this->makePlayer('poor');
        // IT001 要 wood 4 / stone 2 / money 2
        $this->giveResources($city, ['wood' => 3, 'stone' => 100]);

        $this->actingAs($u)->postJson('/api/city/item/craft', ['item_id' => 'IT001'])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);
        $this->assertSame(0, DB::table('city_items')->count());
    }

    // §7 的 crafting_source:IT006 要「青铜作坊」(P03),城里没有就做不了
    public function test_craft_requires_the_crafting_building(): void
    {
        [$u, $city] = $this->makePlayer('nobuilding', eraOrder: 3);
        $this->giveResources($city, ['wood' => 100, 'stone' => 100, 'copper' => 100, 'bronze' => 100]);

        $this->actingAs($u)->postJson('/api/city/item/craft', ['item_id' => 'IT006'])
            ->assertStatus(422)->assertJson(['error' => 'CRAFTING_BUILDING_MISSING']);

        // 把青铜作坊盖起来(必须是 active:施工中的楼不算)
        $this->addBuilding($city, 'P03', 5);

        $this->actingAs($u)->postJson('/api/city/item/craft', ['item_id' => 'IT006'])->assertOk();
        $this->assertSame(1, DB::table('city_items')->count());
    }

    // 手工制作(crafting_building_id 为空)不设建筑门槛 —— §7 明文
    public function test_hand_crafted_items_need_no_building(): void
    {
        [$u, $city] = $this->makePlayer('hands');
        $this->giveResources($city, ['wood' => 100, 'stone' => 100]);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        $this->actingAs($u)->postJson('/api/city/item/craft', ['item_id' => 'IT002'])->assertOk();
        $this->assertSame(1, DB::table('city_items')->count());
    }

    public function test_craft_is_idempotent(): void
    {
        [$u, $city] = $this->makePlayer('idem');
        $this->giveResources($city, ['wood' => 100, 'stone' => 100]);

        $payload = ['item_id' => 'IT001', 'idempotency_key' => 'k-craft-1'];
        $this->actingAs($u)->postJson('/api/city/item/craft', $payload)->assertOk();
        $this->actingAs($u)->postJson('/api/city/item/craft', $payload)->assertOk();

        // 只造出一件,材料只扣一次
        $this->assertSame(1, DB::table('city_items')->count());
        $wood = DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $this->assertEqualsWithDelta(96.0, (float) $wood, 0.01);
    }

    public function test_craft_rejects_stale_revision(): void
    {
        [$u, $city] = $this->makePlayer('stale');
        $this->giveResources($city, ['wood' => 100, 'stone' => 100]);

        $this->actingAs($u)->postJson('/api/city/item/craft', [
            'item_id' => 'IT001', 'expected_revision' => 99,
        ])->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);

        $this->assertSame(0, DB::table('city_items')->count());
    }

    // 后台开关(运营救急):关掉制作后一律 ITEM_CRAFT_DISABLED
    public function test_craft_disabled_switch_blocks_crafting(): void
    {
        [$u, $city] = $this->makePlayer('switch');
        $this->giveResources($city, ['wood' => 100, 'stone' => 100]);

        GameSetting::set(GameSetting::ITEM_CRAFT_ENABLED, false, null, '测试停产');
        GameSetting::flush();

        $this->actingAs($u)->postJson('/api/city/item/craft', ['item_id' => 'IT001'])
            ->assertStatus(422)->assertJson(['error' => 'ITEM_CRAFT_DISABLED']);
        $this->assertSame(0, DB::table('city_items')->count());
    }

    // ---- 装备 / 卸下 ----

    public function test_equip_and_unequip_round_trip(): void
    {
        [$u, $city, $instanceId] = $this->makePlayerWithFarm('equipper');
        $itemId = $this->putItem($city, 'IT003');

        $this->actingAs($u)->postJson('/api/city/item/equip', [
            'city_item_id' => $itemId, 'building_instance_id' => $instanceId,
        ])->assertOk();

        $row = DB::table('city_items')->where('id', $itemId)->first();
        $this->assertSame(ItemCode::STATUS_EQUIPPED, $row->status);
        $this->assertSame($instanceId, (int) $row->equipped_instance_id);

        // 审计 delta 记下了这件工具给这栋楼的加成(§7 IT003 = 农业 +10%)
        $audit = DB::table('audit_logs')->where('action', 'ITEM.EQUIP')->latest('id')->first();
        $this->assertEqualsWithDelta(10.0, json_decode($audit->delta_json, true)['tool_bonus_pct'], 0.01);

        $this->actingAs($u)->postJson('/api/city/item/unequip', ['city_item_id' => $itemId])->assertOk();

        $row = DB::table('city_items')->where('id', $itemId)->first();
        $this->assertSame(ItemCode::STATUS_STORED, $row->status);
        $this->assertNull($row->equipped_instance_id);
        // 卸下保留耐久(backlog §4.2 明文)
        $this->assertEqualsWithDelta(80.0, (float) $row->durability_left, 0.01);
    }

    // 一件工具一栋楼:已装备的再装到别处必须 409,而不是静默换楼
    public function test_equip_rejects_already_equipped_item(): void
    {
        [$u, $city, $instanceId] = $this->makePlayerWithFarm('mutex');
        $second = $this->addBuilding($city, 'F02', 8);
        $itemId = $this->putItem($city, 'IT003');

        $this->actingAs($u)->postJson('/api/city/item/equip', [
            'city_item_id' => $itemId, 'building_instance_id' => $instanceId,
        ])->assertOk();

        $this->actingAs($u)->postJson('/api/city/item/equip', [
            'city_item_id' => $itemId, 'building_instance_id' => $second,
        ])->assertStatus(409)->assertJson(['error' => 'ITEM_ALREADY_EQUIPPED']);

        $this->assertSame($instanceId, (int) DB::table('city_items')->where('id', $itemId)->value('equipped_instance_id'));
    }

    // B2:槽位数后台可调,装满了返回 ITEM_SLOT_FULL
    public function test_equip_respects_slot_limit(): void
    {
        [$u, $city, $instanceId] = $this->makePlayerWithFarm('slots');
        $a = $this->putItem($city, 'IT003');
        $b = $this->putItem($city, 'IT011');
        $c = $this->putItem($city, 'IT001');

        foreach ([$a, $b] as $itemId) {
            $this->actingAs($u)->postJson('/api/city/item/equip', [
                'city_item_id' => $itemId, 'building_instance_id' => $instanceId,
            ])->assertOk();
        }

        // 默认 2 槽 → 第三件被拒
        $this->actingAs($u)->postJson('/api/city/item/equip', [
            'city_item_id' => $c, 'building_instance_id' => $instanceId,
        ])->assertStatus(422)->assertJson(['error' => 'ITEM_SLOT_FULL']);

        // 后台调到 3 槽后立刻生效(设定改动生效)
        GameSetting::set(GameSetting::ITEM_SLOTS_PER_BUILDING, 3, null, '测试扩槽');
        GameSetting::flush();

        $this->actingAs($u)->postJson('/api/city/item/equip', [
            'city_item_id' => $c, 'building_instance_id' => $instanceId,
        ])->assertOk();
    }

    // B2 的另一半:**同 category 的第二件不报错**,只是不生效(生效与否见 ItemMultiplierTest)
    public function test_equipping_second_item_of_same_category_is_allowed(): void
    {
        [$u, $city, $instanceId] = $this->makePlayerWithFarm('samecat');
        $a = $this->putItem($city, 'IT003');
        $b = $this->putItem($city, 'IT011'); // 同为 agriculture_tool

        foreach ([$a, $b] as $itemId) {
            $this->actingAs($u)->postJson('/api/city/item/equip', [
                'city_item_id' => $itemId, 'building_instance_id' => $instanceId,
            ])->assertOk();
        }

        $this->assertSame(2, DB::table('city_items')->where('equipped_instance_id', $instanceId)->count());
    }

    public function test_equip_rejects_broken_item(): void
    {
        [$u, $city, $instanceId] = $this->makePlayerWithFarm('broken');
        $itemId = $this->putItem($city, 'IT003');
        DB::table('city_items')->where('id', $itemId)
            ->update(['status' => ItemCode::STATUS_BROKEN, 'durability_left' => 0]);

        $this->actingAs($u)->postJson('/api/city/item/equip', [
            'city_item_id' => $itemId, 'building_instance_id' => $instanceId,
        ])->assertStatus(422)->assertJson(['error' => 'ITEM_BROKEN']);
    }

    // 施工中的楼不生产,装上去没有意义 → 404(与 NPC 派驻同一口径)
    public function test_equip_rejects_non_active_building(): void
    {
        [$u, $city, $instanceId] = $this->makePlayerWithFarm('constructing');
        DB::table('city_building_instances')->where('id', $instanceId)
            ->update(['status' => 'constructing', 'construction_finished_at' => now()->addHour()]);
        $itemId = $this->putItem($city, 'IT003');

        $this->actingAs($u)->postJson('/api/city/item/equip', [
            'city_item_id' => $itemId, 'building_instance_id' => $instanceId,
        ])->assertStatus(404);
    }

    // 重复卸下 = 成功的无操作:不报错、不 +revision、不刷审计
    public function test_unequip_is_a_no_op_when_not_equipped(): void
    {
        [$u, $city] = $this->makePlayer('noop');
        $itemId = $this->putItem($city, 'IT001');
        $before = (int) $city->fresh()->revision;

        $this->actingAs($u)->postJson('/api/city/item/unequip', ['city_item_id' => $itemId])->assertOk();

        $this->assertSame($before, (int) $city->fresh()->revision);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ITEM.UNEQUIP')->count());
    }

    // ---- 越权(CLAUDE §44 / §83)----

    public function test_cannot_equip_another_players_item(): void
    {
        [, $victimCity] = $this->makePlayer('victim');
        $victimItem = $this->putItem($victimCity, 'IT001');

        [$attacker, $attackerCity, $instanceId] = $this->makePlayerWithFarm('attacker');

        $this->actingAs($attacker)->postJson('/api/city/item/equip', [
            'city_item_id' => $victimItem, 'building_instance_id' => $instanceId,
        ])->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);

        // 越权必须留痕(§67 的「多城市请求 Ownership 失败」检测项)
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'SECURITY.AUTHORIZATION_FAILED')
            ->where('entity_type', 'city_item')->count());
        $this->assertNull(DB::table('city_items')->where('id', $victimItem)->value('equipped_instance_id'));
        $this->assertSame(0, DB::table('city_items')->where('city_id', $attackerCity->id)->count());
    }

    public function test_cannot_unequip_another_players_item(): void
    {
        [, $victimCity] = $this->makePlayer('victim2');
        $victimItem = $this->putItem($victimCity, 'IT001');

        [$attacker] = $this->makePlayer('attacker2');

        $this->actingAs($attacker)->postJson('/api/city/item/unequip', ['city_item_id' => $victimItem])
            ->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
    }

    // ---- 快照契约(M3-ITEM 锚点)----

    public function test_snapshot_exposes_items_block(): void
    {
        [$u, $city, $instanceId] = $this->makePlayerWithFarm('snap');
        $equipped = $this->putItem($city, 'IT003');
        $this->putItem($city, 'IT001');

        $this->actingAs($u)->postJson('/api/city/item/equip', [
            'city_item_id' => $equipped, 'building_instance_id' => $instanceId,
        ])->assertOk();

        $items = $this->actingAs($u)->getJson('/api/city')->assertOk()->json('data.city.items');

        $this->assertSame(2, $items['total']);
        $this->assertSame(1, $items['equipped']);
        $this->assertSame(1, $items['stored']);
        $this->assertSame(0, $items['broken']);
        $this->assertSame(2, $items['slots_per_building']);
        // 装备关系表:building_instance_id => [city_item_id…],前端画「装备」区块直接用
        $this->assertSame([$equipped], $items['equipment'][$instanceId]);

        // 契约字段一律 snake_case
        $row = $items['list'][0];
        foreach (['id', 'item_id', 'name_key', 'category', 'status', 'equipped_instance_id',
            'durability_left', 'durability_max', 'durability_warning', 'effect_code'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        foreach (array_keys($row) as $key) {
            $this->assertSame(strtolower($key), $key, "契约字段 {$key} 不是 snake_case 全小写");
        }
    }

    // ---- 夹具 ----

    private function makePlayer(string $un, int $eraOrder = 1): array
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $eraKey = DB::table('era')->where('era_order', $eraOrder)->value('era_key');
        DB::table('cities')->where('id', $city->id)
            ->update(['money' => 100000, 'era_key' => $eraKey, 'era_order' => $eraOrder]);

        return [$u, City::find($city->id)];
    }

    private function makePlayerWithFarm(string $un): array
    {
        [$u, $city] = $this->makePlayer($un, eraOrder: 5);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        return [$u, $city, $this->addBuilding($city, 'F02', 1)];
    }

    private function addBuilding(City $city, string $buildingId, int $x): int
    {
        return (int) CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => $buildingId, 'level' => 1,
            'x' => $x, 'y' => 1, 'status' => 'active', 'assigned_workers' => 0,
        ])->id;
    }

    // 测试夹具:直接落一行 city_items(制作链路本身在上面的用例里单独验)
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

    private function giveResources(City $city, array $amounts): void
    {
        foreach ($amounts as $code => $amount) {
            DB::table('city_resources')->updateOrInsert(
                ['city_id' => $city->id, 'resource_id' => $code],
                ['amount' => $amount]
            );
        }
    }
}
