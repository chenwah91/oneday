<?php

namespace Tests\Feature\Item;

use App\Game\City\CityFactory;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// R1-B 契约缺口:GET /api/definitions/items(玩家侧工具制作目录)。
//
// 补的缺口:制作必须提交 item_id(§7 是配方合成不是抽奖),而「有哪些工具 / 材料成本多少 /
// 要什么时代与制作建筑」三样只在 item_definition 里 —— 玩家侧一个都读不到
//(/api/admin/definitions/items 是 edit_definition 权限的后台端点,普通玩家 403),
// 前端 item-panel.js 的制作区因此只能降级成一句缺口提示。
//
// 用例分四层(与 NpcDefinitionApiTest 逐层同构):
//   ① 内容层:24 件全在,字段与 §7 逐格对得上;
//   ② 泄露层(假失败):effect_json 的 specs 结构**一个字都不许出现在响应里**;
//   ③ 安全层:未登录 401;两个玩家拿到的响应必须逐字节相同(定义端点不许夹带玩家数据);
//   ④ 限流层:与其它 definitions 端点同挂 throttle:api。
class ItemDefinitionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function makePlayer(string $un): array
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        return [$u, City::find($city->id)];
    }

    // ---------- ① 内容层 ----------

    public function test_lists_every_item_with_craft_catalog_fields(): void
    {
        [$u] = $this->makePlayer('itemdefapi');

        $res = $this->actingAs($u)->getJson('/api/definitions/items');
        $res->assertOk();

        $items = $res->json('data.items');
        // §7 的 24 件工具,一件都不能少
        $this->assertCount(24, $items);
        $this->assertSame(DB::table('item_definition')->count(), count($items));

        $byId = collect($items)->keyBy('item_id');

        // IT001 伐木斧:§7 首行的黄金样本(抄错一格这里就红)
        $it001 = $byId['IT001'];
        $this->assertSame('item.IT001.name', $it001['name_key']);
        // §7 里工具没有中文名,equip_target_desc_zh 是唯一的中文显示成分
        $this->assertSame('伐木工', $it001['equip_target_desc_zh']);
        $this->assertSame('gathering_tool', $it001['category']);
        $this->assertSame('I', $it001['min_era']);
        $this->assertSame(1, $it001['min_era_order']);
        $this->assertSame(60, $it001['durability']);
        $this->assertSame('normal', $it001['durability_tier']);
        $this->assertSame('work_minutes', $it001['durability_mode']);
        $this->assertSame('wood_output_pct', $it001['effect_code']);
        // 浮点字段一律用 delta 比:JSON 会把 8.0 序列化成 `8`,回来就成了 int(与 assertSame 不合)
        $this->assertEqualsWithDelta(8.0, $it001['effect_value'], 1e-9);
        $this->assertSame('percent', $it001['unit']);
        // 制作成本 = §7 的 wood 4 / stone 2 / money 2(0 的列不出现在 map 里)
        $this->assertSame(['wood', 'stone', 'money'], array_keys($it001['craft_cost']));
        $this->assertEqualsWithDelta(4.0, $it001['craft_cost']['wood'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $it001['craft_cost']['money'], 1e-9);
        // 手工制作:两列都空 = §7 明文的「无需建筑」,前端据此不画建筑前置
        $this->assertSame('手工制作', $it001['crafting_source_desc_zh']);
        $this->assertNull($it001['crafting_building_id']);
        $this->assertSame('早期工具', $it001['note']);

        // IT017 工业工程师工具:拿它验「有制作建筑时确实下发 building_id」+ 高时代序号
        $it017 = $byId['IT017'];
        $this->assertSame('P08', $it017['crafting_building_id'], '机械厂是 94 栋里能精确对上的建筑');
        $this->assertSame('机械厂', $it017['crafting_source_desc_zh']);
        $this->assertSame('VIII', $it017['min_era']);
        $this->assertSame(8, $it017['min_era_order'], 'min_era VIII → era_order 8');
        $this->assertSame('industrial', $it017['durability_tier']);

        // IT012 医疗道具:durability_mode = uses(一次性消耗品),玩家要在做之前就看得出来
        $this->assertSame('uses', $byId['IT012']['durability_mode']);

        // §7 点名的建筑不在 94 栋内的那 6 件(木工作坊 / 石工作坊 / 工坊 / 研究院 / 现代工厂):
        // 用户 2026-08-12 拍板改挂现有建筑(400001 迁移),building_id 不再为空;
        // 而 desc 仍是 §7 原文 —— 前端显示「制作于:木工作坊」,同时能按 building_id 判断「还没建」
        $this->assertSame('P02', $byId['IT003']['crafting_building_id']);
        $this->assertSame('木工作坊', $byId['IT003']['crafting_source_desc_zh']);
    }

    // 与定义表逐行一致(下发的是 item_definition,不是代码里的第二份常量)
    public function test_rows_match_the_definition_table_verbatim(): void
    {
        [$u] = $this->makePlayer('itemdefrows');

        $items = collect($this->actingAs($u)->getJson('/api/definitions/items')->json('data.items'))
            ->keyBy('item_id');

        foreach (DB::table('item_definition')->get() as $row) {
            $sent = $items[$row->item_id] ?? null;
            $this->assertNotNull($sent, "{$row->item_id} 没有下发");
            $this->assertSame((string) $row->name_key, $sent['name_key']);
            $this->assertSame((string) $row->category, $sent['category']);
            $this->assertSame((int) $row->durability, $sent['durability']);
            $this->assertSame((string) $row->effect_code, $sent['effect_code']);
            $this->assertEqualsWithDelta((float) $row->effect_value, $sent['effect_value'], 1e-9);
            $this->assertSame(json_decode($row->craft_cost_json, true), array_map(
                fn ($v) => 0 + $v,
                $sent['craft_cost']
            ), "{$row->item_id} 的制作成本与定义表不一致");
        }
    }

    // 制作目录的两个外键必须都翻得出来:
    //   ① craft_cost 的每个 code 都要能在 resource_definition 里查到 —— 否则前端拿不到中文名,
    //      成本行会显示一个裸 code(资源中文名只存在 /api/definitions/resources 那一处,§13);
    //   ② crafting_building_id 非空时必须是 94 栋之一 —— 否则前端的「还没建」判断永远为真。
    public function test_referenced_codes_are_all_resolvable_by_other_definition_endpoints(): void
    {
        [$u] = $this->makePlayer('itemdefref');

        $items = $this->actingAs($u)->getJson('/api/definitions/items')->json('data.items');

        $resourceIds = DB::table('resource_definition')->pluck('resource_id')->all();
        $buildingIds = DB::table('building_definition')->pluck('building_id')->all();

        foreach ($items as $item) {
            $this->assertNotSame([], $item['craft_cost'], "{$item['item_id']} 的制作成本为空(制作路径会当成定义损坏拒绝)");
            foreach (array_keys($item['craft_cost']) as $code) {
                $this->assertContains($code, $resourceIds, "{$item['item_id']} 的成本资源 {$code} 不在资源定义里");
            }
            if ($item['crafting_building_id'] !== null) {
                $this->assertContains($item['crafting_building_id'], $buildingIds);
            }
        }
    }

    // ---------- ② 泄露层(假失败)----------

    // effect_json 的 specs 是**内核内部表达**(target / scope / op / value / scope_key),
    // 与 npc_definition.trait_json 同一条纪律:下发有两害 ——
    //   ① 客户端不可信(§31 / §66):前端拿 specs 自己算加成,永远算不出与服务端乘区一致的数;
    //   ② 它是内部结构,target 名单随波次增删,下发等于把它变成对外契约。
    // 展示信息由 §7 原文三列(effect_code / effect_value / unit)给足。
    // 另两列不下发:unmapped_zh / crafting_unmapped_zh 是「本波没映射上」的内部排查记录;
    // trade_value 是将来拆解返还的基数(B5 已批 M3 不做工具交易),下发等于承诺一个不存在的卖出价
    public function test_internal_effect_specs_are_never_exposed(): void
    {
        [$u] = $this->makePlayer('itemleak');

        $body = $this->actingAs($u)->getJson('/api/definitions/items')->getContent();

        foreach (['effect_json', '"specs"', '"target"', 'scope_key', 'unmapped_zh', 'trade_value'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "响应里不该出现内部结构 {$needle}");
        }

        // 但展示用的三列必须在(不下发 specs 的前提是这三列给足)
        $this->assertStringContainsString('effect_code', $body);
        $this->assertStringContainsString('effect_value', $body);
        $this->assertStringContainsString('craft_cost', $body);
    }

    // ---------- ③ 安全层 ----------

    public function test_requires_auth(): void
    {
        $this->getJson('/api/definitions/items')->assertStatus(401);
    }

    // 越权面:definitions 是**全服共享的静态定义**,不该带任何玩家数据。
    // 两个不同玩家拿到的响应必须逐字节相同 —— 一旦有人往里塞「我做过几件 / 我材料够不够」,
    // 这条就会红,提醒他那属于快照(city.items 块)而不是定义端点
    public function test_two_players_receive_byte_identical_definitions(): void
    {
        [$a, $cityA] = $this->makePlayer('itemdefa');
        [$b] = $this->makePlayer('itemdefb');

        // 给 A 造一件工具 + 一堆材料,制造「两人状态完全不同」的前提
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $cityA->id, 'resource_id' => 'wood'], ['amount' => 500]
        );
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $cityA->id, 'resource_id' => 'stone'], ['amount' => 500]
        );
        DB::table('cities')->where('id', $cityA->id)->update(['money' => 5000]);
        $this->actingAs($a)->postJson('/api/city/item/craft', ['item_id' => 'IT001'])->assertOk();

        $bodyA = $this->actingAs($a)->getJson('/api/definitions/items')->getContent();
        $bodyB = $this->actingAs($b)->getJson('/api/definitions/items')->getContent();

        $this->assertSame($bodyA, $bodyB, '定义端点不许夹带玩家数据');
    }

    // ---------- ④ 限流层 ----------

    // 与 buildings / resources / technologies / npcs 同一档(auth:web + throttle:api)。
    // §48「不同操作使用不同限制」的落地方式就是这份逐路由的 middleware 名单
    public function test_route_carries_the_same_limiter_as_other_definition_endpoints(): void
    {
        $routes = collect(app('router')->getRoutes())->keyBy(fn ($r) => $r->uri());

        $this->assertArrayHasKey('api/definitions/items', $routes->all());

        $mine = $routes['api/definitions/items']->gatherMiddleware();
        $peer = $routes['api/definitions/technologies']->gatherMiddleware();

        $this->assertContains('throttle:api', $mine);
        $this->assertContains('auth:web', $mine);
        $this->assertSame(array_values($peer), array_values($mine), '与其它 definitions 端点的中间件必须完全一致');
    }
}
