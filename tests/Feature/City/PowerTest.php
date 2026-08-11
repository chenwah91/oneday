<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Energy\PowerService;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M.1 电力系统(v3.2 §3.3 energyFactor + §8 RS017 capacity_contract + backlog 9.F4)。
//
// 与 LogisticsTest 同一纪律:每条断言都在注释里写清算式,
// 任何一个口径被改坏(发电从哪来、耗电读哪一列、曲线形状、下限、时代闸门)都必须立刻变红。
//
// 常用建筑(L1,数值抄自 building_level_definition):
//   E03 燃煤电站  吃煤 28/min  → 发电 110/min   / 工人 25 / 维护 30 / power_per_min 0
//   K04 国家实验室 输入只有电力 → 知识 24/min    / 工人 32 / 维护 45 / power_per_min 25
//   F08 现代农场  吃燃料 11 + 电 4 → 粮食 110/min / 工人 10 / 维护 16 / power_per_min **5**(与输入电 4 不等)
//   T02 道路      运输容量 140    / 工人 0 / 维护 2 / power_per_min 0
//   F02 农田      粮食 14/min     / 工人 6 / 维护 4 / power_per_min 0
class PowerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- §3.3 曲线本身(纯函数) ----

    public function test_power_factor_follows_section_3_3_linear_clamp(): void
    {
        // energyFactor = hasPowerDemand ? clamp(powerReceived / powerDemand, 0, 1) : 1
        // 需求 0 → 恒 1.0(不耗电的城市不该被判成缺电)
        $this->assertSame(1.0, PowerService::factor(0, 0));
        $this->assertSame(1.0, PowerService::factor(500, 0));

        // 供 >= 需 → 1.0(不会因为发电过剩而超过 1:电力是打折方向的乘区)
        $this->assertSame(1.0, PowerService::factor(150, 150));
        $this->assertSame(1.0, PowerService::factor(1000, 150));

        // 线性区:覆盖率就是乘数,**没有物流那样的 0.25 下限**
        $this->assertEqualsWithDelta(0.5, PowerService::factor(75, 150), 0.0001);
        $this->assertEqualsWithDelta(0.1, PowerService::factor(15, 150), 0.0001);
        // §15 回归表:「耗电建筑获取电力为 0 → 对应建筑实际产出为 0」
        $this->assertSame(0.0, PowerService::factor(0, 150));

        // 与物流刻意不同:同样是「容量 0 / 有需求」,物流兜到 0.25,电力必须是 0
        $this->assertSame(0.25, SimulationService::logisticsFactor(999.0));
        $this->assertSame(0.0, PowerService::factor(0, 999));

        // 单调不减:供电越多乘数不得回落
        $prev = -1.0;
        foreach ([0, 10, 30, 75, 120, 150, 300] as $available) {
            $f = PowerService::factor($available, 150);
            $this->assertGreaterThanOrEqual($prev - 1e-9, $f);
            $prev = $f;
        }
    }

    // 电力使用率:分母是**名义装机**,装机为 0 时按 max(1, …) 兜底(与 transportLoad 同一套写法)
    public function test_usage_rate_formula(): void
    {
        $this->assertEqualsWithDelta(0.0, PowerService::usageRate(0, 110), 0.0001);
        $this->assertEqualsWithDelta(0.5, PowerService::usageRate(55, 110), 0.0001);
        $this->assertEqualsWithDelta(1.0, PowerService::usageRate(110, 110), 0.0001);
        $this->assertEqualsWithDelta(25.0, PowerService::usageRate(25, 0), 0.0001, '装机 0 → 分母取 1');
        $this->assertEqualsWithDelta(0.0, PowerService::usageRate(0, 0), 0.0001);
    }

    // ---- 发电:唯一来源 = output_json 的 electricity,且**不入库** ----

    public function test_generation_aggregates_from_output_json_and_never_enters_stock(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // E03 ×2 → 装机 110 × 2 = 220;煤给足,电站正常吃煤
        $city = $this->makeCity('pwrgen', ['E03' => 2, 'T02' => 1], 8, ['coal' => 500]);

        Carbon::setTestNow($base->copy()->addMinutes(1));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(220.0, $sim['powerCapacityPerMin'], 0.0001, 'E03 L1 110 × 2');
        $this->assertEqualsWithDelta(0.0, $sim['powerDemandPerMin'], 0.0001, '全城没有耗电建筑');
        $this->assertSame(1.0, $sim['powerFactor'], '需求 0 → 恒 1.0');
        $this->assertFalse($sim['powerShortage']);

        // 电力**不是库存资源**(§8 RS017 capacity_contract / 9.F4):既不进净速率也不进 city_resources
        $this->assertArrayNotHasKey('electricity', $sim['ratesPerMin'], '发电不该出现在资源净速率里');
        $this->assertSame(0.0, $this->amountOf($city, 'electricity'), '发电不该入库');
        // 但煤照吃:电站的**投入**仍然是普通库存资源(28/min × 2 座 × 1 分钟 = 56)
        $this->assertEqualsWithDelta(444.0, $this->amountOf($city, 'coal'), 0.0001, '500 − 28×2×1');
    }

    // 容量类口径的直接后果:电站没煤(recipeRate = 0)照样按名义装机供电。
    // 这与「仓储建筑不派工也给仓容」是同一条既有口径,被改动时必须有人明确决定
    public function test_generation_is_nominal_and_ignores_fuel_shortage(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('pwrnominal', ['E03' => 1, 'T02' => 1], 8, ['coal' => 0]);

        Carbon::setTestNow($base->copy()->addMinutes(1));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(110.0, $sim['powerCapacityPerMin'], 0.0001,
            '装机是名义速率:一块煤都没有也照样 110(容量类产出不受满足率影响)');
        $this->assertSame(0.0, $this->amountOf($city, 'coal'));
    }

    // ---- 耗电:唯一来源 = power_per_min 那一列 ----

    // F08 的 input_json 里写着 electricity 4,而 power_per_min 是 5。
    // 需求必须是 5(读列),不是 4(读 input),也不是 9(两处都读 = 双计)
    public function test_demand_reads_power_per_min_column_not_input_json(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('pwrdemand', ['F08' => 1, 'E03' => 1, 'T02' => 3], 8, ['coal' => 500, 'fuel' => 500]);

        Carbon::setTestNow($base->copy()->addMinutes(1));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(5.0, $sim['powerDemandPerMin'], 0.0001,
            'F08 的耗电 = power_per_min 5,不是 input_json 里的 4,更不是 4+5');
        // 电力也不再从库存扣:燃料照扣 11/min,电力一分不动(库存本来就是 0)
        $this->assertEqualsWithDelta(489.0, $this->amountOf($city, 'fuel'), 0.0001, '500 − 11');
        $this->assertSame(0.0, $this->amountOf($city, 'electricity'));
        // 电力不进运输需求(电走电网不走车队):F08 = 燃料 11 + 粮食 110 = 121;E03 = 煤 28 → 149
        $this->assertEqualsWithDelta(149.0, $sim['transportDemandPerMin'], 0.0001);
    }

    // ---- 缺电打折(黄金样本) ----

    // 装机 110 / 需求 150 → 覆盖率 = 11/15 = 0.733333…,知识产出按同比例打折
    public function test_partial_supply_scales_only_power_consuming_buildings(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // E03 ×1(装机 110)+ K04 ×6(耗电 25×6 = 150)+ F02 ×1(不耗电的对照组)+ T02 ×2(运力 280)
        $city = $this->makeCity('pwrpartial', ['E03' => 1, 'K04' => 6, 'F02' => 1, 'T02' => 2], 8, ['coal' => 500]);

        Carbon::setTestNow($base->copy()->addMinutes(1));
        $sim = SimulationService::simulate($city->fresh());

        $factor = 110.0 / 150.0;
        $this->assertEqualsWithDelta(110.0, $sim['powerCapacityPerMin'], 0.0001);
        $this->assertEqualsWithDelta(150.0, $sim['powerDemandPerMin'], 0.0001, 'K04 25 × 6');
        $this->assertEqualsWithDelta(0.0, $sim['powerSparePerMin'], 0.0001, '缺电时余量为 0,不为负');
        $this->assertEqualsWithDelta(150.0 / 110.0, $sim['powerUsageRate'], 0.0001, '使用率 = 需求 / 装机');
        $this->assertEqualsWithDelta($factor, $sim['powerFactor'], 0.0001);
        $this->assertTrue($sim['powerShortage']);

        // 物流不该顺带打折:需求 = K04 知识 24×6 = 144 + E03 煤 28 + F02 粮 14 = 186,运力 280 → 负载 0.664
        $this->assertEqualsWithDelta(186.0, $sim['transportDemandPerMin'], 0.0001);
        $this->assertSame(1.0, $sim['logisticsFactor']);

        // 耗电建筑:知识 = 24 × 6 × 0.733333… = 105.6
        $this->assertEqualsWithDelta(105.6, $sim['grossProductionPerMin']['knowledge'], 0.0001);
        $this->assertEqualsWithDelta(105.6, $this->amountOf($city, 'knowledge'), 0.0001);
        // 不耗电的建筑一分不受影响(§3.3 的 hasPowerDemand 分支):F02 满产 14
        $this->assertEqualsWithDelta(14.0, $sim['grossProductionPerMin']['food'], 0.0001);
    }

    // §15 必须通过的测试案例:「断电 | 耗电建筑获取电力为 0 | 对应建筑实际产出为 0」
    public function test_zero_power_zeroes_production_of_power_consumers_only(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 一台发电机都没有:K04 耗电 25,F02 不耗电
        $city = $this->makeCity('pwrzero', ['K04' => 1, 'F02' => 1, 'T02' => 1], 8);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(0.0, $sim['powerCapacityPerMin'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $sim['powerDemandPerMin'], 0.0001);
        $this->assertSame(0.0, $sim['powerFactor'], '§15:获取电力为 0 → 乘区为 0');
        $this->assertTrue($sim['powerShortage']);

        $this->assertSame(0.0, (float) ($sim['grossProductionPerMin']['knowledge'] ?? 0.0),
            '§15:耗电建筑实际产出为 0');
        $this->assertSame(0.0, $this->amountOf($city, 'knowledge'));
        // 对照组:F02 照常满产 14 × 10 = 140(初始粮食由 makeCity 置 0)
        $this->assertEqualsWithDelta(140.0, $this->amountOf($city, 'food'), 0.0001);
    }

    // 电力与维护欠费叠乘:两者相互独立(乘区 × 维护欠费率),与 LogisticsTest 的同名用例同构
    public function test_power_stacks_with_maintenance_arrears(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 装机 110 / 需求 2×25 = 50 → 满供(factor 1.0)… 先确认没有电力影响
        // 再把资金压到付不起维护:E03 30 + K04 45×2 + T02 2 = 122/min,1 分钟应付 122,给 10 元
        $city = $this->makeCity('pwrarr', ['E03' => 1, 'K04' => 2, 'T02' => 1], 8, ['coal' => 500], 10.0);

        Carbon::setTestNow($base->copy()->addMinutes(1));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(1.0, $sim['powerFactor'], '装机 110 ≥ 需求 50 → 满供');
        $this->assertTrue($sim['maintenanceArrears']);
        // 知识 = 24 × 2 × 1.0(电力)× 0.5(欠费半停工)= 24
        $this->assertEqualsWithDelta(24.0, $this->amountOf($city, 'knowledge'), 0.0001);
    }

    // ---- 时代闸门 ----

    public function test_era_gate_suppresses_demand_below_min_era(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 时代 VII(默认闸门 8):同样摆一座 K04,需求不计、乘区恒 1.0 → 满产
        $city = $this->makeCity('pwrera7', ['K04' => 1, 'T02' => 1], 7);

        Carbon::setTestNow($base->copy()->addMinutes(1));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(8, GameSetting::get(GameSetting::POWER_MIN_ERA_ORDER));
        $this->assertEqualsWithDelta(0.0, $sim['powerDemandPerMin'], 0.0001, '时代 VII 不计电力需求');
        $this->assertSame(1.0, $sim['powerFactor']);
        $this->assertEqualsWithDelta(24.0, $this->amountOf($city, 'knowledge'), 0.0001, '满产 24 × 1 分钟');
    }

    // ---- 后台设定生效 ----

    public function test_settings_take_effect(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('pwrset', ['K04' => 1, 'T02' => 1], 8);

        // ① 默认:装机 0 / 需求 25 → 乘区 0
        Carbon::setTestNow($base->copy()->addMinutes(1));
        $this->assertSame(0.0, SimulationService::simulate($city->fresh())['powerFactor']);

        // ② 下限抬到 0.5 → 缺电也保底半产(运营救急)
        GameSetting::set(GameSetting::POWER_FACTOR_MIN, 0.5, null, 'test');
        Carbon::setTestNow($base->copy()->addMinutes(2));
        $this->assertSame(0.5, SimulationService::simulate($city->fresh())['powerFactor']);

        // ③ 总开关关掉 → 恒 1.0(= 接入前的历史行为),且需求归零
        GameSetting::set(GameSetting::POWER_GATE_ENABLED, false, null, 'test');
        Carbon::setTestNow($base->copy()->addMinutes(3));
        $sim = SimulationService::simulate($city->fresh());
        $this->assertSame(1.0, $sim['powerFactor']);
        $this->assertEqualsWithDelta(0.0, $sim['powerDemandPerMin'], 0.0001);

        // ④ 满供拐点:开关拨回来 + 造一座电站(装机 110 / 需求 25 已经满供,改用拐点验缺电档)
        GameSetting::set(GameSetting::POWER_GATE_ENABLED, true, null, 'test');
        GameSetting::set(GameSetting::POWER_FACTOR_MIN, 0, null, 'test');
        // 覆盖率 0.8 时:拐点 1.00 → 打折到 0.8;拐点 0.75 → 视为满供 1.0
        $this->assertEqualsWithDelta(0.8, PowerService::factor(80, 100, 1.0, 0.0), 0.0001);
        $this->assertSame(1.0, PowerService::factor(80, 100, 0.75, 0.0));
        GameSetting::set(GameSetting::POWER_FULL_SUPPLY_RATIO, 0.75, null, 'test');
        $this->assertSame(0.75, GameSetting::get(GameSetting::POWER_FULL_SUPPLY_RATIO));
    }

    // ---- 快照契约 ----

    public function test_snapshot_exposes_power_block(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('pwrsnap', ['E03' => 1, 'K04' => 6, 'T02' => 2], 8, ['coal' => 500]);

        $res = $this->actingAs(User::where('username', 'pwrsnap')->first())->getJson('/api/city');
        $res->assertOk();
        $res->assertJsonStructure(['data' => ['city' => [
            'power' => ['capacity_per_min', 'available_per_min', 'demand_per_min',
                'spare_per_min', 'usage_rate', 'factor', 'shortage', 'event_pct'],
        ]]]);

        $power = $res->json('data.city.power');
        $this->assertEqualsWithDelta(110.0, $power['capacity_per_min'], 0.0001);
        $this->assertEqualsWithDelta(110.0, $power['available_per_min'], 0.0001, '无事件减益 → 可用 = 装机');
        $this->assertEqualsWithDelta(150.0, $power['demand_per_min'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $power['spare_per_min'], 0.0001);
        $this->assertEqualsWithDelta(110.0 / 150.0, $power['factor'], 0.0001);
        $this->assertTrue($power['shortage']);
        $this->assertEqualsWithDelta(0.0, $power['event_pct'], 0.0001);

        // 电力不在 resources 里(它不是库存资源)
        $this->assertArrayNotHasKey('electricity', $res->json('data.city.resources') ?? []);
        $this->assertNotNull($city->id);
    }

    // ---- 存量电力迁移(9.F4「清零并折算补偿」) ----

    public function test_electricity_stock_migration_is_idempotent_and_compensates(): void
    {
        $city = $this->makeCity('pwrmig', [], 8);

        // 手工造一笔历史存量(M.1 之前的库才会有)
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'electricity'],
            ['amount' => 200]
        );
        $moneyBefore = $this->moneyOf($city);
        $price = (float) DB::table('market_definition')->where('resource_id', 'electricity')->value('base_price');
        $this->assertEqualsWithDelta(0.9, $price, 0.0001, '§8 RS017 基础价');

        $migration = require database_path('migrations/2026_08_11_800002_settle_electricity_stock_to_flow.php');
        $migration->up();

        // 存量清零(**行仍在**:删行属于红线,要单独取批准)
        $this->assertSame(0.0, $this->amountOf($city, 'electricity'));
        $this->assertDatabaseHas('city_resources', ['city_id' => $city->id, 'resource_id' => 'electricity']);
        // 折算补偿 = 200 × 0.90 = 180
        $this->assertEqualsWithDelta($moneyBefore + 180.0, $this->moneyOf($city), 0.01);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('city_id', $city->id)->where('reason_code', 'POWER_FLOW_MIGRATION')->count());

        // 幂等:再跑一次不该二次补偿、也不该二次写审计
        $migration->up();
        $this->assertEqualsWithDelta($moneyBefore + 180.0, $this->moneyOf($city), 0.01);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('city_id', $city->id)->where('reason_code', 'POWER_FLOW_MIGRATION')->count());
    }

    // ---- 公共辅助 ----

    // 受控城市:清空初始建筑,按 [buildingId => 数量] 摆放(工人一律补满该级需求),
    // 再覆写时代 / 资源 / 资金。人口固定 0:本文件验的是电力,不要让人口吃粮与增长混进算式
    private function makeCity(
        string $un,
        array $buildings,
        int $eraOrder,
        array $resources = [],
        float $money = 1000000.0
    ): City {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        $slot = 0;
        foreach ($buildings as $bid => $count) {
            $workers = (int) DB::table('building_level_definition')
                ->where('building_id', $bid)->where('level', 1)->value('worker_required');
            for ($i = 0; $i < $count; $i++) {
                CityBuildingInstance::create([
                    'city_id' => $city->id, 'building_id' => $bid, 'level' => 1,
                    // 摆成 4×4 网格:只要 (x, y) 不重复即可(测试夹具直接落库,不走占地校验)
                    'x' => 1 + ($slot % 4) * 4, 'y' => 1 + intdiv($slot, 4) * 4,
                    'status' => 'active', 'assigned_workers' => $workers,
                ]);
                $slot++;
            }
        }

        DB::table('cities')->where('id', $city->id)->update([
            'population' => 0,
            'money'      => $money,
            'era_key'    => 'VIII',
            'era_order'  => $eraOrder,
        ]);

        // 初始资源全部清零,再按入参铺:算式里不该出现「建城随机送了多少木头」
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);
        foreach ($resources as $code => $amount) {
            DB::table('city_resources')->updateOrInsert(
                ['city_id' => $city->id, 'resource_id' => $code],
                ['amount' => $amount]
            );
        }

        return $city;
    }

    private function amountOf(City $city, string $resourceId): float
    {
        return (float) (DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', $resourceId)->value('amount') ?? 0);
    }

    private function moneyOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('money');
    }
}
