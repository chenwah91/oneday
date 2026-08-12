<?php

namespace Tests\Feature\Admin;

use App\Game\City\CityFactory;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 审计查询强化(W11-C1 任务3):精确过滤 / action 前缀 LIKE / 时间区间 / 游标 / 单条详情 / 越权。
class AdminAuditQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(string $un = 'auditadm', string $role = 'admin'): User
    {
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function player(string $un): User
    {
        return User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
    }

    // 直接写一条审计:比走真实端点更好控(action / user_id / city_id / 时间全部可指定)
    private function writeAudit(string $action, array $attrs = []): void
    {
        AuditLogger::record($action, 'success', $attrs);
    }

    // ---- 精确过滤 ----

    public function test_filters_by_user_id_city_id_and_request_id(): void
    {
        $admin = $this->admin();
        $p1 = $this->player('auditp1');
        $p2 = $this->player('auditp2');
        $c1 = CityFactory::createForUser($p1);
        $c2 = CityFactory::createForUser($p2);

        $this->writeAudit(AuditAction::BUILDING_BUILD, ['user_id' => $p1->id, 'city_id' => $c1->id]);
        $this->writeAudit(AuditAction::BUILDING_BUILD, ['user_id' => $p2->id, 'city_id' => $c2->id]);
        $this->writeAudit(AuditAction::BUILDING_UPGRADE, ['user_id' => $p1->id, 'city_id' => $c1->id]);

        // user_id
        $rows = $this->actingAs($admin)->getJson("/api/admin/audit?user_id={$p1->id}")->assertOk()->json('data.audit');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame((int) $p1->id, (int) $row['userId']);
        }

        // city_id
        $rows = $this->actingAs($admin)->getJson("/api/admin/audit?city_id={$c2->id}")->assertOk()->json('data.audit');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame((int) $c2->id, (int) $row['cityId']);
        }

        // request_id:取一条已有记录的 request_id,回查必须只剩同一请求的行
        $requestId = DB::table('audit_logs')->where('user_id', $p1->id)->value('request_id');
        $rows = $this->actingAs($admin)->getJson("/api/admin/audit?request_id={$requestId}")->assertOk()->json('data.audit');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($requestId, $row['requestId']);
        }
    }

    // ---- action 前缀 LIKE + allowlist ----

    public function test_action_prefix_like_and_allowlist_regex(): void
    {
        $admin = $this->admin();
        $target = $this->player('auditbantarget');

        // 造两条 ADMIN.* (封 + 解) 与一条非 ADMIN 的
        $this->actingAs($admin)->postJson("/api/admin/players/{$target->id}/ban", ['reason' => '审计前缀测试'])->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/players/{$target->id}/unban", ['reason' => '审计前缀测试解禁'])->assertOk();
        $this->writeAudit(AuditAction::BUILDING_BUILD, ['user_id' => $target->id]);

        $rows = $this->actingAs($admin)->getJson('/api/admin/audit?action=ADMIN.%25&limit=200')->assertOk()->json('data.audit');
        $this->assertGreaterThanOrEqual(2, count($rows));
        foreach ($rows as $row) {
            $this->assertStringStartsWith('ADMIN.', $row['action'], '前缀 LIKE 不该带出别的域');
        }
        $actions = array_column($rows, 'action');
        $this->assertContains(AuditAction::ADMIN_PLAYER_BAN, $actions);
        $this->assertContains(AuditAction::ADMIN_PLAYER_UNBAN, $actions);

        // 不含 % 时仍是精确匹配(旧行为不变)
        $rows = $this->actingAs($admin)->getJson('/api/admin/audit?action='.AuditAction::ADMIN_PLAYER_BAN)->assertOk()->json('data.audit');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(AuditAction::ADMIN_PLAYER_BAN, $row['action']);
        }

        // allowlist:小写 / 引号 / 通配注入一律 422
        foreach (['admin.%', "ADMIN.%' OR '1'='1", 'ADMIN.*'] as $bad) {
            $this->actingAs($admin)->getJson('/api/admin/audit?action='.urlencode($bad))
                ->assertStatus(422)->assertJsonPath('error', 'VALIDATION_ERROR');
        }

        // _ 只当字面量:ADMIN.PLAYER_BAN 里的下划线被转义后,'ADMIN.PLAYER_B%' 仍能命中封禁那条
        $rows = $this->actingAs($admin)->getJson('/api/admin/audit?action='.urlencode('ADMIN.PLAYER_B%'))->assertOk()->json('data.audit');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(AuditAction::ADMIN_PLAYER_BAN, $row['action']);
        }
    }

    // ---- 时间区间 + 游标 ----

    public function test_occurred_at_range_and_cursor_pagination(): void
    {
        $admin = $this->admin();
        $p = $this->player('audittime');

        // 三条错开时间的记录:昨天 / 今天 / 明天。
        // 基准时刻先取下来 —— 直接在循环里 now()->addDays() 会踩到「上一轮已冻结时间」的坑
        $base = now();
        foreach ([-1, 0, 1] as $offset) {
            Carbon::setTestNow($base->copy()->addDays($offset));
            $this->writeAudit(AuditAction::TECH_UNLOCK, ['user_id' => $p->id]);
        }
        Carbon::setTestNow();

        $from = now()->subHours(2)->format('Y-m-d H:i:s');
        $to = now()->addHours(2)->format('Y-m-d H:i:s');

        $rows = $this->actingAs($admin)->getJson(
            '/api/admin/audit?action='.AuditAction::TECH_UNLOCK.'&from='.urlencode($from).'&to='.urlencode($to)
        )->assertOk()->json('data.audit');
        $this->assertCount(1, $rows, '时间区间必须只框住「今天」那一条');

        // 只给 from:今天 + 明天两条
        $rows = $this->actingAs($admin)->getJson(
            '/api/admin/audit?action='.AuditAction::TECH_UNLOCK.'&from='.urlencode($from)
        )->assertOk()->json('data.audit');
        $this->assertCount(2, $rows);

        // 无法解析的时间 422
        $this->actingAs($admin)->getJson('/api/admin/audit?from=not-a-date')
            ->assertStatus(422)->assertJsonPath('error', 'VALIDATION_ERROR');

        // 游标:limit=1 逐条往前翻,id 必须严格递减
        $cursor = null;
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $url = '/api/admin/audit?action='.AuditAction::TECH_UNLOCK.'&limit=1'.($cursor ? "&before_id={$cursor}" : '');
            $res = $this->actingAs($admin)->getJson($url)->assertOk();
            $page = $res->json('data.audit');
            $this->assertCount(1, $page);
            $ids[] = (int) $page[0]['id'];
            $cursor = $res->json('data.next_before_id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)), '游标翻页不得重复');
        $this->assertGreaterThan($ids[1], $ids[0]);
        $this->assertGreaterThan($ids[2], $ids[1]);
    }

    // ---- 单条详情 ----

    public function test_audit_detail_returns_json_columns(): void
    {
        $admin = $this->admin();
        $target = $this->player('auditdetail');

        $this->actingAs($admin)->postJson("/api/admin/players/{$target->id}/ban", ['reason' => '详情端点测试'])->assertOk();
        $id = (int) DB::table('audit_logs')->where('action', AuditAction::ADMIN_PLAYER_BAN)->value('id');

        $res = $this->actingAs($admin)->getJson("/api/admin/audit/{$id}")->assertOk();

        $this->assertSame($id, $res->json('data.audit.id'));
        $this->assertSame(AuditAction::ADMIN_PLAYER_BAN, $res->json('data.audit.action'));
        $this->assertSame('admin', $res->json('data.audit.actor_type'));
        $this->assertSame('详情端点测试', $res->json('data.audit.reason_code'));

        // 四个 JSON 列必须解开成结构而不是字符串
        $this->assertNull($res->json('data.audit.before_json.banned_at'));
        $this->assertNotNull($res->json('data.audit.after_json.banned_at'));
        $this->assertSame('详情端点测试', $res->json('data.audit.after_json.ban_reason'));
        $this->assertSame($target->username, $res->json('data.audit.metadata_json.username'));

        // 不存在的 id → 404
        $this->actingAs($admin)->getJson('/api/admin/audit/999999')->assertStatus(404);
    }

    // ---- 越权 ----

    public function test_audit_endpoints_require_read_audit_permission(): void
    {
        $this->writeAudit(AuditAction::CITY_CREATE, []);
        $id = (int) DB::table('audit_logs')->orderByDesc('id')->value('id');

        // 未登录 401。**必须排在所有 actingAs 之前** ——
        // actingAs 会把用户挂在 guard 上并对本用例后续的每个请求生效,
        // 放在后面的话这条断言实际是以「上一个 actingAs 的身份」发出去的
        $this->getJson('/api/admin/audit')->assertStatus(401);
        $this->getJson("/api/admin/audit/{$id}")->assertStatus(401);

        // 普通玩家:连后台门槛都过不去
        $player = $this->player('auditdenied');
        $this->actingAs($player)->getJson('/api/admin/audit')->assertStatus(403);
        $this->actingAs($player)->getJson("/api/admin/audit/{$id}")->assertStatus(403);

        // support 有 read_audit,列表与详情都应放行(拆权限只会让客服查一半案子要找人代劳)
        $support = $this->admin('auditsupport', 'support');
        $this->actingAs($support)->getJson('/api/admin/audit')->assertOk();
        $this->actingAs($support)->getJson("/api/admin/audit/{$id}")->assertOk();

        // 越权被拒必须留痕(EnsureAdmin 写的 SECURITY.AUTHORIZATION_FAILED)
        $this->assertTrue(
            DB::table('audit_logs')->where('action', AuditAction::SECURITY_AUTHORIZATION_FAILED)
                ->where('user_id', $player->id)->exists()
        );
    }
}
