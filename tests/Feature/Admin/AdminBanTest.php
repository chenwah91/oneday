<?php

namespace Tests\Feature\Admin;

use App\Game\City\CityFactory;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 封禁 / 解禁全链(W11-C1 任务4):
// 封 → 登录被拒 → 在途会话被踢 → 解禁恢复;封管理员 422;幂等;审计成对;绝不删数据。
class AdminBanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function staff(string $un, string $role): User
    {
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function player(string $un): User
    {
        return User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
    }

    // ---- 全链 ----

    public function test_ban_blocks_login_kicks_live_session_and_unban_restores(): void
    {
        $admin = $this->staff('banadm', 'admin');
        $player = $this->player('banvictim');
        $city = CityFactory::createForUser($player);

        // ① 封禁前:登录正常
        $this->postJson('/api/auth/login', ['username' => 'banvictim', 'password' => 'password123'])->assertOk();

        // ② 管理员封禁
        $res = $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/ban", [
            'reason' => '刷资源作弊,工单 T-1024',
        ])->assertOk();
        $this->assertTrue($res->json('data.changed'));
        $this->assertTrue($res->json('data.player.banned'));
        $this->assertNotNull($res->json('data.player.banned_at'));

        // ③ 登录被拒:密码是对的,但账号被封 → 401 + ACCOUNT_BANNED
        $this->postJson('/api/auth/login', ['username' => 'banvictim', 'password' => 'password123'])
            ->assertStatus(401)
            ->assertJsonPath('error', ErrorCode::ACCOUNT_BANNED);

        // ④ 在途会话被踢:模拟「封禁发生时他正开着页面」——
        //    从库里重新取一次用户(等同于 session guard 每个请求从 provider 拿人),
        //    这时任何 /api/ 请求都应被 EnsureNotBanned 拦成 401 ACCOUNT_BANNED
        $this->actingAs(User::find($player->id))->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('error', ErrorCode::ACCOUNT_BANNED);

        // ⑤ **绝不删除玩家数据**:城市与资源必须原封不动
        $this->assertTrue(DB::table('cities')->where('id', $city->id)->exists(), '封禁不得删除城市');
        $this->assertTrue(DB::table('users')->where('id', $player->id)->exists(), '封禁不得删除账号');

        // ⑥ 解禁后恢复
        $res = $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/unban", [
            'reason' => '申诉成立,误判恢复',
        ])->assertOk();
        $this->assertTrue($res->json('data.changed'));
        $this->assertFalse($res->json('data.player.banned'));
        $this->assertNull($res->json('data.player.banned_at'));

        $this->postJson('/api/auth/login', ['username' => 'banvictim', 'password' => 'password123'])->assertOk();
        $this->actingAs(User::find($player->id))->getJson('/api/me')->assertOk();
    }

    // ---- 审计成对 ----

    public function test_ban_and_unban_write_paired_audit_with_before_after(): void
    {
        $admin = $this->staff('banaudit', 'admin');
        $player = $this->player('banaudited');

        $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/ban", ['reason' => '恶意刷分,工单 T-77'])->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/unban", ['reason' => '处罚期满自动恢复'])->assertOk();

        $ban = DB::table('audit_logs')->where('action', AuditAction::ADMIN_PLAYER_BAN)
            ->where('user_id', $player->id)->first();
        $unban = DB::table('audit_logs')->where('action', AuditAction::ADMIN_PLAYER_UNBAN)
            ->where('user_id', $player->id)->first();

        $this->assertNotNull($ban, '封禁必须写 ADMIN.PLAYER_BAN');
        $this->assertNotNull($unban, '解禁必须写 ADMIN.PLAYER_UNBAN');

        foreach ([$ban, $unban] as $row) {
            $this->assertSame('admin', $row->actor_type);
            $this->assertSame((int) $admin->id, (int) $row->actor_id);
            $this->assertSame('user', $row->entity_type);
            $this->assertSame((string) $player->id, (string) $row->entity_id);
            $this->assertNotEmpty($row->reason_code);
        }

        // before/after 记的是 banned_at / ban_reason 两列
        $banBefore = json_decode((string) $ban->before_json, true);
        $banAfter = json_decode((string) $ban->after_json, true);
        $this->assertNull($banBefore['banned_at']);
        $this->assertNotNull($banAfter['banned_at']);
        $this->assertSame('恶意刷分,工单 T-77', $banAfter['ban_reason']);

        $unbanBefore = json_decode((string) $unban->before_json, true);
        $unbanAfter = json_decode((string) $unban->after_json, true);
        $this->assertNotNull($unbanBefore['banned_at']);
        $this->assertNull($unbanAfter['banned_at']);
    }

    // ---- 不许封管理角色 ----

    public function test_cannot_ban_staff_accounts(): void
    {
        $admin = $this->staff('bansuper', 'super_admin');
        $otherAdmin = $this->staff('banotheradm', 'admin');
        $support = $this->staff('bansupport2', 'support');

        foreach ([$otherAdmin, $support, $admin] as $target) {
            $this->actingAs($admin)->postJson("/api/admin/players/{$target->id}/ban", ['reason' => '尝试封禁后台账号'])
                ->assertStatus(422)
                ->assertJsonPath('error', 'VALIDATION_ERROR');

            $this->assertNull(
                DB::table('users')->where('id', $target->id)->value('banned_at'),
                '管理角色账号不得被写入 banned_at'
            );
        }

        // 一条 ADMIN.PLAYER_BAN 都不该产生
        $this->assertSame(0, DB::table('audit_logs')->where('action', AuditAction::ADMIN_PLAYER_BAN)->count());
    }

    // ---- 幂等 ----

    public function test_repeat_ban_and_unban_are_idempotent(): void
    {
        $admin = $this->staff('banidem', 'admin');
        $player = $this->player('banidemvictim');

        $first = $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/ban", ['reason' => '重复封禁幂等测试'])->assertOk();
        $this->assertTrue($first->json('data.changed'));
        $bannedAt = $first->json('data.player.banned_at');

        // 再封一次:返回当前状态,changed=false,时间戳不变
        $second = $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/ban", ['reason' => '重复封禁幂等测试'])->assertOk();
        $this->assertFalse($second->json('data.changed'));
        $this->assertTrue($second->json('data.player.banned'));
        $this->assertSame($bannedAt, $second->json('data.player.banned_at'), '重复封禁不得刷新封禁时刻');

        // 审计只有一条(重复写会让「他被封过几次」失真)
        $this->assertSame(1, DB::table('audit_logs')->where('action', AuditAction::ADMIN_PLAYER_BAN)->count());

        // 解禁两次同理
        $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/unban")->assertOk()
            ->assertJsonPath('data.changed', true);
        $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/unban")->assertOk()
            ->assertJsonPath('data.changed', false);
        $this->assertSame(1, DB::table('audit_logs')->where('action', AuditAction::ADMIN_PLAYER_UNBAN)->count());
    }

    // ---- 权限与输入 ----

    public function test_ban_requires_ban_player_permission_and_reason(): void
    {
        $player = $this->player('banperm');

        // 未登录 401(排在所有 actingAs 之前:actingAs 对本用例后续请求持续生效)
        $this->postJson("/api/admin/players/{$player->id}/ban", ['reason' => '未登录尝试封禁'])->assertStatus(401);

        // game_master 没有 ban_player(权限表见 App\Support\Role)→ 403
        $gm = $this->staff('bangm', 'game_master');
        $this->actingAs($gm)->postJson("/api/admin/players/{$player->id}/ban", ['reason' => '越权尝试封禁'])
            ->assertStatus(403);
        $this->assertNull(DB::table('users')->where('id', $player->id)->value('banned_at'));

        $admin = $this->staff('banperfadm', 'admin');

        // reason 必填且至少 5 字
        $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/ban", [])->assertStatus(422);
        $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/ban", ['reason' => 'abc'])->assertStatus(422);
        // 超过 80 字被拒(超长会写崩 audit_logs.reason_code 并让事务回滚且不留痕)
        $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/ban", ['reason' => str_repeat('长', 81)])->assertStatus(422);

        // 不存在的玩家 404
        $this->actingAs($admin)->postJson('/api/admin/players/999999/ban', ['reason' => '目标不存在测试'])->assertStatus(404);

        // 合法请求通过,且玩家列表能看到封禁状态
        $this->actingAs($admin)->postJson("/api/admin/players/{$player->id}/ban", ['reason' => '正常封禁流程'])->assertOk();
        $row = collect($this->actingAs($admin)->getJson('/api/admin/players?q=banperm')->json('data.players'))->firstWhere('username', 'banperm');
        $this->assertNotNull($row['banned_at']);
        $this->assertSame('正常封禁流程', $row['ban_reason']);
    }
}
