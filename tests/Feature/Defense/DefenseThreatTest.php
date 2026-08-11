<?php

namespace Tests\Feature\Defense;

use App\Game\City\EraService;
use App\Game\Defense\DefenseService;
use App\Game\Item\ItemCode;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcCode;
use App\Game\Simulation\SimulationService;
use App\Support\GameSetting;
use Illuminate\Support\Facades\DB;

// M3-D5:威胁等级模型 + defense_score flat / pct 的读取侧聚合。
//
// 三条被验死的口径:
//   ① 威胁需求**只有一个来源**(EraService::REQUIREMENTS 的九档「国防最低」);
//   ② 分档切点精确(边界值恰好落在哪一档,而不是"差不多");
//   ③ flat / pct **只加一次**:工具 + NPC + 事件同时在场时,合成式固定为
//      (建筑口径 + Σflat) × (1 + Σpct),且同一份数据连算两次结果不变。
class DefenseThreatTest extends DefenseTestCase
{
    // ---------- ① 威胁需求 = §5.1 九档,单一来源 ----------

    public function test_threat_demand_reuses_era_requirements(): void
    {
        // v3.2 §5.1「国防最低」列的九档(I→II … IX→X),逐格照抄自文档,
        // 与 EraService::REQUIREMENTS 是两份独立誊写 —— 谁抄错了这条都会红
        $nine = [20, 60, 120, 250, 450, 800, 1500, 3000, 8000];

        foreach ($nine as $i => $expected) {
            $eraOrder = $i + 1; // 时代 I..IX
            $this->assertSame(
                (float) $expected,
                EraService::defenseRequirement($eraOrder),
                "时代 {$eraOrder} 的威胁需求应等于 §5.1 的国防最低 {$expected}"
            );
        }

        // 最高时代 X 没有下一档:沿用最后一档 8000,不新造第十个数字
        $this->assertSame(8000.0, EraService::defenseRequirement(10));
        // 越界入参一律夹回时代 I(Fail Safe:脏 era_order 不该让威胁需求变成 0 → 永远安全)
        $this->assertSame(20.0, EraService::defenseRequirement(0));
    }

    public function test_evaluate_uses_era_requirement_as_demand(): void
    {
        [$city] = $this->makeCity('thrdemand', ['era_order' => 5]);

        $defense = DefenseService::evaluate($city->fresh(), ['defenseScore' => 0.0]);

        // 时代 V 的城市看的是「升出时代 V 所需的国防最低」= REQUIREMENTS[6] = 450
        $this->assertSame(450.0, $defense['threat_demand_base']);
        $this->assertSame(450.0, $defense['threat_demand']);
    }

    // ---------- ② 分档切点精确 ----------

    public function test_threat_level_boundaries_are_exact(): void
    {
        // 时代 III → 需求 120(§5.1 的 III→IV 档)
        [$city] = $this->makeCity('thrband', ['era_order' => 3]);
        $city = $city->fresh();

        // 安全档的下边界是**闭区间**:恰好等于需求即达标
        $this->assertSame(DefenseService::LEVEL_LOW, $this->levelAt($city, 120.0));
        $this->assertSame(DefenseService::LEVEL_LOW, $this->levelAt($city, 200.0));

        // 差一点点就不是安全档(1e-2 的差也算差)
        $this->assertSame(DefenseService::LEVEL_MEDIUM, $this->levelAt($city, 119.99));

        // 紧张档的下边界同样是闭区间:coverage 恰好 0.60 仍是紧张
        $this->assertSame(DefenseService::LEVEL_MEDIUM, $this->levelAt($city, 72.0));
        $this->assertSame(DefenseService::LEVEL_HIGH, $this->levelAt($city, 71.99));
        $this->assertSame(DefenseService::LEVEL_HIGH, $this->levelAt($city, 0.0));

        // 档序号与枚举一一对应(事件条件比的是序号)
        $this->assertSame(0, DefenseService::LEVEL_RANKS[DefenseService::LEVEL_LOW]);
        $this->assertSame(1, DefenseService::LEVEL_RANKS[DefenseService::LEVEL_MEDIUM]);
        $this->assertSame(2, DefenseService::LEVEL_RANKS[DefenseService::LEVEL_HIGH]);
    }

    public function test_coverage_is_defense_over_demand(): void
    {
        [$city] = $this->makeCity('thrcov', ['era_order' => 3]);

        $defense = DefenseService::evaluate($city->fresh(), ['defenseScore' => 60.0]);

        $this->assertSame(0.5, $defense['coverage']);
        $this->assertSame(60.0, $defense['defense_score']);
        $this->assertSame(60.0, $defense['defense_score_base']);
        $this->assertSame(DefenseService::LEVEL_HIGH, $defense['threat_level']);
        $this->assertSame(2, $defense['threat_rank']);
    }

    // ---------- ③ 后台设定改动即刻生效 ----------

    public function test_threshold_settings_take_effect(): void
    {
        [$city] = $this->makeCity('thrset', ['era_order' => 3]);
        $city = $city->fresh();

        // 默认 0.60 时 coverage=0.5 是危险档
        $this->assertSame(DefenseService::LEVEL_HIGH, $this->levelAt($city, 60.0));

        // 把紧张档阈值放宽到 0.40 → 同一座城变成紧张档
        GameSetting::set(GameSetting::DEFENSE_THREAT_COVERAGE_TENSE, 0.4, null, 'test');
        $this->assertSame(DefenseService::LEVEL_MEDIUM, $this->levelAt($city, 60.0));

        // 把安全档阈值压到 0.5 → 恰好达标,变安全档
        GameSetting::set(GameSetting::DEFENSE_THREAT_COVERAGE_SAFE, 0.5, null, 'test');
        $this->assertSame(DefenseService::LEVEL_LOW, $this->levelAt($city, 60.0));
    }

    public function test_demand_multiplier_setting_scales_demand(): void
    {
        [$city] = $this->makeCity('thrmul', ['era_order' => 3]);
        $city = $city->fresh();

        GameSetting::set(GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER, 2, null, 'test');

        $defense = DefenseService::evaluate($city, ['defenseScore' => 120.0]);
        $this->assertSame(240.0, $defense['threat_demand']);
        $this->assertSame(0.5, $defense['coverage']);
        $this->assertSame(DefenseService::LEVEL_HIGH, $defense['threat_level']);
    }

    // 需求为 0(运营把倍率调成 0)不许出现 0 除,一律按安全档
    public function test_zero_demand_is_safe_not_division_by_zero(): void
    {
        [$city] = $this->makeCity('thrzero', ['era_order' => 3]);
        GameSetting::set(GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER, 0, null, 'test');

        $defense = DefenseService::evaluate($city->fresh(), ['defenseScore' => 0.0]);

        $this->assertSame(0.0, $defense['threat_demand']);
        $this->assertSame(1.0, $defense['coverage']);
        $this->assertSame(DefenseService::LEVEL_LOW, $defense['threat_level']);
    }

    // ---------- ④ flat 通道:工具 ----------

    public function test_equipped_tool_adds_defense_flat(): void
    {
        [$city] = $this->makeCity('thrtool', ['era_order' => 3]);
        $city = $city->fresh();

        $this->assertSame(0.0, $this->scoreAt($city, 100.0) - 100.0);

        // §7 IT008 青铜卫士:国防值 flat +8
        $this->addItem($city, 'IT008');
        $this->assertSame(108.0, $this->scoreAt($city, 100.0));

        // 两件装在两栋楼上 = 两份城防(「同类只取最高」是**单栋建筑内**的产量规则,
        // 与全城 flat 不是一回事 —— 这条口径写在 DefenseService::bonuses 的注释里)
        $this->addItem($city, 'IT008', ItemCode::STATUS_EQUIPPED, 2);
        $this->assertSame(116.0, $this->scoreAt($city, 100.0));
    }

    public function test_stored_or_broken_tool_does_not_count(): void
    {
        [$city] = $this->makeCity('thrtool2', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addItem($city, 'IT008', ItemCode::STATUS_STORED);          // 躺仓库
        $this->addItem($city, 'IT008', ItemCode::STATUS_BROKEN);          // 已损毁
        $this->addItem($city, 'IT008', ItemCode::STATUS_EQUIPPED, 1, 0.0); // 装着但耐久归零

        $this->assertSame(100.0, $this->scoreAt($city, 100.0));
    }

    // ---------- ⑤ flat / pct 通道:NPC ----------

    public function test_npc_traits_add_flat_and_pct(): void
    {
        [$city] = $this->makeCity('thrnpc', ['era_order' => 3]);
        $city = $city->fresh();

        // §6.3 N010 军士:国防值 +12(flat)
        $this->addNpc($city, 'N010');
        $this->assertSame(112.0, $this->scoreAt($city, 100.0));

        // §6.3 N016 校尉:区域国防 +15%(pct)→ (100 + 12) × 1.15
        $this->addNpc($city, 'N016', NpcCode::STATUS_ASSIGNED);
        $this->assertEqualsWithDelta(128.8, $this->scoreAt($city, 100.0), 0.0001);

        // 离职的 NPC 不再计入(行保留只为可追溯)
        DB::table('city_npcs')->where('city_id', $city->id)->update(['status' => NpcCode::STATUS_LEFT]);
        $this->assertSame(100.0, $this->scoreAt($city, 100.0));
    }

    // 150 池扩充进来的军事 NPC 走的是同一条 flat / pct 通道(黄金样本)。
    // 这 10 行的国防特性在扩充草案里还挂在 unmapped_zh,本波次逐条提升为 spec;
    // 提升对不对,只有把它们真的塞进 DefenseService 算一遍才知道 —— 定义层断言看不出「有没有生效」。
    public function test_expansion_military_npc_traits_flow_through_defense_service(): void
    {
        [$city] = $this->makeCity('thrnpc150', ['era_order' => 10]);
        $city = $city->fresh();

        // N036 民兵长 +6 flat / N096 边哨 +7 flat
        $this->addNpc($city, 'N036');
        $this->addNpc($city, 'N096', NpcCode::STATUS_ASSIGNED);
        $this->assertSame(113.0, $this->scoreAt($city, 100.0), '(100 + 6 + 7)');

        // N117 +15% pct / N090 +30% pct → (100 + 13) × (1 + 0.15 + 0.30)
        $this->addNpc($city, 'N117');
        $this->addNpc($city, 'N090', NpcCode::STATUS_ASSIGNED);
        $this->assertEqualsWithDelta(163.85, $this->scoreAt($city, 100.0), 0.0001);

        $defense = DefenseService::evaluate($city, ['defenseScore' => 100.0]);
        $this->assertSame(13.0, $defense['defense_flat']);
        $this->assertEqualsWithDelta(0.45, $defense['defense_pct'], 1e-9);
        $this->assertSame(100.0, $defense['defense_score_base'], '建筑口径不该被加成污染');
    }

    // ---------- ⑥ 只加一次:三个来源同时在场 ----------

    public function test_all_sources_compose_once_in_fixed_order(): void
    {
        [$city] = $this->makeCity('thrmix', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addItem($city, 'IT008');                          // flat +8
        $this->addNpc($city, 'N010');                            // flat +12
        $this->addNpc($city, 'N027', NpcCode::STATUS_ASSIGNED);  // pct +20%
        $this->addModifier($city, ModifierTarget::DEFENSE_SCORE_PCT, ModifierSpec::OP_PCT, 0.25); // 事件 pct +25%

        // 合成式固定:(100 + 8 + 12) × (1 + 0.20 + 0.25) = 120 × 1.45 = 174
        $defense = DefenseService::evaluate($city, ['defenseScore' => 100.0]);
        $this->assertSame(20.0, $defense['defense_flat']);
        $this->assertSame(0.45, $defense['defense_pct']);
        $this->assertSame(174.0, $defense['defense_score']);
        $this->assertSame(100.0, $defense['defense_score_base'], '建筑口径不该被加成污染');

        // **只加一次**:同一份数据连算两次,结果一字不差(累加器没有跨调用残留)
        $again = DefenseService::evaluate($city, ['defenseScore' => 100.0]);
        $this->assertSame($defense, $again);

        // 便捷入口与整块读数同源
        $this->assertSame(174.0, DefenseService::effectiveDefenseScore($city, ['defenseScore' => 100.0]));
    }

    public function test_expired_modifier_is_not_counted(): void
    {
        [$city] = $this->makeCity('threxp', ['era_order' => 3]);
        $city = $city->fresh();

        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => ModifierTarget::DEFENSE_SCORE_PCT, 'scope' => 'city', 'scope_key' => null,
            'op' => ModifierSpec::OP_PCT, 'value' => 0.5,
            'starts_at' => now()->copy()->subHours(2),
            'ends_at'   => now()->copy()->subHour(), // 已到期
            'created_at' => now(),
        ]);

        $this->assertSame(100.0, $this->scoreAt($city, 100.0));
    }

    // op 与 target 口径不符的脏行一律跳过,不"猜"语义
    public function test_wrong_op_rows_are_ignored(): void
    {
        [$city] = $this->makeCity('throp', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addModifier($city, ModifierTarget::DEFENSE_SCORE_FLAT, ModifierSpec::OP_PCT, 0.5);
        $this->addModifier($city, ModifierTarget::DEFENSE_SCORE_PCT, ModifierSpec::OP_FLAT, 50);

        $this->assertSame(100.0, $this->scoreAt($city, 100.0));
    }

    // ---------- ⑦ 威胁需求也能被事件抬起来 ----------

    public function test_threat_demand_pct_raises_the_denominator(): void
    {
        [$city] = $this->makeCity('thrdem', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addModifier($city, ModifierTarget::THREAT_DEMAND_PCT, ModifierSpec::OP_PCT, 0.30);

        $defense = DefenseService::evaluate($city, ['defenseScore' => 120.0]);

        $this->assertSame(120.0, $defense['threat_demand_base']);
        $this->assertSame(156.0, $defense['threat_demand']);   // 120 × 1.30
        $this->assertEqualsWithDelta(0.769231, $defense['coverage'], 1e-6);
        // 原本恰好达标的城市被推进紧张档 —— EVT_BORDER_TENSION 的全部意义就在这一步
        $this->assertSame(DefenseService::LEVEL_MEDIUM, $defense['threat_level']);
    }

    // ---------- ⑧ 快照契约(§11 的两个字段)----------

    public function test_snapshot_exposes_defense_block(): void
    {
        [$city, $user] = $this->makeCity('thrsnap', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 4);   // 建筑口径 100
        $this->addItem($city, 'IT008');    // flat +8

        $res = $this->actingAs($user)->getJson('/api/city')->assertOk();

        $defense = $res->json('data.city.defense');
        $this->assertSame('medium', $defense['threat_level']);      // 108 / 120 = 0.9
        $this->assertSame('紧张', $defense['threat_level_zh']);
        // JSON 里整数值会掉成 int(108.0 → 108),所以用数值比较而不是全等
        $this->assertEqualsWithDelta(108.0, $defense['defense_score'], 1e-6);
        $this->assertEqualsWithDelta(100.0, $defense['defense_score_base'], 1e-6);
        $this->assertEqualsWithDelta(8.0, $defense['defense_flat'], 1e-6);
        $this->assertEqualsWithDelta(120.0, $defense['threat_demand'], 1e-6);
        $this->assertEqualsWithDelta(0.9, $defense['coverage'], 1e-6);
    }

    // 时代门槛读**建筑口径**(常备国防),不含临时加成 —— 否则一个 20 分钟的 buff 能顶过升代门槛
    public function test_era_gate_reads_building_score_not_effective_score(): void
    {
        [$city, $user] = $this->makeCity('threra', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 4);                                  // 建筑口径 100
        $this->addModifier($city, ModifierTarget::DEFENSE_SCORE_PCT, ModifierSpec::OP_PCT, 1.0); // 临时 ×2

        $res = $this->actingAs($user)->getJson('/api/city')->assertOk();

        // 威胁侧看到 200(达标),时代门槛仍按 100 判定(未达 120)
        $this->assertEqualsWithDelta(200.0, $res->json('data.city.defense.defense_score'), 1e-6);

        $row = collect($res->json('data.city.era.next.requirements'))->firstWhere('dimension', 'defense');
        $this->assertEqualsWithDelta(120.0, $row['required'], 1e-6);
        $this->assertEqualsWithDelta(100.0, $row['current'], 1e-6);
        $this->assertFalse($row['met']);
    }

    // ---------- 工具方法 ----------

    private function levelAt(object $city, float $baseScore): string
    {
        return DefenseService::evaluate($city, ['defenseScore' => $baseScore])['threat_level'];
    }

    private function scoreAt(object $city, float $baseScore): float
    {
        return DefenseService::evaluate($city, ['defenseScore' => $baseScore])['defense_score'];
    }

    // 结算内核确实把 D01 的容量类产出聚合成全城国防值(不派工也计,与 §10.8 同口径)
    public function test_kernel_aggregates_watchtower_defense(): void
    {
        [$city] = $this->makeCity('thrkern', ['era_order' => 3]);
        $city = $city->fresh();

        $this->addWatchtowers($city, 3);
        $sim = SimulationService::simulate($city);

        $this->assertSame(75.0, (float) $sim['defenseScore']);
    }
}
