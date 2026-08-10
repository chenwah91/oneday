<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Population\WorkerBackfill;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 人口增长与粮食赤字三级后果(v3.2 §10.1 / §10.3),以及存档回填(§10.4)。
//
// 全部用例都精确到"人"断言:落库人口 = floor(内存 float),所以每条注释里都写清算式,
// 数值改动(哪怕只是 baseGrowth 的一位小数)都必须让这些用例立刻变红。
class PopulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- §10.3 正常增长 ----

    // 容量足、粮足:pop × (1 + 0.002)^分钟 复利
    public function test_normal_growth_compounds(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 5 座 H10(人口容量 126 × 5 = 630)+ 2 座满员 F02(粮食 28/min);人口 500、粮食 500
        $city = $this->makeCity('popgrow', ['H10' => 5, 'F02' => 2], 500, 500.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        // housingUsage = 500/630 = 0.7937 < 0.80 → housingFactor = 1.0
        // foodNetRate = 28 − 500×0.03 = 13/min > 0 → foodFactor = 1.0;healthFactor 占位 1.0
        // happiness 段起 = 60(建城默认)→ happinessFactor = 0.5 + (60−50)/40 = 0.75(§10.3)
        // rate = 0.002 × 0.75 = 0.0015 → 500 × 1.0015^10 = 507.5508 → 落库 floor = 507
        $this->assertSame(507, (int) DB::table('cities')->where('id', $city->id)->value('population'));
        // 名义增长(§10.3 口径,人/分钟)= 段起人口 500 × 0.0015 = 0.75
        $this->assertEqualsWithDelta(0.75, $sim['populationGrowthPerMin'], 0.0001);
        // 粮食 500 + 13×10 = 630(段内人口恒定,粮耗按段起人口 500 计)
        $this->assertEqualsWithDelta(630.0, $this->foodOf($city), 0.0001);
    }

    // 人口被夹在人口容量:接近满员时 housingFactor 线性衰减,增长量再大也不越过容量
    public function test_growth_is_clamped_to_population_capacity(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('popclamp', ['H10' => 5, 'F02' => 2], 625, 500.0);

        Carbon::setTestNow($base->copy()->addMinutes(30));
        SimulationService::simulate($city->fresh());

        // housingUsage = 625/630 = 0.99206 → housingFactor = 1 − (0.99206−0.80)/0.20 × 0.8 = 0.23175
        // happiness 段起 60 → happinessFactor 0.75
        // rate = 0.002 × 0.23175 × 0.75 = 0.00034762 → 625 × 1.00034762^30 = 631.55 → 夹到容量 630
        $this->assertSame(630, (int) DB::table('cities')->where('id', $city->id)->value('population'));
    }

    // 粮食净速率 < 0:foodFactor = 0 → 立即停止人口增长(§10.1),但还没到短缺线,人口不减
    public function test_negative_food_rate_stops_growth(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 有住宅没农田:粮食净速率 = −500×0.03 = −15/min
        $city = $this->makeCity('popnofood', ['H10' => 5], 500, 1000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        // 段末粮食 1000 − 150 = 850,远高于 3 分钟消耗线(45)→ 走正常分支,但 foodFactor=0 → 不增长
        $this->assertSame(500, (int) DB::table('cities')->where('id', $city->id)->value('population'));
        $this->assertEqualsWithDelta(850.0, $this->foodOf($city), 0.0001);
    }

    // ---- §10.1 粮食赤字三级后果 ----

    // 严重短缺:段末库存 < 3 分钟人口消耗 → −0.5%/分钟 复利(迁出)
    public function test_severe_shortage_migrates_population_out(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('popshort', ['H10' => 5], 500, 160.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        // 段末粮食 160 − 15×10 = 10,短缺线 = 500×0.03×3 = 45 → 10 < 45 且 > 0 → 迁出分支
        // 500 × 0.995^10 = 475.5550 → floor = 475
        $this->assertSame(475, (int) DB::table('cities')->where('id', $city->id)->value('population'));
        $this->assertEqualsWithDelta(10.0, $this->foodOf($city), 0.0001);
        // 库存不为 0 → 归零计时保持空
        $this->assertNull(DB::table('cities')->where('id', $city->id)->value('food_zero_since'));
    }

    // 归零满 10 分钟后:超出宽限期的时间按 −1.0%/分钟 复利
    public function test_famine_starts_after_ten_minutes_of_zero_food(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('popfamine', ['H10' => 5], 500, 0.0);

        Carbon::setTestNow($base->copy()->addMinutes(20));
        SimulationService::simulate($city->fresh());

        // 段起库存就是 0 → food_zero_since = 段起(= 00:00:00);饥荒时间 = 20 − 10 = 10 分钟
        // 500 × 0.99^10 = 452.1910 → floor = 452
        $this->assertSame(452, (int) DB::table('cities')->where('id', $city->id)->value('population'));
        $this->assertSame(
            '2026-01-01 00:00:00',
            Carbon::parse(DB::table('cities')->where('id', $city->id)->value('food_zero_since'))->format('Y-m-d H:i:s')
        );
    }

    // 宽限期内(不足 10 分钟)不扣人口
    public function test_no_famine_within_grace_window(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('popgrace', ['H10' => 5], 500, 0.0);

        Carbon::setTestNow($base->copy()->addMinutes(6));
        SimulationService::simulate($city->fresh());

        $this->assertSame(500, (int) DB::table('cities')->where('id', $city->id)->value('population'));
        $this->assertNotNull(DB::table('cities')->where('id', $city->id)->value('food_zero_since'), '归零起点必须落库,供下次结算续算');
    }

    // food_zero_since 跨两次结算持续累计:6 分钟 + 6 分钟 = 归零 12 分钟,只有最后 2 分钟算饥荒
    public function test_food_zero_since_accumulates_across_settlements(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('popzero2', ['H10' => 5], 500, 0.0);

        Carbon::setTestNow($base->copy()->addMinutes(6));
        SimulationService::simulate($city->fresh());
        $zeroSince = DB::table('cities')->where('id', $city->id)->value('food_zero_since');

        Carbon::setTestNow($base->copy()->addMinutes(12));
        SimulationService::simulate($city->fresh());

        // 第二次结算的窗口是 [+6, +12];归零起点仍是 00:00 → 饥荒时间 = 12 − 10 = 2 分钟
        // 500 × 0.99^2 = 490.05 → floor = 490
        $this->assertSame(490, (int) DB::table('cities')->where('id', $city->id)->value('population'));
        $this->assertSame(
            Carbon::parse($zeroSince)->format('Y-m-d H:i:s'),
            Carbon::parse(DB::table('cities')->where('id', $city->id)->value('food_zero_since'))->format('Y-m-d H:i:s'),
            '归零起点在两次结算之间不得被重置'
        );
    }

    // 补粮后归零计时清零:再次归零要重新等满 10 分钟
    public function test_food_zero_since_resets_when_food_returns(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('popzeroreset', ['H10' => 5], 500, 0.0);

        Carbon::setTestNow($base->copy()->addMinutes(6));
        SimulationService::simulate($city->fresh());
        $this->assertNotNull(DB::table('cities')->where('id', $city->id)->value('food_zero_since'));

        // 补一批粮食(高于 3 分钟消耗线 45)再结算 → 归零计时清空
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => 1000]);
        Carbon::setTestNow($base->copy()->addMinutes(7));
        SimulationService::simulate($city->fresh());

        $this->assertNull(DB::table('cities')->where('id', $city->id)->value('food_zero_since'));
        $this->assertSame(500, (int) DB::table('cities')->where('id', $city->id)->value('population'));
    }

    // 人口下限 5:长时间饥荒也不会跌破(§10.1「人口短缺损失不能使人口低于 5」)
    public function test_population_floor_is_five(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('popfloor', ['H10' => 5], 6, 0.0);

        // 12h 离线(封顶时长)= 24 段 × 30 分钟,几乎全程饥荒
        Carbon::setTestNow($base->copy()->addHours(12));
        SimulationService::simulate($city->fresh());

        $this->assertSame(5, (int) DB::table('cities')->where('id', $city->id)->value('population'));
    }

    // ---- 分段结算:人口变化会反过来改变下一段的粮耗 ----

    // 60 分钟 = 2 段 × 30 分钟:第二段的粮耗必须按"第一段结束时的人口"算,不是起始人口。
    // 同时锁住分段的时间一致性:一次结算 60 分钟 === 分两次各结算 30 分钟。
    public function test_segments_feed_population_growth_back_into_food_consumption(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');

        // 城 A:一次性结算 60 分钟(内部切成 2 段 × 30 分钟)
        Carbon::setTestNow($base);
        $cA = $this->makeCity('popseg-a', ['H10' => 5, 'F02' => 2], 500, 100.0);
        Carbon::setTestNow($base->copy()->addMinutes(60));
        SimulationService::simulate($cA->fresh());

        // 城 B:分两次各结算 30 分钟
        Carbon::setTestNow($base);
        $cB = $this->makeCity('popseg-b', ['H10' => 5, 'F02' => 2], 500, 100.0);
        Carbon::setTestNow($base->copy()->addMinutes(30));
        SimulationService::simulate($cB->fresh());
        Carbon::setTestNow($base->copy()->addMinutes(60));
        SimulationService::simulate($cB->fresh());

        $popA = (int) DB::table('cities')->where('id', $cA->id)->value('population');
        $popB = (int) DB::table('cities')->where('id', $cB->id)->value('population');
        // 允许 1 人误差:cities.population 是 INT,分两次结算时中间那次会把不足 1 人的零头 floor 掉,
        // 第二段就从一个略小的人口起算(A 段起 522.99 / B 段起 522)。段长一致 → 复利式一致,
        // 差的只有这一次取整。M2-C2 幸福接入后增长率被压低,两边的 floor 才开始分到相邻整数上
        $this->assertEqualsWithDelta($popB, $popA, 1.0, '一次 60 分钟 === 两次 30 分钟(段长一致 → 结果一致,容 1 人取整差)');
        // 粮食允许 1 点误差:cities.population 是 INT,分两次结算时中间那次会把人口 floor 掉不足 1 人的零头,
        // 第二段的粮耗因此少算 <= 1 人 × 0.03/min × 30min ≈ 0.9。人口结果不受影响(照样 floor 到同一个整数)。
        $this->assertEqualsWithDelta($this->foodOf($cB), $this->foodOf($cA), 1.0);

        // 人口确实涨了(住房 630、粮食净 +13/min,两个因子都是 1.0)
        $this->assertGreaterThan(500, $popA);

        // 关键:粮耗跟着人口走。若第二段仍按 500 人算,粮食终值应恰为 100 + (28 − 15)×60 = 880;
        // 实际人口在涨 → 吃得更多 → 必然严格小于 880(仓储上限 1000,没有被夹住)
        $this->assertLessThan(880.0, $this->foodOf($cA), '第二段粮耗必须按增长后的人口计算');
        $this->assertGreaterThan(0.0, $this->foodOf($cA));
    }

    // ---- §10.4 存档回填 ----

    // 老城(人口 10、建筑无工人):迁移后人口 30,工人按 building_id 排序补满且总数 <= 18
    public function test_save_migration_backfills_population_and_workers(): void
    {
        $u = User::create(['username' => 'oldsave', 'name' => 'oldsave', 'email' => 'os@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        // 还原成 M1 老存档的样子:人口 10、所有建筑 0 工人
        DB::table('cities')->where('id', $city->id)->update(['population' => 10]);
        $ids = [];
        foreach ([['F02', 1, 1], ['F02', 5, 1], ['A01', 9, 1], ['D01', 13, 1], ['P01', 17, 1]] as [$bid, $x, $y]) {
            $ids[] = CityBuildingInstance::create([
                'city_id' => $city->id, 'building_id' => $bid, 'level' => 1,
                'x' => $x, 'y' => $y, 'status' => 'active', 'assigned_workers' => 0,
            ])->id;
        }

        WorkerBackfill::run();

        $this->assertSame(30, (int) DB::table('cities')->where('id', $city->id)->value('population'), '人口 10 → 30');

        // 可用工人 = floor(30 × 0.60) = 18;按 building_id 排序补满:
        // A01(5) → 剩 13;D01(4) → 剩 9;F02(4) → 剩 5;F02(4) → 剩 1;P01 需 3 只剩 1 → 给 1
        $assigned = DB::table('city_building_instances as ci')
            ->where('ci.city_id', $city->id)->orderBy('ci.building_id')->orderBy('ci.id')
            ->pluck('assigned_workers', 'building_id');
        $total = (int) DB::table('city_building_instances')->where('city_id', $city->id)->sum('assigned_workers');

        $this->assertSame(18, $total, '总分配恰好用满 18 个名额,不得超过 available_workers');
        $this->assertSame(5, (int) $assigned['A01']);
        $this->assertSame(4, (int) $assigned['D01']);
        $this->assertSame(1, (int) $assigned['P01'], '名额用尽时最后一栋只补到剩余数');
        $this->assertSame(
            4,
            (int) DB::table('city_building_instances')->where('id', $ids[0])->value('assigned_workers'),
            '两座 F02 都补满 4 人'
        );
    }

    // 人口已高于 30 的城市不被下压
    public function test_save_migration_does_not_lower_existing_population(): void
    {
        $u = User::create(['username' => 'bigcity', 'name' => 'bigcity', 'email' => 'bc@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('cities')->where('id', $city->id)->update(['population' => 800]);

        WorkerBackfill::run();

        $this->assertSame(800, (int) DB::table('cities')->where('id', $city->id)->value('population'));
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

    private function foodOf(City $city): float
    {
        return (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->value('amount');
    }
}
