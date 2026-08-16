<?php

namespace Tests\Feature\Security;

use App\Game\Building\ConstructionService;
use App\Game\City\CityFactory;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M2 对抗式安全回归(C6):把 M2 新增/大改的端点当成攻击面逐项打。
//
// 覆盖端点:
//   POST /api/city/workers/assign · /api/city/research · /api/city/era/upgrade
//   POST /api/city/upgrade/cancel · /api/city/demolish · /api/city/build · /api/city/upgrade
//   POST /api/admin/compensation · /api/admin/settings
//
// 攻击维度:越权 / 幂等滥用 / Revision / 输入边界 / 经济不变量 / 时序组合 / 开关组合态。
// 单纯的正向功能验证不在本文件,归各端点自己的 Feature 测试。
class M2AttackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // 夹具预先解锁的科技(全部时代 I):够 R01 / H01 / S01 / F01 / A01 / D01 过建造闸门,
    // 又刻意**不含** TECH_II_SUST —— 它是本文件里的「研究靶子」,必须保持可研究状态
    private const FIXTURE_TECHS = ['TECH_I_SUST', 'TECH_I_IND', 'TECH_I_CIV', 'TECH_I_LOG', 'TECH_I_DEF'];

    // ---------- 夹具 ----------

    // 一名时代 II 玩家:资源充足、时代 I 科技铺好、时间冻结(elapsed 恒 0,断言不被产量干扰)
    private function player(string $un): array
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        DB::table('cities')->where('id', $city->id)->update([
            'era_key' => 'II', 'era_order' => 2, 'money' => 5000,
        ]);
        foreach (['wood' => 800, 'stone' => 800, 'food' => 500, 'knowledge' => 500] as $res => $amount) {
            DB::table('city_resources')->updateOrInsert(
                ['city_id' => $city->id, 'resource_id' => $res], ['amount' => $amount]
            );
        }
        $this->unlockTech($city->id, ...self::FIXTURE_TECHS);

        return [$u, $city->fresh()];
    }

    // 夹具之外新增的 city_technologies 行数(夹具本身会铺 5 条已解锁科技)
    private function extraTechRows(City $city): int
    {
        return DB::table('city_technologies')->where('city_id', $city->id)
            ->whereNotIn('tech_id', self::FIXTURE_TECHS)->count();
    }

    // 直接落库造实例:本文件验的是端点的防线,不是建造流程
    private function place(City $city, string $buildingId, int $x, int $y, string $status = 'active', int $level = 1): int
    {
        $id = CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => $buildingId, 'level' => $level,
            'x' => $x, 'y' => $y, 'status' => $status, 'assigned_workers' => 0,
        ])->id;

        // construction_finished_at 不可批量赋值(不是玩家能决定的字段),施工/升级中的实例单独补戳
        if ($status !== ConstructionService::STATUS_ACTIVE) {
            DB::table('city_building_instances')->where('id', $id)
                ->update(['construction_finished_at' => now()->copy()->addHour()]);
        }

        return (int) $id;
    }

    private function staff(string $role, string $un): User
    {
        // role 不可批量赋值(防质量赋值提权),测试里用 forceFill 显式提权
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $u->forceFill(['role' => $role])->save();

        return $u;
    }

    private function res(City $city, string $resourceId): float
    {
        return (float) DB::table('city_resources')
            ->where('city_id', $city->id)->where('resource_id', $resourceId)->value('amount');
    }

    // 全城资源 + 资金的完整快照:用来断言「失败路径零状态变化」
    private function wallet(City $city): array
    {
        return [
            'resources' => DB::table('city_resources')->where('city_id', $city->id)
                ->orderBy('resource_id')->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
            'money'     => (float) DB::table('cities')->where('id', $city->id)->value('money'),
            'revision'  => (int) DB::table('cities')->where('id', $city->id)->value('revision'),
        ];
    }

    // =====================================================================
    // 1. 越权
    // =====================================================================

    // A 的实例不能被 B 用任何一个 M2 实例端点碰到,且每次都留 SECURITY.AUTHORIZATION_FAILED
    public function test_cross_player_instance_mutations_are_forbidden_and_audited(): void
    {
        [$ua, $ca] = $this->player('victimA');
        $active = $this->place($ca, 'F02', 1, 1);
        $upgrading = $this->place($ca, 'F02', 5, 1, ConstructionService::STATUS_UPGRADING);

        [$ub, $cb] = $this->player('attackerB');
        $walletA = $this->wallet($ca);

        $attacks = [
            ['/api/city/workers/assign', ['instance_id' => $active, 'workers' => 4]],
            ['/api/city/upgrade',        ['instance_id' => $active]],
            ['/api/city/demolish',       ['instance_id' => $active]],
            ['/api/city/upgrade/cancel', ['instance_id' => $upgrading]],
        ];

        foreach ($attacks as [$route, $payload]) {
            DB::table('audit_logs')->delete();

            $this->actingAs($ub)->postJson($route, $payload)
                ->assertStatus(403)->assertJson(['success' => false, 'error' => 'FORBIDDEN']);

            $row = DB::table('audit_logs')->where('action', 'SECURITY.AUTHORIZATION_FAILED')->first();
            $this->assertNotNull($row, "{$route} 越权被拒但没留审计");
            $this->assertSame('rejected', $row->status);
            $this->assertSame('NOT_OWNER', $row->reason_code);
            $this->assertSame((int) $ub->id, (int) $row->actor_id, '审计里的 actor 必须是攻击者而不是受害者');
        }

        // 受害者的城一分钱没动,实例状态原样
        $this->assertSame($walletA, $this->wallet($ca));
        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $active)->value('assigned_workers'));
        $this->assertSame('active', DB::table('city_building_instances')->where('id', $active)->value('status'));
        $this->assertSame('upgrading', DB::table('city_building_instances')->where('id', $upgrading)->value('status'));
        // B 自己的城也不该被顺手改
        $this->assertSame(0, (int) DB::table('cities')->where('id', $cb->id)->value('revision'));
    }

    // research / era 端点不接受 city_id,城市一律由 session 用户取 —— 越权在契约层面就不存在。
    // 这条锁住这个契约:请求里塞 city_id / user_id 也绝不会作用到别人的城
    public function test_cityless_endpoints_ignore_injected_target_ids(): void
    {
        [$ua, $ca] = $this->player('cityless-victim');
        [$ub, $cb] = $this->player('cityless-attacker');
        $walletA = $this->wallet($ca);

        $this->actingAs($ub)->postJson('/api/city/research', [
            'tech_id' => 'TECH_II_SUST', 'city_id' => $ca->id, 'user_id' => $ua->id,
        ])->assertOk();

        $this->actingAs($ub)->postJson('/api/city/era/upgrade', [
            'city_id' => $ca->id, 'era_order' => 10,
        ])->assertStatus(422);

        // 受害者的城:资源 / revision / 科技行全部为零变化
        $this->assertSame($walletA, $this->wallet($ca));
        $this->assertSame(0, DB::table('city_technologies')->where('city_id', $ca->id)->where('status', 'researching')->count());
        // 攻击者只改到自己的城
        $this->assertSame(1, DB::table('city_technologies')->where('city_id', $cb->id)->where('status', 'researching')->count());
        $this->assertSame(2, (int) DB::table('cities')->where('id', $cb->id)->value('era_order'), '客户端塞 era_order 不能越级');
    }

    // 后台越级:每个角色只能踩到自己那一档,越级一律 403 + 审计(缺哪个权限也要记下来)
    public function test_admin_endpoint_privilege_ladder_is_enforced(): void
    {
        [$target] = $this->player('ladder-target');

        $cases = [
            // [角色, 端点, 方法, 期望状态]
            [Role::SUPPORT,     'compensation/lookup', 'get',  403],
            [Role::SUPPORT,     'compensation',        'post', 403],
            [Role::SUPPORT,     'settings',            'get',  403],
            [Role::GAME_MASTER, 'compensation/lookup', 'get',  200],
            [Role::GAME_MASTER, 'settings',            'get',  403],
            [Role::GAME_MASTER, 'settings',            'post', 403],
            [Role::ADMIN,       'settings',            'get',  200],
        ];

        foreach ($cases as $i => [$role, $path, $method, $expected]) {
            $user = $this->staff($role, "ladder{$i}");
            DB::table('audit_logs')->delete();

            $payload = match ($path) {
                'compensation'        => ['username' => 'ladder-target', 'resource' => 'wood', 'delta' => 100, 'reason' => '越级测试补偿'],
                'settings'            => ['setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => false, 'reason' => '越级测试开关'],
                default               => ['username' => 'ladder-target'],
            };

            $res = $method === 'get'
                ? $this->actingAs($user, 'admin')->getJson('/api/admin/'.$path.'?username=ladder-target')
                : $this->actingAs($user, 'admin')->postJson('/api/admin/'.$path, $payload);

            $res->assertStatus($expected);

            if ($expected === 403) {
                $row = DB::table('audit_logs')->where('action', 'SECURITY.AUTHORIZATION_FAILED')->first();
                $this->assertNotNull($row, "{$role} → {$path} 被拒但没留审计");
                $this->assertSame('MISSING_PERMISSION', $row->reason_code);
                $meta = json_decode((string) $row->metadata_json, true);
                $this->assertSame($role, $meta['role']);
                $this->assertNotNull($meta['required_permission']);
            }
        }

        // 越级尝试全程没改到任何东西
        $this->assertTrue(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ADMIN.COMPENSATION')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ADMIN.CONFIG_CHANGE')->count());
    }

    // 普通玩家碰后台:NOT_ADMIN(不是「级别不够」),同样留痕
    public function test_player_hitting_admin_m2_endpoints_is_denied_as_not_admin(): void
    {
        [$u] = $this->player('plain-player');

        foreach ([['get', 'compensation/lookup'], ['get', 'settings']] as [$method, $path]) {
            DB::table('audit_logs')->delete();
            // 挂在 admin guard 上:后台自 2026-08-15 起走独立会话,只有**玩家会话**的请求会被
            // auth:admin 挡成 401 而不进 EnsureAdmin(那条路径在 AdminAccessTest 里单独验)。
            // 这里要验的是 EnsureAdmin 本身:持有后台会话但角色是 player → NOT_ADMIN 403 + 留痕
            $this->actingAs($u, 'admin')->getJson('/api/admin/'.$path)->assertStatus(403);
            $row = DB::table('audit_logs')->where('action', 'SECURITY.AUTHORIZATION_FAILED')->first();
            $this->assertSame('NOT_ADMIN', $row->reason_code);
        }
    }

    // =====================================================================
    // 2. 幂等滥用
    // =====================================================================

    // 同一 key 换 action:全部 409 IDEMPOTENCY_KEY_REUSED,并写 SECURITY.SUSPICIOUS_ACTIVITY
    public function test_one_key_cannot_be_carried_across_m2_actions(): void
    {
        [$u, $city] = $this->player('keyhopper');
        $instance = $this->place($city, 'F02', 1, 1);
        $upgrading = $this->place($city, 'F02', 5, 1, ConstructionService::STATUS_UPGRADING);
        $key = 'm2-cross-action-key';

        // 先用这把 key 做一次研究(合法首用)
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_SUST', 'idempotency_key' => $key])->assertOk();
        $wallet = $this->wallet($city);

        $reuses = [
            ['/api/city/era/upgrade',    []],
            ['/api/city/workers/assign', ['instance_id' => $instance, 'workers' => 1]],
            ['/api/city/demolish',       ['instance_id' => $instance]],
            ['/api/city/upgrade/cancel', ['instance_id' => $upgrading]],
            ['/api/city/build',          ['building_id' => 'R01', 'x' => 12, 'y' => 12]],
        ];

        foreach ($reuses as [$route, $payload]) {
            DB::table('audit_logs')->delete();

            $this->actingAs($u)->postJson($route, $payload + ['idempotency_key' => $key])
                ->assertStatus(409)->assertJson(['success' => false, 'error' => 'IDEMPOTENCY_KEY_REUSED']);

            $row = DB::table('audit_logs')->where('action', 'SECURITY.SUSPICIOUS_ACTIVITY')->first();
            $this->assertNotNull($row, "{$route} 的 key 复用没留可疑行为审计");
            $this->assertSame('IDEMPOTENCY_KEY_REUSED', $row->reason_code);
        }

        // 五次复用一次状态都没改动
        $this->assertSame($wallet, $this->wallet($city));
        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $instance)->value('assigned_workers'));
        $this->assertDatabaseHas('city_building_instances', ['id' => $instance]);
    }

    // 同一 key 同一 action 但换参数:一律 409,不允许「借壳」改成另一次操作
    public function test_same_key_with_switched_parameters_is_rejected(): void
    {
        [$u, $city] = $this->player('paramswap');
        $a = $this->place($city, 'F02', 1, 1);
        $b = $this->place($city, 'F02', 5, 1);

        // workers:同 key 换人数
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instance_id' => $a, 'workers' => 4, 'idempotency_key' => 'k-worker'])->assertOk();
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instance_id' => $a, 'workers' => 2, 'idempotency_key' => 'k-worker'])->assertStatus(409);
        // 同 key 换实例
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instance_id' => $b, 'workers' => 4, 'idempotency_key' => 'k-worker'])->assertStatus(409);
        $this->assertSame(4, (int) DB::table('city_building_instances')->where('id', $a)->value('assigned_workers'));
        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $b)->value('assigned_workers'));

        // research:同 key 换科技
        $knowledgeBefore = $this->res($city, 'knowledge');
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_SUST', 'idempotency_key' => 'k-tech'])->assertOk();
        $afterFirst = $this->res($city, 'knowledge');
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_LOG', 'idempotency_key' => 'k-tech'])->assertStatus(409);
        $this->assertLessThan($knowledgeBefore, $afterFirst);
        $this->assertSame($afterFirst, $this->res($city, 'knowledge'), 'key 复用被拒后不得再扣一次知识');
        $this->assertSame(1, $this->extraTechRows($city), '被拒的第二项科技不得建行');

        // demolish:同 key 换实例
        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $a, 'idempotency_key' => 'k-demo'])->assertOk();
        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $b, 'idempotency_key' => 'k-demo'])->assertStatus(409);
        $this->assertDatabaseHas('city_building_instances', ['id' => $b]);
    }

    // 幂等键按 user 分域:B 拿着 A 的 key 既不能重放 A 的结果,也不会被 A 的历史挡住自己的操作
    public function test_idempotency_keys_are_scoped_per_user(): void
    {
        [$ua, $ca] = $this->player('keyowner');
        [$ub, $cb] = $this->player('keythief');
        $ia = $this->place($ca, 'F02', 1, 1);
        $ib = $this->place($cb, 'F02', 1, 1);
        $key = 'shared-looking-key';

        $this->actingAs($ua)->postJson('/api/city/workers/assign', ['instance_id' => $ia, 'workers' => 4, 'idempotency_key' => $key])->assertOk();

        // B 拿同一把 key 打 A 的实例:走的是所有权拒绝(403),而不是「命中 A 的幂等记录」重放成功
        $this->actingAs($ub)->postJson('/api/city/workers/assign', ['instance_id' => $ia, 'workers' => 4, 'idempotency_key' => $key])
            ->assertStatus(403);

        // B 用同一把 key 操作自己的实例:属于 B 的首次使用,正常成功
        $this->actingAs($ub)->postJson('/api/city/workers/assign', ['instance_id' => $ib, 'workers' => 4, 'idempotency_key' => $key])->assertOk();

        $this->assertSame(4, (int) DB::table('city_building_instances')->where('id', $ia)->value('assigned_workers'));
        $this->assertSame(4, (int) DB::table('city_building_instances')->where('id', $ib)->value('assigned_workers'));
        $this->assertSame(2, DB::table('idempotency_keys')->where('key', $key)->count());
    }

    // 退款类操作的重放绝不二次退款(拆除 / 取消升级 / 管理员补偿三条线一起验)
    public function test_refund_and_credit_replays_never_pay_twice(): void
    {
        [$u, $city] = $this->player('replayer');
        $toDemolish = $this->place($city, 'F02', 1, 1);
        $toCancel = $this->place($city, 'F02', 5, 1, ConstructionService::STATUS_UPGRADING);

        // 拆除
        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $toDemolish, 'idempotency_key' => 'r-demo'])->assertOk();
        $afterDemolish = $this->wallet($city);
        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $toDemolish, 'idempotency_key' => 'r-demo'])->assertOk();
        $this->assertSame($afterDemolish, $this->wallet($city), '拆除重放二次退款了');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'BUILDING.DEMOLISH')->count());

        // 取消升级
        $this->actingAs($u)->postJson('/api/city/upgrade/cancel', ['instance_id' => $toCancel, 'idempotency_key' => 'r-cancel'])->assertOk();
        $afterCancel = $this->wallet($city);
        $this->actingAs($u)->postJson('/api/city/upgrade/cancel', ['instance_id' => $toCancel, 'idempotency_key' => 'r-cancel'])->assertOk();
        $this->assertSame($afterCancel, $this->wallet($city), '取消升级重放二次退款了');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'BUILDING.UPGRADE_CANCEL')->count());

        // 管理员补偿
        $gm = $this->staff(Role::GAME_MASTER, 'replay-gm');
        $body = ['city_id' => $city->id, 'resource' => 'wood', 'delta' => 50, 'reason' => '幂等重放不得二次入账', 'idempotency_key' => 'r-comp'];
        $this->actingAs($gm, 'admin')->postJson('/api/admin/compensation', $body)->assertOk();
        $afterComp = $this->wallet($city);
        $this->actingAs($gm, 'admin')->postJson('/api/admin/compensation', $body)->assertOk()->assertJson(['data' => ['replayed' => true, 'delta' => 0]]);
        $this->assertSame($afterComp, $this->wallet($city), '补偿重放二次入账了');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ADMIN.COMPENSATION')->count());
    }

    // =====================================================================
    // 3. Revision
    // =====================================================================

    // 旧 revision:每个 M2 mutation 都必须 409,且状态零变化、只留一条冲突审计
    public function test_stale_revision_rejects_every_m2_mutation_with_zero_state_change(): void
    {
        [$u, $city] = $this->player('stalerev');
        $active = $this->place($city, 'F02', 1, 1);
        $upgrading = $this->place($city, 'F02', 5, 1, ConstructionService::STATUS_UPGRADING);
        $stale = (int) DB::table('cities')->where('id', $city->id)->value('revision') + 99;

        $routes = [
            ['/api/city/build',          ['building_id' => 'R01', 'x' => 12, 'y' => 12]],
            ['/api/city/upgrade',        ['instance_id' => $active]],
            ['/api/city/upgrade/cancel', ['instance_id' => $upgrading]],
            ['/api/city/demolish',       ['instance_id' => $active]],
            ['/api/city/workers/assign', ['instance_id' => $active, 'workers' => 4]],
            ['/api/city/research',       ['tech_id' => 'TECH_II_SUST']],
            ['/api/city/era/upgrade',    []],
        ];

        $before = $this->wallet($city);
        $instancesBefore = DB::table('city_building_instances')->where('city_id', $city->id)
            ->orderBy('id')->get()->toArray();

        foreach ($routes as [$route, $payload]) {
            DB::table('audit_logs')->delete();

            $this->actingAs($u)->postJson($route, $payload + ['expected_revision' => $stale])
                ->assertStatus(409)->assertJson(['success' => false, 'error' => 'REVISION_CONFLICT']);

            $rows = DB::table('audit_logs')->get();
            $this->assertCount(1, $rows, "{$route} 冲突后写了不止一条审计");
            $this->assertSame('SECURITY.REVISION_CONFLICT', $rows[0]->action);
            $this->assertSame('rejected', $rows[0]->status);
        }

        // 资源 / 资金 / revision / 实例集合全部原样
        $this->assertSame($before, $this->wallet($city));
        $this->assertEquals($instancesBefore, DB::table('city_building_instances')->where('city_id', $city->id)->orderBy('id')->get()->toArray());
        $this->assertSame(0, $this->extraTechRows($city));
        $this->assertSame(2, (int) DB::table('cities')->where('id', $city->id)->value('era_order'));
    }

    // =====================================================================
    // 4. 输入边界
    // =====================================================================

    // 工人分配:负数 / 超上限 / NaN 串 / 数组注入 / 非法实例 ID,全部 422 且零变化
    public function test_worker_assign_rejects_hostile_input(): void
    {
        [$u, $city] = $this->player('badworker');
        $id = $this->place($city, 'F02', 1, 1);
        $before = $this->wallet($city);

        $payloads = [
            ['instance_id' => $id, 'workers' => -1],
            ['instance_id' => $id, 'workers' => 100001],
            ['instance_id' => $id, 'workers' => 'NaN'],
            ['instance_id' => $id, 'workers' => 1.5],
            ['instance_id' => $id, 'workers' => ['4']],
            ['instance_id' => $id],
            ['instance_id' => 0, 'workers' => 1],
            ['instance_id' => -5, 'workers' => 1],
            ['instance_id' => ['1'], 'workers' => 1],
            ['instance_id' => '1 OR 1=1', 'workers' => 1],
            ['instance_id' => $id, 'workers' => 1, 'idempotency_key' => str_repeat('k', 101)],
        ];

        foreach ($payloads as $i => $payload) {
            $this->actingAs($u)->postJson('/api/city/workers/assign', $payload)
                ->assertStatus(422)->assertJson(['success' => false, 'error' => 'VALIDATION_ERROR']);
        }

        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $id)->value('assigned_workers'));
        $this->assertSame($before, $this->wallet($city));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'WORKER.ASSIGN')->count());
    }

    // 研究:数组 / 超长串 / 注入串 / 不存在的 ID,全部 422 且不扣知识、不建行
    public function test_research_rejects_hostile_tech_id(): void
    {
        [$u, $city] = $this->player('badtech');
        $knowledge = $this->res($city, 'knowledge');

        $payloads = [
            ['tech_id' => ['TECH_II_SUST']],
            ['tech_id' => str_repeat('T', 33)],
            ['tech_id' => ''],
            ['tech_id' => "' OR '1'='1"],
            ['tech_id' => 'TECH_NOT_REAL'],
            [],
        ];

        foreach ($payloads as $payload) {
            $this->actingAs($u)->postJson('/api/city/research', $payload)
                ->assertStatus(422)->assertJson(['success' => false, 'error' => 'VALIDATION_ERROR']);
        }

        $this->assertSame($knowledge, $this->res($city, 'knowledge'));
        $this->assertSame(0, $this->extraTechRows($city), '非法 tech_id 不得在 city_technologies 建行');
    }

    // 管理员补偿:0 / 超界 / NaN / 数组 delta、太短或超长的 reason、容量类与未知资源,全部 422 且零入账
    public function test_compensation_rejects_hostile_input(): void
    {
        [$pu, $city] = $this->player('comp-target');
        $gm = $this->staff(Role::GAME_MASTER, 'comp-gm');
        $before = $this->wallet($city);

        $base = ['city_id' => $city->id, 'resource' => 'wood', 'delta' => 10, 'reason' => '正常补偿理由'];
        $payloads = [
            ['delta' => 0] + $base,
            ['delta' => 1000000001] + $base,
            ['delta' => -1000000001] + $base,
            ['delta' => 'NaN'] + $base,
            ['delta' => ['10']] + $base,
            ['reason' => 'abcd'] + $base,
            ['reason' => str_repeat('理', 81)] + $base,
            ['resource' => 'population_capacity'] + $base,
            ['resource' => 'storage_capacity'] + $base,
            ['resource' => 'not_a_resource'] + $base,
            ['resource' => str_repeat('r', 33)] + $base,
            // 扣穿:一次扣走比库存更多的木材
            ['delta' => -999999] + $base,
        ];

        foreach ($payloads as $payload) {
            $this->actingAs($gm, 'admin')->postJson('/api/admin/compensation', $payload)
                ->assertStatus(422)->assertJson(['success' => false]);
        }

        $this->assertSame($before, $this->wallet($city), '任何一条非法补偿都不许落库');
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ADMIN.COMPENSATION')->count());
    }

    // 后台开关:未登记 key / 非布尔值 / 缺 reason,全部 422 且开关值不变、不写 CONFIG_CHANGE
    public function test_game_setting_update_rejects_hostile_input(): void
    {
        $admin = $this->staff(Role::ADMIN, 'setting-attacker');

        $payloads = [
            ['setting_key' => 'never_registered', 'value' => true, 'reason' => '造一个新开关'],
            ['setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => 'yes', 'reason' => '字符串真值'],
            ['setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => 1, 'reason' => '整数真值'],
            ['setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => ['false'], 'reason' => '数组值'],
            ['setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => false],
            ['setting_key' => str_repeat('k', 65), 'value' => false, 'reason' => '超长 key'],
        ];

        foreach ($payloads as $payload) {
            $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', $payload)->assertStatus(422);
        }

        $this->assertTrue(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ADMIN.CONFIG_CHANGE')->count());

        // 对照:真正的 false 必须能改进去(否则「开关关不掉」会被当成安全加固误伤)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => false, 'reason' => '正常关闭',
        ])->assertOk();
        $this->assertFalse(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
    }

    // 错误响应只暴露稳定错误码 + request_id(+ 校验明细),绝不外泄堆栈 / SQL / 文件路径
    public function test_error_responses_never_leak_internals(): void
    {
        [$u, $city] = $this->player('leakhunter');
        $id = $this->place($city, 'F02', 1, 1);

        $probes = [
            ['/api/city/research',       ['tech_id' => 'TECH_NOT_REAL']],
            ['/api/city/research',       ['tech_id' => 'TECH_X_SUST']],
            ['/api/city/era/upgrade',    []],
            ['/api/city/workers/assign', ['instance_id' => 999999, 'workers' => 1]],
            ['/api/city/upgrade/cancel', ['instance_id' => $id]],
            ['/api/city/demolish',       ['instance_id' => 999999]],
        ];

        $allowedKeys = ['success', 'error', 'request_id', 'errors', 'details'];

        foreach ($probes as [$route, $payload]) {
            $res = $this->actingAs($u)->postJson($route, $payload);
            $this->assertContains($res->status(), [404, 422], "{$route} 的失败状态码不在预期内");

            $body = $res->json();
            $this->assertSame([], array_diff(array_keys($body), $allowedKeys), "{$route} 的错误响应多了不该有的字段");

            $raw = $res->getContent();
            foreach (['SQLSTATE', 'Exception', '#0 ', 'vendor\\', 'vendor/', 'C:\\', '/var/www', 'select * from'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, (string) $raw, "{$route} 的错误响应泄露了内部信息:{$needle}");
            }
        }
    }

    // =====================================================================
    // 5. 经济不变量
    // =====================================================================

    // 建了就拆(施工中拆 = 取消建造,返还率 70%,是全系统最慷慨的一档)必须永远亏
    public function test_build_demolish_cycling_always_loses_material(): void
    {
        [$u, $city] = $this->player('arbitrage1');
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->update(['amount' => 300]);

        $woodStart = $this->res($city, 'wood');
        $moneyStart = (float) DB::table('cities')->where('id', $city->id)->value('money');
        $previous = $woodStart;

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'R01', 'x' => 10, 'y' => 10])->assertOk();
            $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->latest('id')->value('id');
            // 时间冻结 → 一直是 constructing,走的是 70% 那条最慷慨的返还
            $this->assertSame('constructing', DB::table('city_building_instances')->where('id', $instanceId)->value('status'));
            $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $instanceId])->assertOk();

            $now = $this->res($city, 'wood');
            $this->assertLessThan($previous, $now, "第 {$i} 轮建拆没有净亏损,存在套利");
            $previous = $now;
        }

        // R01 只花木材,不花资金 → 资金不变;木材单调下跌
        $this->assertSame($moneyStart, (float) DB::table('cities')->where('id', $city->id)->value('money'));
        $this->assertLessThan($woodStart, $this->res($city, 'wood'));
        $this->assertSame(0, DB::table('city_building_instances')->where('city_id', $city->id)->count());
    }

    // 升了就取消(返还 70% 材料、资金全损)同样必须永远亏
    public function test_upgrade_cancel_cycling_always_loses_material(): void
    {
        [$u, $city] = $this->player('arbitrage2');
        $id = $this->place($city, 'F02', 1, 1);

        $woodStart = $this->res($city, 'wood');
        $stoneStart = $this->res($city, 'stone');
        $moneyStart = (float) DB::table('cities')->where('id', $city->id)->value('money');
        $previousWood = $woodStart;

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();
            $this->actingAs($u)->postJson('/api/city/upgrade/cancel', ['instance_id' => $id])->assertOk();

            $now = $this->res($city, 'wood');
            $this->assertLessThan($previousWood, $now, "第 {$i} 轮升级取消没有净亏损,存在套利");
            $previousWood = $now;
            // 等级必须回到 L1(取消不是「白拿一级」)
            $this->assertSame(1, (int) DB::table('city_building_instances')->where('id', $id)->value('level'));
            $this->assertSame('active', DB::table('city_building_instances')->where('id', $id)->value('status'));
        }

        $this->assertLessThan($woodStart, $this->res($city, 'wood'));
        $this->assertLessThan($stoneStart, $this->res($city, 'stone'));
        $this->assertLessThan($moneyStart, (float) DB::table('cities')->where('id', $city->id)->value('money'), '资金一律不返还');
    }

    // 时代升级是纯门槛、无扣费:升到位后重复调用不得再前进一格,也不得多写一条 ERA.UPGRADE
    public function test_repeated_era_upgrade_cannot_skip_ahead(): void
    {
        [$u, $city] = $this->qualifiedEraOneCity('era-repeat');

        $this->actingAs($u)->postJson('/api/city/era/upgrade')->assertOk();
        $this->assertSame(2, (int) DB::table('cities')->where('id', $city->id)->value('era_order'));
        $revisionAfterFirst = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        // 再连打三次:II→III 的条件远未达标,必须次次 422 ERA_REQUIRED,时代停在 II
        for ($i = 0; $i < 3; $i++) {
            $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
            $res->assertStatus(422)->assertJson(['success' => false, 'error' => 'ERA_REQUIRED']);
            $this->assertSame('requirements_not_met', $res->json('details.reason'));
        }

        $this->assertSame(2, (int) DB::table('cities')->where('id', $city->id)->value('era_order'));
        $this->assertSame('II', DB::table('cities')->where('id', $city->id)->value('era_key'));
        $this->assertSame($revisionAfterFirst, (int) DB::table('cities')->where('id', $city->id)->value('revision'), '失败的时代升级不得涨 revision');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ERA.UPGRADE')->count());
    }

    // 补偿扣减不得把余额扣成负数(扣穿 = 拒绝并回滚,不是「扣到 0 为止」)
    public function test_compensation_cannot_drive_balance_negative(): void
    {
        [$pu, $city] = $this->player('comp-neg');
        $gm = $this->staff(Role::GAME_MASTER, 'comp-neg-gm');
        $wood = $this->res($city, 'wood');
        $money = (float) DB::table('cities')->where('id', $city->id)->value('money');

        $this->actingAs($gm, 'admin')->postJson('/api/admin/compensation', [
            'city_id' => $city->id, 'resource' => 'wood', 'delta' => -($wood + 0.01), 'reason' => '扣穿木材应被拒绝',
        ])->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);

        $this->actingAs($gm, 'admin')->postJson('/api/admin/compensation', [
            'city_id' => $city->id, 'resource' => 'money', 'delta' => -($money + 0.01), 'reason' => '扣穿资金应被拒绝',
        ])->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);

        $this->assertSame($wood, $this->res($city, 'wood'));
        $this->assertSame($money, (float) DB::table('cities')->where('id', $city->id)->value('money'));
        // 恰好扣到 0 是允许的(边界在「小于 0」而不是「等于 0」)
        $this->actingAs($gm, 'admin')->postJson('/api/admin/compensation', [
            'city_id' => $city->id, 'resource' => 'wood', 'delta' => -$wood, 'reason' => '恰好扣到零应放行',
        ])->assertOk();
        $this->assertSame(0.0, $this->res($city, 'wood'));
    }

    // =====================================================================
    // 6. 时序 / 组合
    // =====================================================================

    // 【现状锁定】施工中 / 升级中的实例照样可以派工:工人被占住却不产出。
    // 这是待裁决项(见汇报的裁决建议),这条测试只钉住当前行为,防止无声漂移。
    public function test_current_behavior_workers_can_be_assigned_to_unfinished_instances(): void
    {
        [$u, $city] = $this->player('idleworkers');
        $constructing = $this->place($city, 'F02', 1, 1, ConstructionService::STATUS_CONSTRUCTING);
        $upgrading = $this->place($city, 'F02', 5, 1, ConstructionService::STATUS_UPGRADING);
        $active = $this->place($city, 'F02', 9, 1);

        // 施工中 / 升级中都能派满 4 人(现状)
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instance_id' => $constructing, 'workers' => 4])->assertOk();
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instance_id' => $upgrading, 'workers' => 4])->assertOk();

        // 人口 30 → availableWorkers = 18;已被两栋未完工建筑占走 8 人
        $res = $this->actingAs($u)->postJson('/api/city/workers/assign', ['instance_id' => $active, 'workers' => 4]);
        $res->assertOk()->assertJson(['data' => ['available_workers' => 18, 'assigned_workers' => 12]]);

        // 但未完工实例一份产出都没有:生产集合里只剩那一栋 active 的 F02。
        // 用 transportDemandPerMin 断言「集合里有几栋」——它取的是名义输入输出之和(F02 = 14/min),
        // 不受科技/物流等乘区影响,三栋全 active 时会是 42
        $sim = SimulationService::simulate($city->fresh());
        $this->assertEqualsWithDelta(14.0, (float) $sim['transportDemandPerMin'], 0.0001,
            '未完工建筑不得进入生产集合;若此断言变化说明生产集合口径被改了');
        $this->assertGreaterThan(0.0, (float) ($sim['grossProductionPerMin']['food'] ?? 0), '那一栋已完工的农田仍应正常产出');
    }

    // 施工中的建筑不能下升级单(一栋楼同时只有一项工程)
    public function test_constructing_instance_cannot_be_upgraded_or_cancelled(): void
    {
        [$u, $city] = $this->player('constructing-upgrade');
        $id = $this->place($city, 'F02', 1, 1, ConstructionService::STATUS_CONSTRUCTING);
        $before = $this->wallet($city);

        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);
        // 取消「升级」也不能拿来取消建造(取消建造的唯一入口是拆除)
        $this->actingAs($u)->postJson('/api/city/upgrade/cancel', ['instance_id' => $id])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);

        $this->assertSame($before, $this->wallet($city));
        $this->assertSame('constructing', DB::table('city_building_instances')->where('id', $id)->value('status'));
    }

    // 【现状锁定】研究不绑定建筑:研究期间把知识建筑拆光,在研项目照常按时完成。
    // 现状文档化(不改玩法):研究费用是下单时一次性付清的,不存在「断电停工」语义。
    public function test_current_behavior_research_survives_demolishing_knowledge_buildings(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        [$u, $city] = $this->player('techdemolisher');

        // 时代 III 才有 K01 学堂;这里只需要一栋「知识建筑」当作被拆对象
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'III', 'era_order' => 3]);
        $k01 = $this->place($city, 'K01', 1, 1);

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_SUST'])->assertOk();
        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $k01])->assertOk();

        // 研究时长 1 分钟:拆完之后照样到点解锁
        Carbon::setTestNow($base->copy()->addMinutes(5));
        $this->actingAs($u)->getJson('/api/city')->assertOk();

        $this->assertSame('unlocked', DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', 'TECH_II_SUST')->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'TECH.UNLOCK')->count());
    }

    // 时代闸门与建造闸门共用 cities.era_order:升代前建不了、升代后立刻能建,顺序不可绕过
    public function test_era_gate_cannot_be_raced_by_build(): void
    {
        [$u, $city] = $this->qualifiedEraOneCity('era-race');

        // 建造靶子 F02(时代 II)的前置科技先铺好,让它只可能栽在时代闸门上;
        // 研究靶子用 TECH_II_LOG,它的前置 TECH_I_LOG 也一并解锁,同样只可能栽在时代闸门上
        $this->unlockTech($city->id, 'TECH_I_SUST', 'TECH_II_SUST', 'TECH_I_LOG');

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 12, 'y' => 12])
            ->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);
        // 研究同理:v3.2 §5.1「只开放该时代科技树」,下一代科技必须先升代
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_LOG'])
            ->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $this->actingAs($u)->postJson('/api/city/era/upgrade')->assertOk();

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 12, 'y' => 12])->assertOk();
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_LOG'])->assertOk();
    }

    // =====================================================================
    // 7. game_settings 组合态
    // =====================================================================

    // worker_gate_enabled = false 只关「没人也照产」,不得顺手关掉派工的两条上限校验
    public function test_worker_gate_disabled_does_not_weaken_assign_validation(): void
    {
        [$u, $city] = $this->player('gateoff-assign');
        $id = $this->place($city, 'F02', 1, 1);
        $admin = $this->staff(Role::ADMIN, 'gateoff-admin');

        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => false, 'reason' => '救急关闭用工闸门',
        ])->assertOk();

        // 下面两条是**玩家侧**请求,guard 必须显式写 'web':
        // 上一行 actingAs(..., 'admin') 已经把默认 guard 切成 admin,不写的话这两个玩家会被挂到后台那把锁上
        // 规则 1 超编:F02 L1 只要 4 人
        $this->actingAs($u, 'web')->postJson('/api/city/workers/assign', ['instance_id' => $id, 'workers' => 5])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);

        // 规则 2 超劳动力:人口 5 → 可用 3
        DB::table('cities')->where('id', $city->id)->update(['population' => 5]);
        $this->actingAs($u, 'web')->postJson('/api/city/workers/assign', ['instance_id' => $id, 'workers' => 4])
            ->assertStatus(422)->assertJson(['error' => 'WORKER_NOT_AVAILABLE']);

        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $id)->value('assigned_workers'));
    }

    // ---------- 时代 I 达标夹具(与 EraUpgradeTest 同一套 §5.1 条件) ----------

    private function qualifiedEraOneCity(string $un): array
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        foreach ([['H01', 0, 0], ['H01', 2, 0], ['H01', 4, 0], ['S01', 6, 0], ['F01', 0, 3], ['A01', 4, 3], ['D01', 8, 0]] as [$b, $x, $y]) {
            $this->place($city, $b, $x, $y);
        }
        DB::table('cities')->where('id', $city->id)->update(['population' => 60, 'money' => 5000, 'happiness' => 60]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'food'], ['amount' => 400]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'wood'], ['amount' => 800]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'stone'], ['amount' => 800]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'knowledge'], ['amount' => 500]);

        return [$u, $city->fresh()];
    }
}
