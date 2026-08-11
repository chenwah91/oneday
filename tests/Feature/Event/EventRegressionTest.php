<?php

namespace Tests\Feature\Event;

use App\Game\Event\EventDefinition;
use App\Game\Market\PriceEngine;
use App\Game\Modifier\ModifierTarget;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// §15 回归:事件对结算的**完整链路** —— 触发 → 乘区/flat 生效 → 到期消退 → 数值恢复。
//
// 另附 9.D5 批准口径的落实断言:事件与市场共用同一 EPOCH 原点,窗长各自定义。
class EventRegressionTest extends EventTestCase
{
    use RefreshDatabase;

    // ---- 完整链路(负向:event 乘区)----

    public function test_negative_event_full_lifecycle(): void
    {
        [$city] = $this->makeCity('lifecycle', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->setResource($city, 'food', 400);
        $this->onlyEnable('EVT_DROUGHT');

        // ① 触发前:干净基线
        $baseline = $this->runSettle($city, 5);
        $baseRate = (float) $baseline['grossProductionPerMin']['food'];

        $instance = DB::table('city_events')->where('city_id', $city->id)->first();
        $this->assertSame('active', $instance->status);

        // ② 乘区生效:modifier 行落库,产量被压到 65%
        $modifier = DB::table('city_active_modifiers')->where('source_id', $instance->id)
            ->where('target', ModifierTarget::SLOT_EVENT)->first();
        $this->assertNotNull($modifier);
        $this->assertEqualsWithDelta(-0.35, (float) $modifier->value, 0.0001);

        $during = $this->runSettle($city, 15);
        $this->assertEqualsWithDelta($baseRate * 0.65, (float) $during['grossProductionPerMin']['food'], 0.0001);

        // ③ 到期消退:实例翻成 expired + EVENT.EXPIRE 审计
        $after = $this->runSettle($city, 40);
        $this->assertSame('expired', DB::table('city_events')->where('id', $instance->id)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'EVENT.EXPIRE')->count());

        // ④ 数值恢复:窗口 [40,60] 与减益区间 [5,25] 完全不相交 → 回到基线
        $recovered = $this->runSettle($city, 60);
        $this->assertEqualsWithDelta($baseRate, (float) $recovered['grossProductionPerMin']['food'], 0.0001);

        // 到期的 modifier 行不必清理:Provider 按时间求交,过期后覆盖比例自然为 0
        $this->assertSame(1, DB::table('city_active_modifiers')->where('source_id', $instance->id)->count());
    }

    // ---- 完整链路(flat 通道:幸福目标值)----

    // 幸福走的是「改目标值 + §10.2 快落慢升」这条口径(D 区 D4 批准),
    // 所以断言的是**收敛后的稳态值**:有事件时收敛到 74,到期后回落到 70。
    public function test_happiness_flat_lifts_the_target_then_recovers(): void
    {
        [$city] = $this->makeCity('happy', ['era_order' => 3, 'population' => 300, 'happiness' => 69]);
        // 住房 10 栋 H03 = 容量 420(使用率 0.714 ≤ 0.90 → 住房加成 +10)
        for ($i = 0; $i < 10; $i++) {
            $this->addBuilding($city, 'H03');
        }
        // 粮食产能远超人口消耗 → 不触发赤字惩罚,目标幸福稳定在 60 + 10 = 70
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->setResource($city, 'food', 500);

        $this->onlyEnable('EVT_FESTIVAL');
        // 拉长到 60 分钟,让「生效 → 到期」跨越两次结算窗口
        DB::table('event_definition')->where('event_id', 'EVT_FESTIVAL')->update(['duration_minutes' => 60]);
        EventDefinition::flush();

        // ① 触发前:目标 70,幸福从 69 收敛上来
        $this->runSettle($city, 5);
        $this->assertEqualsWithDelta(70.0, (float) DB::table('cities')->where('id', $city->id)->value('happiness'), 0.0001);

        $instance = DB::table('city_events')->where('city_id', $city->id)->first();
        $this->assertNotNull($instance);
        $flat = DB::table('city_active_modifiers')->where('source_id', $instance->id)
            ->where('target', ModifierTarget::HAPPINESS_FLAT)->first();
        $this->assertEqualsWithDelta(4.0, (float) $flat->value, 0.0001);

        // ② flat 生效:目标抬到 74,幸福按 +0.5/分钟 收敛过去
        $this->runSettle($city, 35);
        $this->assertEqualsWithDelta(74.0, (float) DB::table('cities')->where('id', $city->id)->value('happiness'), 0.0001);

        // ③ 到期消退 + 数值恢复:事件在 65 分钟结束,窗口 [35,95] 的后半段没有 flat →
        //    目标回到 70,幸福按 −1.0/分钟 落回去
        $this->runSettle($city, 95);
        $this->assertEqualsWithDelta(70.0, (float) DB::table('cities')->where('id', $city->id)->value('happiness'), 0.0001);
        $this->assertSame('expired', DB::table('city_events')->where('id', $instance->id)->value('status'));
    }

    // ---- 正向事件不占 §13 的加成帽 ----

    // 满配城市的正向事件也必须真实到账:它走的是「直接发资源」,与乘区帽无关
    public function test_positive_event_pays_out_even_when_multipliers_are_capped(): void
    {
        [$city] = $this->makeCity('capped', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->addBuilding($city, 'F02', 4);
        $this->setResource($city, 'food', 100);
        $this->onlyEnable('EVT_HARVEST');

        $foodBefore = $this->resourceOf($city, 'food');
        $sim = $this->runSettle($city, 5);

        $reward = DB::table('audit_logs')->where('action', 'EVENT.REWARD')->first();
        $this->assertNotNull($reward);
        $granted = json_decode($reward->delta_json, true)['food'];
        $this->assertGreaterThan(0, $granted);

        // 粮食 = 期初 + 本段产出 − 消耗 + 发放。只断言「发放确实进了库存」这一点:
        // 本段产出与消耗由内核负责,不在本用例的断言范围
        $this->assertGreaterThan($foodBefore, $this->resourceOf($city, 'food'));

        // 乘区完全没被占用 → §13 的帽一点没被吃掉
        $this->assertSame(0, DB::table('city_active_modifiers')
            ->where('city_id', $city->id)->where('target', ModifierTarget::SLOT_EVENT)->count());
        $this->assertArrayHasKey('food', $sim['grossProductionPerMin']);
    }

    // ---- 9.D5:事件与市场共用 EPOCH 原点,窗长各自定义 ----

    public function test_event_and_market_share_the_same_epoch_origin(): void
    {
        $at = Carbon::parse('2026-03-04 05:06:07');

        $marketWindow = (int) GameSetting::get(GameSetting::MARKET_WINDOW_SECONDS);
        $eventWindow = (int) GameSetting::get(GameSetting::EVENT_WINDOW_SECONDS);

        // 两边都以 Unix 纪元 0 为原点:窗口号 = floor(时间戳 / 窗长)
        $this->assertSame(intdiv($at->getTimestamp(), $marketWindow), PriceEngine::epochAt($at));
        $this->assertSame(60, $eventWindow, '9.D5 / §9.1:事件资格窗口默认 60 秒');
        $this->assertSame($marketWindow, $eventWindow, '两者默认同为 60 秒时,窗口号完全对齐');

        // 窗长各自可调:把事件窗改成 30 秒,市场不受影响
        GameSetting::set(GameSetting::EVENT_WINDOW_SECONDS, 30, null, 'test');
        $this->assertSame($marketWindow, (int) GameSetting::get(GameSetting::MARKET_WINDOW_SECONDS));
        $this->assertSame(30, (int) GameSetting::get(GameSetting::EVENT_WINDOW_SECONDS));
    }

    // 触发实例上记录的 window_index 与「共用原点」的算法一致 —— 掷点种子可被离线复算
    public function test_instance_window_index_matches_the_shared_epoch_formula(): void
    {
        [$city] = $this->makeCity('epoch');
        $this->onlyEnable('EVT_FESTIVAL');

        $this->runSettle($city, 5);

        $instance = DB::table('city_events')->where('city_id', $city->id)->first();
        $windowSeconds = (int) GameSetting::get(GameSetting::EVENT_WINDOW_SECONDS);

        $first = intdiv(Carbon::parse(self::BASE)->getTimestamp(), $windowSeconds) + 1;
        $last = intdiv(Carbon::parse(self::BASE)->addMinutes(5)->getTimestamp(), $windowSeconds);

        $this->assertGreaterThanOrEqual($first, (int) $instance->window_index);
        $this->assertLessThanOrEqual($last, (int) $instance->window_index);
    }

    // ---- 事件不干扰既有系统 ----

    // 没有任何事件时,结算结果与事件系统上线前完全一致(event 乘区恒 1.0、flat 恒 0)
    public function test_no_events_means_no_change_to_settlement(): void
    {
        [$city] = $this->makeCity('neutral', ['era_order' => 2, 'population' => 300]);
        $this->addBuilding($city, 'F02', 4);
        $this->setResource($city, 'food', 400);
        DB::table('event_definition')->update(['enabled' => false]);
        EventDefinition::flush();

        $first = $this->runSettle($city, 10);
        $second = $this->runSettle($city, 20);

        $this->assertSame(0, DB::table('city_events')->where('city_id', $city->id)->count());
        $this->assertSame(0, DB::table('city_active_modifiers')->where('city_id', $city->id)->count());
        $this->assertEqualsWithDelta(
            (float) $first['grossProductionPerMin']['food'],
            (float) $second['grossProductionPerMin']['food'],
            0.0001
        );
    }
}
