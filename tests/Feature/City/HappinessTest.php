<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M2-C2 幸福度 / 健康 / 治安(v3.2 §10.1 食物品质与赤字、§10.2 幸福合成与快落慢升、
// §10.3 happinessFactor、§10.8 health / security)。
//
// 与 PopulationTest 同一纪律:每条断言都在注释里写清算式,任何一个系数被改坏都必须立刻变红。
//
// 常用建筑(L1):
//   H10 住宅 人口容量 126 / 无工人需求        F02 农田 粮食 14/min、工人 4
//   M01 诊所 医疗容量 120 / 工人 8            D01 哨塔 国防值 25 / 工人 4 / 维护粮食 1
//   P01 磨坊 吃粮食 10/min 产面粉 8/min、工人 3
class HappinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- §10.2 目标幸福的各个分项 ----

    // 建城默认幸福 = 60(§10.2 baseHappiness),迁移的列默认值就该是它
    public function test_new_city_starts_at_base_happiness(): void
    {
        $u = User::create(['username' => 'hapbase', 'name' => 'hapbase', 'email' => 'hb@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        $this->assertEqualsWithDelta(60.0, $this->happinessOf($city), 0.0001);
    }

    // 住房充足(使用率 <= 0.90)→ housingBonus = +10 → 目标 70;
    // 60 起步、升速 +0.5/min、10 分钟只能升 5 → 65(还没够到目标)
    public function test_housing_bonus_raises_target_and_rise_is_capped(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 容量 630、人口 500(使用率 0.7937)、粮食充足且净速率为正
        $city = $this->makeCity('haphouse', ['H10' => 5, 'F02' => 2], 500, 500.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        // 目标 = 60 + 10(住房) + 0(食物品质) + 0(医疗) + 0(治安) + 0(税) + 0(赤字) = 70
        // 60 + 0.5×10 = 65 < 70 → 停在 65
        $this->assertEqualsWithDelta(65.0, $this->happinessOf($city), 0.0001);
    }

    // 升到目标就停:同样的城,结算 30 分钟本可升 15,但目标 70 封住 → 恰好 70
    public function test_rise_never_overshoots_target(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('hapcap', ['H10' => 5, 'F02' => 2], 500, 500.0);

        Carbon::setTestNow($base->copy()->addMinutes(30));
        SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(70.0, $this->happinessOf($city), 0.0001);
    }

    // 严重超容 → housingBonus 触底 -15 → 目标 45;快落 -1.0/min,10 分钟降 10 → 50
    public function test_over_capacity_housing_penalty_falls_fast(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 无住宅 → 人口容量 0 → 使用率 = 500/max(1,0) 远超 1.20 → 惩罚吃满 -15
        $city = $this->makeCity('hapover', ['F02' => 2], 500, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        // 目标 = 60 − 15 = 45;60 − 1.0×10 = 50 > 45 → 停在 50(快落也不越过目标)
        $this->assertEqualsWithDelta(50.0, $this->happinessOf($city), 0.0001);
    }

    // 快落比慢升快一倍:同一段时长,下降走 -1.0/min、上升走 +0.5/min
    public function test_fall_is_twice_as_fast_as_rise(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $up = $this->makeCity('haprise', ['H10' => 5, 'F02' => 2], 500, 500.0);   // 目标 70
        $down = $this->makeCity('hapfall', ['F02' => 2], 500, 5000.0);            // 目标 45

        Carbon::setTestNow($base->copy()->addMinutes(4));
        SimulationService::simulate($up->fresh());
        SimulationService::simulate($down->fresh());

        $this->assertEqualsWithDelta(62.0, $this->happinessOf($up), 0.0001, '升 0.5×4 = +2');
        $this->assertEqualsWithDelta(56.0, $this->happinessOf($down), 0.0001, '降 1.0×4 = −4');
    }

    // 医疗覆盖(§10.2):medicalCapacity / population,满覆盖 +5,不足按比例
    //
    // 全部覆盖类用例都刻意用 <= 30 分钟(= 单段):目标幸福按「段起人口」算,
    // 单段时段起人口就是初始人口,覆盖率不会被段内的人口增长搅动,断言才能精确到 0.0001
    public function test_medical_coverage_adds_up_to_five(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // M01 诊所医疗容量 120,人口 240 → 覆盖率 0.5 → +2.5;住房 630 足够 → +10
        $city = $this->makeCity('hapmed', ['H10' => 5, 'F02' => 2, 'M01' => 1], 240, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(25));
        SimulationService::simulate($city->fresh());

        // 目标 = 60 + 10(住房) + 0(食物品质) + 2.5(医疗 0.5×5) + 0(治安) = 72.5
        // 升速 0.5 × 25 = 12.5 → 60 + 12.5 = 72.5,恰好落到目标上
        $this->assertEqualsWithDelta(72.5, $this->happinessOf($city), 0.0001);
    }

    // 治安覆盖走同一口径:D01 哨塔国防值 25、人口 50 → 覆盖率 0.5 → +2.5
    public function test_security_coverage_uses_same_mapping(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('hapsec', ['H10' => 1, 'F02' => 1, 'D01' => 1], 50, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(25));
        SimulationService::simulate($city->fresh());

        // 住房 126、人口 50 → 使用率 0.397 <= 0.90 → +10;国防 25/50 = 0.5 → +2.5;目标 72.5
        $this->assertEqualsWithDelta(72.5, $this->happinessOf($city), 0.0001);
    }

    // §10.8:health = round(医疗覆盖 × 100)、security = round(国防覆盖 × 100),两者都不落库
    public function test_health_and_security_are_coverage_mappings(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 刻意不摆住宅:人口容量 0 → housingFactor 0 → 人口不增长,覆盖率分母恒为 240
        $city = $this->makeCity('haphs', ['M01' => 1, 'D01' => 1], 240, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(240, (int) DB::table('cities')->where('id', $city->id)->value('population'), '无住宅 → 人口不动');
        $this->assertSame(50, $sim['health'], 'round(min(1, 120/240) × 100)');
        $this->assertSame(10, $sim['security'], 'round(min(1, 25/240) × 100) = round(10.42)');
        // 容量本身也回传,供综合面板与 M3 使用
        $this->assertEqualsWithDelta(120.0, $sim['medicalCapacity'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $sim['defenseScore'], 0.0001);
    }

    // 没有医疗/国防建筑 → 两项都是 0(覆盖率下界)
    public function test_health_and_security_are_zero_without_buildings(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('haphs0', ['F02' => 1], 240, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(0, $sim['health']);
        $this->assertSame(0, $sim['security']);
    }

    // 覆盖率夹在 1.0:容量远超人口时 health/security 封顶 100、两项加成各封顶 +5
    public function test_coverage_is_clamped_to_one(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // M01 医疗 120 / D01 国防 25,人口 20 → 覆盖率 6.0 与 1.25,都要被夹回 1.0
        $city = $this->makeCity('hapclamp', ['H10' => 1, 'F02' => 1, 'M01' => 1, 'D01' => 1], 20, 5000.0);
        // 从 79 起步:25 分钟的升幅(12.5)足够顶到目标,才能证明目标确实是 80 而不是更高
        DB::table('cities')->where('id', $city->id)->update(['happiness' => 79]);

        Carbon::setTestNow($base->copy()->addMinutes(25));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(100, $sim['health']);
        $this->assertSame(100, $sim['security']);
        // 目标 = 60 + 10(住房 20/126) + 5(医疗夹 1.0) + 5(治安夹 1.0) = 80
        // 不夹的话目标会是 60+10+30+6.25 = 106.25 → 夹到 100 → 幸福会升到 91.5,断言立刻变红
        $this->assertEqualsWithDelta(80.0, $this->happinessOf($city), 0.0001);
    }

    // 食物品质(§10.1 四档 → §10.2 加成):面粉产能覆盖 > 30% → +5
    public function test_food_quality_flour_bonus(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // P01 磨坊 1 座:面粉 8/min → 可供给人口 = 8 / 0.03 = 266.67;人口 500 → 覆盖 0.533 > 0.30 → +5
        // F02 4 座保证粮食净速率为正(56 − 500×0.03 = 41)且磨坊有料
        $city = $this->makeCity('hapflour', ['H10' => 5, 'F02' => 4, 'P01' => 1], 500, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(30));
        SimulationService::simulate($city->fresh());

        // 目标 = 60 + 10(住房 500/630 = 0.794) + 5(面粉档) = 75;升 0.5×30 = 15 → 恰好 75
        $this->assertEqualsWithDelta(75.0, $this->happinessOf($city), 0.0001);
    }

    // 面粉覆盖不足 30% 时不给加成(阈值是严格大于)
    public function test_food_quality_below_threshold_gives_no_bonus(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 人口 1000 → 面粉覆盖 = 266.67 / 1000 = 0.267 < 0.30 → +0
        // 住宅 H10 × 10 = 容量 1260(使用率 0.794 → 住房 +10);农田 8 座保证粮食为正
        $city = $this->makeCity('hapflour2', ['H10' => 10, 'F02' => 8, 'P01' => 1], 1000, 8000.0);

        Carbon::setTestNow($base->copy()->addMinutes(30));
        SimulationService::simulate($city->fresh());

        // 目标 = 60 + 10 = 70;升 0.5×30 = 15 → min(75, 70) = 70(若面粉档误判成 +5,目标 75 → 结果 75)
        $this->assertEqualsWithDelta(70.0, $this->happinessOf($city), 0.0001);
    }

    // ---- §10.1 粮食赤字 → happiness -1/分钟 ----

    // 连续赤字满 5 分钟起扣:赤字 12 分钟 → shortagePenalty = −(12 − 5) = −7
    public function test_food_deficit_penalty_starts_after_five_minutes(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 有住宅没农田:粮食净速率 = −500×0.03 = −15/min;粮食 5000 保证不归零、也不触短缺线
        $city = $this->makeCity('hapdef', ['H10' => 5], 500, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(12));
        SimulationService::simulate($city->fresh());

        // 目标 = 60 + 10(住房) + 0 − 7(赤字 12−5) = 63;当前 60 < 63 → 升 0.5×12 = +6 → 66?
        // 不对:升不越过目标,min(60 + 6, 63) = 63
        $this->assertEqualsWithDelta(63.0, $this->happinessOf($city), 0.0001);
        // 赤字起点落库 = 段起(结算窗口起点)
        $this->assertSame(
            '2026-01-01 00:00:00',
            Carbon::parse(DB::table('cities')->where('id', $city->id)->value('food_deficit_since'))->format('Y-m-d H:i:s')
        );
    }

    // 赤字不足 5 分钟:penalty = 0,但计时已经开始落库
    public function test_no_deficit_penalty_within_grace_window(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('hapdefgrace', ['H10' => 5], 500, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(4));
        SimulationService::simulate($city->fresh());

        // 目标 = 60 + 10 − 0 = 70;60 + 0.5×4 = 62
        $this->assertEqualsWithDelta(62.0, $this->happinessOf($city), 0.0001);
        $this->assertNotNull(
            DB::table('cities')->where('id', $city->id)->value('food_deficit_since'),
            '赤字起点必须落库,供下次结算续算'
        );
    }

    // 跨结算续扣:第一次结算 4 分钟(还在宽限期内),第二次结算到第 20 分钟 →
    // 连续赤字必须按 20 分钟算(而不是本次窗口的 16 分钟),penalty = −(20 − 5) = −15
    public function test_food_deficit_accumulates_across_settlements(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 无农田 → 净速率恒为 −15/min;粮食 5000 保证 20 分钟内既不归零也不触短缺线
        $city = $this->makeCity('hapdefacc', ['H10' => 5], 500, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(4));
        SimulationService::simulate($city->fresh());
        $since = DB::table('cities')->where('id', $city->id)->value('food_deficit_since');
        // 第一段目标 = 60 + 10 − 0(赤字 4 分钟 < 5)= 70;升 0.5×4 = +2 → 62
        $this->assertEqualsWithDelta(62.0, $this->happinessOf($city), 0.0001);

        Carbon::setTestNow($base->copy()->addMinutes(20));
        SimulationService::simulate($city->fresh());

        // 第二段窗口 [4, 20],赤字起点仍是 0 → 赤字 20 分钟 → penalty = −15 → 目标 = 60 + 10 − 15 = 55
        // 当前 62 > 55 → 快落 1.0×16 = −16 → 62 − 16 = 46,被目标托住 → 55
        // (若赤字只按本窗口 16 分钟算,penalty = −11 → 目标 59 → 结果 59,断言立刻变红)
        $this->assertEqualsWithDelta(55.0, $this->happinessOf($city), 0.0001);
        $this->assertSame(
            Carbon::parse($since)->format('Y-m-d H:i:s'),
            Carbon::parse(DB::table('cities')->where('id', $city->id)->value('food_deficit_since'))->format('Y-m-d H:i:s'),
            '赤字起点在两次结算之间不得被重置'
        );
    }

    // 赤字解除 → 计时清空,再次赤字要重新等满 5 分钟
    public function test_food_deficit_resets_when_net_rate_turns_positive(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('hapdefreset', ['H10' => 5], 500, 5000.0);

        Carbon::setTestNow($base->copy()->addMinutes(4));
        SimulationService::simulate($city->fresh());
        $this->assertNotNull(DB::table('cities')->where('id', $city->id)->value('food_deficit_since'));

        // 补两座满员农田(28/min > 15/min 的人口消耗)→ 净速率转正 → 赤字解除
        foreach ([1, 2] as $i) {
            CityBuildingInstance::create([
                'city_id' => $city->id, 'building_id' => 'F02', 'level' => 1,
                'x' => 15 + $i * 2, 'y' => 5, 'status' => 'active', 'assigned_workers' => 4,
            ]);
        }
        Carbon::setTestNow($base->copy()->addMinutes(5));
        SimulationService::simulate($city->fresh());

        $this->assertNull(DB::table('cities')->where('id', $city->id)->value('food_deficit_since'));
    }

    // 长时间赤字:幸福被 clamp 在 0,不会跑成负数
    public function test_happiness_is_clamped_to_zero(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 无住宅(住房 −15)+ 持续赤字:12h 封顶结算,目标早已被夹到 0
        $city = $this->makeCity('hapzero', [], 500, 100000.0);

        Carbon::setTestNow($base->copy()->addHours(12));
        SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(0.0, $this->happinessOf($city), 0.0001);
    }

    // 幸福被 clamp 在 100:目标顶天也只有 60+10+15+5+5 = 95,所以直接从库里塞一个 100 验上界不被冲破
    public function test_happiness_is_clamped_to_hundred(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('haphundred', ['H10' => 5, 'F02' => 2], 500, 5000.0);
        DB::table('cities')->where('id', $city->id)->update(['happiness' => 100]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        // 目标 70 < 100 → 快落 −1.0×10 = 90(先证明确实在往下走,没有被卡住)
        $this->assertEqualsWithDelta(90.0, $this->happinessOf($city), 0.0001);
        $this->assertLessThanOrEqual(100.0, $this->happinessOf($city));
    }

    // ---- §10.3 happinessFactor 三段位对人口增长的影响 ----

    // >= 70 → 1.0;60 → 0.75;< 50 → 0。三座同构城市只有初始幸福不同,增长结果必须分成三档
    public function test_happiness_factor_three_bands_drive_growth(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');

        $cases = [
            // [用户名, 初始幸福, 期望人口]
            // housingUsage = 500/630 = 0.7937 → housingFactor 1.0;foodNetRate +13 → foodFactor 1.0
            // rate = 0.002 × happinessFactor;10 分钟复利后 floor
            ['hapf70', 70.0, 510],  // factor 1.0    → 500 × 1.002^10   = 510.0905 → 510
            ['hapf60', 60.0, 507],  // factor 0.75   → 500 × 1.0015^10  = 507.5508 → 507
            ['hapf49', 49.0, 500],  // factor 0      → 完全不增长
        ];

        foreach ($cases as [$un, $happiness, $expected]) {
            Carbon::setTestNow($base);
            $city = $this->makeCity($un, ['H10' => 5, 'F02' => 2], 500, 500.0);
            DB::table('cities')->where('id', $city->id)->update(['happiness' => $happiness]);

            Carbon::setTestNow($base->copy()->addMinutes(10));
            SimulationService::simulate($city->fresh());

            $this->assertSame(
                $expected,
                (int) DB::table('cities')->where('id', $city->id)->value('population'),
                "happiness = {$happiness} 对应的增长档位不符"
            );
        }
    }

    // 50 分界:恰好 50 → factor = 0.5(不是 0);49.999 → 0
    public function test_happiness_factor_boundary_at_fifty(): void
    {
        $this->assertEqualsWithDelta(0.5, SimulationService::happinessFactor(50.0), 0.0001);
        $this->assertEqualsWithDelta(0.0, SimulationService::happinessFactor(49.999), 0.0001);
        $this->assertEqualsWithDelta(1.0, SimulationService::happinessFactor(70.0), 0.0001);
        $this->assertEqualsWithDelta(1.0, SimulationService::happinessFactor(100.0), 0.0001);
        // 50~70 线性:60 → 0.5 + 10/40 = 0.75
        $this->assertEqualsWithDelta(0.75, SimulationService::happinessFactor(60.0), 0.0001);
    }

    // 迁出/饥荒分支不受 happiness 影响(§10.1:这两条是粮食的直接后果,不进 §10.3 的乘式)
    public function test_shortage_and_famine_ignore_happiness(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');

        // 严重短缺:幸福 0 与幸福 100 结果必须相同(PopulationTest 的基线 475)
        foreach ([['hapsh0', 0.0], ['hapsh100', 100.0]] as [$un, $h]) {
            Carbon::setTestNow($base);
            $city = $this->makeCity($un, ['H10' => 5], 500, 160.0);
            DB::table('cities')->where('id', $city->id)->update(['happiness' => $h]);

            Carbon::setTestNow($base->copy()->addMinutes(10));
            SimulationService::simulate($city->fresh());

            // 500 × 0.995^10 = 475.5550 → floor 475
            $this->assertSame(475, (int) DB::table('cities')->where('id', $city->id)->value('population'), "迁出不该受 happiness={$h} 影响");
        }

        // 饥荒:同理(PopulationTest 的基线 452)
        foreach ([['hapfm0', 0.0], ['hapfm100', 100.0]] as [$un, $h]) {
            Carbon::setTestNow($base);
            $city = $this->makeCity($un, ['H10' => 5], 500, 0.0);
            DB::table('cities')->where('id', $city->id)->update(['happiness' => $h]);

            Carbon::setTestNow($base->copy()->addMinutes(20));
            SimulationService::simulate($city->fresh());

            $this->assertSame(452, (int) DB::table('cities')->where('id', $city->id)->value('population'), "饥荒不该受 happiness={$h} 影响");
        }
    }

    // ---- 快照契约 ----

    // /api/city 必须带 happiness / health / security 三个 snake_case 字段
    public function test_snapshot_exposes_happiness_health_security(): void
    {
        $u = User::create(['username' => 'hapsnap', 'name' => 'hapsnap', 'email' => 'hs@x.com', 'password' => 'password123']);
        CityFactory::createForUser($u);

        $res = $this->actingAs($u)->getJson('/api/city');
        $res->assertOk();
        $res->assertJsonStructure(['data' => ['city' => ['happiness', 'health', 'security']]]);
        // 新城:幸福 60、没有医疗/国防建筑 → health = security = 0
        $res->assertJson(['data' => ['city' => ['happiness' => 60, 'health' => 0, 'security' => 0]]]);
    }

    // ---- 公共辅助 ----

    // 受控城市:清空初始建筑,按 [buildingId => 数量] 摆放(工人一律补满该级需求),再覆写人口/粮食/资金
    private function makeCity(string $un, array $buildings, int $population, float $food, float $money = 100000): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        $x = 1;
        foreach ($buildings as $bid => $count) {
            $workers = (int) DB::table('building_level_definition')
                ->where('building_id', $bid)->where('level', 1)->value('worker_required');
            for ($i = 0; $i < $count; $i++) {
                CityBuildingInstance::create([
                    'city_id' => $city->id, 'building_id' => $bid, 'level' => 1,
                    'x' => $x, 'y' => 1, 'status' => 'active', 'assigned_workers' => $workers,
                ]);
                $x += 4;
            }
        }

        DB::table('cities')->where('id', $city->id)->update(['population' => $population, 'money' => $money]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => $food]);

        return $city;
    }

    private function happinessOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('happiness');
    }
}
