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

// 结算内核内部结构测试(A1 逐建筑实例中间结构 + G17 资源批量落库)
//
// 目的:这两项是行为不变的重构,所以这里全部用黑盒精确断言锁住结果——
// 乘区(worker/power/logistics/tech/npc/tool/event)M1 阶段恒为 1.0,
// 乘数积必须恒等于 1.0,任何一格被改坏都会让下面的精确用例立刻变红。
class SimulationInternalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- A1-a:乘数生效性(精确产出断言,精度 0.0001) ----

    // 1 座 F02 + 1 座 P01 + 部分粮食库存:满足率 0.5,产出/投入同比例打折,各资源终值精确可算
    public function test_farm_plus_mill_exact_amounts(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('mixexact', ['F02', 'P01'], ['food' => 50, 'wood' => 123.5, 'stone' => 77.25]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        // P01 需求 10/min × 10min = 100,库存 50 → recipeRate = 0.5
        // (保守规则:F02 本区间产的粮食不能立刻喂给 P01)
        // 粮食 = 50 + (14 − 10×0.5) × 10 = 140
        $this->assertEqualsWithDelta(140.0, $this->amountOf($city, 'food'), 0.0001);
        // 面粉 = 8 × 0.5 × 10 = 40(资源行原本不存在,靠 upsert 插入)
        $this->assertEqualsWithDelta(40.0, $this->amountOf($city, 'flour'), 0.0001);
        // 无速率的资源一分不动
        $this->assertEqualsWithDelta(123.5, $this->amountOf($city, 'wood'), 0.0001);
        $this->assertEqualsWithDelta(77.25, $this->amountOf($city, 'stone'), 0.0001);
        // 维护资金 (4 + 2)/min × 10min = 60,不受乘区与满足率影响
        $this->assertEqualsWithDelta(9940.0, $this->moneyOf($city), 0.0001);
    }

    // 容量类产出 + 维护粮食 + 人口吃粮:三者都不进配方、不受乘区影响
    public function test_capacity_and_maintenance_paths_exact(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // F02 粮食14/维护资金4;D01 国防值25/维护资金5/维护粮食1;H01 人口容量18;S01 仓储容量150/维护资金2
        $city = $this->makeCity('capexact', ['F02', 'D01', 'H01', 'S01'], ['food' => 200], 10);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(1150.0, (float) $sim['storageCapacity'], '仓储容量在构建中间结构时提取到全局');
        $this->assertSame(18.0, (float) $sim['populationCapacity'], '人口容量同样是全局累计,不进 grossOut');
        // 粮食速率 = +14(F02) − 1(D01 维护粮食) − 10×0.03(人口)=0.3 → 12.7/min → 200 + 127 = 327
        $this->assertEqualsWithDelta(327.0, $this->amountOf($city, 'food'), 0.0001);
        // 维护资金 (4 + 5 + 0 + 2)/min × 10min = 110;
        // 税收(§10.5)= 人口 10 × 0.02 × 治理效率 0.5(无 A01 → 治理容量 0 → 负载 10 > 1.25)= 0.1/min × 10 = 1
        // 10000 + 1 − 110 = 9891
        $this->assertEqualsWithDelta(9891.0, $this->moneyOf($city), 0.0001);
        // gross 只统计配方侧:国防值/人口容量/仓储容量不算产出,维护粮食与人口吃粮不算消耗
        $this->assertEqualsWithDelta(14.0, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertSame(['food'], array_keys($sim['grossProductionPerMin']), '容量类产出不得混进 grossProductionPerMin');
        $this->assertSame([], $sim['grossConsumptionPerMin'], '维护粮食与人口吃粮不计入 gross 消耗');
    }

    // ---- A1-b:grossProductionPerMin / grossConsumptionPerMin ----

    // P01 满足率 0.5:gross 面粉产出 4/min、gross 粮食消耗 5/min,且与净速率互相自洽
    //
    // 城里同时摆一座 F02:它的 gross 粮食产出 14/min 不受满足率影响,只受乘区影响,
    // 因此这条断言是本用例的"防退化锚"——单看磨坊时,乘区被改成 0.5 会同时让需求减半、
    // 满足率回到 1.0,gross 数值恰好不变而漏检。
    public function test_gross_keys_reflect_recipe_rate(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('grosshalf', ['F02', 'P01'], ['food' => 50]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertArrayHasKey('grossProductionPerMin', $sim);
        $this->assertArrayHasKey('grossConsumptionPerMin', $sim);
        $this->assertEqualsWithDelta(4.0, $sim['grossProductionPerMin']['flour'], 0.0001, 'gross 面粉产出 = 8 × 0.5');
        $this->assertEqualsWithDelta(5.0, $sim['grossConsumptionPerMin']['food'], 0.0001, 'gross 粮食消耗 = 10 × 0.5');
        $this->assertEqualsWithDelta(14.0, $sim['grossProductionPerMin']['food'], 0.0001, 'F02 的 gross 粮食产出不打折');
        $this->assertSame(['food'], array_keys($sim['grossConsumptionPerMin']), '只有配方投入进 gross 消耗');
        // 净速率 = gross 产出 − gross 消耗(此城人口 0、无维护粮食)
        $this->assertEqualsWithDelta(4.0, $sim['ratesPerMin']['flour'], 0.0001);
        $this->assertEqualsWithDelta(9.0, $sim['ratesPerMin']['food'], 0.0001, '14 − 5');
    }

    // 料足时 gross 就是名义速率;多栋同类建筑逐实例累加(2 座 = 2 倍)
    public function test_gross_keys_sum_per_instance(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('grossfull', ['P01', 'P01'], ['food' => 1000]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(16.0, $sim['grossProductionPerMin']['flour'], 0.0001, '两座磨坊 gross 产出 8×2');
        $this->assertEqualsWithDelta(20.0, $sim['grossConsumptionPerMin']['food'], 0.0001, '两座磨坊 gross 消耗 10×2');
        $this->assertEqualsWithDelta(160.0, $this->amountOf($city, 'flour'), 0.0001);
        $this->assertEqualsWithDelta(800.0, $this->amountOf($city, 'food'), 0.0001);
    }

    // elapsed == 0 也要照常返回两个 gross 键(前端速率显示用),且不写库
    public function test_gross_keys_present_when_elapsed_zero(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('grosszero', ['P01'], ['food' => 1000]);

        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(0, $sim['elapsedSeconds']);
        // 需求为 0 → 满足率取 1 → 返回的是无约束名义速率
        $this->assertEqualsWithDelta(8.0, $sim['grossProductionPerMin']['flour'], 0.0001);
        $this->assertEqualsWithDelta(10.0, $sim['grossConsumptionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(1000.0, $this->amountOf($city, 'food'), 0.0001, 'elapsed=0 不落库');
    }

    // ---- G17-c:资源批量 upsert ----

    // 多资源城市:一次 upsert 后逐资源数值与"逐条写入"的预期完全一致,新资源行被插入、旧行被更新
    public function test_batch_upsert_writes_every_resource_exactly(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // F02 粮食+14;P01 吃粮食10产面粉8;E02 吃木材6产燃料5
        $city = $this->makeCity('upsertmix', ['F02', 'P01', 'E02'], ['food' => 60, 'wood' => 100, 'stone' => 50]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        // 粮食需求 100、库存 60 → P01 recipeRate 0.6;木材需求 60、库存 100 → E02 recipeRate 1.0
        $this->assertEqualsWithDelta(140.0, $this->amountOf($city, 'food'), 0.0001, '60 + (14 − 10×0.6)×10');
        $this->assertEqualsWithDelta(48.0, $this->amountOf($city, 'flour'), 0.0001, '8 × 0.6 × 10');
        $this->assertEqualsWithDelta(40.0, $this->amountOf($city, 'wood'), 0.0001, '100 − 6×10');
        $this->assertEqualsWithDelta(50.0, $this->amountOf($city, 'fuel'), 0.0001, '5 × 10');
        $this->assertEqualsWithDelta(50.0, $this->amountOf($city, 'stone'), 0.0001, '无速率的资源不被 upsert 波及');
        // 维护资金 (4 + 2 + 4)/min × 10min = 100
        $this->assertEqualsWithDelta(9900.0, $this->moneyOf($city), 0.0001);

        // 复合主键 (city_id, resource_id) 生效:每种资源只有一行,upsert 不会插出重复。
        // 6 行 = 开局的 木/石/粮/知识(initial_resources 默认含 knowledge)+ 结算新增的 面粉/燃料
        $this->assertSame(6, DB::table('city_resources')->where('city_id', $city->id)->count());
        $dupes = DB::table('city_resources')->where('city_id', $city->id)
            ->select('resource_id')->groupBy('resource_id')->havingRaw('COUNT(*) > 1')->get();
        $this->assertCount(0, $dupes);
    }

    // 连续多次结算:upsert 反复命中同一批主键,只更新不新增行
    public function test_repeated_upsert_does_not_duplicate_rows(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('upsertloop', ['F02', 'P01'], ['food' => 500]);

        for ($i = 1; $i <= 3; $i++) {
            Carbon::setTestNow($base->copy()->addMinutes(10 * $i));
            SimulationService::simulate($city->fresh());
        }

        $this->assertSame(5, DB::table('city_resources')->where('city_id', $city->id)->count(), '木材/石料/粮食/知识/面粉 各一行');
        // 三段各 10min,料一直充足(recipeRate=1):面粉 8×30 = 240,粮食 500 + (14−10)×30 = 620
        $this->assertEqualsWithDelta(240.0, $this->amountOf($city, 'flour'), 0.0001);
        $this->assertEqualsWithDelta(620.0, $this->amountOf($city, 'food'), 0.0001);
    }

    // ---- 公共辅助 ----

    // 建一座受控城市:清空初始建筑,按 $buildingIds 逐个摆放(每栋一个实例),再覆写人口/资金/资源
    private function makeCity(string $un, array $buildingIds, array $resources = [], int $population = 0, float $money = 10000): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        $x = 1;
        foreach ($buildingIds as $bid) {
            // 工人一律补满该级 worker_required:本文件验的是乘区/满足率/落库,
            // 不是用工率,所以 workerFactor 必须恒为 1.0(否则每条精确断言都会被打折干扰)
            $workers = (int) DB::table('building_level_definition')
                ->where('building_id', $bid)->where('level', 1)->value('worker_required');
            CityBuildingInstance::create([
                'city_id' => $city->id, 'building_id' => $bid, 'level' => 1,
                'x' => $x, 'y' => 1, 'status' => 'active', 'assigned_workers' => $workers,
            ]);
            $x += 4;
        }

        DB::table('cities')->where('id', $city->id)->update(['population' => $population, 'money' => $money]);
        foreach ($resources as $res => $amount) {
            DB::table('city_resources')->updateOrInsert(
                ['city_id' => $city->id, 'resource_id' => $res],
                ['amount' => $amount]
            );
        }

        return $city;
    }

    // 读资源现值(行不存在时按 0)
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
