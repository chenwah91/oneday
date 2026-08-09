<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 管理员角色分级(CLAUDE §63):权限梯度 + Fail Closed + 不可自助提权
class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // 建一个指定角色的用户。role 不可批量赋值,只能 forceFill 显式写入
    private function userWithRole(string $role, string $username): User
    {
        $user = User::create([
            'username' => $username, 'name' => $username,
            'email' => $username . '@example.com', 'password' => 'password123',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    // 取一条真实存在的建筑等级定义,避免测试写死具体 buildingId
    private function anyBuildingLevel(): object
    {
        return DB::table('building_level_definition')->orderBy('building_id')->orderBy('level')->first();
    }

    // 提交一次 Definition 调整(值 +1,不改变数值语义方向)
    private function postDefinitionEdit(User $actor): \Illuminate\Testing\TestResponse
    {
        $row = $this->anyBuildingLevel();

        return $this->actingAs($actor)->postJson('/api/admin/definitions/building-level', [
            'buildingId' => $row->building_id,
            'level'      => $row->level,
            'field'      => 'worker_required',
            'value'      => (int) $row->worker_required + 1,
            'reason'     => '角色权限测试',
        ]);
    }

    // ---------- 权限梯度 ----------

    public function test_support_can_read_audit_but_cannot_edit_definition(): void
    {
        $support = $this->userWithRole(Role::SUPPORT, 'supportuser');

        $this->actingAs($support)->getJson('/api/admin/audit')->assertOk();
        $this->actingAs($support)->getJson('/api/admin/players')->assertOk();
        $this->postDefinitionEdit($support)->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
    }

    public function test_game_master_can_read_but_cannot_edit_definition(): void
    {
        $gm = $this->userWithRole(Role::GAME_MASTER, 'gmuser');

        $this->actingAs($gm)->getJson('/api/admin/players')->assertOk();
        $this->actingAs($gm)->getJson('/api/admin/audit')->assertOk();
        $this->postDefinitionEdit($gm)->assertStatus(403);
    }

    public function test_admin_can_edit_definition(): void
    {
        $admin = $this->userWithRole(Role::ADMIN, 'roleadmin');
        $row = $this->anyBuildingLevel();

        $this->postDefinitionEdit($admin)->assertOk();

        $after = DB::table('building_level_definition')
            ->where('building_id', $row->building_id)->where('level', $row->level)->value('worker_required');
        $this->assertSame((int) $row->worker_required + 1, (int) $after);
    }

    // super_admin 继承下级全部权限
    public function test_super_admin_inherits_all_permissions(): void
    {
        $su = $this->userWithRole(Role::SUPER_ADMIN, 'superuser');

        $this->actingAs($su)->getJson('/api/admin/players')->assertOk();
        $this->postDefinitionEdit($su)->assertOk();
    }

    // ---------- Fail Closed ----------

    public function test_player_forbidden_on_every_admin_endpoint(): void
    {
        $player = $this->userWithRole(Role::PLAYER, 'plainuser');
        $row = $this->anyBuildingLevel();

        foreach (['/api/admin/me', '/api/admin/players', '/api/admin/players/1', '/api/admin/audit',
            '/api/admin/definitions/building-levels?buildingId=' . $row->building_id] as $path) {
            $this->actingAs($player)->getJson($path)->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
        }
        $this->postDefinitionEdit($player)->assertStatus(403);
    }

    // 数据库里出现未知角色值(脏数据/人为写入)时一律拒绝,绝不「认不出来就放行」
    public function test_unknown_role_is_denied(): void
    {
        $weird = $this->userWithRole(Role::PLAYER, 'weirduser');
        $weird->forceFill(['role' => 'wizard'])->save();

        $this->actingAs($weird)->getJson('/api/admin/players')->assertStatus(403);
        $this->actingAs($weird)->getJson('/api/admin/me')->assertStatus(403);
        $this->postDefinitionEdit($weird)->assertStatus(403);
    }

    // 越权被拒必须留痕:审计 SECURITY.AUTHORIZATION_FAILED,metadata 带 required_permission
    public function test_missing_permission_is_audited_with_required_permission(): void
    {
        $support = $this->userWithRole(Role::SUPPORT, 'auditsupport');
        $this->postDefinitionEdit($support)->assertStatus(403);

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', $audit->action);
        $this->assertSame('MISSING_PERMISSION', $audit->reason_code);
        $meta = json_decode($audit->metadata_json, true);
        $this->assertSame(Role::EDIT_DEFINITION, $meta['required_permission']);
        $this->assertSame(Role::SUPPORT, $meta['role']);
    }

    // ---------- /api/admin/me ----------

    public function test_me_returns_role_and_permissions(): void
    {
        $support = $this->userWithRole(Role::SUPPORT, 'meuser');
        $res = $this->actingAs($support)->getJson('/api/admin/me');
        $res->assertOk();
        $this->assertSame(Role::SUPPORT, $res->json('data.role'));
        $this->assertSame('meuser', $res->json('data.username'));

        $permissions = $res->json('data.permissions');
        $this->assertContains(Role::READ_PLAYER, $permissions);
        $this->assertContains(Role::READ_AUDIT, $permissions);
        $this->assertNotContains(Role::ADJUST_RESOURCE, $permissions);
        $this->assertNotContains(Role::EDIT_DEFINITION, $permissions);
        $this->assertNotContains(Role::MANAGE_ADMIN, $permissions);

        // super_admin 应拿到全部权限
        $su = $this->userWithRole(Role::SUPER_ADMIN, 'mesuper');
        $this->assertSame(
            Role::permissions(),
            $this->actingAs($su)->getJson('/api/admin/me')->json('data.permissions')
        );
    }

    // ---------- Role 纯函数 ----------

    public function test_role_matrix(): void
    {
        // 权限梯度:高角色继承低角色
        $this->assertTrue(Role::allows(Role::SUPPORT, Role::READ_AUDIT));
        $this->assertTrue(Role::allows(Role::ADMIN, Role::READ_AUDIT));
        $this->assertFalse(Role::allows(Role::SUPPORT, Role::ADJUST_RESOURCE));
        $this->assertTrue(Role::allows(Role::GAME_MASTER, Role::ADJUST_RESOURCE));
        $this->assertFalse(Role::allows(Role::GAME_MASTER, Role::EDIT_DEFINITION));
        $this->assertTrue(Role::allows(Role::ADMIN, Role::BAN_PLAYER));
        $this->assertFalse(Role::allows(Role::ADMIN, Role::MANAGE_ADMIN));
        $this->assertTrue(Role::allows(Role::SUPER_ADMIN, Role::MANAGE_ADMIN));

        // player 没有任何后台权限
        $this->assertSame([], Role::permissionsFor(Role::PLAYER));
        $this->assertFalse(Role::isStaff(Role::PLAYER));
        $this->assertTrue(Role::isStaff(Role::SUPPORT));

        // Fail Closed:未知角色 / 未知权限 / null 一律 false
        $this->assertFalse(Role::allows('wizard', Role::READ_AUDIT));
        $this->assertFalse(Role::allows(null, Role::READ_AUDIT));
        $this->assertFalse(Role::allows(Role::SUPER_ADMIN, 'launch_missiles'));
        $this->assertFalse(Role::isStaff(null));
        $this->assertFalse(Role::isValid('Admin')); // 大小写敏感,不做模糊匹配
    }

    // ---------- admin:promote ----------

    public function test_promote_accepts_whitelisted_roles(): void
    {
        User::create(['username' => 'promoteme', 'name' => 'promoteme', 'email' => 'pm@a.com', 'password' => 'password123']);

        $this->artisan('admin:promote promoteme support')->assertExitCode(0);
        $this->assertSame(Role::SUPPORT, User::where('username', 'promoteme')->value('role'));

        $this->artisan('admin:promote promoteme super_admin')->assertExitCode(0);
        $this->assertSame(Role::SUPER_ADMIN, User::where('username', 'promoteme')->value('role'));

        // 省略 role 参数时仍默认 admin(兼容原有用法)
        $this->artisan('admin:promote promoteme')->assertExitCode(0);
        $this->assertSame(Role::ADMIN, User::where('username', 'promoteme')->value('role'));
    }

    public function test_promote_rejects_invalid_role(): void
    {
        User::create(['username' => 'badrole', 'name' => 'badrole', 'email' => 'br@a.com', 'password' => 'password123']);

        $this->artisan('admin:promote badrole wizard')->assertExitCode(1);
        $this->assertSame(Role::PLAYER, User::where('username', 'badrole')->value('role'));
    }

    // ---------- 不可自助提权 ----------

    // role 不在 $fillable 内:注册/创建时带 role 一律被忽略,super_admin 也不例外
    public function test_role_not_mass_assignable_for_super_admin(): void
    {
        $user = User::create([
            'username' => 'sneakysuper', 'name' => 'sneakysuper', 'email' => 'ss@a.com',
            'password' => 'password123', 'role' => Role::SUPER_ADMIN,
        ]);
        $this->assertSame(Role::PLAYER, $user->refresh()->role);
        $this->assertSame(Role::PLAYER, User::where('username', 'sneakysuper')->value('role'));

        // 注册接口带 role 同样无效
        $this->postJson('/api/auth/register', [
            'username' => 'sneakyreg', 'email' => 'sr@a.com',
            'password' => 'password123',
            'role' => Role::SUPER_ADMIN,
        ])->assertStatus(201);
        $this->assertSame(Role::PLAYER, User::where('username', 'sneakyreg')->value('role'));
    }
}
