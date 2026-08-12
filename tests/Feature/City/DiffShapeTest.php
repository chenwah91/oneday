<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Item\ItemCode;
use App\Game\NPC\NpcCode;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M3-W7 契约缺口 ⑦ 的 **Mutation 侧**:所有 diff 响应里的 map 型字段一律「空时 {}、非空时对象」。
//
// ══ 坑与 SnapshotShapeTest 是同一个 ═══════════════════════════════════════════════
// PHP 的 json_encode 对关联数组有两种输出:有键时编成对象 `{"wood":-20}`,**空时编成数组** `[]`。
// 快照侧 W7 已经用 ApiResponse::map 收口,diff 侧当时还是裸数组 —— 于是「前端一套解析代码通吃
// 快照与 diff」这个前提有裂缝:同一个 data.delta,建造时是对象、派驻 NPC 时是数组。
//
// 空态尤其藏得深,因为它只在特定操作上出现:
//   · 时代升级 —— 门槛而非费用,delta **恒为空**;
//   · NPC 派驻 / 工具装备 —— 不动资源,delta 恒为空;
//   · 所有幂等重放路径 —— 不重复扣费,delta 恒为空;
//   · 拆除 / 取消升级的 truncated —— 没被仓储上限截断时恒为空。
// 这几条正是「平时点点没事,某个操作一做前端就炸」的来源。
//
// ══ 断言必须打在原始响应文本上 ═════════════════════════════════════════════════════
// json_decode(..., true) 会把 `{}` 和 `[]` 都变成 PHP 的 [],用 assoc 数组断言等于什么都没验。
// 所以本文件一律:① json_decode($content)(不带 true)后用 assertIsObject 分辨形状;
//                ② 再对**原始文本**直接断言含 `"delta":{}` / 不含 `"delta":[]`。
// 第 ② 条是防呆:哪天有人把 ① 改成 $res->json(...),② 会立刻把他拦下来。
//
// **反向纪律**同样要验:列表型(technologies.unlocked / era.next.requirements)必须保持数组,
// 包错方向会让前端的 .map() 当场失效。
class DiffShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- 断言工具 ----------

    /**
     * 发一次 Mutation,返回 [按对象解析出来的 data, 原始响应文本]。
     * 不带 true 的 json_decode 是唯一分得清 {} 与 [] 的读法。
     *
     * @return array{0: object, 1: string}
     */
    private function rawPost(User $user, string $url, array $payload = []): array
    {
        $res = $this->actingAs($user)->postJson($url, $payload);
        $res->assertOk();
        $content = $res->getContent();

        return [json_decode($content)->data, $content];
    }

    // map 型字段:必须是对象。非空时顺带确认它确实有键(不是被包成了空壳)
    private function assertMap(object $data, string $key, string $content, string $why): void
    {
        $this->assertTrue(property_exists($data, $key), "diff 里应当有 {$key} 字段");
        $this->assertIsObject($data->{$key}, $why);
        // 原始文本层的防呆:`"key":[` 说明它被编成了数组
        $this->assertStringNotContainsString('"' . $key . '":[', $content, "{$key} 在原始响应里不能是数组");
    }

    // 空 map:原始文本必须逐字含 `"key":{}`,且绝不含 `"key":[]`
    private function assertEmptyMap(object $data, string $key, string $content, string $why): void
    {
        $this->assertMap($data, $key, $content, $why);
        $this->assertSame([], (array) $data->{$key}, "{$key} 应当是空的(形状变了,内容没变)");
        $this->assertStringContainsString('"' . $key . '":{}', $content, $why);
        $this->assertStringNotContainsString('"' . $key . '":[]', $content, $why);
    }

    // 非空 map:是对象且至少有一个键
    private function assertFilledMap(object $data, string $key, string $content, string $why): void
    {
        $this->assertMap($data, $key, $content, $why);
        $this->assertNotSame([], (array) $data->{$key}, "{$key} 本用例里应当非空");
    }

    // ---------- 夹具 ----------

    private function makeUser(string $un): User
    {
        return User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
    }

    /** 一座城:资源与资金铺足,时代与科技按需要垫高 @return array{0: User, 1: City} */
    private function makeCity(string $un, int $eraOrder = 1, float $amount = 100000.0): array
    {
        $user = $this->makeUser($un);
        $city = CityFactory::createForUser($user);

        $eraKey = DB::table('era')->where('era_order', $eraOrder)->value('era_key');
        DB::table('cities')->where('id', $city->id)->update([
            'money' => 100000, 'era_key' => $eraKey, 'era_order' => $eraOrder,
        ]);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => $amount]);

        return [$user, City::find($city->id)];
    }

    private function addBuilding(City $city, string $buildingId = 'F02', int $x = 1, int $level = 1): int
    {
        return (int) CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => $buildingId, 'level' => $level,
            'x' => $x, 'y' => 1, 'status' => 'active', 'assigned_workers' => 0,
        ])->id;
    }

    private function putNpc(City $city, string $npcId = 'N005'): int
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        return (int) DB::table('city_npcs')->insertGetId([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => NpcCode::STATUS_IDLE, 'assigned_instance_id' => null,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function putItem(City $city, string $itemId = 'IT003'): int
    {
        $durability = (float) DB::table('item_definition')->where('item_id', $itemId)->value('durability');

        return (int) DB::table('city_items')->insertGetId([
            'city_id' => $city->id, 'item_id' => $itemId,
            'durability_left' => $durability, 'status' => ItemCode::STATUS_STORED,
            'equipped_instance_id' => null, 'acquired_source' => ItemCode::SOURCE_CRAFT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------- 建造 / 升级 / 取消 / 拆除 ----------

    // 建造:delta 有扣费(非空),resources 是资源字典 —— 两者都得是对象
    public function test_build_diff_maps_are_objects(): void
    {
        [$user, $city] = $this->makeCity('diffbuild', eraOrder: 2);
        $this->unlockTechFor($city->id, 'F02');

        // 地图 20×20(SimConstants::MAP_W),选一块建城时没占用的空地
        [$data, $content] = $this->rawPost($user, '/api/city/build', ['building_id' => 'F02', 'x' => 15, 'y' => 15]);

        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象(前端按 resources.wood 取值)');
        $this->assertFilledMap($data, 'delta', $content, '建造扣费后 delta 非空');
        $this->assertEqualsWithDelta(-20.0, $data->delta->wood, 1e-6, 'F02 L1 木材 20');
    }

    // 建造的幂等重放:不重复扣费 → delta 恒为空,正是最容易退化成 `[]` 的一条路径
    public function test_build_replay_returns_an_empty_delta_object(): void
    {
        [$user, $city] = $this->makeCity('diffbuildreplay', eraOrder: 2);
        $this->unlockTechFor($city->id, 'F02');
        $body = ['building_id' => 'F02', 'x' => 15, 'y' => 15, 'idempotency_key' => 'diff-shape-build'];

        $this->actingAs($user)->postJson('/api/city/build', $body)->assertOk();
        [$data, $content] = $this->rawPost($user, '/api/city/build', $body);

        $this->assertEmptyMap($data, 'delta', $content, '幂等重放不扣费,空 delta 必须是 {} 而不是 []');
        $this->assertFilledMap($data, 'resources', $content, '重放仍要回当前资源字典');
    }

    // 升级:下单扣材料 → delta 非空;幂等重放 → delta 空对象
    public function test_upgrade_diff_and_replay_map_shapes(): void
    {
        [$user, $city] = $this->makeCity('diffupgrade');
        $instanceId = $this->addBuilding($city);
        $body = ['instance_id' => $instanceId, 'idempotency_key' => 'diff-shape-upgrade'];

        [$data, $content] = $this->rawPost($user, '/api/city/upgrade', $body);
        $this->assertFilledMap($data, 'delta', $content, '升级下单扣材料,delta 非空');
        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');

        [$replay, $replayContent] = $this->rawPost($user, '/api/city/upgrade', $body);
        $this->assertEmptyMap($replay, 'delta', $replayContent, '升级重放的空 delta 必须是 {}');
    }

    // 取消升级:delta 是退回来的材料(非空),truncated 在没被仓储截断时为空 ——
    // 不包 map 就成了「正常退款时 truncated 是 [],被截断时才是 {}」的双形状
    public function test_upgrade_cancel_truncated_is_an_empty_object_when_nothing_was_capped(): void
    {
        // 资源够升级、又远离仓储上限(默认 1000),退款才不会被截断
        [$user, $city] = $this->makeCity('diffcancel', amount: 300.0);
        $instanceId = $this->addBuilding($city);
        $this->actingAs($user)->postJson('/api/city/upgrade', ['instance_id' => $instanceId])->assertOk();

        [$data, $content] = $this->rawPost($user, '/api/city/upgrade/cancel', ['instance_id' => $instanceId]);

        $this->assertFilledMap($data, 'delta', $content, '取消升级会退材料,delta 非空');
        $this->assertEmptyMap($data, 'truncated', $content, '没被仓储截断时 truncated 必须是 {}');
    }

    // 拆除:delta 非空(退材料)、truncated 空 —— 两个 map 同一条口径
    public function test_demolish_diff_maps_are_objects(): void
    {
        [$user, $city] = $this->makeCity('diffdemolish', amount: 10.0);
        $instanceId = $this->addBuilding($city);

        [$data, $content] = $this->rawPost($user, '/api/city/demolish', ['instance_id' => $instanceId]);

        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');
        $this->assertFilledMap($data, 'delta', $content, '拆除会退材料,delta 非空');
        $this->assertEmptyMap($data, 'truncated', $content, '没被仓储截断时 truncated 必须是 {}');
    }

    // 拆除的幂等重放:实例已删,delta 与 truncated **同时**为空 —— 一次验两个空 map
    public function test_demolish_replay_returns_empty_delta_and_truncated_objects(): void
    {
        [$user, $city] = $this->makeCity('diffdemolishreplay', amount: 10.0);
        $instanceId = $this->addBuilding($city);
        $body = ['instance_id' => $instanceId, 'idempotency_key' => 'diff-shape-demolish'];

        $this->actingAs($user)->postJson('/api/city/demolish', $body)->assertOk();
        [$data, $content] = $this->rawPost($user, '/api/city/demolish', $body);

        $this->assertEmptyMap($data, 'delta', $content, '重放不重复退款,delta 必须是 {}');
        $this->assertEmptyMap($data, 'truncated', $content, '重放的 truncated 同样必须是 {}');
    }

    // ---------- 科技 / 时代 ----------

    // 研究:扣知识 → delta 非空。顺带钉住反向纪律 —— technologies.unlocked 是**列表**,保持数组
    public function test_research_diff_maps_are_objects_and_tech_list_stays_an_array(): void
    {
        [$user, $city] = $this->makeCity('diffresearch');
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'knowledge'],
            ['amount' => 100000]
        );

        [$data, $content] = $this->rawPost($user, '/api/city/research', ['tech_id' => 'TECH_I_SUST']);

        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');
        $this->assertFilledMap($data, 'delta', $content, '研究扣知识,delta 非空');
        // 反向纪律:列表型包成对象会让前端的 .map() 当场失效
        $this->assertIsArray($data->technologies->unlocked, 'unlocked 是列表型,必须保持数组');
    }

    // 时代升级是**门槛而非费用**,delta 恒为空 —— 本波次最典型的一条空态路径。
    // 同时钉住 era.next.requirements 是列表型,保持数组
    public function test_era_upgrade_delta_is_always_an_empty_object(): void
    {
        [$user, $city] = $this->makeQualifiedCity('differa');

        [$data, $content] = $this->rawPost($user, '/api/city/era/upgrade');

        $this->assertEmptyMap($data, 'delta', $content, '时代升级不扣费,delta 恒为空 → 必须是 {}');
        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');
        $this->assertIsArray($data->era->next->requirements, 'requirements 是列表型,必须保持数组');
    }

    // 一座「刚好全部达标」的时代 I 城市(条件见 v3.2 §5.1 I→II 那一行)
    private function makeQualifiedCity(string $un): array
    {
        $user = $this->makeUser($un);
        $city = CityFactory::createForUser($user);

        foreach ([['H01', 0, 0], ['H01', 2, 0], ['H01', 4, 0], ['S01', 6, 0], ['F01', 0, 3], ['A01', 4, 3], ['D01', 8, 0]] as [$bid, $x, $y]) {
            CityBuildingInstance::create([
                'city_id' => $city->id, 'building_id' => $bid, 'level' => 1,
                'x' => $x, 'y' => $y, 'status' => 'active', 'assigned_workers' => 0,
            ]);
        }

        DB::table('cities')->where('id', $city->id)->update(['population' => 60, 'money' => 500, 'happiness' => 60]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'food'], ['amount' => 400]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'knowledge'], ['amount' => 0]);

        return [$user, City::find($city->id)];
    }

    // ---------- NPC ----------

    // 招募:扣资金 → delta 非空
    public function test_npc_recruit_diff_maps_are_objects(): void
    {
        [$user] = $this->makeCity('diffrecruit', eraOrder: 2);

        [$data, $content] = $this->rawPost($user, '/api/city/npc/recruit');

        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');
        $this->assertFilledMap($data, 'delta', $content, '招募扣资金,delta 非空');
    }

    // 派驻 / 撤下不动资源 → delta 恒为空。前端的 NPC 面板正是空态最先炸的地方
    public function test_npc_assign_and_unassign_keep_an_empty_delta_object(): void
    {
        [$user, $city] = $this->makeCity('diffassign', eraOrder: 2);
        $instanceId = $this->addBuilding($city);
        $npcId = $this->putNpc($city);

        [$data, $content] = $this->rawPost($user, '/api/city/npc/assign', [
            'city_npc_id' => $npcId, 'building_instance_id' => $instanceId,
        ]);
        $this->assertEmptyMap($data, 'delta', $content, '派驻不动资源,空 delta 必须是 {}');
        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');

        [$off, $offContent] = $this->rawPost($user, '/api/city/npc/unassign', ['city_npc_id' => $npcId]);
        $this->assertEmptyMap($off, 'delta', $offContent, '撤下同样不动资源,空 delta 必须是 {}');
    }

    // ---------- 工具 ----------

    // 制作:扣材料 → delta 非空
    public function test_item_craft_diff_maps_are_objects(): void
    {
        [$user] = $this->makeCity('diffcraft');

        [$data, $content] = $this->rawPost($user, '/api/city/item/craft', ['item_id' => 'IT001']);

        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');
        $this->assertFilledMap($data, 'delta', $content, '制作扣材料,delta 非空');
    }

    // 装备 / 卸下不动资源 → delta 恒为空(与 NPC 派驻同一条坑)
    public function test_item_equip_and_unequip_keep_an_empty_delta_object(): void
    {
        [$user, $city] = $this->makeCity('diffequip', eraOrder: 5);
        $instanceId = $this->addBuilding($city);
        $itemId = $this->putItem($city);

        [$data, $content] = $this->rawPost($user, '/api/city/item/equip', [
            'city_item_id' => $itemId, 'building_instance_id' => $instanceId,
        ]);
        $this->assertEmptyMap($data, 'delta', $content, '装备不动资源,空 delta 必须是 {}');
        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');

        [$off, $offContent] = $this->rawPost($user, '/api/city/item/unequip', ['city_item_id' => $itemId]);
        $this->assertEmptyMap($off, 'delta', $offContent, '卸下同样不动资源,空 delta 必须是 {}');
    }

    // ---------- 市场 ----------

    // 买入 / 卖出都动资源与资金 → delta 非空;幂等重放 → delta 空对象
    public function test_market_trade_diff_and_replay_map_shapes(): void
    {
        [$user] = $this->makeCity('difftrade', amount: 500.0);
        $body = ['resource_code' => 'iron', 'quantity' => 10, 'idempotency_key' => 'diff-shape-trade'];

        [$data, $content] = $this->rawPost($user, '/api/market/buy', $body);
        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');
        $this->assertFilledMap($data, 'delta', $content, '买入动资源与资金,delta 非空');

        [$replay, $replayContent] = $this->rawPost($user, '/api/market/buy', $body);
        $this->assertEmptyMap($replay, 'delta', $replayContent, '交易重放不重复成交,空 delta 必须是 {}');
    }

    // ---------- 事件 ----------

    // 结算:选项带资金损益 → delta 非空;幂等重放 → delta 空对象
    public function test_event_resolve_diff_and_replay_map_shapes(): void
    {
        [$user, $city] = $this->makeCity('diffevent', eraOrder: 3);
        $instanceId = $this->seedActiveEvent($city);
        $body = ['event_instance_id' => $instanceId, 'choice' => 'b', 'idempotency_key' => 'diff-shape-event'];

        [$data, $content] = $this->rawPost($user, '/api/city/events/resolve', $body);
        $this->assertFilledMap($data, 'resources', $content, '资源字典必须是对象');
        $this->assertFilledMap($data, 'delta', $content, '小型庆典扣资金,delta 非空');

        [$replay, $replayContent] = $this->rawPost($user, '/api/city/events/resolve', $body);
        $this->assertEmptyMap($replay, 'delta', $replayContent, '事件重放不重复结算,空 delta 必须是 {}');
    }

    // 直接落一条 active 的城市庆典实例(测试夹具:正常路径要等窗口掷点,与本文件验的形状无关)
    private function seedActiveEvent(City $city): int
    {
        return (int) DB::table('city_events')->insertGetId([
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'status' => 'active',
            'triggered_at' => now(), 'expires_at' => now()->addHours(2),
            'rolled_json' => json_encode([]), 'applied_json' => json_encode([]),
            // 触发窗口号(city_events 没有 timestamps 列,但 window_index 是必填)
            'window_index' => 1,
        ]);
    }

    // ---------- 工人分配:本 diff 没有 map 型字段 ----------

    // 审计结论落成用例:工人分配不动资源,diff 里根本没有 resources / delta。
    // 哪天有人「顺手」往里加一个 map 字段,这条会提醒他一起过 ApiResponse::map
    public function test_worker_assign_diff_carries_no_map_fields(): void
    {
        [$user, $city] = $this->makeCity('diffworker');
        $instanceId = $this->addBuilding($city);

        [$data] = $this->rawPost($user, '/api/city/workers/assign', ['instance_id' => $instanceId, 'workers' => 4]);

        $this->assertFalse(property_exists($data, 'resources'), '工人分配不返回资源字典');
        $this->assertFalse(property_exists($data, 'delta'), '工人分配不返回 delta');
        $this->assertIsInt($data->available_workers, '全是标量,没有 map 型字段');
    }

    // ---------- 极端空态:一行资源都没有 ----------

    // 全城资源行被清空时,resources 仍是 `{}` 而不是 `[]`(与 SnapshotShapeTest 的同名极端用例对齐)。
    // 挑派驻做载体:它是唯一一个既不读也不写资源的 Mutation
    public function test_resources_map_survives_a_city_with_no_resource_rows(): void
    {
        [$user, $city] = $this->makeCity('diffbare', eraOrder: 2);
        $instanceId = $this->addBuilding($city);
        $npcId = $this->putNpc($city);
        DB::table('city_resources')->where('city_id', $city->id)->delete();

        [$data, $content] = $this->rawPost($user, '/api/city/npc/assign', [
            'city_npc_id' => $npcId, 'building_instance_id' => $instanceId,
        ]);

        $this->assertEmptyMap($data, 'resources', $content, '一行资源都没有时 resources 也必须是 {}');
    }

    // ---------- 假失败层 ----------

    // 把断言换成 assoc 解码就什么都验不出来 —— 这条用例把这个陷阱本身钉住,
    // 免得后来人「顺手」把上面几条改成 $res->json(...) 之后以为还在验形状
    public function test_assoc_decoding_cannot_tell_the_two_shapes_apart(): void
    {
        [$user, $city] = $this->makeCity('difftrap', eraOrder: 2);
        $instanceId = $this->addBuilding($city);
        $npcId = $this->putNpc($city);

        $res = $this->actingAs($user)->postJson('/api/city/npc/assign', [
            'city_npc_id' => $npcId, 'building_instance_id' => $instanceId,
        ])->assertOk();

        // assoc 侧:{} 和 [] 解出来都是 []
        $this->assertSame([], $res->json('data.delta'));
        // 原始侧:分得出来
        $this->assertIsObject(json_decode($res->getContent())->data->delta);
        $this->assertStringContainsString('"delta":{}', $res->getContent());
    }
}
