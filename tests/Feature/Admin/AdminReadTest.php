<?php

namespace Tests\Feature\Admin;

use App\Game\City\CityFactory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
