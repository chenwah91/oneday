<?php

namespace Tests\Feature\Admin;

use App\Game\City\CityFactory;
use App\Game\Population\WorkerService;
use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\GameSetting;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 后台可配规则开关 game_settings
// 覆盖:默认值 / get-set / 请求级缓存 / 审计 / 权限(admin 可改、game_master 不可) / 开关关闭后的内核行为
class GameSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

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

    // 一座城 + 一栋 F02(L1 需 4 人、产粮 14/分),不派工人;时间冻结 → elapsed 恒 0,只看速率
    private function cityWithIdleFarm(string $username): City
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $user = User::create([
            'username' => $username, 'name' => $username,
            'email' => $username . '@example.com', 'password' => 'password123',
        ]);
        $city = CityFactory::createForUser($user);
        CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'F02', 'level' => 1,
            'x' => 1, 'y' => 1, 'status' => 'active',
        ]);

        return $city;
    }

    // ---------- 迁移种子与默认值 ----------

    public function test_migration_seeds_registered_settings(): void
    {
        foreach (array_keys(GameSetting::DEFINITIONS) as $key) {
            $this->assertSame(1, DB::table('game_settings')->where('setting_key', $key)->count());
        }

        // 默认值必须与「接入开关前的历史行为」一致:两个开关都是 true
        $this->assertTrue(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
        $this->assertTrue(GameSetting::get(GameSetting::WORKER_ASSIGN_ALLOW_DECREASE_ALWAYS));
    }

    // 缺行(库比代码旧)时回退默认值,而不是崩掉或换一套规则
    public function test_missing_row_falls_back_to_default(): void
    {
        DB::table('game_settings')->where('setting_key', GameSetting::WORKER_GATE_ENABLED)->delete();
        GameSetting::flush();

        $this->assertTrue(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
        // 未登记的 key:显式默认值优先,没有默认值则 null
        $this->assertSame('fallback', GameSetting::get('never_registered', 'fallback'));
        $this->assertNull(GameSetting::get('never_registered'));
    }

    // ---------- get / set ----------

    public function test_set_updates_value_and_writes_audit(): void
    {
        $admin = $this->staff(Role::ADMIN, 'setadmin1');

        $result = GameSetting::set(GameSetting::WORKER_GATE_ENABLED, false, (int) $admin->id, '临时救急关闭用工闸门');

        $this->assertTrue($result['before']);
        $this->assertFalse($result['after']);
        $this->assertFalse(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
        $this->assertSame('false', DB::table('game_settings')
            ->where('setting_key', GameSetting::WORKER_GATE_ENABLED)->value('value_json'));

        $audit = DB::table('audit_logs')->where('action', 'ADMIN.CONFIG_CHANGE')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame((int) $admin->id, (int) $audit->actor_id);
        $this->assertSame('game_setting', $audit->entity_type);
        $this->assertSame(GameSetting::WORKER_GATE_ENABLED, $audit->entity_id);
        $this->assertSame('临时救急关闭用工闸门', $audit->reason_code);
        $this->assertTrue(json_decode($audit->before_json, true)[GameSetting::WORKER_GATE_ENABLED]);
        $this->assertFalse(json_decode($audit->after_json, true)[GameSetting::WORKER_GATE_ENABLED]);
    }

    // 未登记的 key 一律拒绝(allowlist,CLAUDE §45):不允许后台造出没人读的死配置
    public function test_set_rejects_unknown_key(): void
    {
        $this->expectException(GameRuleException::class);
        GameSetting::set('never_registered', true, null, '不该被写进去');
    }

    public function test_set_rejects_non_boolean_value(): void
    {
        $this->expectException(GameRuleException::class);
        // 布尔开关只收真正的 true/false,不做 "1"/"on"/"yes" 的模糊解释
        GameSetting::set(GameSetting::WORKER_GATE_ENABLED, 'yes', null, '类型不符');
    }

    // 请求级缓存:一次请求内整表只查一次库(applyLocked 在事务内高频调用,逐次查库不可接受)
    public function test_get_uses_per_request_cache(): void
    {
        GameSetting::flush();

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'game_settings')) {
                $queries++;
            }
        });

        for ($i = 0; $i < 5; $i++) {
            GameSetting::get(GameSetting::WORKER_GATE_ENABLED);
            GameSetting::get(GameSetting::WORKER_ASSIGN_ALLOW_DECREASE_ALWAYS);
        }

        $this->assertSame(1, $queries);
    }

    // 写入后必须失效缓存,否则同一请求内后续结算还在用旧规则
    public function test_set_flushes_cache(): void
    {
        $this->assertTrue(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
        GameSetting::set(GameSetting::WORKER_GATE_ENABLED, false, null, '改完立刻要生效');
        $this->assertFalse(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
    }

    // ---------- 内核行为:worker_gate_enabled ----------

    // 开关开着(默认):没派工人 → workerFactor = 0 → 该建筑不产出
    public function test_worker_gate_enabled_blocks_production_without_workers(): void
    {
        $city = $this->cityWithIdleFarm('gateon');

        $sim = SimulationService::simulate($city);

        $this->assertSame(0.0, (float) ($sim['grossProductionPerMin'][ResourceCode::FOOD] ?? 0));
    }

    // 开关关掉:workerFactor 恒为 1.0 → 没派工人也满额产出(F02 L1 = 14 粮/分)
    public function test_worker_gate_disabled_forces_factor_one(): void
    {
        $city = $this->cityWithIdleFarm('gateoff');
        GameSetting::set(GameSetting::WORKER_GATE_ENABLED, false, null, '关闭用工闸门做救急验证');

        $sim = SimulationService::simulate($city);

        $this->assertSame(14.0, (float) ($sim['grossProductionPerMin'][ResourceCode::FOOD] ?? 0));
    }

    // ---------- 内核行为:worker_assign_allow_decrease_always ----------

    // 直接调服务层而不是打 HTTP 端点:这里验的是开关对规则分支的影响,
    // 不该跟着玩家侧 API 的字段命名一起变动
    private function farmInstanceId(City $city): int
    {
        return (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');
    }

    // 开关开着(默认):人口暴跌导致历史分配超上限时,撤人仍放行
    public function test_decrease_always_allowed_by_default(): void
    {
        $city = $this->cityWithIdleFarm('decon');
        $instanceId = $this->farmInstanceId($city);

        // 先满编 4 人,再把人口压到 1(availableWorkers = floor(1 × 0.6) = 0),制造「历史分配超上限」
        WorkerService::assign($city, $instanceId, 4, null, null);
        DB::table('cities')->where('id', $city->id)->update(['population' => 1]);

        // 撤到 2 人仍超上限(0),但这是「只减」,放行
        WorkerService::assign($city->fresh(), $instanceId, 2, null, null);

        $this->assertSame(2, (int) DB::table('city_building_instances')->where('id', $instanceId)->value('assigned_workers'));
    }

    // 开关关掉:撤人也要满足劳动力上限,超上限的目标值一律拒
    public function test_decrease_rejected_when_switch_disabled(): void
    {
        $city = $this->cityWithIdleFarm('decoff');
        $instanceId = $this->farmInstanceId($city);

        WorkerService::assign($city, $instanceId, 4, null, null);
        DB::table('cities')->where('id', $city->id)->update(['population' => 1]);

        GameSetting::set(GameSetting::WORKER_ASSIGN_ALLOW_DECREASE_ALWAYS, false, null, '收紧只减放行');

        try {
            WorkerService::assign($city->fresh(), $instanceId, 2, null, null);
            $this->fail('开关关闭后,超劳动力上限的撤人应被拒绝');
        } catch (GameRuleException $e) {
            $this->assertSame(ErrorCode::WORKER_NOT_AVAILABLE, $e->errorCode);
        }
        $this->assertSame(4, (int) DB::table('city_building_instances')->where('id', $instanceId)->value('assigned_workers'));

        // 撤到 0(<= availableWorkers)仍然可以,不至于把玩家彻底锁死
        WorkerService::assign($city->fresh(), $instanceId, 0, null, null);
        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $instanceId)->value('assigned_workers'));
    }

    // ---------- 后台设置页 ----------

    public function test_admin_can_list_and_update_settings(): void
    {
        $admin = $this->staff(Role::ADMIN, 'settingadmin');

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/settings');
        $res->assertOk();
        $settings = collect($res->json('data.settings'));
        $this->assertSame(count(GameSetting::DEFINITIONS), $settings->count());
        $row = $settings->firstWhere('setting_key', GameSetting::WORKER_GATE_ENABLED);
        $this->assertTrue($row['value']);
        $this->assertNotSame('', $row['description']);
        $this->assertSame('bool', $row['type']);

        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::WORKER_GATE_ENABLED,
            'value'       => false,
            'reason'      => '后台关闭用工闸门',
        ])->assertOk()->assertJson(['data' => [
            'setting_key' => GameSetting::WORKER_GATE_ENABLED,
            'before'      => true,
            'after'       => false,
        ]]);

        $this->assertSame('false', DB::table('game_settings')
            ->where('setting_key', GameSetting::WORKER_GATE_ENABLED)->value('value_json'));
        $this->assertSame((int) $admin->id, (int) DB::table('game_settings')
            ->where('setting_key', GameSetting::WORKER_GATE_ENABLED)->value('updated_by'));
    }

    // 权限:开关改变全服规则,与改数值同级 → game_master 及以下一律 403 并留审计
    public function test_game_master_cannot_read_or_update_settings(): void
    {
        $gm = $this->staff(Role::GAME_MASTER, 'settinggm');

        $this->actingAs($gm, 'admin')->getJson('/api/admin/settings')->assertStatus(403);
        $this->actingAs($gm, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => false, 'reason' => '越权尝试',
        ])->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', $audit->action);
        $this->assertSame(Role::EDIT_DEFINITION, json_decode($audit->metadata_json, true)['required_permission']);

        // 开关值一点没变
        $this->assertTrue(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
    }

    // guard 写 'admin' 才落得到 EnsureAdmin 的角色闸门(403);只有玩家会话时是 auth:admin 的 401
    public function test_player_denied(): void
    {
        $player = $this->staff(Role::PLAYER, 'settingplayer');
        $this->actingAs($player, 'admin')->getJson('/api/admin/settings')->assertStatus(403);
    }

    // 未登录单独一条:actingAs 会作用到整个用例剩余部分,和已登录断言混在一起验不出 401
    public function test_guest_denied(): void
    {
        $this->getJson('/api/admin/settings')->assertStatus(401);
        $this->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => false, 'reason' => '未登录尝试',
        ])->assertStatus(401);
    }

    // 后台改开关同样强制 reason,且未登记的 key 一律拒绝
    public function test_update_requires_reason_and_registered_key(): void
    {
        $admin = $this->staff(Role::ADMIN, 'settingadmin2');

        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => false,
        ])->assertStatus(422);

        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => 'never_registered', 'value' => true, 'reason' => '造一个新 key',
        ])->assertStatus(422);

        $this->assertTrue(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));
    }
}
