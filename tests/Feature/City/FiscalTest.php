<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Simulation\SimConstants;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// M2-C3 财政 / 治理(v3.2 §10.5 财政 + §10.6 治理)。
//
// 与 PopulationTest / HappinessTest 同一纪律:每条断言都在注释里写清算式,
// 任何一个系数被改坏(人均税额、时代倍率、四档效率、半停工 0.5)都必须立刻变红。
//
// 常用建筑(L1,除非另注):
//   A01 行政所  治理容量 80 / 工人 5 / 维护资金 7        A01 L2 治理容量 108 / 工人 6 / 维护资金 8.75
//   K01 学堂    知识 3/min / 工人 8 / 维护资金 8(output_json 里没有治理容量 → 一点治理容量都不提供)
//   F02 农田    粮食 14/min / 工人 4 / 维护资金 4        P01 磨坊 吃粮 10 产面粉 8 / 工人 3 / 维护资金 2
//   H10 住宅    人口容量 126 / 无工人 / 维护资金 9
class FiscalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- §10.5 税收 ----

    // 税收累计精确值:taxIncome = population × taxPerCapitaPerMin × governanceEfficiency
    public function test_tax_income_accrues_exactly(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // A01 治理容量 80、人口 40 → 负载 0.5 <= 0.80 → 效率 1.00(税收不打折)
        $city = $this->makeCity('fisctax', ['A01' => 1], 40, 500.0, 10000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        // 税收 = 40 × 0.02 × 1.00 = 0.8/min;维护 A01 7/min
        // 10000 + 0.8×10 − 7×10 = 10000 + 8 − 70 = 9938
        $this->assertEqualsWithDelta(9938.0, $this->moneyOf($city), 0.0001);
        $this->assertEqualsWithDelta(0.8, $sim['taxIncomePerMin'], 0.0001);
        $this->assertEqualsWithDelta(80.0, $sim['governanceCapacity'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $sim['governanceLoad'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $sim['governanceEfficiency'], 0.0001);
        // 没欠费:维护付得起 → 半停工不生效
        $this->assertFalse($sim['maintenanceArrears']);
        $this->assertEqualsWithDelta(1.0, $sim['maintenanceRate'], 0.0001);
    }

    // 人口 0 的城市一分税收都没有(税基是人口,不是建筑)
    public function test_zero_population_pays_no_tax(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('fisczero', ['A01' => 1], 0, 500.0, 10000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(0.0, $sim['taxIncomePerMin'], 0.0001);
        // 只有维护 7/min × 10min = 70
        $this->assertEqualsWithDelta(9930.0, $this->moneyOf($city), 0.0001);
    }

    // ---- §10.5 时代人均税额:0.02 × 1.5^(era_order − 1) ----

    public function test_tax_per_capita_scales_by_era(): void
    {
        // 时代 I = 0.020000;II = 0.030000;III = 0.045000(v3.2 §10.5 原文列举)
        $this->assertEqualsWithDelta(0.02, SimulationService::taxPerCapitaPerMin(1), 0.0000001);
        $this->assertEqualsWithDelta(0.03, SimulationService::taxPerCapitaPerMin(2), 0.0000001);
        $this->assertEqualsWithDelta(0.045, SimulationService::taxPerCapitaPerMin(3), 0.0000001);
        $this->assertEqualsWithDelta(0.0675, SimulationService::taxPerCapitaPerMin(4), 0.0000001);
        // 时代 X = 0.02 × 1.5^9 = 0.7688671875
        $this->assertEqualsWithDelta(0.7688671875, SimulationService::taxPerCapitaPerMin(10), 0.0000001);
        // 兜底:era_order 缺失/为 0/为负 一律按时代 I(不得算出 1.5 的负次幂)
        $this->assertEqualsWithDelta(0.02, SimulationService::taxPerCapitaPerMin(0), 0.0000001);
        $this->assertEqualsWithDelta(0.02, SimulationService::taxPerCapitaPerMin(-3), 0.0000001);
    }

    // cities.era_order 真的会驱动税收(列由 M2-B6 时代升级提供;列还没上线时跳过)
    public function test_era_order_column_drives_tax_income(): void
    {
        if (! Schema::hasColumn('cities', 'era_order')) {
            $this->markTestSkipped('cities.era_order 尚未上线(M2-B6 时代升级),整数倍率已由纯函数用例覆盖');
        }

        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('fiscera', ['A01' => 1], 40, 500.0, 10000.0);
        // 只 UPDATE 单城单列,不改结构
        DB::table('cities')->where('id', $city->id)->update(['era_order' => 2]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        // 时代 II 人均税额 0.03:税收 = 40 × 0.03 × 1.00 = 1.2/min
        // 10000 + 1.2×10 − 7×10 = 9942(时代 I 的话是 9938)
        $this->assertEqualsWithDelta(1.2, $sim['taxIncomePerMin'], 0.0001);
        $this->assertEqualsWithDelta(9942.0, $this->moneyOf($city), 0.0001);
    }

    // ---- §10.5 / §10.6 治理效率四档 ----

    public function test_governance_efficiency_four_bands(): void
    {
        // <= 0.80 → 1.00
        $this->assertEqualsWithDelta(1.0, SimulationService::governanceEfficiency(0.0), 0.0001);
        $this->assertEqualsWithDelta(1.0, SimulationService::governanceEfficiency(0.80), 0.0001);
        // 0.80 ~ 1.00 → 0.90
        $this->assertEqualsWithDelta(0.9, SimulationService::governanceEfficiency(0.8001), 0.0001);
        $this->assertEqualsWithDelta(0.9, SimulationService::governanceEfficiency(1.00), 0.0001);
        // 1.00 ~ 1.25 → 0.70
        $this->assertEqualsWithDelta(0.7, SimulationService::governanceEfficiency(1.0001), 0.0001);
        $this->assertEqualsWithDelta(0.7, SimulationService::governanceEfficiency(1.25), 0.0001);
        // > 1.25 → 0.50
        $this->assertEqualsWithDelta(0.5, SimulationService::governanceEfficiency(1.2501), 0.0001);
        $this->assertEqualsWithDelta(0.5, SimulationService::governanceEfficiency(999.0), 0.0001);

        // governanceLoad = population / max(1, governanceCapacity):容量 0 时分母取 1(不是除零)
        $this->assertEqualsWithDelta(0.5, SimulationService::governanceLoad(40, 80), 0.0001);
        $this->assertEqualsWithDelta(30.0, SimulationService::governanceLoad(30, 0), 0.0001);
    }

    // 四档在真实结算里逐档生效:同一座 A01(容量 80),只改人口,资金结果分成四个值
    public function test_governance_bands_drive_tax_in_settlement(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');

        $cases = [
            // [用户名, 人口, 负载, 效率, 期望资金]
            // 资金 = 10000 + 人口 × 0.02 × 效率 × 10min − 维护 7/min × 10min
            ['fiscb1', 64,  '0.80',   1.00, 9942.80], // 10000 + 12.8 − 70
            ['fiscb2', 80,  '1.00',   0.90, 9944.40], // 10000 + 14.4 − 70
            ['fiscb3', 100, '1.25',   0.70, 9944.00], // 10000 + 14.0 − 70
            ['fiscb4', 101, '1.2625', 0.50, 9940.10], // 10000 + 10.1 − 70
        ];

        foreach ($cases as [$un, $population, $load, $efficiency, $expectedMoney]) {
            Carbon::setTestNow($base);
            // 刻意不摆住宅:人口容量 0 → housingFactor 0 → 人口整段不动,负载与税基都恒定
            $city = $this->makeCity($un, ['A01' => 1], $population, 500.0, 10000.0);

            Carbon::setTestNow($base->copy()->addMinutes(10));
            $sim = SimulationService::simulate($city->fresh());

            $this->assertSame($population, (int) DB::table('cities')->where('id', $city->id)->value('population'));
            $this->assertEqualsWithDelta((float) $load, $sim['governanceLoad'], 0.0001, "负载档 {$load}");
            $this->assertEqualsWithDelta($efficiency, $sim['governanceEfficiency'], 0.0001, "效率档 {$load}");
            $this->assertEqualsWithDelta($expectedMoney, $this->moneyOf($city), 0.0001, "负载 {$load} 的税收结果");
        }
    }

    // ---- 治理容量的单一来源:只认 output_json 的 governance_capacity ----

    // K01 学堂的 output_json 里没有治理容量 → 它一点治理容量都不提供。
    //
    // 该建筑曾经在已删除的 governance_bonus 列里写着 30(与 output_json 的两套口径不相等,
    // 用户 2026-08-10 裁决物理删列,V3.2.1)。列没了,双计的可能性从数据层面被消灭 ——
    // 这条用例现在同时守两件事:三列真的不在表里 + 治理容量仍然只认 output_json
    public function test_governance_capacity_only_counts_output_json(): void
    {
        // 三列必须已从定义表消失(V3.2.1 删列迁移)。若哪天有人把列加回来,这条立刻变红
        foreach (['happiness_bonus', 'governance_bonus', 'defense_score'] as $dropped) {
            $this->assertFalse(
                Schema::hasColumn('building_level_definition', $dropped),
                "{$dropped} 已于 V3.2.1 删除,不得重新出现在定义表"
            );
        }

        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('fiscbonus', ['K01' => 1], 20, 500.0, 10000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacity'], 0.0001, 'K01 不产治理容量');
        // 容量 0 → 负载 = 20 / max(1,0) = 20 > 1.25 → 效率 0.50 → 税收 20 × 0.02 × 0.5 = 0.2/min
        // 10000 + 0.2×10 − 8×10 = 9922(若误把旧列的 30 算进来:负载 0.667 → 效率 1.00 → 9924)
        $this->assertEqualsWithDelta(0.5, $sim['governanceEfficiency'], 0.0001);
        $this->assertEqualsWithDelta(9922.0, $this->moneyOf($city), 0.0001);
    }

    // A01 L2:output_json 治理容量 108(删列前那一列写的是 104,两套口径数值本就不相等)。
    // 结算必须取 108 —— 这是「删掉的那一列不可拿来替代 output_json」最直接的证据
    public function test_governance_capacity_takes_output_json_value(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('fiscl2', [], 40, 500.0, 10000.0);
        CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'A01', 'level' => 2,
            'x' => 1, 'y' => 1, 'status' => 'active', 'assigned_workers' => 6,
        ]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(108.0, $sim['governanceCapacity'], 0.0001, 'A01 L2 output 108 / bonus 104,取 output');
    }

    // ---- §10.5 财政赤字 / 维护欠费 → 半停工 ----

    // 段内资金(含税收)付不起全额维护 → 有维护费的建筑产出 ×0.5
    public function test_maintenance_arrears_halves_production(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // F02 维护 4/min → 10 分钟应付 40;资金只有 10、人口 0(无税收)→ 欠费
        $city = $this->makeCity('fiscarr', ['F02' => 1], 0, 100.0, 10.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertTrue($sim['maintenanceArrears'], '10 < 40 → 本段欠费');
        $this->assertEqualsWithDelta(0.5, $sim['maintenanceRate'], 0.0001);
        // 粮食 = 100 + 14 × 0.5 × 10 = 170(满产的话是 240)
        $this->assertEqualsWithDelta(170.0, $this->foodOf($city), 0.0001);
        // 资金夹在 0:付不起的部分不记负债,已经转成了半停工惩罚
        $this->assertEqualsWithDelta(0.0, $this->moneyOf($city), 0.0001);
        $this->assertEqualsWithDelta(7.0, $sim['ratesPerMin']['food'], 0.0001, '净速率也是半产口径');
        $this->assertEqualsWithDelta(4.0, $sim['maintenanceMoneyPerMin'], 0.0001, '维护本身不打折,照收全额');
    }

    // 同一座城资金充足 → 满产,证明上面那 170 确实来自欠费而不是别的因素
    public function test_funded_city_produces_at_full_rate(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('fiscpaid', ['F02' => 1], 0, 100.0, 1000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertFalse($sim['maintenanceArrears']);
        $this->assertEqualsWithDelta(240.0, $this->foodOf($city), 0.0001, '100 + 14 × 10');
        $this->assertEqualsWithDelta(960.0, $this->moneyOf($city), 0.0001, '1000 − 4 × 10');
    }

    // 欠费解除即恢复:补钱之后的下一段立刻回到满产(半停工不是粘性状态)
    public function test_arrears_recovers_after_funding(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('fiscrec', ['F02' => 1], 0, 100.0, 10.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());
        $this->assertEqualsWithDelta(170.0, $this->foodOf($city), 0.0001, '第一段欠费半产');
        $this->assertEqualsWithDelta(0.0, $this->moneyOf($city), 0.0001);

        // 补一笔钱(相当于卖资源 / 管理员补偿)后再结算 10 分钟
        DB::table('cities')->where('id', $city->id)->update(['money' => 1000]);
        Carbon::setTestNow($base->copy()->addMinutes(20));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertFalse($sim['maintenanceArrears']);
        $this->assertEqualsWithDelta(310.0, $this->foodOf($city), 0.0001, '170 + 14 × 10 满产');
        $this->assertEqualsWithDelta(960.0, $this->moneyOf($city), 0.0001, '1000 − 4 × 10');
    }

    // 零维护建筑不受欠费波及:整城欠费时住宅照样提供人口容量
    public function test_zero_maintenance_buildings_are_not_halved(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // H01 住宅(人口容量 18、维护 0)+ F02(维护 4);资金 0、人口 0 → F02 欠费
        $city = $this->makeCity('fiscfree', ['H01' => 1, 'F02' => 1], 0, 100.0, 0.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertTrue($sim['maintenanceArrears']);
        $this->assertEqualsWithDelta(18.0, $sim['populationCapacity'], 0.0001, '零维护的住宅不欠费,容量不打折');
        $this->assertEqualsWithDelta(170.0, $this->foodOf($city), 0.0001, 'F02 半产');
    }

    // 欠费与库存满足率的叠乘顺序:半停工先折算原料需求,再算满足率(与乘区同级)
    public function test_arrears_scales_input_demand_before_recipe_rate(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // P01 磨坊:吃粮 10/min、产面粉 8/min、维护 2/min;资金 5 < 应付 20 → 欠费
        $city = $this->makeCity('fiscmix', ['P01' => 1], 0, 100.0, 5.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertTrue($sim['maintenanceArrears']);
        // 需求 = 10 × 0.5(欠费) × 10min = 50 <= 库存 100 → recipeRate = 1.0
        // 面粉 = 8 × 0.5 × 1.0 × 10 = 40;粮食 = 100 − 50 = 50
        // (若欠费只打折产出、不折算需求:需求 100 → recipeRate 1.0 → 面粉仍 40 但粮食会被吃到 0)
        $this->assertEqualsWithDelta(40.0, $this->amountOf($city, 'flour'), 0.0001);
        $this->assertEqualsWithDelta(50.0, $this->foodOf($city), 0.0001, '半停工吃料同比例减半');
        $this->assertEqualsWithDelta(4.0, $sim['grossProductionPerMin']['flour'], 0.0001, '8 × 0.5');
        $this->assertEqualsWithDelta(5.0, $sim['grossConsumptionPerMin']['food'], 0.0001, '10 × 0.5');
    }

    // ---- §10.5 财政预警(黄 < 10 分钟维护 / 红 < 3 分钟维护) ----

    public function test_fiscal_warning_thresholds(): void
    {
        // 维护 4/min:资金 40 恰好撑 10 分钟 → 还不算黄(阈值是「小于」10 分钟)
        $this->assertSame('none', SimulationService::fiscalWarning(40.0, 4.0));
        $this->assertSame('yellow', SimulationService::fiscalWarning(39.99, 4.0)); // 9.9975 分钟
        // 资金 12 恰好撑 3 分钟 → 仍是黄,再少一点才转红
        $this->assertSame('yellow', SimulationService::fiscalWarning(12.0, 4.0));
        $this->assertSame('red', SimulationService::fiscalWarning(11.99, 4.0));    // 2.9975 分钟
        $this->assertSame('red', SimulationService::fiscalWarning(0.0, 4.0));      // 已经一分钱没有
        // 维护为 0 的城市永远付得起 → 恒 none(哪怕资金也是 0,分母不能拿来除)
        $this->assertSame('none', SimulationService::fiscalWarning(0.0, 0.0));
        $this->assertSame('none', SimulationService::fiscalWarning(100.0, 0.0));
        // 阈值本身锁死在 §10.5 的 10 / 3 分钟
        $this->assertSame(10.0, SimConstants::FISCAL_WARNING_YELLOW_MINUTES);
        $this->assertSame(3.0, SimConstants::FISCAL_WARNING_RED_MINUTES);
    }

    // 三态在真实结算里各出现一次:同一座 F02(维护 4/min),只改初始资金
    public function test_fiscal_warning_three_states_in_settlement(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');

        // 三个用例都紧贴阈值:黄线两侧只差 1 分钟、红线两侧只差 0.5 分钟,
        // 任何人把 10 / 3 这两个阈值挪动一点都会有用例变红
        $cases = [
            // [用户名, 初始资金, 10 分钟后资金 = 初始 − 40, 可撑分钟数, 期望预警]
            ['fiscwn', 80.0, 40.0, 10.0, 'none'],   // 恰好 10 分钟 → 还不黄
            ['fiscwy', 76.0, 36.0, 9.0,  'yellow'], // 9 分钟 → 黄
            ['fiscwr', 50.0, 10.0, 2.5,  'red'],    // 2.5 分钟 → 红
        ];

        foreach ($cases as [$un, $money0, $money1, $minutes, $expected]) {
            Carbon::setTestNow($base);
            // 人口 0:没有税收,资金变化只剩维护一条线,算式干净
            $city = $this->makeCity($un, ['F02' => 1], 0, 100.0, $money0);

            Carbon::setTestNow($base->copy()->addMinutes(10));
            $sim = SimulationService::simulate($city->fresh());

            $this->assertEqualsWithDelta($money1, $this->moneyOf($city), 0.0001, "{$un} 结算后资金");
            $this->assertEqualsWithDelta(4.0, $sim['maintenanceMoneyPerMin'], 0.0001);
            $this->assertEqualsWithDelta($minutes, $money1 / 4.0, 0.0001, "{$un} 可支撑分钟数");
            $this->assertSame($expected, $sim['fiscalWarning'], "{$un} 预警级别");
        }
    }

    // 零维护的城市永远 none:住宅摆满、资金归零也不该吓唬玩家
    public function test_zero_maintenance_city_never_warns(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('fiscwz', ['H01' => 2], 0, 100.0, 0.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(0.0, $sim['maintenanceMoneyPerMin'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->moneyOf($city), 0.0001);
        $this->assertSame('none', $sim['fiscalWarning']);
    }

    // 预警与欠费半停工是同一条时间线上的先后两步:欠费之后资金必然被夹到 0 → 一定是红
    public function test_arrears_city_is_red(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('fiscwarr', ['F02' => 1], 0, 100.0, 10.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertTrue($sim['maintenanceArrears']);
        $this->assertEqualsWithDelta(0.0, $this->moneyOf($city), 0.0001);
        $this->assertSame('red', $sim['fiscalWarning']);
    }

    // ---- 与人口/幸福分段的叠加一致性 ----

    // 税基是「段起人口」:人口在段间增长,第二段的税收必须跟着涨;
    // 且「一次 60 分钟」与「两次 30 分钟」的资金结果一致(分段无关性)
    public function test_tax_follows_segment_start_population(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');

        // 城 A:一次性结算 60 分钟(内部切成 2 段 × 30 分钟)
        Carbon::setTestNow($base);
        $cA = $this->makeCity('fiscsegA', ['H10' => 5, 'F02' => 2], 500, 100.0, 100000.0);
        Carbon::setTestNow($base->copy()->addMinutes(60));
        SimulationService::simulate($cA->fresh());

        // 城 B:分两次各结算 30 分钟
        Carbon::setTestNow($base);
        $cB = $this->makeCity('fiscsegB', ['H10' => 5, 'F02' => 2], 500, 100.0, 100000.0);
        Carbon::setTestNow($base->copy()->addMinutes(30));
        SimulationService::simulate($cB->fresh());
        Carbon::setTestNow($base->copy()->addMinutes(60));
        SimulationService::simulate($cB->fresh());

        // 无治理建筑 → 容量 0 → 负载 500 > 1.25 → 效率 0.50,人均税额 0.02 → 税率 = 人口 × 0.01
        // 维护 = H10 9×5 + F02 4×2 = 53/min × 60min = 3180
        // 段1(0~30):人口 500 → 税 5/min × 30 = 150
        //   人口增长 rate = 0.002 × housingFactor 1.0 × foodFactor 1.0 × happinessFactor 0.75 = 0.0015
        //   → 500 × 1.0015^30 = 522.9962962
        // 段2(30~60):税 = 522.9962962 × 0.01 × 30 = 156.8988888
        // 资金 = 100000 + 150 + 156.8988888 − 3180 = 97126.8988888 → 落库 97126.90
        $this->assertEqualsWithDelta(97126.90, $this->moneyOf($cA), 0.01);
        // 若第二段仍按 500 人收税,总税收只有 300 → 资金恰为 97120。必须严格更多
        $this->assertGreaterThan(97121.0, $this->moneyOf($cA), '第二段税基必须是增长后的人口');
        // 分段无关性:B 中间那次把人口 floor 掉不足 1 人的零头,第二段税基少 <= 1 人 × 0.01 × 30 = 0.3
        $this->assertEqualsWithDelta($this->moneyOf($cB), $this->moneyOf($cA), 0.5,
            '一次 60 分钟 === 两次 30 分钟');
    }

    // ---- 快照契约 ----

    // /api/city 必须带 tax_income_per_min / 维护速率 / 财政预警 与 governance 三件套(全 snake_case)
    public function test_snapshot_exposes_fiscal_fields(): void
    {
        $u = User::create(['username' => 'fiscsnap', 'name' => 'fiscsnap', 'email' => 'fs@x.com', 'password' => 'password123']);
        CityFactory::createForUser($u);

        $res = $this->actingAs($u)->getJson('/api/city');
        $res->assertOk();
        $res->assertJsonStructure(['data' => ['city' => [
            'tax_income_per_min', 'maintenance_money_per_min', 'fiscal_warning',
            'governance' => ['load', 'efficiency', 'capacity'],
        ]]]);

        // 新城没有任何建筑 → 维护 0 → 预警恒 none(HUD 的资金数字保持常态色)
        $this->assertEqualsWithDelta(0.0, $res->json('data.city.maintenance_money_per_min'), 0.0001);
        $this->assertSame('none', $res->json('data.city.fiscal_warning'));

        // 新城:人口 30、没有治理建筑 → 容量 0 → 负载 30/max(1,0) = 30 > 1.25 → 效率 0.50
        // 税收 = 30 × 0.02 × 0.50 = 0.3/min
        $city = $res->json('data.city');
        $this->assertEqualsWithDelta(0.0, $city['governance']['capacity'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $city['governance']['load'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $city['governance']['efficiency'], 0.0001);
        $this->assertEqualsWithDelta(0.3, $city['tax_income_per_min'], 0.0001);
    }

    // 快照里的预警会随资金真实变色:HUD 直接读这两个字段,不自己算阈值
    public function test_snapshot_fiscal_warning_reflects_money(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // F02 维护 4/min、人口 0(无税收);资金 10 → 只够撑 2.5 分钟 → 红
        $city = $this->makeCity('fiscsnapr', ['F02' => 1], 0, 100.0, 10.0);
        $u = User::where('username', 'fiscsnapr')->first();

        // 时间不推进(elapsed = 0):资金原样返回,预警只由「资金 / 维护速率」决定
        $snapshot = $this->actingAs($u)->getJson('/api/city')->assertOk()->json('data.city');
        $this->assertEqualsWithDelta(4.0, $snapshot['maintenance_money_per_min'], 0.0001);
        $this->assertSame('red', $snapshot['fiscal_warning']);

        // 同一座城改资金:70 → 撑 17.5 分钟 → none;30 → 撑 7.5 分钟 → 黄
        foreach ([[70.0, 'none'], [30.0, 'yellow']] as [$money, $expected]) {
            DB::table('cities')->where('id', $city->id)->update(['money' => $money]);

            $snapshot = $this->actingAs($u)->getJson('/api/city')->assertOk()->json('data.city');
            $this->assertSame($expected, $snapshot['fiscal_warning'], "资金 {$money} 的预警级别");
        }
    }

    // ---- 公共辅助 ----

    // 受控城市:清空初始建筑,按 [buildingId => 数量] 摆放(工人一律补满该级需求),再覆写人口/粮食/资金
    private function makeCity(string $un, array $buildings, int $population, float $food, float $money): City
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

    private function amountOf(City $city, string $resourceId): float
    {
        return (float) (DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', $resourceId)->value('amount') ?? 0);
    }

    private function foodOf(City $city): float
    {
        return $this->amountOf($city, 'food');
    }

    private function moneyOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('money');
    }
}
