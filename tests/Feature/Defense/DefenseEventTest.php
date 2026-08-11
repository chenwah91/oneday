<?php

namespace Tests\Feature\Defense;

use App\Game\Defense\DefenseService;
use App\Game\Event\EventCondition;
use App\Game\Event\EventDefinition;
use App\Game\Event\EventService;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcCode;
use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\GameSetting;
use Illuminate\Support\Facades\DB;

// M3-D5:两条国防事件复活(EVT_RAID / EVT_BORDER_TENSION)+ 事件权重的「国防达标」改读威胁档。
//
// 损失公式(9.E2 + §17)在这里被逐个数字钉死:
//   缺口率 = clamp(1 − 覆盖率, 0, 1)
//   损失率 = clamp(缺口率 × 基础倍率 × 威胁档倍率, 0, 上限)
// 默认参数下紧张档退化成 9.E2 的原式 clamp(1 − defense/demand, 0, 0.30);危险档再 ×1.5。
class DefenseEventTest extends DefenseTestCase
{
    // ---------- 触发条件:威胁等级 ----------

    public function test_raid_does_not_trigger_when_threat_is_low(): void
    {
        [$city] = $this->makeCity('raidlow', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 6); // 150 ≥ 需求 120 → 安全档
        $this->onlyEnable('EVT_RAID');

        $this->runSettle($city, 30);

        $this->assertSame(0, DB::table('city_events')->where('city_id', $city->id)->count(),
            '安全档不该挨劫掠 —— 条件「威胁等级≥中」是硬门槛');
    }

    public function test_raid_triggers_when_threat_is_medium(): void
    {
        [$city] = $this->makeCity('raidmed', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 4); // 100 / 120 = 0.833 → 紧张档
        $this->onlyEnable('EVT_RAID');

        $this->runSettle($city, 30);

        $row = DB::table('city_events')->where('city_id', $city->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('EVT_RAID', $row->event_id);

        $rolled = json_decode((string) $row->rolled_json, true);
        $this->assertSame(DefenseService::LEVEL_MEDIUM, $rolled['threat']['level']);
    }

    // ---------- 损失公式 ----------

    // 紧张档、未触顶:损失率 = 1 − 覆盖率(9.E2 原式)
    public function test_raid_loss_follows_shortfall_formula(): void
    {
        [$city] = $this->makeCity('raidfml', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 4);                 // 覆盖率 100/120 = 0.8333…
        $this->setResource($city, ResourceCode::WOOD, 900);
        $this->setResource($city, ResourceCode::STONE, 600);
        $this->onlyEnable('EVT_RAID');

        $this->runSettle($city, 30);

        $rolled = $this->rolledOf($city);
        // 缺口率 = 1 − 0.8333… = 0.16666…;基础倍率 1.0 × 紧张档 1.0 → 未触顶 0.30
        $this->assertEqualsWithDelta(0.166667, $rolled['threat']['loss_pct'], 1e-5);
        $this->assertEqualsWithDelta(-0.166667, $rolled['loss']['pct'], 1e-5);

        $this->assertEqualsWithDelta(750.0, $this->resourceOf($city, ResourceCode::WOOD), 0.01);
        $this->assertEqualsWithDelta(500.0, $this->resourceOf($city, ResourceCode::STONE), 0.01);
    }

    // 危险档:缺口率 × 1.5 会超过上限 → 被 defense_raid_loss_max_pct 夹到 0.30
    public function test_raid_loss_is_capped_at_max_pct(): void
    {
        [$city] = $this->makeCity('raidcap', ['era_order' => 3]);
        $city = $city->fresh();

        GameSetting::set(GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER, 2, null, 'test'); // 需求 240
        $this->addWatchtowers($city, 4);                                                  // 覆盖率 100/240 = 0.4167 → 危险档
        $this->setResource($city, ResourceCode::WOOD, 1000);
        $this->setResource($city, ResourceCode::STONE, 400);
        $moneyBefore = (float) $city->fresh()->money;
        $this->onlyEnable('EVT_RAID');

        $this->runSettle($city, 30);

        $rolled = $this->rolledOf($city);
        $this->assertSame(DefenseService::LEVEL_HIGH, $rolled['threat']['level']);
        // 0.5833… × 1.5 = 0.875 → 夹到 0.30
        $this->assertSame(0.3, $rolled['threat']['loss_pct']);

        $this->assertEqualsWithDelta(700.0, $this->resourceOf($city, ResourceCode::WOOD), 0.01);
        $this->assertEqualsWithDelta(280.0, $this->resourceOf($city, ResourceCode::STONE), 0.01);

        // 9.E2「作用于**非资金**库存」:自动效果一分钱都不碰(资金只在选项 B 赎金里才动)
        foreach ($rolled['loss']['entries'] as $entry) {
            $this->assertNotSame(ResourceCode::MONEY, $entry['resource']);
        }
        $this->assertGreaterThan($moneyBefore * 0.9, (float) $city->fresh()->money);
    }

    public function test_raid_loss_max_pct_setting_takes_effect(): void
    {
        [$city] = $this->makeCity('raidset', ['era_order' => 3]);
        $city = $city->fresh();

        GameSetting::set(GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER, 2, null, 'test');
        GameSetting::set(GameSetting::DEFENSE_RAID_LOSS_MAX_PCT, 0.1, null, 'test');
        $this->addWatchtowers($city, 4);
        $this->setResource($city, ResourceCode::WOOD, 1000);
        $this->onlyEnable('EVT_RAID');

        $this->runSettle($city, 30);

        $this->assertSame(0.1, $this->rolledOf($city)['threat']['loss_pct']);
        $this->assertEqualsWithDelta(900.0, $this->resourceOf($city, ResourceCode::WOOD), 0.01);
    }

    // 损失减免链(D0.3 的 event_loss_reduction_pct):N027 的「事件损失-10%」照样作用于劫掠
    public function test_loss_reduction_chain_applies_to_raid(): void
    {
        [$city] = $this->makeCity('raidred', ['era_order' => 3]);
        $city = $city->fresh();

        GameSetting::set(GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER, 2, null, 'test'); // 需求 240
        $this->addWatchtowers($city, 4);                                                  // 建筑口径 100
        // N027:事件损失减免 10% + 国防 +20% → 有效国防 120,覆盖率 0.5 仍是危险档 → 仍触顶 0.30
        $this->addNpc($city, 'N027', NpcCode::STATUS_ASSIGNED);
        $this->setResource($city, ResourceCode::WOOD, 1000);
        $this->onlyEnable('EVT_RAID');

        $this->runSettle($city, 30);

        $rolled = $this->rolledOf($city);
        $this->assertSame(0.1, $rolled['threat']['loss_reduction']);
        $this->assertSame(0.27, $rolled['threat']['loss_pct']); // 0.30 × (1 − 0.10)
        $this->assertEqualsWithDelta(730.0, $this->resourceOf($city, ResourceCode::WOOD), 0.01);
    }

    // ---------- 三个选项 ----------

    // 选项 A 动员守军:粮食 -300 + 临时国防 +25%(defense_score_pct 通道)
    public function test_option_a_mobilise_grants_temporary_defense(): void
    {
        [$city, $user] = $this->makeCity('raidopta', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 4);
        $this->setResource($city, ResourceCode::FOOD, 5000);
        $this->onlyEnable('EVT_RAID');
        $this->runSettle($city, 30);

        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');
        $foodBefore = $this->resourceOf($city, ResourceCode::FOOD);

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'a',
        ])->assertOk();

        $this->assertEqualsWithDelta($foodBefore - 300, $this->resourceOf($city, ResourceCode::FOOD), 1.0);

        $modifier = DB::table('city_active_modifiers')
            ->where('city_id', $city->id)
            ->where('target', ModifierTarget::DEFENSE_SCORE_PCT)
            ->first();
        $this->assertNotNull($modifier, '动员守军必须写下一条 defense_score_pct 的临时加成');
        $this->assertSame(ModifierSpec::OP_PCT, $modifier->op);
        $this->assertEqualsWithDelta(0.25, (float) $modifier->value, 1e-6);

        // 读取侧立刻看得到:100 × 1.25 = 125 ≥ 需求 120 → 威胁档回到安全
        $defense = DefenseService::evaluate($city->fresh(), SimulationService::simulate($city->fresh()));
        $this->assertEqualsWithDelta(125.0, $defense['defense_score'], 1e-6);
        $this->assertSame(DefenseService::LEVEL_LOW, $defense['threat_level']);
    }

    // 选项 B 支付赎金:库存全额退还 + 资金按同一损失率扣(§9.2「资金损失,建筑无损」)
    public function test_option_b_ransom_refunds_stock_and_charges_money(): void
    {
        [$city, $user] = $this->makeCity('raidoptb', ['era_order' => 3]);
        $city = $city->fresh();

        GameSetting::set(GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER, 2, null, 'test');
        $this->addWatchtowers($city, 4);
        $this->setResource($city, ResourceCode::WOOD, 1000);
        $this->onlyEnable('EVT_RAID');
        $this->runSettle($city, 30);

        // 自动效果已经扣掉 30%
        $this->assertEqualsWithDelta(700.0, $this->resourceOf($city, ResourceCode::WOOD), 0.01);

        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');
        $moneyBefore = (float) $city->fresh()->money;

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b',
        ])->assertOk();

        // 库存原样退回(按当时的 base 退,不是按此刻库存重算)
        $this->assertEqualsWithDelta(1000.0, $this->resourceOf($city, ResourceCode::WOOD), 0.01);

        // 资金按同一个损失率扣(结算前先跑了一次 Time Delta,资金会先付一段维护 → 用相对比例断言)
        $moneyAfter = (float) $city->fresh()->money;
        $this->assertEqualsWithDelta(0.70, $moneyAfter / $moneyBefore, 0.01);

        $rolled = json_decode((string) DB::table('city_events')->where('id', $instanceId)->value('rolled_json'), true);
        $this->assertSame(0.0, (float) $rolled['loss']['pct']);
        $this->assertSame(ResourceCode::MONEY, $rolled['threat']['ransom']['resource']);
    }

    // 选项 C 迎击:自动效果就是「按国防值结算」,选它不再有额外变化(也不许再扣一次)
    public function test_option_c_keeps_the_settled_loss(): void
    {
        [$city, $user] = $this->makeCity('raidoptc', ['era_order' => 3]);
        $city = $city->fresh();

        GameSetting::set(GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER, 2, null, 'test');
        $this->addWatchtowers($city, 4);
        $this->setResource($city, ResourceCode::WOOD, 1000);
        $this->onlyEnable('EVT_RAID');
        $this->runSettle($city, 30);

        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'c',
        ])->assertOk();

        $this->assertEqualsWithDelta(700.0, $this->resourceOf($city, ResourceCode::WOOD), 0.01);
    }

    // 掷点纪律(§11.3):损失率在触发时定死,结算时**不重算** ——
    // 否则玩家可以先补几栋岗哨,再回来把赎金按新国防值算便宜
    public function test_loss_pct_is_frozen_at_trigger_time(): void
    {
        [$city, $user] = $this->makeCity('raidfrz', ['era_order' => 3]);
        $city = $city->fresh();

        GameSetting::set(GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER, 2, null, 'test');
        $this->addWatchtowers($city, 4);
        $this->setResource($city, ResourceCode::WOOD, 1000);
        $this->onlyEnable('EVT_RAID');
        $this->runSettle($city, 30);

        // 触发之后城防翻倍(覆盖率从 0.4167 变成 0.8333,若重算损失率会降到 0.1667)
        $this->addWatchtowers($city, 4);

        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');
        $moneyBefore = (float) $city->fresh()->money;

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b',
        ])->assertOk();

        // 赎金仍按触发时的 0.30 计
        $this->assertEqualsWithDelta(0.70, (float) $city->fresh()->money / $moneyBefore, 0.01);
    }

    // ---------- EVT_BORDER_TENSION ----------

    public function test_border_tension_raises_threat_demand(): void
    {
        [$city] = $this->makeCity('border1', ['era_order' => 5, 'population' => 6000]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 18); // 450 = 时代 V 的国防最低 → 事发前恰好安全
        $this->onlyEnable('EVT_BORDER_TENSION');

        $before = DefenseService::evaluate($city, ['defenseScore' => 450.0]);
        $this->assertSame(DefenseService::LEVEL_LOW, $before['threat_level']);

        $this->runSettle($city, 30);

        $this->assertSame(1, DB::table('city_events')->where('city_id', $city->id)->count());

        $modifier = DB::table('city_active_modifiers')
            ->where('city_id', $city->id)
            ->where('target', ModifierTarget::THREAT_DEMAND_PCT)
            ->first();
        $this->assertNotNull($modifier, '「国防需求+30%」必须落成 threat_demand_pct 的持续型 modifier');
        $this->assertEqualsWithDelta(0.30, (float) $modifier->value, 1e-6);

        // 需求 450 → 585,原本达标的城市被推进紧张档
        $after = DefenseService::evaluate($city->fresh(), ['defenseScore' => 450.0]);
        $this->assertEqualsWithDelta(585.0, $after['threat_demand'], 1e-6);
        $this->assertSame(DefenseService::LEVEL_MEDIUM, $after['threat_level']);
    }

    public function test_border_tension_option_a_adds_defense_pct(): void
    {
        [$city, $user] = $this->makeCity('border2', ['era_order' => 5, 'population' => 6000]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 18);
        $this->onlyEnable('EVT_BORDER_TENSION');
        $this->runSettle($city, 30);

        $instanceId = (int) DB::table('city_events')->where('city_id', $city->id)->value('id');

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'a',
        ])->assertOk();

        $modifier = DB::table('city_active_modifiers')
            ->where('city_id', $city->id)
            ->where('target', ModifierTarget::DEFENSE_SCORE_PCT)
            ->first();
        $this->assertNotNull($modifier, '「加强防务」的国防 +20% 必须真的生效');
        $this->assertEqualsWithDelta(0.20, (float) $modifier->value, 1e-6);

        // 450 × 1.20 = 540,对上被抬高的需求 585 仍是紧张档 —— 但缺口确实被补上了一截
        $defense = DefenseService::evaluate($city->fresh(), ['defenseScore' => 450.0]);
        $this->assertEqualsWithDelta(540.0, $defense['defense_score'], 1e-6);

        // 「维护+15%」仍在 unmapped 里,玩家与后台都看得见它没生效
        $definition = EventDefinition::find('EVT_BORDER_TENSION');
        $this->assertNotSame([], $definition['options_json']['a']['unmapped_zh']);
    }

    // ---------- 权重的「国防达标」修正改读威胁档 ----------

    public function test_defense_ok_weight_reads_threat_level(): void
    {
        [$city] = $this->makeCity('weightok', ['era_order' => 5, 'population' => 6000]);
        $city = $city->fresh();

        $definition = EventDefinition::find('EVT_BORDER_TENSION');

        // ① 安全档(450 ≥ 450):国防达标 → 权重 ×0.5(9.D2 的 event_weight_defense_ok)
        $this->addWatchtowers($city, 18);
        [$weight] = EventCondition::weight($definition, $this->metricsOf($city));
        $this->assertEqualsWithDelta(2.5, $weight, 1e-6); // base_weight 5 × 0.5

        // ② 拆掉 5 栋 → 325 / 450 = 0.722 → 紧张档,不再达标 → 修正消失
        DB::table('city_building_instances')->where('city_id', $city->id)->limit(5)->delete();
        [$weight] = EventCondition::weight($definition, $this->metricsOf($city->fresh()));
        $this->assertEqualsWithDelta(5.0, $weight, 1e-6);

        // ③ 后台把「达标」门槛放宽到紧张档 → 同一座城又拿回 ×0.5
        GameSetting::set(GameSetting::EVENT_DEFENSE_OK_MAX_THREAT_RANK, 1, null, 'test');
        [$weight] = EventCondition::weight($definition, $this->metricsOf($city->fresh()));
        $this->assertEqualsWithDelta(2.5, $weight, 1e-6);
    }

    // 治安值不再参与「国防达标」判定:治安拉满但威胁档是危险 → 不该拿到 ×0.5
    public function test_security_no_longer_proxies_defense_ok(): void
    {
        [$city] = $this->makeCity('weightsec', ['era_order' => 5, 'population' => 6000]);
        $city = $city->fresh();

        $metrics = $this->metricsOf($city);
        $metrics['security'] = 100.0; // 旧代理指标拉满

        $this->assertSame(DefenseService::LEVEL_HIGH, $metrics['threat_level']);
        [$weight] = EventCondition::weight(EventDefinition::find('EVT_BORDER_TENSION'), $metrics);
        $this->assertEqualsWithDelta(5.0, $weight, 1e-6);
    }

    // 事件条件读到的威胁档与快照读到的必须是同一份(两处口径一致)
    public function test_event_metrics_and_snapshot_agree(): void
    {
        [$city, $user] = $this->makeCity('agree', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 4);
        $this->addItem($city, 'IT008');

        $metrics = $this->metricsOf($city);
        $snapshot = $this->actingAs($user)->getJson('/api/city')->assertOk()->json('data.city.defense');

        $this->assertSame($metrics['threat_level'], $snapshot['threat_level']);
        $this->assertEqualsWithDelta($metrics['defense']['defense_score'], $snapshot['defense_score'], 1e-6);
        $this->assertEqualsWithDelta($metrics['defense']['threat_demand'], $snapshot['threat_demand'], 1e-6);
    }

    // ---------- 工具方法 ----------

    private function metricsOf(City $city): array
    {
        $fresh = $city->fresh();
        $locked = DB::table('cities')->where('id', $fresh->id)->first();

        return EventCondition::snapshot($locked, SimulationService::simulate($fresh));
    }

    private function rolledOf(City $city): array
    {
        $json = DB::table('city_events')->where('city_id', $city->id)->orderBy('id')->value('rolled_json');

        return json_decode((string) $json, true) ?: [];
    }
}
