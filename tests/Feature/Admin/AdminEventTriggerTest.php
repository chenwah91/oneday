<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\AuditAction;
use App\Support\ErrorCode;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Event\EventTestCase;

// 管理员手动触发事件(W11-C1 任务5)。
//
// 复用 Tests\Feature\Event\EventTestCase 的脚手架:时间冻结 + makeCity + onlyEnable ——
// 事件的一切断言都建立在「窗口号不漂」之上,自己再造一套只会漂。
class AdminEventTriggerTest extends EventTestCase
{
    use RefreshDatabase;

    private function admin(string $un = 'evttrigadm', string $role = 'admin'): User
    {
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    // 只启用指定事件,并把自然触发概率压到 0 ——
    // 本文件验的是**手动**触发,自然触发混进来会让 active 计数变得不可预期
    private function onlyManual(string ...$eventIds): void
    {
        $this->onlyEnable(...$eventIds);
        GameSetting::set(GameSetting::EVENT_TRIGGER_CHANCE, 0.0, null, 'test');
    }

    // ---- 实例真实落地 + 效果生效 + 两条审计 ----

    public function test_force_trigger_lands_instance_effects_and_two_audits(): void
    {
        [$city] = $this->makeCity('forcecity');
        $admin = $this->admin();
        $this->onlyManual('EVT_FESTIVAL');

        $res = $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => '复现玩家工单 T-2048',
        ])->assertOk();

        $instanceId = (int) $res->json('data.event_instance_id');
        $this->assertGreaterThan(0, $instanceId);
        $this->assertSame('EVT_FESTIVAL', $res->json('data.event_id'));

        // ① 实例真实落地:与自然触发同一张表、同一套字段
        $instance = DB::table('city_events')->where('id', $instanceId)->first();
        $this->assertNotNull($instance);
        $this->assertSame((int) $city->id, (int) $instance->city_id);
        $this->assertSame('active', $instance->status);
        $this->assertSame(
            Carbon::parse(self::BASE)->addMinutes(12)->toDateTimeString(),
            Carbon::parse($instance->expires_at)->toDateTimeString(),
            '到期时刻 = 触发 + duration_minutes(与自然触发同口径)'
        );
        $this->assertNotNull($instance->applied_json, '必须走过 EventEffect 并把结果落库');

        // ② 效果真的生效:EVT_FESTIVAL 是「幸福 +4、持续 12 分钟」→ 落成 flat modifier 行
        $modifier = DB::table('city_active_modifiers')
            ->where('city_id', $city->id)->where('source_type', 'event')->where('source_id', $instanceId)
            ->first();
        $this->assertNotNull($modifier, '持续型效果必须落到 city_active_modifiers');
        $this->assertSame(4.0, (float) $modifier->value);

        // ③ 冷却照常写(跳过的是「读」,不是「写」)
        $this->assertSame(
            Carbon::parse(self::BASE)->addMinutes(40)->toDateTimeString(),
            Carbon::parse(DB::table('city_event_cooldowns')->where('city_id', $city->id)
                ->where('event_id', 'EVT_FESTIVAL')->value('available_at'))->toDateTimeString()
        );

        // ④ 两条审计:EVENT.TRIGGER(actor=system)+ ADMIN.CONFIG_CHANGE(actor=admin),共享同一 entity_id
        $trigger = DB::table('audit_logs')->where('action', AuditAction::EVENT_TRIGGER)
            ->where('entity_id', (string) $instanceId)->first();
        $this->assertNotNull($trigger, '必须复用自然触发那条 EVENT.TRIGGER 审计');
        $this->assertSame('system', $trigger->actor_type);

        $adminAudit = DB::table('audit_logs')->where('action', AuditAction::ADMIN_CONFIG_CHANGE)
            ->where('entity_id', (string) $instanceId)->first();
        $this->assertNotNull($adminAudit, '手动触发必须另写一条管理员审计');
        $this->assertSame('admin', $adminAudit->actor_type);
        $this->assertSame((int) $admin->id, (int) $adminAudit->actor_id);
        $this->assertSame('city_event', $adminAudit->entity_type);
        $this->assertSame('复现玩家工单 T-2048', $adminAudit->reason_code);

        $meta = json_decode((string) $adminAudit->metadata_json, true);
        $this->assertTrue($meta['forced'], '与自然触发的区分点');
        $this->assertSame('EVT_FESTIVAL', $meta['event_id']);
        $this->assertSame('复现玩家工单 T-2048', $meta['reason']);
        $this->assertSame(['weight_roll', 'cooldown'], $meta['skipped']);
    }

    // ---- 冷却被跳过 ----

    public function test_force_trigger_ignores_cooldown(): void
    {
        [$city] = $this->makeCity('forcecd');
        $admin = $this->admin();
        $this->onlyManual('EVT_FESTIVAL');

        // 手工种一条「还要等两小时」的冷却:自然路径此时绝不会抽到它
        DB::table('city_event_cooldowns')->insert([
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL',
            'available_at' => Carbon::parse(self::BASE)->addHours(2),
        ]);

        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => '冷却中也要能复现',
        ])->assertOk();

        $this->assertSame(1, DB::table('city_events')->where('city_id', $city->id)->count(), '冷却不该挡住手动触发');

        // 冷却行被刷新成「本次触发 + cooldown_minutes」,复现完不会立刻又被自然抽中
        $this->assertSame(
            Carbon::parse(self::BASE)->addMinutes(40)->toDateTimeString(),
            Carbon::parse(DB::table('city_event_cooldowns')->where('city_id', $city->id)
                ->where('event_id', 'EVT_FESTIVAL')->value('available_at'))->toDateTimeString()
        );
    }

    // ---- 并发上限仍拦 ----

    public function test_force_trigger_still_respects_active_limits(): void
    {
        [$city] = $this->makeCity('forcelimit');
        $admin = $this->admin();
        $this->onlyManual('EVT_FESTIVAL', 'EVT_HARVEST', 'EVT_DROUGHT', 'EVT_FLOOD');

        // 全局上限收到 1:第一条进得去,第二条必须被拦
        GameSetting::set(GameSetting::EVENT_MAX_ACTIVE_DISASTER, 1, null, 'test');
        GameSetting::set(GameSetting::EVENT_MAX_ACTIVE, 1, null, 'test');

        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => '第一条应当成功',
        ])->assertOk();

        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_HARVEST', 'reason' => '第二条应当被上限拦下',
        ])->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::EVENT_LIMIT_REACHED)
            ->assertJsonPath('details.limit', 'max_active');

        // 同一事件不得重复叠加(叠第二份 = 持续型 modifier 双倍生效)
        GameSetting::set(GameSetting::EVENT_MAX_ACTIVE, 5, null, 'test');
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => '同一条不得叠加两份',
        ])->assertStatus(422)
            ->assertJsonPath('error', ErrorCode::EVENT_LIMIT_REACHED)
            ->assertJsonPath('details.limit', 'already_active');

        // 灾害档的独立上限同样照常尊重(总上限 5、灾害档 1)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_DROUGHT', 'reason' => '第一条灾害应当成功',
        ])->assertOk();
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FLOOD', 'reason' => '第二条灾害应被灾害档拦下',
        ])->assertStatus(422)
            ->assertJsonPath('details.limit', 'max_active_disaster');

        // 被拦下的三次一条实例都不该落地
        $this->assertSame(2, DB::table('city_events')->where('city_id', $city->id)->count());
    }

    // ---- 开关与不存在的事件 ----

    public function test_force_trigger_respects_switches_and_unknown_event(): void
    {
        [$city] = $this->makeCity('forceswitch');
        $admin = $this->admin();
        $this->onlyManual('EVT_FESTIVAL');

        // 未登记的 event_id → 404
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_NOT_EXISTS', 'reason' => '不存在的事件应当 404',
        ])->assertStatus(404);

        // 不存在的城市 → 404
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => 999999, 'event_id' => 'EVT_FESTIVAL', 'reason' => '不存在的城市应当 404',
        ])->assertStatus(404);

        // 该事件被停用 → 422(事件被关掉通常是因为它本身算错了,不给绕过开关的后门)
        DB::table('event_definition')->where('event_id', 'EVT_FESTIVAL')->update(['enabled' => false]);
        \App\Game\Event\EventDefinition::flush();
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => '停用的事件不许强触发',
        ])->assertStatus(422)->assertJsonPath('error', ErrorCode::EVENT_DISABLED);

        // 事件总开关关掉 → 同样 422
        DB::table('event_definition')->where('event_id', 'EVT_FESTIVAL')->update(['enabled' => true]);
        \App\Game\Event\EventDefinition::flush();
        GameSetting::set(GameSetting::EVENT_ENABLED, false, null, 'test');
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => '系统停用时不许强触发',
        ])->assertStatus(422)->assertJsonPath('error', ErrorCode::EVENT_DISABLED);

        $this->assertSame(0, DB::table('city_events')->where('city_id', $city->id)->count());
    }

    // ---- 权限与强制 reason ----

    public function test_force_trigger_requires_edit_definition_and_reason(): void
    {
        [$city] = $this->makeCity('forceperm');

        // 未登录 401(排在所有 actingAs 之前:actingAs 对本用例后续请求持续生效)
        $this->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => '未登录尝试触发',
        ])->assertStatus(401);

        // game_master 没有 edit_definition → 403
        $gm = $this->admin('forcegm', 'game_master');
        $this->actingAs($gm, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => '越权尝试触发',
        ])->assertStatus(403);

        $admin = $this->admin();
        $this->onlyManual('EVT_FESTIVAL');

        // reason 必填、至少 5 字、不超过 80 字
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL',
        ])->assertStatus(422);
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => 'abc',
        ])->assertStatus(422);
        $this->actingAs($admin, 'admin')->postJson('/api/admin/events/trigger', [
            'city_id' => $city->id, 'event_id' => 'EVT_FESTIVAL', 'reason' => str_repeat('长', 81),
        ])->assertStatus(422);

        // 一条实例都不该落地
        $this->assertSame(0, DB::table('city_events')->where('city_id', $city->id)->count());
    }
}
