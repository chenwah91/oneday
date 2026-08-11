<?php

namespace Tests\Feature\Event;

use App\Game\Event\EventCondition;
use App\Game\Event\EventDefinition;
use App\Game\Event\EventRandom;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 触发引擎:掷点确定性 / 权重三修正 / 冷却 / 并发 / 离线补算封顶 / 禁用不触发 / 过期作废。
class EventTriggerTest extends EventTestCase
{
    use RefreshDatabase;

    // ---- 掷点确定性(§30 / §66 / backlog §11.3)----

    // 同一 (城市, 窗口, 标签) 永远得到同一个数;换城市 / 换窗口就变
    public function test_roll_is_deterministic_per_city_and_window(): void
    {
        $a = EventRandom::unit(7, 12345, 'trigger');
        $b = EventRandom::unit(7, 12345, 'trigger');

        $this->assertSame($a, $b, '同一城市同一窗口必须掷出同一个数(否则重登录就能刷事件)');
        $this->assertNotSame($a, EventRandom::unit(8, 12345, 'trigger'));
        $this->assertNotSame($a, EventRandom::unit(7, 12346, 'trigger'));
        $this->assertNotSame($a, EventRandom::unit(7, 12345, 'pick'));
        $this->assertGreaterThanOrEqual(0.0, $a);
        $this->assertLessThan(1.0, $a);
    }

    // 引擎级的确定性:同一段离线时间重算两次,必须触发在**同样的窗口**上。
    // 这一条正面挡住 backlog §11.3 点名的「玩家控制上线时间刷事件」
    public function test_offline_backfill_is_reproducible(): void
    {
        [$city] = $this->makeCity('detrigger', ['money' => 100000]);
        $this->onlyEnable('EVT_FESTIVAL');
        // 概率回到 8%:要验的是「同样的窗口中签」,不是「每窗都中」
        GameSetting::set(GameSetting::EVENT_TRIGGER_CHANCE, 0.08, null, 'test');
        DB::table('event_definition')->where('event_id', 'EVT_FESTIVAL')->update(['cooldown_minutes' => 0]);
        EventDefinition::flush();

        $this->runSettle($city, 120);
        $first = DB::table('city_events')->where('city_id', $city->id)->orderBy('id')->pluck('window_index')->all();

        // 「重登录」:清掉这一轮的结果,把事件时钟拨回原点,再跑同一段时间
        DB::table('city_events')->where('city_id', $city->id)->delete();
        DB::table('city_event_cooldowns')->where('city_id', $city->id)->delete();
        DB::table('city_active_modifiers')->where('city_id', $city->id)->delete();
        DB::table('cities')->where('id', $city->id)->update(['event_settled_at' => self::BASE]);

        $this->runSettle($city, 120);
        $second = DB::table('city_events')->where('city_id', $city->id)->orderBy('id')->pluck('window_index')->all();

        $this->assertNotEmpty($first, '两小时 × 8% 应该至少触发一次');
        $this->assertSame($first, $second, '同一段时间重算必须落在同样的窗口上');
    }

    // ---- 基本触发链路 ----

    public function test_trigger_writes_instance_cooldown_and_audit(): void
    {
        [$city] = $this->makeCity('trigbasic');
        $this->onlyEnable('EVT_FESTIVAL');

        $this->runSettle($city, 5);

        $instance = DB::table('city_events')->where('city_id', $city->id)->first();
        $this->assertNotNull($instance);
        $this->assertSame('EVT_FESTIVAL', $instance->event_id);
        $this->assertSame('active', $instance->status);
        // 触发时刻取「本次结算时刻」,到期 = 触发 + duration(12 分钟)
        $this->assertSame(
            Carbon::parse(self::BASE)->addMinutes(5 + 12)->toDateTimeString(),
            Carbon::parse($instance->expires_at)->toDateTimeString()
        );

        $cooldown = DB::table('city_event_cooldowns')->where('city_id', $city->id)->first();
        $this->assertSame('EVT_FESTIVAL', $cooldown->event_id);
        $this->assertSame(
            Carbon::parse(self::BASE)->addMinutes(5 + 40)->toDateTimeString(),
            Carbon::parse($cooldown->available_at)->toDateTimeString()
        );

        $audit = DB::table('audit_logs')->where('action', 'EVENT.TRIGGER')->first();
        $this->assertNotNull($audit);
        $this->assertSame('system', $audit->actor_type, '触发不是玩家操作,actor 必须是 system');
        $this->assertSame((string) $city->id, (string) $audit->city_id);
        $meta = json_decode($audit->metadata_json, true);
        $this->assertSame('EVT_FESTIVAL', $meta['event_id']);
        // 权重三修正系数进审计:「为什么抽中这一条」要能复盘
        $this->assertArrayHasKey('weight_detail', $meta);
        // json_encode 会把 1.0 写成 1,读回来是 int —— 用 assertEquals 比较数值本身
        $this->assertEquals(1.0, $meta['weight_detail']['condition']);
        $this->assertArrayHasKey('window_index', $meta);
    }

    // 禁用的事件连候选池都进不去(后台开关的最直接语义)
    public function test_disabled_event_never_triggers(): void
    {
        [$city] = $this->makeCity('trigdisabled');
        $this->onlyEnable('EVT_FESTIVAL');
        DB::table('event_definition')->where('event_id', 'EVT_FESTIVAL')->update(['enabled' => false]);
        EventDefinition::flush();

        $this->runSettle($city, 30);

        $this->assertSame(0, DB::table('city_events')->where('city_id', $city->id)->count());
    }

    // 全局总开关关掉:一条都不触发(已生效的实例仍照常到期,见 expire 用例)
    public function test_global_switch_off_stops_triggering(): void
    {
        [$city] = $this->makeCity('trigoff');
        $this->onlyEnable('EVT_FESTIVAL');
        GameSetting::set(GameSetting::EVENT_ENABLED, false, null, 'test');

        $this->runSettle($city, 30);

        $this->assertSame(0, DB::table('city_events')->where('city_id', $city->id)->count());
    }

    // 条件不满足 = 权重 0 = 不进候选池(9.D2 的硬门槛口径)
    public function test_condition_is_a_hard_gate(): void
    {
        // 资金 500(<1000)→ EVT_FESTIVAL 的条件不成立
        [$city] = $this->makeCity('trigcond', ['money' => 500]);
        $this->onlyEnable('EVT_FESTIVAL');

        $this->runSettle($city, 30);
        $this->assertSame(0, DB::table('city_events')->where('city_id', $city->id)->count());

        // 补足资金后同样的窗口就能中签
        DB::table('cities')->where('id', $city->id)->update([
            'money' => 100000, 'event_settled_at' => self::BASE,
        ]);
        $this->runSettle($city, 30);
        $this->assertSame(1, DB::table('city_events')->where('city_id', $city->id)->count());
    }

    // 时代门槛与条件同级(§9.2 的 min_era 列)
    public function test_era_gate_blocks_candidates(): void
    {
        [$city] = $this->makeCity('trigera', ['era_order' => 2]); // EVT_FESTIVAL 要 III
        $this->onlyEnable('EVT_FESTIVAL');

        $this->runSettle($city, 30);

        $this->assertSame(0, DB::table('city_events')->where('city_id', $city->id)->count());
    }

    // ---- 权重三修正系数(9.D2)----

    // ① 条件修正:硬门槛 0 / 1
    public function test_weight_condition_modifier(): void
    {
        [$city] = $this->makeCity('wcond', ['money' => 500]);
        $definition = EventDefinition::find('EVT_FESTIVAL');
        $metrics = $this->metricsOf($city);

        [$weight, $detail] = EventCondition::weight($definition, $metrics);
        $this->assertSame(0.0, $weight);
        $this->assertSame(0.0, $detail['condition']);

        $metrics['money'] = 5000;
        [$weight, $detail] = EventCondition::weight($definition, $metrics);
        $this->assertSame(1.0, $detail['condition']);
        $this->assertEqualsWithDelta(8.0, $weight, 0.0001, 'EVT_FESTIVAL 基础权重 8,无状态修正时原样');
    }

    // ② 城市状态修正:粮食赤字放大 food 类;高幸福压低全部负面
    public function test_weight_state_modifier(): void
    {
        [$city] = $this->makeCity('wstate');
        $pest = EventDefinition::find('EVT_GRANARY_PEST'); // category=food,负向

        $metrics = $this->metricsOf($city);
        $metrics['food_deficit'] = false;
        $metrics['happiness'] = 50;
        $this->assertEqualsWithDelta(1.0, EventCondition::stateMultiplier($pest, $metrics), 0.0001);

        // 粮食赤字 ×1.5
        $metrics['food_deficit'] = true;
        $this->assertEqualsWithDelta(1.5, EventCondition::stateMultiplier($pest, $metrics), 0.0001);

        // 再叠高幸福 ×0.7(负面事件通吃)→ 1.5 × 0.7
        $metrics['happiness'] = 80;
        $this->assertEqualsWithDelta(1.05, EventCondition::stateMultiplier($pest, $metrics), 0.0001);

        // 系数是后台可调的:改设定立刻生效
        GameSetting::set(GameSetting::EVENT_WEIGHT_FOOD_DEFICIT, 4.0, null, 'test');
        $this->assertEqualsWithDelta(2.8, EventCondition::stateMultiplier($pest, $metrics), 0.0001);
    }

    // 治理超载 ×2.0 只作用于 governance 类;治安低 ×2.0 只作用于 security 类
    public function test_weight_state_modifier_is_category_scoped(): void
    {
        [$city] = $this->makeCity('wscope');
        $metrics = $this->metricsOf($city);
        $metrics['governance_load'] = 2.0;
        $metrics['security'] = 10;
        $metrics['happiness'] = 50;

        $corruption = EventDefinition::find('EVT_CORRUPTION'); // governance
        $crime = EventDefinition::find('EVT_CRIME');           // security
        $festival = EventDefinition::find('EVT_FESTIVAL');     // civil(正向)

        $this->assertEqualsWithDelta(2.0, EventCondition::stateMultiplier($corruption, $metrics), 0.0001);
        $this->assertEqualsWithDelta(2.0, EventCondition::stateMultiplier($crime, $metrics), 0.0001);
        $this->assertEqualsWithDelta(1.0, EventCondition::stateMultiplier($festival, $metrics), 0.0001);
    }

    // ③ 难度修正:全局乘在权重上
    public function test_weight_difficulty_modifier(): void
    {
        [$city] = $this->makeCity('wdiff');
        $metrics = $this->metricsOf($city);
        $definition = EventDefinition::find('EVT_FESTIVAL');

        GameSetting::set(GameSetting::EVENT_DIFFICULTY_MULTIPLIER, 2.5, null, 'test');

        [$weight, $detail] = EventCondition::weight($definition, $metrics);
        $this->assertEqualsWithDelta(2.5, $detail['difficulty'], 0.0001);
        $this->assertEqualsWithDelta(20.0, $weight, 0.0001, '8 × 1 × 2.5');
    }

    // ---- 冷却 / 并发 / 离线上限 ----

    // 冷却期内不重复抽中(§9.1)
    public function test_cooldown_blocks_retrigger(): void
    {
        [$city] = $this->makeCity('trigcd');
        $this->onlyEnable('EVT_FESTIVAL');

        $this->runSettle($city, 5);
        $this->assertSame(1, DB::table('city_events')->where('city_id', $city->id)->count());

        // 20 分钟后:事件已到期(12 分钟),但冷却 40 分钟未过 → 不该再来一次
        $this->runSettle($city, 20);
        $this->assertSame(1, DB::table('city_events')->where('city_id', $city->id)->count());

        // 50 分钟后冷却结束 → 可以再触发
        $this->runSettle($city, 50);
        $this->assertSame(2, DB::table('city_events')->where('city_id', $city->id)->count());
    }

    // 离线补算封顶(9.D3 批准 3 次):12 小时 × 8% 期望 57.6 次,实际最多 3 条
    public function test_offline_backfill_is_capped(): void
    {
        [$city] = $this->makeCity('trigoffline');
        // 全部启用 + 概率拉满 + 冷却清零:不封顶的话 720 个窗口会刷出一大堆
        DB::table('event_definition')->update(['cooldown_minutes' => 0]);
        EventDefinition::flush();
        GameSetting::set(GameSetting::EVENT_TRIGGER_CHANCE, 1.0, null, 'test');

        $this->runSettle($city, 720);

        $count = DB::table('city_events')->where('city_id', $city->id)->count();
        $this->assertLessThanOrEqual(3, $count, '离线补算必须封顶在 event_offline_max_triggers');
        $this->assertGreaterThan(0, $count);
    }

    // 上限是后台可调的:调成 1 就只补 1 条
    public function test_offline_cap_is_configurable(): void
    {
        [$city] = $this->makeCity('trigcap');
        DB::table('event_definition')->update(['cooldown_minutes' => 0]);
        EventDefinition::flush();
        GameSetting::set(GameSetting::EVENT_TRIGGER_CHANCE, 1.0, null, 'test');
        GameSetting::set(GameSetting::EVENT_OFFLINE_MAX_TRIGGERS, 1, null, 'test');

        $this->runSettle($city, 720);

        $this->assertSame(1, DB::table('city_events')->where('city_id', $city->id)->count());
    }

    // 并发上限(§9.1「同时最多 3 个」)
    public function test_max_active_limit(): void
    {
        [$city] = $this->makeCity('trigmax');
        DB::table('event_definition')->update(['cooldown_minutes' => 0]);
        EventDefinition::flush();
        GameSetting::set(GameSetting::EVENT_TRIGGER_CHANCE, 1.0, null, 'test');
        GameSetting::set(GameSetting::EVENT_OFFLINE_MAX_TRIGGERS, 10, null, 'test');
        GameSetting::set(GameSetting::EVENT_MAX_ACTIVE, 2, null, 'test');

        $this->runSettle($city, 60);

        $this->assertLessThanOrEqual(2, $this->activeInstances($city)->count());
    }

    // ---- 过期作废(§70)----

    public function test_expired_instances_are_flipped_and_audited(): void
    {
        [$city] = $this->makeCity('trigexpire');
        $this->onlyEnable('EVT_FESTIVAL');

        $this->runSettle($city, 5);
        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        // 到期(12 分钟)之后再结算一次
        $this->runSettle($city, 30);

        $this->assertSame('expired', DB::table('city_events')->where('id', $instanceId)->value('status'));

        $audit = DB::table('audit_logs')->where('action', 'EVENT.EXPIRE')->first();
        $this->assertNotNull($audit);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame((string) $instanceId, $audit->entity_id);
    }

    // 总开关关掉也照样到期:否则一关开关全服减益永久卡死
    public function test_expiry_still_runs_when_globally_disabled(): void
    {
        [$city] = $this->makeCity('trigexpoff');
        $this->onlyEnable('EVT_FESTIVAL');
        $this->runSettle($city, 5);

        GameSetting::set(GameSetting::EVENT_ENABLED, false, null, 'test');
        $this->runSettle($city, 30);

        $this->assertSame(0, $this->activeInstances($city)->count());
    }

    // 指标快照:条件判定用的每一个 metric 都真的读得出来
    private function metricsOf($city): array
    {
        $locked = DB::table('cities')->where('id', $city->id)->first();

        return EventCondition::snapshot($locked, [
            'population' => (float) $locked->population,
            'populationCapacity' => 1000.0,
            'happiness' => (float) $locked->happiness,
            'health' => 50.0,
            'security' => 50.0,
            'governanceLoad' => 0.5,
            'transportCapacity' => 0.0,
            'storageCapacity' => 1000.0,
            'money' => (float) $locked->money,
            'resources' => [],
            'grossProductionPerMin' => [],
            'ratesPerMin' => [],
            'fiscalWarning' => 'none',
        ]);
    }
}
