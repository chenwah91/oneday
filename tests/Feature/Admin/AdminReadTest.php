<?php

namespace Tests\Feature\Admin;

use App\Game\City\CityFactory;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        // role 已不可批量赋值,测试里用 forceFill 显式提权
        $user = User::create(['username' => 'adm', 'name' => 'adm', 'email' => 'adm@a.com', 'password' => 'password123']);
        $user->forceFill(['role' => 'admin'])->save();
        return $user;
    }

    public function test_players_list(): void
    {
        $p = User::create(['username' => 'someplayer', 'name' => 'someplayer', 'email' => 'sp@p.com', 'password' => 'password123']);
        CityFactory::createForUser($p);
        $res = $this->actingAs($this->admin())->getJson('/api/admin/players');
        $res->assertOk()->assertJsonStructure(['data' => ['players' => [['id', 'username', 'email', 'role']]]]);
        $this->assertTrue(collect($res->json('data.players'))->contains('username', 'someplayer'));
    }

    // 确认列表响应里不会泄漏密码字段
    public function test_players_list_no_password_leak(): void
    {
        $p = User::create(['username' => 'nopass', 'name' => 'nopass', 'email' => 'np@p.com', 'password' => 'password123']);
        CityFactory::createForUser($p);
        $res = $this->actingAs($this->admin())->getJson('/api/admin/players');
        $res->assertOk();
        $body = $res->getContent();
        $this->assertStringNotContainsString('password', $body);
    }

    public function test_player_detail_includes_city_summary(): void
    {
        $p = User::create(['username' => 'detailplayer', 'name' => 'detailplayer', 'email' => 'dp@p.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($p);
        $res = $this->actingAs($this->admin())->getJson('/api/admin/players/' . $p->id);
        $res->assertOk()->assertJsonStructure([
            'data' => [
                'player' => ['id', 'username', 'email', 'role'],
                'city' => ['id', 'revision', 'population', 'money', 'buildingCount'],
            ],
        ]);
        $this->assertSame($city->id, $res->json('data.city.id'));
        $body = $res->getContent();
        $this->assertStringNotContainsString('password', $body);
    }

    public function test_player_detail_not_found(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/players/999999');
        $res->assertStatus(404);
    }

    // ---------- 玩家详情全景页(W13-1)----------

    // 各分区都在、名称联查生效、上界(settled 10 / trades 20 / recent_audit 20)守得住
    public function test_player_detail_full_sections_and_bounds(): void
    {
        $admin = $this->admin();
        $p = User::create(['username' => 'panorama', 'name' => 'panorama', 'email' => 'pn@p.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($p);
        $cityId = (int) $city->id;
        $now = now();

        // 建筑:手工造一栋(building_id 取定义表现有行,不写死 code),同时做 NPC 的派驻岗位
        $buildingId = (string) DB::table('building_definition')->orderBy('building_id')->value('building_id');
        $instanceId = (int) DB::table('city_building_instances')->insertGetId([
            'city_id' => $cityId, 'building_id' => $buildingId, 'level' => 2, 'assigned_workers' => 3,
            'x' => 10, 'y' => 20, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // NPC:在岗,派驻到第一栋建筑(npc_id 取定义表现有行,不写死 code)
        $npcId = (string) DB::table('npc_definition')->orderBy('npc_id')->value('npc_id');
        DB::table('city_npcs')->insert([
            'city_id' => $cityId, 'npc_id' => $npcId, 'skill_level' => 3, 'xp' => 0,
            'skill_value' => 40, 'morale' => 70, 'status' => 'assigned',
            'assigned_instance_id' => $instanceId,
            'acquired_source' => 'recruit', 'acquired_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // 科技:一条已解锁
        $techId = (string) DB::table('technology_definition')->orderBy('tech_id')->value('tech_id');
        DB::table('city_technologies')->insert([
            'city_id' => $cityId, 'tech_id' => $techId, 'status' => 'unlocked',
            'started_at' => $now->copy()->subHour(), 'finished_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // 工具:一件库存
        $itemId = (string) DB::table('item_definition')->orderBy('item_id')->value('item_id');
        DB::table('city_items')->insert([
            'city_id' => $cityId, 'item_id' => $itemId, 'durability_left' => 5,
            'status' => 'stored', 'acquired_source' => 'craft', 'acquired_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // 事件:1 条生效 + 12 条已结算(已结算分区上限 10,必须多造两条才验得出封顶)
        $eventId = (string) DB::table('event_definition')->orderBy('event_id')->value('event_id');
        DB::table('city_events')->insert([
            'city_id' => $cityId, 'event_id' => $eventId, 'status' => 'active',
            'triggered_at' => $now, 'expires_at' => $now->copy()->addHour(), 'window_index' => 1,
        ]);
        for ($i = 0; $i < 12; $i++) {
            DB::table('city_events')->insert([
                'city_id' => $cityId, 'event_id' => $eventId, 'status' => 'resolved',
                'triggered_at' => $now->copy()->subMinutes(30 + $i), 'expires_at' => $now->copy()->subMinutes($i),
                'resolved_at' => $now->copy()->subMinutes($i), 'chosen_option' => 'a', 'window_index' => 100 + $i,
            ]);
        }

        // 市场交易:25 条 MARKET.BUY(交易分区上限 20;同时也是 recent_audit 的封顶素材)
        for ($i = 0; $i < 25; $i++) {
            AuditLogger::record(AuditAction::MARKET_BUY, 'success', [
                'user_id' => $p->id, 'city_id' => $cityId,
                'delta_json' => ['wood' => 5, 'money' => -30],
            ]);
        }

        $res = $this->actingAs($admin)->getJson('/api/admin/players/' . $p->id)->assertOk();

        // 旧契约不破坏(player / city 的既有键),新分区键全部就位
        $res->assertJsonStructure(['data' => [
            'player' => ['id', 'username', 'email', 'role', 'created_at', 'banned_at', 'ban_reason'],
            'city'   => ['id', 'revision', 'population', 'money', 'buildingCount',
                'name', 'era_key', 'era_order', 'happiness', 'map_width', 'map_height',
                'last_simulated_at', 'created_at'],
            'resources', 'buildings', 'npcs', 'technologies', 'items',
            'events' => ['active', 'settled'], 'trades', 'recent_audit',
        ]]);

        // 资源:建城赠送的初始库存要在,且逐行带显示名
        $resources = $res->json('data.resources');
        $this->assertNotEmpty($resources);
        foreach ($resources as $row) {
            $this->assertNotSame('', (string) $row['name']);
        }

        // 建筑:行数与 DB 一致,名称来自 building_definition 联查
        $buildings = $res->json('data.buildings');
        $this->assertSame(DB::table('city_building_instances')->where('city_id', $cityId)->count(), count($buildings));
        $defName = DB::table('building_definition')->where('building_id', $buildings[0]['building_id'])->value('name');
        $this->assertSame($defName, $buildings[0]['name']);

        // NPC:岗位解析出建筑实例 id 与建筑名
        $npcs = $res->json('data.npcs');
        $this->assertCount(1, $npcs);
        $this->assertSame('assigned', $npcs[0]['status']);
        $this->assertSame($instanceId, $npcs[0]['assigned_instance_id']);
        $this->assertNotNull($npcs[0]['assigned_building_name']);
        $this->assertNotSame('', (string) $npcs[0]['name']);

        // 科技 / 工具
        $this->assertSame($techId, $res->json('data.technologies.0.tech_id'));
        $this->assertSame('unlocked', $res->json('data.technologies.0.status'));
        $this->assertSame($itemId, $res->json('data.items.0.item_id'));
        $this->assertNotNull($res->json('data.items.0.durability_max'), '耐久上限应联查自 item_definition');

        // 事件:active 1 条,settled 封顶 10(造了 12 条)
        $this->assertCount(1, $res->json('data.events.active'));
        $this->assertSame($eventId, $res->json('data.events.active.0.event_id'));
        $this->assertCount(10, $res->json('data.events.settled'));

        // 交易:封顶 20(造了 25 条),且 delta 解码成映射
        $trades = $res->json('data.trades');
        $this->assertCount(20, $trades);
        $this->assertSame(AuditAction::MARKET_BUY, $trades[0]['action']);
        $this->assertSame(5.0, (float) $trades[0]['delta']['wood']);

        // 最近审计:封顶 20,且行里只有轻量四键(大 JSON 列不下发)
        $audit = $res->json('data.recent_audit');
        $this->assertCount(20, $audit);
        $this->assertSame(['id', 'action', 'occurred_at', 'status'], array_keys($audit[0]));
    }

    // 没有城市的玩家:city=null,各分区给空数组(不是 null / 不是缺键)
    public function test_player_detail_without_city_gives_empty_sections(): void
    {
        $p = User::create(['username' => 'nocity', 'name' => 'nocity', 'email' => 'nc@p.com', 'password' => 'password123']);
        $res = $this->actingAs($this->admin())->getJson('/api/admin/players/' . $p->id)->assertOk();

        $this->assertNull($res->json('data.city'));
        $this->assertSame([], $res->json('data.resources'));
        $this->assertSame([], $res->json('data.buildings'));
        $this->assertSame([], $res->json('data.npcs'));
        $this->assertSame([], $res->json('data.technologies'));
        $this->assertSame([], $res->json('data.items'));
        $this->assertSame([], $res->json('data.events.active'));
        $this->assertSame([], $res->json('data.events.settled'));
        $this->assertSame([], $res->json('data.trades'));
        $this->assertSame([], $res->json('data.recent_audit'));
    }

    // 敏感列绝不出现在响应任何角落(password / remember_token)
    public function test_player_detail_never_leaks_sensitive_columns(): void
    {
        $p = User::create(['username' => 'sensitive', 'name' => 'sensitive', 'email' => 'ss@p.com', 'password' => 'password123']);
        CityFactory::createForUser($p);

        $body = $this->actingAs($this->admin())->getJson('/api/admin/players/' . $p->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('remember_token', $body);
    }

    // 权限:未登录 401、普通玩家 403(read_player 由 EnsureAdmin 拦截)
    public function test_player_detail_requires_read_player_permission(): void
    {
        $target = User::create(['username' => 'permtarget', 'name' => 'permtarget', 'email' => 'pt@p.com', 'password' => 'password123']);

        // 未登录 401。必须排在所有 actingAs 之前 —— actingAs 会把用户挂在 guard 上对后续请求生效
        $this->getJson('/api/admin/players/' . $target->id)->assertStatus(401);

        $player = User::create(['username' => 'permdenied', 'name' => 'permdenied', 'email' => 'pd@p.com', 'password' => 'password123']);
        $this->actingAs($player)->getJson('/api/admin/players/' . $target->id)->assertStatus(403);
    }

    public function test_audit_list(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/audit');
        $res->assertOk()->assertJsonStructure(['data' => ['audit']]);
    }

    // 审计列表应能按 action 过滤,且能看到刚才 admin 自己登录相关的操作产生的记录
    public function test_audit_list_filter_by_action(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/api/admin/players');
        $res = $this->actingAs($admin)->getJson('/api/admin/audit?action=SECURITY.AUTHORIZATION_FAILED');
        $res->assertOk();
        foreach ($res->json('data.audit') as $row) {
            $this->assertSame('SECURITY.AUTHORIZATION_FAILED', $row['action']);
        }
    }

    // limit 需要被 clamp 到 <=200,超大值不应报错也不应超出上限
    public function test_audit_list_limit_clamped(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/audit?limit=99999');
        $res->assertOk();
        $this->assertLessThanOrEqual(200, count($res->json('data.audit')));
    }
}
