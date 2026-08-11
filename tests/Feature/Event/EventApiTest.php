<?php

namespace Tests\Feature\Event;

use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 玩家 API:GET 列表 + POST 结算(§70 五道校验 + 幂等 + Revision + 越权 + 审计)。
class EventApiTest extends EventTestCase
{
    use RefreshDatabase;

    // ---- GET /api/city/events ----

    public function test_list_requires_auth(): void
    {
        $this->getJson('/api/city/events')->assertStatus(401);
    }

    public function test_list_returns_active_instances_with_options_and_limits(): void
    {
        [$city, $user] = $this->makeCity('evtlist');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);

        $res = $this->actingAs($user)->getJson('/api/city/events')->assertOk();
        $events = $res->json('data.events');

        $this->assertSame(1, $events['active_count']);
        $active = $events['active'][0];
        $this->assertSame('EVT_FESTIVAL', $active['event_id']);
        $this->assertSame('城市庆典', $active['name_zh']);
        $this->assertSame('positive', $active['event_type']);
        $this->assertGreaterThan(0, $active['remaining_seconds']);
        // 三个选项都带上原文与「未生效」清单
        $this->assertCount(3, $active['options']);
        $this->assertSame('a', $active['options'][0]['key']);
        $this->assertNotEmpty($active['options'][0]['desc_zh']);
        $this->assertNotEmpty($active['options'][0]['unmapped_zh']);

        // 规则参数随列表下发(前端不自己编一套数值)
        $this->assertSame(3, $events['limits']['max_active']);
        $this->assertTrue($events['limits']['enabled']);
    }

    // 列表端点自己会跑一次懒结算:不然玩家打开面板永远是空的
    public function test_list_endpoint_settles_events(): void
    {
        [$city, $user] = $this->makeCity('evtsettle');
        $this->onlyEnable('EVT_FESTIVAL');

        Carbon::setTestNow(Carbon::parse(self::BASE)->addMinutes(30));
        $res = $this->actingAs($user)->getJson('/api/city/events')->assertOk();

        $this->assertSame(1, $res->json('data.events.active_count'));
    }

    // 城市快照里只放精简摘要(详情走独立端点,§15 体积可控)
    public function test_city_snapshot_carries_only_a_summary(): void
    {
        [$city, $user] = $this->makeCity('evtsnap');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);

        $events = $this->actingAs($user)->getJson('/api/city')->assertOk()->json('data.city.events');

        $this->assertSame(1, $events['active_count']);
        $this->assertSame('EVT_FESTIVAL', $events['active'][0]['event_id']);
        $this->assertArrayNotHasKey('options', $events['active'][0], '选项文案不进快照');
    }

    // ---- POST /api/city/events/resolve ----

    public function test_resolve_applies_option_and_bumps_revision(): void
    {
        [$city, $user] = $this->makeCity('evtresolve');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);

        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');
        $moneyBefore = (float) DB::table('cities')->where('id', $city->id)->value('money');
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $res = $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId,
            'choice'            => 'b', // 小型庆典:资金 -300,幸福 +5
        ])->assertOk();

        $this->assertEqualsWithDelta(-300, $res->json('data.delta.money'), 0.01);
        $this->assertSame($revisionBefore + 1, $res->json('data.revision'));
        $this->assertEqualsWithDelta($moneyBefore - 300, (float) DB::table('cities')->where('id', $city->id)->value('money'), 0.01);

        $row = DB::table('city_events')->where('id', $instanceId)->first();
        $this->assertSame('resolved', $row->status);
        $this->assertSame('b', $row->chosen_option);
        $this->assertNotNull($row->resolved_at);

        $audit = DB::table('audit_logs')->where('action', 'EVENT.RESOLVE')->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $instanceId, $audit->entity_id);
        $this->assertSame('player', $audit->actor_type);
        $this->assertEqualsWithDelta(-300, json_decode($audit->delta_json, true)['money'], 0.01);
        $this->assertSame('b', json_decode($audit->metadata_json, true)['option']);
    }

    // 未生效的选项文案(unmapped)进审计:玩家投诉「我选了但没发生」时的答案
    public function test_resolve_audit_records_unmapped_texts(): void
    {
        [$city, $user] = $this->makeCity('evtunmapped');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'a',
        ])->assertOk();

        $meta = json_decode(DB::table('audit_logs')->where('action', 'EVENT.RESOLVE')->value('metadata_json'), true);
        $this->assertNotEmpty($meta['unmapped_zh']);
    }

    // §70 ②⑤:同一个实例不允许二次结算
    public function test_resolve_is_rejected_twice(): void
    {
        [$city, $user] = $this->makeCity('evttwice');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b',
        ])->assertOk();

        $moneyAfterFirst = (float) DB::table('cities')->where('id', $city->id)->value('money');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b',
        ])->assertStatus(409)->assertJsonPath('error', 'EVENT_ALREADY_RESOLVED');

        // 第二次没有再扣一次钱
        $this->assertEqualsWithDelta($moneyAfterFirst, (float) DB::table('cities')->where('id', $city->id)->value('money'), 0.01);
    }

    // §70 ③:过期的事件不可领,并顺手翻成 expired
    public function test_resolve_is_rejected_after_expiry(): void
    {
        [$city, $user] = $this->makeCity('evtexpired');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        // 事件 12 分钟到期,推进到 40 分钟
        Carbon::setTestNow(Carbon::parse(self::BASE)->addMinutes(40));

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b',
        ])->assertStatus(422)->assertJsonPath('error', 'EVENT_EXPIRED');

        // 翻牌是懒结算的活(resolve 抛异常会回滚整个事务,顺手翻的状态会被一起抹掉),
        // 但玩家侧看到的一定是 expired:列表端点先跑一次结算,快照对「过期未翻牌」也一律显示 expired
        $listed = $this->actingAs($user)->getJson('/api/city/events')->assertOk()->json('data.events');
        $this->assertSame(0, $listed['active_count']);
        $this->assertSame('expired', $listed['recent'][0]['status']);
        $this->assertSame('expired', DB::table('city_events')->where('id', $instanceId)->value('status'));
    }

    // §70 ④:choice 必须是该事件真实存在的选项
    public function test_resolve_rejects_invalid_choice(): void
    {
        [$city, $user] = $this->makeCity('evtchoice', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'R01', 3);
        $this->addBuilding($city, 'R01', 3);
        $this->setResource($city, 'wood', 500);
        $this->onlyEnable('EVT_FOREST_FIRE'); // 只有 a / b 两个选项
        $this->runSettle($city, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        // c 不存在
        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'c',
        ])->assertStatus(422)->assertJsonPath('error', 'EVENT_OPTION_INVALID');

        // 有选项却不传:服务器不替玩家挑
        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId,
        ])->assertStatus(422)->assertJsonPath('error', 'EVENT_OPTION_INVALID');

        // 非 a/b/c 的输入连 validate 都过不去
        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'z',
        ])->assertStatus(422)->assertJsonPath('error', 'VALIDATION_ERROR');

        $this->assertSame('active', DB::table('city_events')->where('id', $instanceId)->value('status'));
    }

    // §70 ①:越权结算别人的事件 → 403 + 审计 + Security Log
    public function test_resolve_rejects_other_players_event(): void
    {
        [$cityA] = $this->makeCity('evtowner');
        [, $userB] = $this->makeCity('evtintruder');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($cityA, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $cityA->id)->value('id');

        $this->actingAs($userB)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b',
        ])->assertStatus(403)->assertJsonPath('error', 'FORBIDDEN');

        $this->assertSame('active', DB::table('city_events')->where('id', $instanceId)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'SECURITY.AUTHORIZATION_FAILED')
            ->where('entity_type', 'city_event')->count());
    }

    // 不存在的实例 → 404(不泄露「这个 id 属于谁」)
    public function test_resolve_unknown_instance_is_404(): void
    {
        [, $user] = $this->makeCity('evt404');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => 999999, 'choice' => 'a',
        ])->assertStatus(404);
    }

    // 幂等:同一个 key 重放不重复结算
    public function test_resolve_is_idempotent(): void
    {
        [$city, $user] = $this->makeCity('evtidem');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        $key = 'evt-idem-1';
        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b', 'idempotency_key' => $key,
        ])->assertOk();

        $money = (float) DB::table('cities')->where('id', $city->id)->value('money');

        // 重放:200,但不再扣一次钱、也不再多写一条审计
        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b', 'idempotency_key' => $key,
        ])->assertOk();

        $this->assertEqualsWithDelta($money, (float) DB::table('cities')->where('id', $city->id)->value('money'), 0.01);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENT.RESOLVE')->count());
    }

    // Revision 冲突
    public function test_resolve_rejects_stale_revision(): void
    {
        [$city, $user] = $this->makeCity('evtrev');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b', 'expected_revision' => 999,
        ])->assertStatus(409)->assertJsonPath('error', 'REVISION_CONFLICT');

        $this->assertSame('active', DB::table('city_events')->where('id', $instanceId)->value('status'));
    }

    // 付不起选项成本 → 整条拒绝(不做「能扣多少扣多少」)
    public function test_resolve_rejects_unaffordable_option(): void
    {
        [$city, $user] = $this->makeCity('evtpoor');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        DB::table('cities')->where('id', $city->id)->update(['money' => 10]);

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'a', // 大型庆典:资金 -800
        ])->assertStatus(422)->assertJsonPath('error', 'INSUFFICIENT_RESOURCE');

        $this->assertSame('active', DB::table('city_events')->where('id', $instanceId)->value('status'));
        $this->assertEqualsWithDelta(10, (float) DB::table('cities')->where('id', $city->id)->value('money'), 0.01);
    }

    // 全局停用时不允许结算(事件出问题时最不该让玩家继续领)
    public function test_resolve_is_blocked_when_globally_disabled(): void
    {
        [$city, $user] = $this->makeCity('evtblocked');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        GameSetting::set(GameSetting::EVENT_ENABLED, false, null, 'test');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b',
        ])->assertStatus(422)->assertJsonPath('error', 'EVENT_DISABLED');
    }

    // 输入校验:实例 id 必须是正整数
    public function test_resolve_validates_input(): void
    {
        [, $user] = $this->makeCity('evtvalidate');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => -1,
        ])->assertStatus(422)->assertJsonPath('error', 'VALIDATION_ERROR');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [])
            ->assertStatus(422)->assertJsonPath('error', 'VALIDATION_ERROR');
    }
}
