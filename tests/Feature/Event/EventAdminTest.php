<?php

namespace Tests\Feature\Event;

use App\Game\Definition\GameDataVersion;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// 后台事件管理:用户 2026-08-10 拍板③「**所有事件必须在管理员后台可设定**」的验收面。
//
// 两条路径都要守住:
//   ① 逐事件(开关 / 权重 / 冷却 / 持续时间 / 效果强度)→ /api/admin/definitions/event;
//   ② 全局(触发概率 / 并发上限 / 离线补算上限 / 权重三修正系数)→ /api/admin/settings。
// 外加一条硬约束:**后台改动必须即刻影响后续触发**(定义读取不许缓存过窗)。
class EventAdminTest extends EventTestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // role 已不可批量赋值,测试里用 forceFill 显式提权
        $user = User::create(['username' => 'evtadmin', 'name' => 'evtadmin', 'email' => 'evtadmin@example.com', 'password' => 'password123']);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    // ---- 列表 ----

    public function test_list_returns_thirty_events_with_landing_summary(): void
    {
        $res = $this->actingAs($this->admin(), 'admin')->getJson('/api/admin/definitions/events')->assertOk();
        $events = $res->json('data.events');

        $this->assertCount(30, $events);
        $this->assertSame(
            ['enabled', 'base_weight', 'cooldown_minutes', 'duration_minutes', 'effect_multiplier'],
            $res->json('data.editable')
        );

        $drought = collect($events)->firstWhere('event_id', 'EVT_DROUGHT');
        $this->assertSame('干旱', $drought['name_zh']);
        // 「效果落地」一栏:运营看得出这条事件到底有几条效果能执行、几条只是文案
        $this->assertGreaterThan(0, $drought['mapped_effect_count']);
        $this->assertArrayHasKey('unmapped_effect_count', $drought);

        // 停用的事件必须带原因(后台要能一眼看出为什么是灰的)。
        // 不再点名具体某一条:依赖落地的波次会陆续把它们复活(EVT_BLACKOUT 已在 M.1 电力波次复活),
        // 点名会让这条断言每复活一条就红一次 —— 真正要守的是「停用必有原因」这条不变量
        $disabled = collect($events)->filter(fn ($e) => (int) $e['enabled'] === 0)->values();
        $this->assertGreaterThan(0, $disabled->count());
        foreach ($disabled as $row) {
            $this->assertNotEmpty($row['disabled_reason'], "{$row['event_id']} 停用了却没写原因");
        }

        // 反向:启用的行不许挂着停用原因。EVT_BLACKOUT 就是 M.1 电力落地后复活的那一条
        $blackout = collect($events)->firstWhere('event_id', 'EVT_BLACKOUT');
        $this->assertSame(1, (int) $blackout['enabled'], 'M.1 电力落地后 EVT_BLACKOUT 已复活');
        $this->assertNull($blackout['disabled_reason']);
        $this->assertGreaterThan(0, $blackout['mapped_effect_count'], '复活的事件必须有能执行的效果');
    }

    // guard 写 'admin' 才落得到 EnsureAdmin 的角色闸门(403);只有玩家会话时是 auth:admin 的 401
    public function test_list_requires_edit_definition_permission(): void
    {
        $player = User::create(['username' => 'evtplayer', 'name' => 'p', 'email' => 'evtplayer@example.com', 'password' => 'password123']);

        $this->actingAs($player, 'admin')->getJson('/api/admin/definitions/events')->assertStatus(403);
    }

    // ---- 编辑:审计 + 版本递增 ----

    public function test_edit_weight_audits_and_bumps_version(): void
    {
        $versionBefore = GameDataVersion::current();

        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_DROUGHT', 'field' => 'base_weight', 'value' => 20, 'reason' => '干旱出现得太少',
        ])->assertOk()->assertJsonPath('data.before', '8.0000')->assertJsonPath('data.after', 20);

        $this->assertEqualsWithDelta(
            20,
            (float) DB::table('event_definition')->where('event_id', 'EVT_DROUGHT')->value('base_weight'),
            0.0001
        );

        $audit = DB::table('audit_logs')->where('entity_type', 'event_definition')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertSame('admin', $audit->actor_type);
        $this->assertSame('EVT_DROUGHT', $audit->entity_id);
        $this->assertSame('干旱出现得太少', $audit->reason_code);

        $this->assertNotSame($versionBefore, GameDataVersion::current(), '改事件数值必须 bump game_data_version');
    }

    public function test_edit_requires_reason_and_allowlisted_field(): void
    {
        $admin = $this->admin();

        // 没有 reason
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_DROUGHT', 'field' => 'base_weight', 'value' => 20,
        ])->assertStatus(422);

        // 不在 allowlist 里的字段(改分类 = 改权重分组,属结构性变更)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_DROUGHT', 'field' => 'category', 'value' => 1, 'reason' => '试试',
        ])->assertStatus(422);

        // 负值
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_DROUGHT', 'field' => 'base_weight', 'value' => -1, 'reason' => '试试',
        ])->assertStatus(422);

        // 超上限
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_DROUGHT', 'field' => 'effect_multiplier', 'value' => 99, 'reason' => '试试',
        ])->assertStatus(422);

        // 分钟类必须是整数
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_DROUGHT', 'field' => 'cooldown_minutes', 'value' => 12.5, 'reason' => '试试',
        ])->assertStatus(422);

        // 不存在的事件
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_NOPE', 'field' => 'base_weight', 'value' => 1, 'reason' => '试试',
        ])->assertStatus(404);
    }

    // 启用一条「自动效果全是 unmapped」的事件:不拦,但要明确告知开了也没后果。
    // 样本换成 EVT_NEW_DEPOSIT(整条依赖资源节点系统 M.6,自动效果仍然是空的)——
    // 原来的样本 EVT_BLACKOUT 已在 M.1 电力波次复活,自动效果不再为空
    public function test_enabling_an_unmappable_event_returns_a_warning(): void
    {
        $this->assertSame([], json_decode((string) DB::table('event_definition')
            ->where('event_id', 'EVT_NEW_DEPOSIT')->value('auto_effect_json'), true)['effects'],
            '样本前提:该事件的自动效果确实为空');

        $res = $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_NEW_DEPOSIT', 'field' => 'enabled', 'value' => 1, 'reason' => '先试试',
        ])->assertOk();

        $this->assertNotNull($res->json('data.warning'));
        $this->assertSame(1, (int) DB::table('event_definition')->where('event_id', 'EVT_NEW_DEPOSIT')->value('enabled'));
    }

    // ---- 硬约束:后台改动即刻影响后续触发 ----

    // 后台把事件停用 → 同一进程里下一次结算就不该再触发它
    public function test_disabling_via_admin_takes_effect_immediately(): void
    {
        [$city] = $this->makeCity('adminoff');
        $this->onlyEnable('EVT_FESTIVAL');

        // disabled_reason 于 W11-B 起是停用的必填项(后台列表要显示「这条为什么是灰的」)
        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_FESTIVAL', 'field' => 'enabled', 'value' => 0,
            'reason' => '临时下线', 'disabled_reason' => '庆典临时下线,等权重重算',
        ])->assertOk();

        $this->runSettle($city, 30);

        $this->assertSame(0, DB::table('city_events')->where('city_id', $city->id)->count());
    }

    // 后台改持续时间 → 新触发的实例立刻按新值到期
    public function test_duration_change_takes_effect_on_next_trigger(): void
    {
        [$city] = $this->makeCity('admindur');
        $this->onlyEnable('EVT_FESTIVAL');

        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_FESTIVAL', 'field' => 'duration_minutes', 'value' => 40, 'reason' => '庆典延长',
        ])->assertOk();

        $this->runSettle($city, 5);

        $instance = DB::table('city_events')->where('city_id', $city->id)->first();
        $this->assertSame(
            \Illuminate\Support\Carbon::parse(self::BASE)->addMinutes(45)->toDateTimeString(),
            \Illuminate\Support\Carbon::parse($instance->expires_at)->toDateTimeString()
        );
    }

    // 后台改效果强度 → 新触发实例的效果数值按新倍率
    public function test_effect_multiplier_change_takes_effect_on_next_trigger(): void
    {
        [$city] = $this->makeCity('adminmul', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'R01', 3);
        $this->addBuilding($city, 'R01', 3);
        $this->setResource($city, 'wood', 500);
        $this->onlyEnable('EVT_FOREST_FIRE');

        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_FOREST_FIRE', 'field' => 'effect_multiplier', 'value' => 0.5, 'reason' => '火灾太狠',
        ])->assertOk();

        $this->runSettle($city, 5);

        $rolled = json_decode(DB::table('city_events')->where('city_id', $city->id)->value('rolled_json'), true);
        $this->assertEqualsWithDelta(-0.025, $rolled['loss']['pct'], 0.0001, '5% × 0.5');
    }

    // ---- 全局参数走设置页(数值控件自动渲染)----

    public function test_global_event_settings_are_editable_through_the_settings_page(): void
    {
        $admin = $this->admin();

        $list = $this->actingAs($admin, 'admin')->getJson('/api/admin/settings')->assertOk()->json('data.settings');
        $chance = collect($list)->firstWhere('setting_key', 'event_trigger_chance');

        $this->assertSame('number', $chance['type']);
        $this->assertSame(0, $chance['min_value']);
        $this->assertSame(1, $chance['max_value']);
        $this->assertTrue($chance['registered']);

        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => 'event_trigger_chance', 'value' => 0.25, 'reason' => '提高事件密度',
        ])->assertOk();

        $this->assertSame(0.25, GameSetting::get(GameSetting::EVENT_TRIGGER_CHANCE));

        // 越界值被服务端拒绝(前端校验只是体验优化)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => 'event_trigger_chance', 'value' => 1.5, 'reason' => '越界',
        ])->assertStatus(422);
    }

    // 全局并发上限改了立刻影响触发
    public function test_global_max_active_change_takes_effect_immediately(): void
    {
        [$city] = $this->makeCity('adminmax');
        DB::table('event_definition')->update(['cooldown_minutes' => 0]);
        \App\Game\Event\EventDefinition::flush();
        GameSetting::set(GameSetting::EVENT_TRIGGER_CHANCE, 1.0, null, 'test');
        GameSetting::set(GameSetting::EVENT_OFFLINE_MAX_TRIGGERS, 10, null, 'test');

        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/settings', [
            'setting_key' => 'event_max_active', 'value' => 1, 'reason' => '压事件密度',
        ])->assertOk();

        $this->runSettle($city, 60);

        $this->assertSame(1, $this->activeInstances($city)->count());
    }
}
