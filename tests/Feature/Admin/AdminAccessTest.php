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

    public function test_player_forbidden_and_audited(): void
    {
        $u = User::create(['username' => 'plainplayer', 'name' => 'plainplayer', 'email' => 'p@p.com', 'password' => 'password123']);
        $this->actingAs($u)->getJson('/api/admin/players')
            ->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_admin_allowed(): void
    {
        // role 已不可批量赋值,测试里用 forceFill 显式提权
        $u = User::create(['username' => 'bossadmin', 'name' => 'bossadmin', 'email' => 'a@a.com', 'password' => 'password123']);
        $u->forceFill(['role' => 'admin'])->save();
        $this->actingAs($u)->getJson('/api/admin/players')->assertOk();
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
