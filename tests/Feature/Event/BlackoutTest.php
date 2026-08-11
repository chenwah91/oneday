<?php

namespace Tests\Feature\Event;

use App\Game\Modifier\ModifierTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// EVT_BLACKOUT 大停电全链回归(v3.2 §9.2 + §15「断电」那一行 + M.1 电力)。
//
// 这条事件在 M3-D4 交付时是 Fail Closed 停用的:power 乘区恒 1.0、「电力使用率」没有口径。
// M.1 落地后它是**唯一**一条把电力系统从头串到尾的用例:
//   条件(电力使用率>85%)→ 触发 → 自动效果(全城电力可用量-40%)→ 耗电建筑产量打折
//   → 两个选项各自改写减益 → 到期后自动恢复。
//
// 城市配置(所有算式都基于它):
//   E03 燃煤电站 ×1  → 装机 110/min(吃煤 28/min)
//   K04 国家实验室 ×4 → 耗电 25×4 = 100/min,产知识 24/min 每座
//   T02 道路 ×3      → 运力 420(压住物流乘区,免得它混进算式)
//   ⇒ 使用率 = 100 / 110 = 0.909… > 0.85 → 条件成立
//   ⇒ 无事件时 factor = min(1, 110/100) = 1.0(高负荷但不缺电,正是「大停电」该发生的场景)
class BlackoutTest extends EventTestCase
{
    use RefreshDatabase;

    // 触发 → 产量打折 → 到期恢复
    public function test_blackout_triggers_discounts_production_and_recovers(): void
    {
        [$city] = $this->makeGrid('blackout');
        $this->onlyEnable('EVT_BLACKOUT');

        // ---- ① 触发前:高使用率但满供 ----
        $sim = $this->runSettle($city, 1);
        $this->assertEqualsWithDelta(110.0, $sim['powerCapacityPerMin'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $sim['powerDemandPerMin'], 0.0001);
        $this->assertEqualsWithDelta(100.0 / 110.0, $sim['powerUsageRate'], 0.0001, '0.909… > 0.85 → 条件成立');
        $this->assertSame(1.0, $sim['powerFactor']);

        // 本次结算之后事件懒结算掷点 → 应当已经触发(掷点概率被 onlyEnable 拉满)
        $instances = $this->activeInstances($city);
        $this->assertCount(1, $instances, 'EVT_BLACKOUT 应已触发');
        $this->assertSame('EVT_BLACKOUT', $instances[0]->event_id);

        // 自动效果落成一行 power 乘区 modifier(-40%,持续 8 分钟 = duration_minutes)
        $modifier = DB::table('city_active_modifiers')
            ->where('city_id', $city->id)->where('target', ModifierTarget::SLOT_POWER)->first();
        $this->assertNotNull($modifier, '「全城电力可用量-40%」必须落成一行 target=power 的 modifier');
        $this->assertSame('city', $modifier->scope);
        $this->assertSame('pct', $modifier->op);
        $this->assertEqualsWithDelta(-0.4, (float) $modifier->value, 0.0001);

        // ---- ② 减益生效:窗口 [1min, 5min] 完全落在事件区间 [1min, 9min] 内 ----
        $before = $this->resourceOf($city, 'knowledge');
        $sim = $this->runSettle($city, 5);

        $this->assertEqualsWithDelta(-0.4, $sim['powerEventPct'], 0.0001, '整窗覆盖 → 全额减益');
        $this->assertEqualsWithDelta(66.0, $sim['powerAvailablePerMin'], 0.0001, '110 × (1 − 0.40)');
        $this->assertEqualsWithDelta(0.66, $sim['powerFactor'], 0.0001, '66 / 100');
        $this->assertTrue($sim['powerShortage']);
        // 知识 = 24 × 4 × 0.66 = 63.36 /min × 4 分钟 = 253.44
        $this->assertEqualsWithDelta(63.36, $sim['grossProductionPerMin']['knowledge'], 0.0001);
        $this->assertEqualsWithDelta($before + 253.44, $this->resourceOf($city, 'knowledge'), 0.0001);

        // ---- ③ 到期恢复:窗口 [20min, 30min] 与事件区间 [1, 9] 完全不相交 ----
        $this->runSettle($city, 20);
        $sim = $this->runSettle($city, 30);

        $this->assertEqualsWithDelta(0.0, $sim['powerEventPct'], 0.0001, '到期后覆盖比例归 0,数值自己恢复');
        $this->assertEqualsWithDelta(110.0, $sim['powerAvailablePerMin'], 0.0001);
        $this->assertSame(1.0, $sim['powerFactor']);
        $this->assertFalse($sim['powerShortage']);
        // 实例已被懒结算翻成 expired,冷却 50 分钟内不会再抽中
        $this->assertCount(0, $this->activeInstances($city));
        $this->assertSame('expired', (string) DB::table('city_events')
            ->where('city_id', $city->id)->orderByDesc('id')->value('status'));
    }

    // 选项 A「启用备用燃料:燃料-300,减益降为-10%」
    public function test_option_a_pays_fuel_and_softens_the_penalty(): void
    {
        [$city, $user] = $this->makeGrid('blackouta');
        $this->setResource($city, 'fuel', 1000);
        $this->onlyEnable('EVT_BLACKOUT');

        $this->runSettle($city, 1);
        $instanceId = (int) $this->activeInstances($city)[0]->id;

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'a',
        ])->assertOk();

        // 燃料照扣
        $this->assertEqualsWithDelta(700.0, $this->resourceOf($city, 'fuel'), 0.0001);
        // 减益被改写成 -10%(改的是 power 那一行,不是 event 那一行)
        $modifier = DB::table('city_active_modifiers')
            ->where('city_id', $city->id)->where('target', ModifierTarget::SLOT_POWER)->first();
        $this->assertEqualsWithDelta(-0.1, (float) $modifier->value, 0.0001);

        // 结算窗口 [1min, 5min] 全覆盖 → 可用 = 110 × 0.9 = 99 → factor = 0.99
        $sim = $this->runSettle($city, 5);
        $this->assertEqualsWithDelta(-0.1, $sim['powerEventPct'], 0.0001);
        $this->assertEqualsWithDelta(99.0, $sim['powerAvailablePerMin'], 0.0001);
        $this->assertEqualsWithDelta(0.99, $sim['powerFactor'], 0.0001);
    }

    // 选项 B「工业限电:民生建筑保持运行,工业停机」
    // 落地口径(events.json 的 unmapped_zh 里写明了这是一次折算):
    //   电力减益归零(民生保供)+ category=processing 停机(工业停机)
    public function test_option_b_lifts_the_grid_penalty_and_stops_industry(): void
    {
        [$city, $user] = $this->makeGrid('blackoutb');
        // 加一座 P08 机械厂(processing 类,耗电 15、吃钢 14、产机械 6)作为「工业」样本
        $this->addBuilding($city, 'P08', 30);
        $this->setResource($city, 'steel', 1000);
        $this->onlyEnable('EVT_BLACKOUT');

        $this->runSettle($city, 1);
        $instanceId = (int) $this->activeInstances($city)[0]->id;

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b',
        ])->assertOk();

        // ① 电网减益归零
        $power = DB::table('city_active_modifiers')
            ->where('city_id', $city->id)->where('target', ModifierTarget::SLOT_POWER)->first();
        $this->assertEqualsWithDelta(0.0, (float) $power->value, 0.0001);

        // ② 工业停机:一行 event 乘区 modifier,作用域是 building_category = processing
        $industry = DB::table('city_active_modifiers')
            ->where('city_id', $city->id)->where('target', ModifierTarget::SLOT_EVENT)->first();
        $this->assertNotNull($industry);
        $this->assertSame('building_category', $industry->scope);
        $this->assertSame('processing', $industry->scope_key);
        $this->assertEqualsWithDelta(-1.0, (float) $industry->value, 0.0001);

        // 结算:电网减益解除(民生保供),P08 停机(机械产量为 0)
        $sim = $this->runSettle($city, 5);
        $this->assertEqualsWithDelta(0.0, $sim['powerEventPct'], 0.0001, '电网减益已解除');
        $this->assertEqualsWithDelta(110.0, $sim['powerAvailablePerMin'], 0.0001, '可用发电回到装机');
        // 需求 = K04 100 + P08 15 = 115 → factor = 110/115。
        // **停机的工厂仍然占名义耗电**:需求口径与物流的名义运输需求一致(取定义速率,不看乘区),
        // 所以「工业限电」在本实现里换来的是电网减益消失,而不是需求侧也跟着降下来
        $this->assertEqualsWithDelta(115.0, $sim['powerDemandPerMin'], 0.0001);
        $this->assertEqualsWithDelta(110.0 / 115.0, $sim['powerFactor'], 0.0001);
        $this->assertGreaterThan(0.0, $sim['grossProductionPerMin']['knowledge'], '民生(研究)建筑保持运行');
        $this->assertSame(0.0, (float) ($sim['grossProductionPerMin']['machinery'] ?? 0.0), '工业停机');
    }

    // 条件闸门:使用率不到 85% 就不该抽中(哪怕掷点概率拉满)
    public function test_low_usage_never_triggers(): void
    {
        [$city] = $this->makeGrid('blackoutlow');
        // 再加一座电站:装机 220 → 使用率 100/220 = 0.4545 < 0.85
        $this->addBuilding($city, 'E03', 25);
        $this->onlyEnable('EVT_BLACKOUT');

        $sim = $this->runSettle($city, 1);
        $this->assertEqualsWithDelta(100.0 / 220.0, $sim['powerUsageRate'], 0.0001);
        $this->assertCount(0, $this->activeInstances($city), '使用率不达标 → 硬门槛出局');

        // 再推 30 分钟(掷点概率 1.0,窗口一个接一个)仍然不该触发
        $this->runSettle($city, 31);
        $this->assertCount(0, $this->activeInstances($city));
    }

    // 定义行本身:启用、无停用理由、条件与效果都已是可执行 DSL(unmapped 已清空)
    public function test_definition_row_is_enabled_and_fully_mapped(): void
    {
        $row = DB::table('event_definition')->where('event_id', 'EVT_BLACKOUT')->first();

        $this->assertSame(1, (int) $row->enabled);
        $this->assertNull($row->disabled_reason);

        $condition = json_decode((string) $row->condition_json, true);
        $this->assertSame([], $condition['unmapped_zh']);
        $this->assertSame('power_usage_rate', $condition['all'][0]['metric']);
        $this->assertSame('>', $condition['all'][0]['op']);
        $this->assertEqualsWithDelta(0.85, (float) $condition['all'][0]['value'], 1e-9);

        $auto = json_decode((string) $row->auto_effect_json, true);
        $this->assertSame([], $auto['unmapped_zh']);
        $this->assertSame('modifier', $auto['effects'][0]['kind']);
        $this->assertSame('power', $auto['effects'][0]['target']);
        $this->assertEqualsWithDelta(-0.4, (float) $auto['effects'][0]['value'], 1e-9);

        $options = json_decode((string) $row->options_json, true);
        $this->assertSame([], $options['a']['unmapped_zh'], '选项 A 已完全可执行');
        $this->assertNotEmpty($options['b']['effects'], '选项 B 必须有可执行效果');
    }

    // ---- 公共辅助 ----

    // 高负荷电网:装机 110 / 需求 100 → 使用率 0.909(> 0.85),满供(factor 1.0)
    private function makeGrid(string $un): array
    {
        [$city, $user] = $this->makeCity($un, [
            'era_order'  => 8,
            'era_key'    => 'VIII',
            'money'      => 10000000,
            'population' => 0,
        ]);

        $this->addBuilding($city, 'E03', 25);
        for ($i = 0; $i < 4; $i++) {
            $this->addBuilding($city, 'K04', 32);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->addBuilding($city, 'T02', 0);
        }

        // 资源清零后只给煤:知识 / 机械的增量才是干净的
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);
        $this->setResource($city, 'coal', 1000);

        return [$city->fresh(), $user];
    }
}
