<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_guest_denied(): void
    {
        $this->getJson('/api/admin/players')->assertStatus(401);
    }

    // 玩家只有**游戏会话**(web guard)时打后台:2026-08-15 起后台走独立的 admin guard,
    // 请求在 auth:admin 就被挡成 401,压根走不到 EnsureAdmin —— 状态码由改动前的 403 变成 401。
    //
    // 但**审计必须照留**(2026-08-16 补):「已登录玩家在扫后台 API」是最有价值的入侵信号
    // (§60 授权失败必记 / §67 该行为要形成 security_flags)。改用 auth:admin 之后这条路径不再
    // 经过 EnsureAdmin,原本必然写下的那条审计会整条消失 —— 现在由 bootstrap/app.php 的
    // AuthenticationException 分支补回。reason_code 用 NO_ADMIN_SESSION,与 EnsureAdmin 的
    // NOT_ADMIN(有后台会话但角色不够)刻意分开:两者的处置优先级不同。
    public function test_player_web_session_gets_401_on_admin_api_and_is_still_audited(): void
    {
        $u = User::create(['username' => 'websessionplayer', 'name' => 'websessionplayer', 'email' => 'w@p.com', 'password' => 'password123']);

        $this->actingAs($u, 'web')->getJson('/api/admin/players')
            ->assertStatus(401)->assertJson(['error' => 'AUTH_REQUIRED']);

        $audit = DB::table('audit_logs')->where('action', 'SECURITY.AUTHORIZATION_FAILED')->latest('id')->first();
        $this->assertNotNull($audit, '已登录玩家探测后台 API 必须留痕');
        $this->assertSame('NO_ADMIN_SESSION', $audit->reason_code);
        $this->assertSame($u->id, (int) $audit->user_id);
    }

    // 第二道闸:**持有后台会话**但角色不是后台人员 → 403 + 审计。
    // 现实场景是「管理员登进后台之后被降级 / role 被写脏」,登录口那道闸只在登录当时判一次,
    // 会话存活期内的每个请求由 EnsureAdmin 兜住(Fail Closed)
    public function test_non_staff_holding_admin_session_is_forbidden_and_audited(): void
    {
        $u = User::create(['username' => 'plainplayer', 'name' => 'plainplayer', 'email' => 'p@p.com', 'password' => 'password123']);
        $this->actingAs($u, 'admin')->getJson('/api/admin/players')
            ->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_admin_allowed(): void
    {
        // role 已不可批量赋值,测试里用 forceFill 显式提权
        $u = User::create(['username' => 'bossadmin', 'name' => 'bossadmin', 'email' => 'a@a.com', 'password' => 'password123']);
        $u->forceFill(['role' => 'admin'])->save();
        $this->actingAs($u, 'admin')->getJson('/api/admin/players')->assertOk();
    }

    public function test_promote_command(): void
    {
        User::create(['username' => 'tobeadmin', 'name' => 'tobeadmin', 'email' => 't@a.com', 'password' => 'password123']);
        $this->artisan('admin:promote tobeadmin')->assertExitCode(0);
        $this->assertSame('admin', User::where('username', 'tobeadmin')->value('role'));
    }

    // role 不在 $fillable 内,批量赋值应被忽略,新用户一律落到默认的 player
    public function test_role_not_mass_assignable(): void
    {
        $user = User::create([
            'username' => 'sneaky', 'name' => 'sneaky', 'email' => 'sneaky@a.com',
            'password' => 'password123', 'role' => 'admin',
        ]);
        // 刚 create() 出来的实例只带已被批量赋值的属性,role 没被赋值时在内存里是 null;
        // 需要 refresh() 从数据库重读,才能看到列默认值 'player' 是否被绕过
        $this->assertSame('player', $user->refresh()->role);
        $this->assertSame('player', User::where('username', 'sneaky')->value('role'));
    }
}
