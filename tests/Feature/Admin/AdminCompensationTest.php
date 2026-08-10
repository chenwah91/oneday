<?php

namespace Tests\Feature\Admin;

use App\Game\City\CityFactory;
use App\Game\Resource\ResourceCode;
use App\Models\City;
use App\Models\User;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 管理员补偿 ADMIN.COMPENSATION(CLAUDE §80 / E7)
// 覆盖:权限矩阵 / 成功入账 / 扣减 / 扣穿被拒 / 超仓储被拒 / 幂等 / 审计完整性 / 定位查询
class AdminCompensationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // 时间冻结在建城时刻 → 结算 elapsed 恒为 0,断言不受产量/维护费干扰
    private function makePlayer(string $username): array
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $user = User::create([
            'username' => $username, 'name' => $username,
            'email' => $username . '@example.com', 'password' => 'password123',
        ]);

        return [$user, CityFactory::createForUser($user)];
    }

    private function staff(string $role, string $username): User
    {
        // role 不可批量赋值,测试里用 forceFill 显式提权
        $user = User::create([
            'username' => $username, 'name' => $username,
            'email' => $username . '@example.com', 'password' => 'password123',
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function amount(City $city, string $resource): float
    {
        return (float) (DB::table('city_resources')
            ->where('city_id', $city->id)->where('resource_id', $resource)->value('amount') ?? 0);
    }

    // ---------- 成功路径 ----------

    public function test_game_master_can_compensate_resource_with_full_audit(): void
    {
        [$player, $city] = $this->makePlayer('compplayer1');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm1');

        $before = $this->amount($city, ResourceCode::WOOD);
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $res = $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'username' => 'compplayer1',
            'resource' => ResourceCode::WOOD,
            'delta'    => 100,
            'reason'   => '结算 bug 导致木材丢失,人工补回',
            'ticket'   => 'TKT-1001',
        ]);

        $res->assertOk()->assertJson(['success' => true, 'data' => [
            'city_id'  => $city->id,
            'user_id'  => $player->id,
            'resource' => ResourceCode::WOOD,
            'delta'    => 100,
            'before'   => $before,
            'after'    => $before + 100,
            'revision' => $revisionBefore + 1,
            'replayed' => false,
        ]]);

        // 玩家侧余额确实到账,revision 递增
        $this->assertSame($before + 100, $this->amount($city, ResourceCode::WOOD));
        $this->assertSame($revisionBefore + 1, (int) DB::table('cities')->where('id', $city->id)->value('revision'));

        // 审计行完整(§63:Admin ID / Reason / Before / After / Delta 一个都不能少)
        $audit = DB::table('audit_logs')->where('action', 'ADMIN.COMPENSATION')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame((int) $gm->id, (int) $audit->actor_id);
        $this->assertSame((int) $player->id, (int) $audit->user_id);
        $this->assertSame((int) $city->id, (int) $audit->city_id);
        $this->assertSame('success', $audit->status);
        $this->assertSame('结算 bug 导致木材丢失,人工补回', $audit->reason_code);
        $this->assertSame('city_resource', $audit->entity_type);
        $this->assertSame(ResourceCode::WOOD, $audit->entity_id);
        $this->assertSame($revisionBefore, (int) $audit->city_revision_before);
        $this->assertSame($revisionBefore + 1, (int) $audit->city_revision_after);
        $this->assertSame($before, (float) json_decode($audit->before_json, true)[ResourceCode::WOOD]);
        $this->assertSame($before + 100, (float) json_decode($audit->after_json, true)[ResourceCode::WOOD]);
        $this->assertSame(100.0, (float) json_decode($audit->delta_json, true)[ResourceCode::WOOD]);
        $this->assertSame('TKT-1001', json_decode($audit->metadata_json, true)['ticket']);
    }

    // 资金走 cities.money,不进 city_resources
    public function test_compensate_money_updates_city_money(): void
    {
        [, $city] = $this->makePlayer('compplayer2');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm2');
        $before = (float) DB::table('cities')->where('id', $city->id)->value('money');

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'city_id'  => $city->id,
            'resource' => ResourceCode::MONEY,
            'delta'    => 250.5,
            'reason'   => '活动奖励补发',
        ])->assertOk()->assertJson(['data' => ['after' => $before + 250.5]]);

        $this->assertSame($before + 250.5, (float) DB::table('cities')->where('id', $city->id)->value('money'));
        $this->assertSame(0, DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', ResourceCode::MONEY)->count());
    }

    // 负 delta = 扣减,结果 >= 0 时放行
    public function test_negative_delta_deducts(): void
    {
        [, $city] = $this->makePlayer('compplayer3');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm3');
        $before = $this->amount($city, ResourceCode::WOOD);

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'city_id'  => $city->id,
            'resource' => ResourceCode::WOOD,
            'delta'    => -50,
            'reason'   => '误发补偿,按工单回收',
        ])->assertOk();

        $this->assertSame($before - 50, $this->amount($city, ResourceCode::WOOD));
    }

    // 玩家从未持有过的资源:upsert 直接建行
    public function test_compensate_resource_without_existing_row(): void
    {
        [, $city] = $this->makePlayer('compplayer4');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm4');
        $this->assertSame(0.0, $this->amount($city, ResourceCode::IRON));

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'city_id'  => $city->id,
            'resource' => ResourceCode::IRON,
            'delta'    => 30,
            'reason'   => '任务奖励未发放,人工补发',
        ])->assertOk();

        $this->assertSame(30.0, $this->amount($city, ResourceCode::IRON));
    }

    // ---------- 规则拒绝 ----------

    // 扣穿到负数:422 + 全回滚(余额/revision/审计都不留痕)
    public function test_deduction_below_zero_is_rejected_and_rolled_back(): void
    {
        [, $city] = $this->makePlayer('compplayer5');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm5');
        $before = $this->amount($city, ResourceCode::WOOD);
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'city_id'  => $city->id,
            'resource' => ResourceCode::WOOD,
            'delta'    => -($before + 1),
            'reason'   => '越扣越多的错误操作',
        ])->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);

        $this->assertSame($before, $this->amount($city, ResourceCode::WOOD));
        $this->assertSame($revisionBefore, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ADMIN.COMPENSATION')->count());
    }

    // 超过仓储上限:直接拒绝,不静默截断(悄悄少发比发不出去更难查)
    public function test_exceeding_storage_capacity_is_rejected(): void
    {
        [, $city] = $this->makePlayer('compplayer6');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm6');
        $before = $this->amount($city, ResourceCode::WOOD);

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'city_id'  => $city->id,
            'resource' => ResourceCode::WOOD,
            'delta'    => 100000,
            'reason'   => '手滑多打了几个零',
        ])->assertStatus(422)->assertJson(['error' => 'STORAGE_FULL']);

        $this->assertSame($before, $this->amount($city, ResourceCode::WOOD));
    }

    // 容量类「资源」是建筑算出来的派生值,没有库存可补
    public function test_capacity_and_unknown_resource_rejected(): void
    {
        [, $city] = $this->makePlayer('compplayer7');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm7');

        foreach ([ResourceCode::STORAGE_CAPACITY, 'not_a_resource'] as $resource) {
            $this->actingAs($gm)->postJson('/api/admin/compensation', [
                'city_id'  => $city->id,
                'resource' => $resource,
                'delta'    => 10,
                'reason'   => '尝试补一个不存在的资源',
            ])->assertStatus(422);
        }
    }

    // reason 是 §63 的硬要求:太短(< 5 字)等于没填
    public function test_reason_is_required_and_min_length(): void
    {
        [, $city] = $this->makePlayer('compplayer8');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm8');

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'city_id' => $city->id, 'resource' => ResourceCode::WOOD, 'delta' => 10,
        ])->assertStatus(422);

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'city_id' => $city->id, 'resource' => ResourceCode::WOOD, 'delta' => 10, 'reason' => '补',
        ])->assertStatus(422);
    }

    // 目标不存在:404,且不能退化成「随便挑一座城」
    public function test_unknown_target_returns_404(): void
    {
        $gm = $this->staff(Role::GAME_MASTER, 'compgm9');

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'username' => 'nobody_here', 'resource' => ResourceCode::WOOD, 'delta' => 10,
            'reason' => '目标不存在的补偿请求',
        ])->assertStatus(404);

        $this->actingAs($gm)->getJson('/api/admin/compensation/lookup')->assertStatus(404);
    }

    // ---------- 权限矩阵 ----------

    // player 与 support 都必须 403 并留 SECURITY.AUTHORIZATION_FAILED。
    // 两者被拒的位置不同:player 连后台门槛都过不了(组级 admin 中间件,NOT_ADMIN),
    // support 进得来但权限不够(路由级 admin:adjust_resource,MISSING_PERMISSION)
    public function test_player_and_support_are_forbidden_and_audited(): void
    {
        [, $city] = $this->makePlayer('compplayer10');

        $cases = [
            ['role' => Role::PLAYER, 'username' => 'complow1', 'reason' => 'NOT_ADMIN', 'required' => null],
            ['role' => Role::SUPPORT, 'username' => 'complow2', 'reason' => 'MISSING_PERMISSION', 'required' => Role::ADJUST_RESOURCE],
        ];

        foreach ($cases as $case) {
            $actor = $this->staff($case['role'], $case['username']);

            $this->actingAs($actor)->getJson('/api/admin/compensation/lookup?city_id=' . $city->id)
                ->assertStatus(403);

            $this->actingAs($actor)->postJson('/api/admin/compensation', [
                'city_id' => $city->id, 'resource' => ResourceCode::WOOD, 'delta' => 999,
                'reason' => '越权尝试补偿自己',
            ])->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);

            $audit = DB::table('audit_logs')->latest('id')->first();
            $this->assertSame('SECURITY.AUTHORIZATION_FAILED', $audit->action);
            $this->assertSame('rejected', $audit->status);
            $this->assertSame($case['reason'], $audit->reason_code);
            $this->assertSame($case['required'], json_decode($audit->metadata_json, true)['required_permission']);
        }

        // 越权尝试一分钱也不能落地
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ADMIN.COMPENSATION')->count());
    }

    public function test_guest_denied(): void
    {
        $this->postJson('/api/admin/compensation', [
            'city_id' => 1, 'resource' => ResourceCode::WOOD, 'delta' => 1, 'reason' => '未登录尝试',
        ])->assertStatus(401);
    }

    // ---------- 幂等 ----------

    public function test_idempotent_replay_does_not_double_credit(): void
    {
        [, $city] = $this->makePlayer('compplayer11');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm11');
        $before = $this->amount($city, ResourceCode::WOOD);

        $payload = [
            'city_id'         => $city->id,
            'resource'        => ResourceCode::WOOD,
            'delta'           => 60,
            'reason'          => '网络重试导致的重复提交',
            'idempotency_key' => 'comp-key-0001',
        ];

        $this->actingAs($gm)->postJson('/api/admin/compensation', $payload)
            ->assertOk()->assertJson(['data' => ['replayed' => false, 'after' => $before + 60]]);
        $revisionAfterFirst = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->actingAs($gm)->postJson('/api/admin/compensation', $payload)
            ->assertOk()->assertJson(['data' => ['replayed' => true, 'delta' => 0]]);

        // 只入账一次:余额、revision、审计行都不得翻倍
        $this->assertSame($before + 60, $this->amount($city, ResourceCode::WOOD));
        $this->assertSame($revisionAfterFirst, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ADMIN.COMPENSATION')->count());
    }

    // 同一 key 换成别的参数 = key 复用,409(不能静默当成重放放过去)
    public function test_reused_key_with_different_payload_conflicts(): void
    {
        [, $city] = $this->makePlayer('compplayer12');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm12');

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'city_id' => $city->id, 'resource' => ResourceCode::WOOD, 'delta' => 10,
            'reason' => '第一次补偿木材', 'idempotency_key' => 'comp-key-0002',
        ])->assertOk();

        $this->actingAs($gm)->postJson('/api/admin/compensation', [
            'city_id' => $city->id, 'resource' => ResourceCode::WOOD, 'delta' => 999,
            'reason' => '同一个键换了金额', 'idempotency_key' => 'comp-key-0002',
        ])->assertStatus(409)->assertJson(['error' => 'IDEMPOTENCY_KEY_REUSED']);
    }

    // ---------- 定位查询 ----------

    public function test_lookup_returns_city_and_resource_list(): void
    {
        [$player, $city] = $this->makePlayer('compplayer13');
        $gm = $this->staff(Role::GAME_MASTER, 'compgm13');

        $res = $this->actingAs($gm)->getJson('/api/admin/compensation/lookup?username=compplayer13');
        $res->assertOk()->assertJson(['data' => [
            'user' => ['id' => $player->id, 'username' => 'compplayer13'],
            'city' => ['id' => $city->id],
        ]]);

        $resources = collect($res->json('data.resources'));
        // 31 种库存资源全在列表里(含 money),容量类一个都不在
        $this->assertSame(31, $resources->count());
        $this->assertTrue($resources->contains(fn ($r) => $r['code'] === ResourceCode::MONEY && $r['name'] === '资金'));
        $this->assertFalse($resources->contains(fn ($r) => $r['code'] === ResourceCode::STORAGE_CAPACITY));
        // 当前余额随列表一起给出,后台不用二次请求
        $wood = $resources->firstWhere('code', ResourceCode::WOOD);
        $this->assertSame($this->amount($city, ResourceCode::WOOD), (float) $wood['amount']);
    }
}
