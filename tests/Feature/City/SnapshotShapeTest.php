<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Item\ItemCode;
use App\Game\NPC\NpcCode;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M3-W7 契约缺口 ③⑦:/api/city 快照的士气离职阈值 + map 型字段的空值形状。
//
// ══ ⑦ 是什么坑 ═══════════════════════════════════════════════════════════════════
// PHP 的 json_encode 对关联数组有两种输出:有键时编成对象 `{"3":[7]}`,**空时编成数组** `[]`。
// 于是 npcs.assignments 这类字段的 JSON 形状随数据变化 —— 前端每个读它的地方都得先判一次
// 「这到底是数组还是对象」,漏判一处就是「没派人时面板直接报错」这种只在空态复现的 bug。
// W7 统一成对象:空时 `{}`。
//
// 断言必须打在**原始响应文本**上:json_decode(..., true) 会把 `{}` 和 `[]` 都变成 PHP 的 [],
// 用 assoc 数组断言等于什么都没验。所以本文件一律 json_decode($content)(不带 true),
// 再用 assertIsObject / assertIsArray 分辨两种形状。
//
// 反向纪律同样要验:**列表型必须保持数组**。把 list / buildings 包成对象会让前端的
// `.map()` 当场失效 —— 包错方向和不包一样有害。
class SnapshotShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @return array{0: User, 1: City} */
    private function makeCity(string $un): array
    {
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($user);

        return [$user, $city->fresh()];
    }

    // 取原始响应并按**对象**解析(不带 true):这是唯一分得清 {} 与 [] 的读法
    private function rawSnapshot(User $user): object
    {
        $res = $this->actingAs($user)->getJson('/api/city');
        $res->assertOk();

        return json_decode($res->getContent());
    }

    private function addNpc(City $city, string $npcId, ?int $instanceId = null): int
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        return (int) DB::table('city_npcs')->insertGetId([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => $instanceId === null ? NpcCode::STATUS_IDLE : NpcCode::STATUS_ASSIGNED,
            'assigned_instance_id' => $instanceId,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addItem(City $city, string $itemId, int $instanceId): int
    {
        $durability = (float) DB::table('item_definition')->where('item_id', $itemId)->value('durability');

        return (int) DB::table('city_items')->insertGetId([
            'city_id' => $city->id, 'item_id' => $itemId,
            'durability_left' => $durability, 'status' => ItemCode::STATUS_EQUIPPED,
            'equipped_instance_id' => $instanceId,
            'acquired_source' => ItemCode::SOURCE_CRAFT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------- ③ 士气离职阈值 ----------

    // 前端此前把 30 硬编码在 NPC 面板里 —— 后台一改 npc_morale_leave_threshold 就成了两套真相。
    // 阈值属于数值规格,只该有 game_settings 这一份口径
    public function test_snapshot_exposes_morale_leave_threshold(): void
    {
        [$user] = $this->makeCity('shapemorale');

        $npcs = $this->actingAs($user)->getJson('/api/city')->json('data.city.npcs');

        $this->assertArrayHasKey('morale_leave_threshold', $npcs);
        $this->assertEqualsWithDelta(30.0, $npcs['morale_leave_threshold'], 1e-9, 'A4 默认阈值 30');
    }

    // 改后台设定,快照立刻跟着变(证明它读的是 game_settings,不是代码里的第二份常量)
    public function test_morale_leave_threshold_follows_the_admin_setting(): void
    {
        [$user] = $this->makeCity('shapemorale2');

        GameSetting::set(GameSetting::NPC_MORALE_LEAVE_THRESHOLD, 45, null, 'test');
        GameSetting::flush();

        $npcs = $this->actingAs($user)->getJson('/api/city')->json('data.city.npcs');
        $this->assertEqualsWithDelta(45.0, $npcs['morale_leave_threshold'], 1e-9);
    }

    // ---------- ⑦ map 型字段的空值形状 ----------

    // 一个 NPC 都没派驻 / 一件工具都没装备时:两个 map 型字段必须是 `{}`,不是 `[]`
    public function test_empty_maps_serialize_as_objects(): void
    {
        [$user] = $this->makeCity('shapeempty');

        $city = $this->rawSnapshot($user)->data->city;

        $this->assertIsObject($city->npcs->assignments, '空派驻表必须是 {},不是 []');
        $this->assertIsObject($city->items->equipment, '空装备表必须是 {},不是 []');
        $this->assertIsObject($city->resources);
        $this->assertIsObject($city->rates_per_min);

        // 空对象的确没有任何键(形状变了,内容没变)
        $this->assertSame([], (array) $city->npcs->assignments);
        $this->assertSame([], (array) $city->items->equipment);
    }

    // 有派驻 / 有装备时**仍然是对象**,且键值内容与接线前逐字相同
    public function test_populated_maps_keep_the_same_object_shape_and_content(): void
    {
        [$user, $city] = $this->makeCity('shapefull');
        $instanceId = (int) CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'F01', 'level' => 1,
            'x' => 20, 'y' => 20, 'status' => 'active', 'assigned_workers' => 0,
        ])->id;

        $npcId = $this->addNpc($city, 'N002', $instanceId);
        $itemId = $this->addItem($city, 'IT001', $instanceId);

        $snapshot = $this->rawSnapshot($user)->data->city;

        $this->assertIsObject($snapshot->npcs->assignments);
        $this->assertIsObject($snapshot->items->equipment);
        // 键是建筑实例 id(JSON 对象的键一律是字符串),值是 id 列表
        $this->assertSame([$npcId], $snapshot->npcs->assignments->{$instanceId});
        $this->assertSame([$itemId], $snapshot->items->equipment->{$instanceId});
    }

    // **反向纪律** —— 列表型一律保持数组。包错方向会让前端的 .map() 当场失效
    public function test_list_shaped_fields_stay_arrays(): void
    {
        [$user, $city] = $this->makeCity('shapelist');
        CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'F01', 'level' => 1,
            'x' => 22, 'y' => 22, 'status' => 'active', 'assigned_workers' => 0,
        ]);

        $snapshot = $this->rawSnapshot($user)->data->city;

        $this->assertIsArray($snapshot->buildings);
        $this->assertIsArray($snapshot->npcs->list);
        $this->assertIsArray($snapshot->items->list);
        $this->assertIsArray($snapshot->technologies->unlocked);
        $this->assertIsArray($snapshot->events->active);
        // 空的列表型字段同样是 []:这座城一个 NPC / 工具 / 科技都没有
        $this->assertSame([], $snapshot->npcs->list);
        $this->assertSame([], $snapshot->technologies->unlocked);
    }

    // resources / rates_per_min:非空时也必须是对象(键是资源 code,不是下标)。
    // 这两个字段前端是当字典用的(`resources.food`),编成数组就取不到值了
    public function test_resource_maps_are_objects_when_populated(): void
    {
        [$user, $city] = $this->makeCity('shaperes');
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'food'],
            ['amount' => 123.5]
        );

        $snapshot = $this->rawSnapshot($user)->data->city;

        $this->assertIsObject($snapshot->resources);
        $this->assertEqualsWithDelta(123.5, $snapshot->resources->food, 1e-6);
        $this->assertIsObject($snapshot->rates_per_min);
    }

    // 全城资源行被清空的极端情况(拆完 + 吃光):resources 仍是 `{}`,不会退化成 `[]`
    public function test_resources_map_survives_an_empty_city(): void
    {
        [$user, $city] = $this->makeCity('shapebare');
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();
        DB::table('city_resources')->where('city_id', $city->id)->delete();

        $snapshot = $this->rawSnapshot($user)->data->city;

        $this->assertIsObject($snapshot->resources, '一行资源都没有时也必须是 {}');
    }

    // 假失败层:把断言换成 assoc 解码就什么都验不出来 —— 这条用例把这个陷阱本身钉住,
    // 免得后来人「顺手」把上面几条改成 $res->json(...) 之后以为还在验形状
    public function test_assoc_decoding_cannot_tell_the_two_shapes_apart(): void
    {
        [$user] = $this->makeCity('shapetrap');

        $assoc = $this->actingAs($user)->getJson('/api/city')->json('data.city.npcs.assignments');
        $raw = $this->rawSnapshot($user)->data->city->npcs->assignments;

        // assoc 侧:{} 和 [] 解出来都是 []
        $this->assertSame([], $assoc);
        // 原始侧:分得出来
        $this->assertIsObject($raw);
    }
}
