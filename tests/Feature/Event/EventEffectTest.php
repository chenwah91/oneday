<?php

namespace Tests\Feature\Event;

use App\Game\Event\EventDefinition;
use App\Game\Event\EventService;
use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\Providers\EventMultiplierProvider;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 效果应用:正向直接发资源 / 负向走乘区 / flat 通道 / 掷点落库 / 到期消退。
//
// 这一份守的是 §13 帽修正方向(用户 2026-08-10 拍板③)的两条落地:
//   正向事件 → 直接发资源(不占加成帽);负向事件 → event 乘区(<1.0,不受帽约束)。
class EventEffectTest extends EventTestCase
{
    use RefreshDatabase;

    // ---- 正向:直接发资源(§13 修正方向)----

    // 发放量 = 当前 gross 产出速率 × 加成率 × 持续分钟。审计里另有一条 EVENT.REWARD
    public function test_positive_event_grants_resources_directly(): void
    {
        [$city] = $this->makeCity('grant', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->setResource($city, 'food', 100);
        $this->onlyEnable('EVT_HARVEST');

        $sim = $this->runSettle($city, 5);

        $instance = DB::table('city_events')->where('city_id', $city->id)->first();
        $this->assertSame('EVT_HARVEST', $instance->event_id);

        // EVT_HARVEST:农业产量 +20%,持续 15 分钟 → 一次性发 gross × 0.20 × 15
        $expected = round((float) $sim['grossProductionPerMin']['food'] * 0.20 * 15, 4);
        $applied = json_decode($instance->applied_json, true);

        $this->assertEqualsWithDelta($expected, $applied['resources']['food'], 0.01);
        $this->assertGreaterThan(0, $expected);

        // 乘区一格都没占:正向事件不写 event 乘区(§13 帽修正方向)
        $this->assertSame(0, DB::table('city_active_modifiers')
            ->where('city_id', $city->id)->where('target', ModifierTarget::SLOT_EVENT)->count());

        $reward = DB::table('audit_logs')->where('action', 'EVENT.REWARD')->first();
        $this->assertNotNull($reward, '正向发放必须单独写一条 EVENT.REWARD');
        $this->assertSame('system', $reward->actor_type);
        $this->assertEqualsWithDelta($expected, json_decode($reward->delta_json, true)['food'], 0.01);
        $this->assertSame('direct_resource', json_decode($reward->metadata_json, true)['grant_mode']);
    }

    // 全局效果强度倍率(W11-A 的 event_effect_multiplier_global):
    // 最终强度 = 逐事件的 effect_multiplier × 本键。调到 0 = 事件照常触发、照常显示,但一律零效果 ——
    // 事件算错时比整条 event_enabled 停用更温和的止血阀(停用会让玩家连已触发的都领不到)。
    public function test_global_effect_multiplier_scales_the_grant(): void
    {
        GameSetting::set(GameSetting::EVENT_EFFECT_MULTIPLIER_GLOBAL, 0.5, null, 'W11-A 测试');

        [$city] = $this->makeCity('grantscale', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->setResource($city, 'food', 100);
        $this->onlyEnable('EVT_HARVEST');

        $sim = $this->runSettle($city, 5);
        $instance = DB::table('city_events')->where('city_id', $city->id)->first();

        // 与上一条用例同一场景,只是强度砍半:gross × (0.20 × 0.5) × 15
        $expected = round((float) $sim['grossProductionPerMin']['food'] * 0.20 * 0.5 * 15, 4);
        $applied = json_decode($instance->applied_json, true);

        $this->assertEqualsWithDelta($expected, $applied['resources']['food'], 0.01);
        $this->assertGreaterThan(0, $expected);
        // 事件本身照常触发(止血的是效果,不是触发)
        $this->assertSame('EVT_HARVEST', $instance->event_id);
    }

    // 幂等:同一个实例只发一次。再结算多少次都不会重复发放
    public function test_grant_is_paid_only_once(): void
    {
        [$city] = $this->makeCity('grantonce', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->setResource($city, 'food', 100);
        $this->onlyEnable('EVT_HARVEST');

        $this->runSettle($city, 5);
        $this->runSettle($city, 8);
        $this->runSettle($city, 11);

        $this->assertSame(1, DB::table('city_events')->where('city_id', $city->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENT.REWARD')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENT.TRIGGER')->count());
    }

    // ---- 负向:event 乘区(精确基线)----

    // EVT_DROUGHT:农业产量 -35%,持续 20 分钟。
    // 完整覆盖结算窗口 → 该类建筑的 gross 产出恰好是基线的 0.65
    public function test_negative_event_applies_exact_multiplier(): void
    {
        [$city] = $this->makeCity('drought', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->setResource($city, 'food', 400);
        $this->onlyEnable('EVT_DROUGHT');

        // [0,5]:事件在本次结算**之后**才触发,所以这一段是干净的基线
        $baseline = $this->runSettle($city, 5);
        $baseRate = (float) $baseline['grossProductionPerMin']['food'];
        $this->assertGreaterThan(0, $baseRate);

        // [5,15]:减益区间 [5,25] 完整覆盖 → ×0.65
        $during = $this->runSettle($city, 15);
        $this->assertEqualsWithDelta($baseRate * 0.65, (float) $during['grossProductionPerMin']['food'], 0.0001);

        // [15,35]:减益 25 分结束,只覆盖 10/20 = 一半 → 1 − 0.35×0.5 = 0.825
        $fading = $this->runSettle($city, 35);
        $this->assertEqualsWithDelta($baseRate * 0.825, (float) $fading['grossProductionPerMin']['food'], 0.0001);

        // [35,45]:完全不相交 → 数值恢复
        $after = $this->runSettle($city, 45);
        $this->assertEqualsWithDelta($baseRate, (float) $after['grossProductionPerMin']['food'], 0.0001);
    }

    // 乘区值 <1.0 → 不占 §13 的加成帽:表里 event 目标的行恒为负
    public function test_event_multiplier_rows_are_always_penalties(): void
    {
        [$city] = $this->makeCity('penalty', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->onlyEnable('EVT_DROUGHT');

        $this->runSettle($city, 5);

        $rows = DB::table('city_active_modifiers')->where('city_id', $city->id)
            ->where('target', ModifierTarget::SLOT_EVENT)->get();

        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $row) {
            $this->assertLessThan(0, (float) $row->value);
        }
    }

    // ---- Provider 级:覆盖比例折算与 flat 通道 ----

    public function test_provider_prorates_by_coverage_and_flat_by_segment(): void
    {
        [$city] = $this->makeCity('provider', ['era_order' => 2]);
        $instanceId = $this->addBuilding($city, 'F02', 4);

        $now = Carbon::parse(self::BASE)->addMinutes(60);
        // 生效区间 [now-40, now-20] —— 覆盖 40 分钟窗口的前一半
        DB::table('city_active_modifiers')->insert([
            [
                'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 1,
                'target' => ModifierTarget::SLOT_EVENT, 'scope' => ModifierSpec::SCOPE_CITY,
                'scope_key' => null, 'op' => ModifierSpec::OP_PCT, 'value' => -0.40,
                'starts_at' => $now->copy()->subMinutes(40), 'ends_at' => $now->copy()->subMinutes(20),
                'created_at' => $now,
            ],
            [
                'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 1,
                'target' => ModifierTarget::HAPPINESS_FLAT, 'scope' => ModifierSpec::SCOPE_CITY,
                'scope_key' => null, 'op' => ModifierSpec::OP_FLAT, 'value' => 8.0,
                'starts_at' => $now->copy()->subMinutes(40), 'ends_at' => $now->copy()->subMinutes(20),
                'created_at' => $now,
            ],
        ]);

        $provider = new EventMultiplierProvider();
        $provider->prepare($this->contextFor($city, $now, 40.0), []);

        // 乘区:-40% 只覆盖窗口的一半 → 1 − 0.40 × 0.5 = 0.80
        $this->assertEqualsWithDelta(0.80, $provider->multiplierFor([
            'instanceId' => $instanceId, 'buildingId' => 'F02', 'grossOut' => ['food' => 1],
        ]), 0.0001);

        // flat:第一段 [0,20] 完整落在生效区间内 → 全额 8.0
        $first = $provider->timedFlatSpecs(0.0, 20.0);
        $this->assertCount(1, $first);
        $this->assertEqualsWithDelta(8.0, $first[0]->value, 0.0001);
        $this->assertSame(ModifierTarget::HAPPINESS_FLAT, $first[0]->target);

        // 第二段 [20,40] 完全在生效区间之外 → 一条都不投稿(到期即消退)
        $this->assertSame([], $provider->timedFlatSpecs(20.0, 40.0));

        // 跨越到期时刻的段 [10,30]:交集 10/20 → 折半
        $straddle = $provider->timedFlatSpecs(10.0, 30.0);
        $this->assertEqualsWithDelta(4.0, $straddle[0]->value, 0.0001);
    }

    // 作用域命中:building_category / resource / building_instance 三种都要正确
    public function test_provider_scope_matching(): void
    {
        [$city] = $this->makeCity('scope', ['era_order' => 2]);
        $farm = $this->addBuilding($city, 'F02', 4);
        $wood = $this->addBuilding($city, 'R01', 3);

        $now = Carbon::parse(self::BASE)->addMinutes(10);
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 1,
            'target' => ModifierTarget::SLOT_EVENT, 'scope' => ModifierSpec::SCOPE_BUILDING_CATEGORY,
            'scope_key' => 'food_production', 'op' => ModifierSpec::OP_PCT, 'value' => -0.30,
            'starts_at' => Carbon::parse(self::BASE), 'ends_at' => $now->copy()->addMinutes(60),
            'created_at' => $now,
        ]);

        $provider = new EventMultiplierProvider();
        $provider->prepare($this->contextFor($city, $now, 10.0, ['F02', 'R01']), []);

        $this->assertEqualsWithDelta(0.70, $provider->multiplierFor([
            'instanceId' => $farm, 'buildingId' => 'F02', 'grossOut' => ['food' => 1],
        ]), 0.0001);
        $this->assertEqualsWithDelta(1.0, $provider->multiplierFor([
            'instanceId' => $wood, 'buildingId' => 'R01', 'grossOut' => ['wood' => 1],
        ]), 0.0001, '别的分类不该被波及');
    }

    // ---- 掷点落库不复掷(backlog §11.3)----

    // EVT_MIGRATION 的「人口+2%~5%」在触发时掷一次并落 rolled_json;
    // 之后再怎么结算都不会重掷(否则玩家可以反复上下线刷一个更好的结果)
    public function test_range_roll_is_persisted_and_never_rerolled(): void
    {
        [$city] = $this->makeCity('roll', ['era_order' => 3, 'population' => 300, 'happiness' => 100]);
        for ($i = 0; $i < 8; $i++) {
            $this->addBuilding($city, 'H03');
        }
        $this->setResource($city, 'food', 800);
        $this->onlyEnable('EVT_MIGRATION');

        $this->runSettle($city, 5);

        $instance = DB::table('city_events')->where('city_id', $city->id)->first();
        $this->assertNotNull($instance, 'EVT_MIGRATION 条件(幸福≥75 且住房空余≥10%)应当成立');

        $rolled = json_decode($instance->rolled_json, true);
        $this->assertArrayHasKey('population_roll', $rolled);
        $this->assertGreaterThanOrEqual(0.02, $rolled['population_roll']['pct']);
        $this->assertLessThanOrEqual(0.05, $rolled['population_roll']['pct']);
        $this->assertGreaterThan(0, $rolled['population_roll']['amount']);

        // 人口确实涨了,且与掷出的比例一致
        $population = (int) DB::table('cities')->where('id', $city->id)->value('population');
        $this->assertSame(300 + (int) $rolled['population_roll']['amount'], $population);

        // 再结算两次:掷点结果一字不变、人口不再变化
        $this->runSettle($city, 9);
        $this->runSettle($city, 13);

        $after = DB::table('city_events')->where('id', $instance->id)->first();
        $this->assertSame($instance->rolled_json, $after->rolled_json);
    }

    // ---- 选项:调整已发生的效果 ----

    // EVT_FOREST_FIRE 选项 B「损失减半」:按触发时记下的 base × 比例差退还
    public function test_option_can_halve_an_already_applied_loss(): void
    {
        [$city] = $this->makeCity('loss', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'R01', 3);
        $this->addBuilding($city, 'R01', 3);
        $this->setResource($city, 'wood', 500);
        $this->onlyEnable('EVT_FOREST_FIRE');

        $this->runSettle($city, 5);

        $instance = DB::table('city_events')->where('city_id', $city->id)->first();
        $rolled = json_decode($instance->rolled_json, true);
        $this->assertEqualsWithDelta(-0.05, $rolled['loss']['pct'], 0.0001);

        $lost = abs((float) $rolled['loss']['amount']);
        $woodAfterTrigger = $this->resourceOf($city, 'wood');

        EventService::resolve($city->fresh(), (int) $instance->id, 'b', null, null);

        // 退还一半损失
        $this->assertEqualsWithDelta($woodAfterTrigger + $lost / 2, $this->resourceOf($city, 'wood'), 0.01);

        $after = DB::table('city_events')->where('id', $instance->id)->first();
        $this->assertSame('resolved', $after->status);
        $this->assertEqualsWithDelta(-0.025, json_decode($after->rolled_json, true)['loss']['pct'], 0.0001);
    }

    // 效果强度倍率(后台可调)乘在效果数值上:同一条事件,倍率 2 → 损失翻倍
    public function test_effect_multiplier_scales_effect_magnitude(): void
    {
        [$city] = $this->makeCity('strength', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'R01', 3);
        $this->addBuilding($city, 'R01', 3);
        $this->setResource($city, 'wood', 500);
        $this->onlyEnable('EVT_FOREST_FIRE');
        DB::table('event_definition')->where('event_id', 'EVT_FOREST_FIRE')->update(['effect_multiplier' => 2]);
        EventDefinition::flush();

        $this->runSettle($city, 5);

        $rolled = json_decode(DB::table('city_events')->where('city_id', $city->id)->value('rolled_json'), true);
        $this->assertEqualsWithDelta(-0.10, $rolled['loss']['pct'], 0.0001, '5% × 倍率 2 = 10%');

        $modifier = DB::table('city_active_modifiers')->where('city_id', $city->id)
            ->where('target', ModifierTarget::SLOT_EVENT)->first();
        $this->assertEqualsWithDelta(-0.60, (float) $modifier->value, 0.0001, '-30% × 倍率 2');
    }

    // ---- 事件损失减免(D0.3 的 event_loss_reduction_pct,消费点在 EventService)----

    public function test_loss_reduction_reduces_stock_losses(): void
    {
        [$city] = $this->makeCity('reduce', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'R01', 3);
        $this->addBuilding($city, 'R01', 3);
        $this->setResource($city, 'wood', 500);
        $this->onlyEnable('EVT_FOREST_FIRE');

        // 一条 50% 减免的持续型 modifier(将来的「危机管理」正向事件就是这么写的)
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 999,
            'target' => ModifierTarget::EVENT_LOSS_REDUCTION_PCT, 'scope' => ModifierSpec::SCOPE_CITY,
            'scope_key' => null, 'op' => ModifierSpec::OP_PCT, 'value' => 0.5,
            'starts_at' => Carbon::parse(self::BASE), 'ends_at' => Carbon::parse(self::BASE)->addHours(2),
            'created_at' => Carbon::parse(self::BASE),
        ]);

        $this->runSettle($city, 5);

        $rolled = json_decode(DB::table('city_events')->where('city_id', $city->id)->value('rolled_json'), true);
        $this->assertEqualsWithDelta(-0.025, $rolled['loss']['pct'], 0.0001, '5% 的损失被减免一半');
    }

    // ---- 幸福 flat:目标值(duration>0)与当前值(duration=0)两条口径 ----

    // duration>0 → 写 happiness_flat modifier(改目标值,由 §10.2 快落慢升收敛)
    public function test_persistent_happiness_goes_through_the_flat_channel(): void
    {
        [$city] = $this->makeCity('flatchan', ['era_order' => 3]);
        $this->onlyEnable('EVT_FESTIVAL');

        $this->runSettle($city, 5);

        $row = DB::table('city_active_modifiers')->where('city_id', $city->id)
            ->where('target', ModifierTarget::HAPPINESS_FLAT)->first();

        $this->assertNotNull($row, 'duration>0 的幸福效果必须走 flat 通道');
        $this->assertEqualsWithDelta(4.0, (float) $row->value, 0.0001);
        $this->assertSame('flat', $row->op);
    }

    // duration=0 → 直接改当前值(EVT_REFUGEES 选项 A 的「幸福+2」)
    public function test_instant_happiness_changes_the_current_value(): void
    {
        [$city] = $this->makeCity('flatnow', ['era_order' => 4, 'population' => 300, 'happiness' => 50]);
        // 住房空余 = 容量 − 人口 ≥ 100:10 栋 H03(42/栋)= 420,空余 120
        for ($i = 0; $i < 10; $i++) {
            $this->addBuilding($city, 'H03');
        }
        $this->setResource($city, 'food', 5000);
        $this->onlyEnable('EVT_REFUGEES');

        $this->runSettle($city, 5);
        $instance = DB::table('city_events')->where('city_id', $city->id)->first();
        $this->assertNotNull($instance, 'EVT_REFUGEES 条件(住房空余≥100)应当成立');

        $before = (float) DB::table('cities')->where('id', $city->id)->value('happiness');
        EventService::resolve($city->fresh(), (int) $instance->id, 'a', null, null);
        $after = (float) DB::table('cities')->where('id', $city->id)->value('happiness');

        $this->assertEqualsWithDelta($before + 2.0, $after, 0.0001);
        // 瞬时型不写 flat 行(否则会重复生效一次)
        $this->assertSame(0, DB::table('city_active_modifiers')->where('city_id', $city->id)
            ->where('source_id', $instance->id)->where('target', ModifierTarget::HAPPINESS_FLAT)->count());
    }

    // 治安是派生值 → 一律走 flat 通道,duration=0 时按设定给时长
    public function test_security_always_uses_the_flat_channel_with_a_duration(): void
    {
        [$city] = $this->makeCity('secflat', ['era_order' => 4, 'population' => 300]);
        for ($i = 0; $i < 10; $i++) {
            $this->addBuilding($city, 'H03');
        }
        $this->setResource($city, 'food', 5000);
        $this->onlyEnable('EVT_REFUGEES');
        GameSetting::set(GameSetting::EVENT_INSTANT_SECURITY_MINUTES, 20, null, 'test');

        $this->runSettle($city, 5);
        $instance = DB::table('city_events')->where('city_id', $city->id)->first();

        EventService::resolve($city->fresh(), (int) $instance->id, 'c', null, null); // 拒绝:治安+2,幸福-2

        $row = DB::table('city_active_modifiers')->where('city_id', $city->id)
            ->where('target', ModifierTarget::SECURITY_FLAT)->first();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(2.0, (float) $row->value, 0.0001);
        $this->assertEqualsWithDelta(20, Carbon::parse($row->starts_at)->diffInMinutes(Carbon::parse($row->ends_at)), 0.0001);
    }

    // 造一个 ModifierContext(Provider 单测用)
    private function contextFor($city, Carbon $now, float $totalMinutes, array $buildingIds = ['F02']): ModifierContext
    {
        return new ModifierContext(
            cityId: (int) $city->id,
            eraOrder: 2,
            buildingIds: $buildingIds,
            capacities: [],
            city: DB::table('cities')->where('id', $city->id)->first(),
            now: $now,
            totalMinutes: $totalMinutes,
        );
    }
}
