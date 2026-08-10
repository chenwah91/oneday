<?php

namespace Tests\Feature\Regression;

use App\Game\City\CityFactory;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\CityResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v3.1 §15 M1 回归测试:Time-Delta 一致性 / 离线结算 / 建造上限 / 错误脱敏 / 粮食守恒
class EconomyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    // 每个用例结束都复位 Carbon 假时间,避免污染后续用例
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // 一座城 + 一座工人补满的 F02(F02 L1 需 4 人;不补满 workerFactor 会把产量打折)
    private function cityWithFarm(string $un): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active', 'assigned_workers' => 4]);
        return $city;
    }

    public function test_time_delta_single_vs_segmented_conserves(): void
    {
        // 说明:brief 原始写法是拿两座城(各自随机初始粮食)的结算后绝对值互相比较,
        // 但 CityFactory 建城时粮食是 random_int(300,500),两城起点不同,绝对值不可能相等。
        // 用 Carbon::setTestNow 精确控制"当前时间",分别让 A 城一次性结算 600s、
        // B 城分两段(300s+300s)结算,再比较两城各自的"净变化量"(而非绝对值),
        // 这样才是真正验证 §15 要求的 Time-Delta 一致性(与分段次数无关)。
        $base = Carbon::parse('2026-01-01 00:00:00');

        // 城 A:一次性结算 600s
        Carbon::setTestNow($base);
        $cA = $this->cityWithFarm('deltaA');
        $foodA0 = (float) CityResource::where('city_id', $cA->id)->where('resource_id', 'food')->value('amount');
        Carbon::setTestNow($base->copy()->addSeconds(600));
        SimulationService::simulate($cA->fresh());
        $foodA1 = (float) CityResource::where('city_id', $cA->id)->where('resource_id', 'food')->value('amount');

        // 城 B:分两段 300s + 300s 结算
        Carbon::setTestNow($base);
        $cB = $this->cityWithFarm('deltaB');
        $foodB0 = (float) CityResource::where('city_id', $cB->id)->where('resource_id', 'food')->value('amount');
        Carbon::setTestNow($base->copy()->addSeconds(300));
        SimulationService::simulate($cB->fresh());
        Carbon::setTestNow($base->copy()->addSeconds(600));
        SimulationService::simulate($cB->fresh());
        $foodB1 = (float) CityResource::where('city_id', $cB->id)->where('resource_id', 'food')->value('amount');

        $this->assertEqualsWithDelta($foodA1 - $foodA0, $foodB1 - $foodB0, 0.01, '600s 单次结算净变化应与 300+300 分段结算一致');

        // 同一 now 再结算一次(elapsed=0),应无变化(幂等)
        SimulationService::simulate($cB->fresh());
        $foodB2 = (float) CityResource::where('city_id', $cB->id)->where('resource_id', 'food')->value('amount');
        $this->assertEqualsWithDelta($foodB1, $foodB2, 0.01, '无经过时间再结算不变');
    }

    public function test_offline_8h_no_negative_and_capped(): void
    {
        $city = $this->cityWithFarm('offliner');
        $city->update(['last_simulated_at' => now()->subHours(8)]);
        SimulationService::simulate($city->fresh());

        foreach (CityResource::where('city_id', $city->id)->get() as $r) {
            $this->assertGreaterThanOrEqual(0, (float) $r->amount, '离线后资源不为负');
            $this->assertLessThanOrEqual(1000 + 0.01, (float) $r->amount, '资源被 BASE_STORAGE 夹住(无仓储建筑)');
        }
    }

    public function test_building_limit_reached(): void
    {
        $u = User::create(['username' => 'limiter', 'name' => 'limiter', 'email' => 'lim@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 100000]);
        // M2-B4 建造科技闸门排在数量上限之前(v3.2 §4),先铺好 A01 的前置科技,验的才是数量上限
        $this->unlockTechFor($city->id, 'A01');
        // A01 max_count=1
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'A01', 'x' => 1, 'y' => 1])->assertOk();
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'A01', 'x' => 6, 'y' => 6])
            ->assertStatus(422)->assertJson(['error' => 'BUILDING_LIMIT_REACHED']);
        $this->assertSame(1, DB::table('city_building_instances')->where('city_id', $city->id)->where('building_id', 'A01')->count());
    }

    public function test_error_response_has_no_secrets(): void
    {
        $res = $this->getJson('/api/_boom'); // testing-only 抛错路由(routes/web.php,仅 testing 环境注册)
        $body = $res->getContent();
        foreach (['password', 'APP_KEY', 'DB_PASSWORD', 'secret'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body);
        }
    }

    public function test_food_conservation_10min(): void
    {
        $city = $this->cityWithFarm('conserver');
        $foodBefore = (float) CityResource::where('city_id', $city->id)->where('resource_id', 'food')->value('amount');
        $city->update(['last_simulated_at' => now()->subSeconds(600)]);
        SimulationService::simulate($city->fresh());
        $foodAfter = (float) CityResource::where('city_id', $city->id)->where('resource_id', 'food')->value('amount');
        // 10 分钟:F02 产 14/min × 10 − 人口30×0.03×10 = 140 − 9 = 131(未触顶前;起始 300~500,+131 < 1000)
        // 新城无住宅 → populationCapacity=0 → housingFactor=0 → 人口不增长,10 分钟内粮耗恒定
        $this->assertEqualsWithDelta($foodBefore + 131, $foodAfter, 0.5);
    }

    // ---- M1 缺陷 0.2:建造/升级/拆除必须在锁内先跑 Time Delta 结算 ----

    // 过期快照不能消费(资源侧):离线期间被吃掉的木材,不能拿来付建造费
    public function test_stale_snapshot_resource_cannot_be_spent(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);

        $u = User::create(['username' => 'staleres', 'name' => 'staleres', 'email' => 'staleres@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        // E02 木炭窑:每分钟吃 木材 6(净速率为负),城里没有产木材的建筑;工人补满(L1 需 4 人)
        CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'E02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active', 'assigned_workers' => 4]);
        // T02 道路(运输容量 140):本城下面要被置为时代 II,而物流(M2-C4 / §10.7)自时代 II 起计需求。
        // E02 的运输需求 = 输入 6 + 输出 5 = 11,没路的话负载 11 → 物流率 0.25,木材 10 分钟只吃掉 15、吃不完;
        // 本用例验的是「过期快照不能消费」,不是物流,所以给它一条路让物流率回到 1.00(11/140 = 0.079)
        CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'T02', 'level' => 1, 'x' => 5, 'y' => 1, 'status' => 'active', 'assigned_workers' => 0]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->update(['amount' => 30]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'stone')->update(['amount' => 1000]);
        // era_order:F02 是时代 II 建筑,时代闸门(M2-B6)排在材料校验之前,不垫时代就验不到 INSUFFICIENT_RESOURCE
        DB::table('cities')->where('id', $city->id)->update(['money' => 1000, 'era_key' => 'II', 'era_order' => 2]);
        // 科技闸门(M2-B4)同样排在材料之前,一并铺好前置科技
        $this->unlockTechFor($city->id, 'F02');

        // 10 分钟吃掉 60 木材,只有 30 → 结算后木材必然为 0
        Carbon::setTestNow($base->copy()->addMinutes(10));

        // 不先调快照,直接建造 F02(需木材 20):必须按结算后的 0 判定,而不是旧快照的 30
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 10, 'y' => 10])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);
        $this->assertDatabaseMissing('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F02']);

        // 建造失败时整个事务回滚(结算写入一并回滚,last_simulated_at 未推进,不会丢时间);
        // 再走一次只读快照结算,木材落到 0 —— 证明那 30 木材确实已被消耗殆尽、不可用于建造
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        $wood = (float) CityResource::where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $this->assertSame(0.0, $wood, '结算后木材应为 0,而非旧快照的 30');
    }

    // 过期快照不能消费(资金侧):离线期间被维护费扣光的资金,不能拿来付建造费
    public function test_stale_snapshot_money_cannot_be_spent(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);

        $city = $this->cityWithFarm('stalemoney'); // 已有一座 F02(维护 资金 4/min)
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 1000]);
        // era_order:F02 是时代 II 建筑,时代闸门(M2-B6)排在材料校验之前,不垫时代就验不到 INSUFFICIENT_RESOURCE
        DB::table('cities')->where('id', $city->id)->update(['money' => 30, 'era_key' => 'II', 'era_order' => 2]);
        // 科技闸门(M2-B4)同样排在材料之前,一并铺好前置科技
        $this->unlockTechFor($city->id, 'F02');

        // 10 分钟维护费 40,只有 30 → 结算后资金必然为 0
        Carbon::setTestNow($base->copy()->addMinutes(10));

        // 建造 F02(需资金 12):必须按结算后的 0 判定,而不是旧的 cities.money=30
        $this->actingAs($city->user)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 10, 'y' => 10])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);
        $this->assertSame(1, DB::table('city_building_instances')->where('city_id', $city->id)->count());

        $this->actingAs($city->user)->getJson('/api/city')->assertOk();
        $money = (float) DB::table('cities')->where('id', $city->id)->value('money');
        $this->assertSame(0.0, $money, '结算后资金应为 0,而非旧快照的 30');
    }

    // 建造不追溯:新建筑不得为建成之前的时段补产
    public function test_build_does_not_produce_retroactively(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);

        $u = User::create(['username' => 'noretro', 'name' => 'noretro', 'email' => 'noretro@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->update(['amount' => 1000]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'stone')->update(['amount' => 1000]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => 400]);
        // era_order:F02 是时代 II 建筑,时代闸门(M2-B6)会挡下时代 I 的城,这里要的是建造成功
        DB::table('cities')->where('id', $city->id)->update(['money' => 1000, 'era_key' => 'II', 'era_order' => 2]);
        // 科技闸门(M2-B4)同样要过,这里要的是建造成功
        $this->unlockTechFor($city->id, 'F02');

        // 前 10 分钟城里没有农田:只有人口吃粮 30×0.03×10 = 9,粮食 400 → 391
        Carbon::setTestNow($base->copy()->addMinutes(10));
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2])->assertOk();

        $food = (float) CityResource::where('city_id', $city->id)->where('resource_id', 'food')->value('amount');
        $this->assertEqualsWithDelta(391, $food, 0.01, '建造时应按"建造前的建筑集合"结算');

        // 给新农田补满工人(否则 workerFactor=0,"不追溯"会被"本来就不产"掩盖,断言失去意义)。
        // 同一时刻分配,经过 0 秒 → 不产生任何产出
        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instance_id' => $instanceId, 'workers' => 4])->assertOk();

        // 同一时刻(经过 0 秒)再取快照:新农田不得倒补建成之前的 10 分钟产量
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        $foodAfterSnapshot = (float) CityResource::where('city_id', $city->id)->where('resource_id', 'food')->value('amount');
        $this->assertEqualsWithDelta(391, $foodAfterSnapshot, 0.01, '新建筑不得追溯生产建成前的时段');
    }

    // ---- M1 缺陷 0.3:离线结算时长封顶 12h ----

    // 同配置的两座城:离线 48h 与 12h 的结算结果必须完全相同(48h 被封到 12h)
    public function test_offline_settlement_is_capped(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');

        Carbon::setTestNow($base);
        $cA = $this->cityWithFarm('cap48');
        $this->normalizeForCapTest($cA);
        Carbon::setTestNow($base->copy()->addHours(48));
        $simA = SimulationService::simulate($cA->fresh());

        Carbon::setTestNow($base);
        $cB = $this->cityWithFarm('cap12');
        $this->normalizeForCapTest($cB);
        Carbon::setTestNow($base->copy()->addHours(12));
        $simB = SimulationService::simulate($cB->fresh());

        $this->assertSame(43200, $simA['elapsedSeconds'], '48h 离线应被封顶到 12h');
        $this->assertSame(43200, $simB['elapsedSeconds']);

        $foodA = (float) CityResource::where('city_id', $cA->id)->where('resource_id', 'food')->value('amount');
        $foodB = (float) CityResource::where('city_id', $cB->id)->where('resource_id', 'food')->value('amount');
        $this->assertEqualsWithDelta($foodB, $foodA, 0.01, '48h 的产出应与 12h 相同');
        // 粮食净速率 = 14 − 450×0.03(=13.5) = 0.5/min,12h(720min)产 360:400 → 760
        $this->assertEqualsWithDelta(760, $foodA, 0.01);

        $moneyA = (float) DB::table('cities')->where('id', $cA->id)->value('money');
        $moneyB = (float) DB::table('cities')->where('id', $cB->id)->value('money');
        $this->assertEqualsWithDelta($moneyB, $moneyA, 0.01, '48h 的维护扣款应与 12h 相同');
        // 维护 资金 4/min × 720min = 2880;
        // 税收(§10.5)= 人口 450 × 0.02 × 治理效率 0.5(无治理建筑 → 容量 0 → 负载 450 > 1.25)= 4.5/min × 720 = 3240
        // (人口容量 0 → housingFactor 0 → 24 段人口恒为 450,税收速率整段不变)
        // 100000 − 2880 + 3240 = 100360
        $this->assertEqualsWithDelta(100360, $moneyA, 0.01);

        // last_simulated_at 仍推进到 now(不是只推进封顶那 12h),否则未结算的时间会积压后被反复重算
        $lastA = Carbon::parse(DB::table('cities')->where('id', $cA->id)->value('last_simulated_at'));
        $this->assertSame($base->copy()->addHours(48)->format('Y-m-d H:i:s'), $lastA->format('Y-m-d H:i:s'));
    }

    // 封顶用例的统一初值:人口 450 让粮食净速率恰为 +0.5/min(14 − 450×0.03),
    // 12h 只产 360,加上起始 400 = 760,不会触到 1000 仓储上限
    private function normalizeForCapTest(City $city): void
    {
        DB::table('cities')->where('id', $city->id)->update(['population' => 450, 'money' => 100000]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => 400]);
    }

    // ---- M1 缺陷:加工建筑缺料照样出货(凭空造成品)→ 保守库存满足率 ----
    //
    // 主用例建筑 P01 磨坊 L1:投入 粮食 10/min、产出 面粉 8/min、维护 资金 2/min。
    // 为让断言精确,统一把人口设为 0(排除人口吃粮),并保证城里只有磨坊。

    // 缺料:粮食为 0 时不得产出任何面粉,粮食不为负,但维护费照扣
    public function test_processing_without_input_produces_nothing(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->cityWithMills('millzero', 1, 0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        $this->assertSame(0.0, $this->amountOf($city, 'flour'), '缺料时不得凭空造出面粉');
        $this->assertSame(0.0, $this->amountOf($city, 'food'), '粮食停在 0,不为负');
        // 维护 资金 2/min × 10min = 20:建筑闲置也照付维护
        $this->assertEqualsWithDelta(10000 - 20, $this->moneyOf($city), 0.01, '维护资金不受满足率影响');
    }

    // 半料:库存 50、需求 100 → 满足率 0.5,产出同比例打折,原料恰好耗尽
    public function test_processing_partial_input_scales_output(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->cityWithMills('millhalf', 1, 50);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        // recipeRate = 50/100 = 0.5 → 面粉 8×0.5×10 = 40,粮食 10×0.5×10 = 50 全部吃光
        $this->assertEqualsWithDelta(40, $this->amountOf($city, 'flour'), 0.01, '面粉应按满足率 0.5 打折');
        $this->assertEqualsWithDelta(0, $this->amountOf($city, 'food'), 0.01, '粮食恰好耗尽');
    }

    // 多栋共享同一原料:需求经 demand 汇总,总消耗不得超过库存
    public function test_processing_multiple_buildings_share_stock(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->cityWithMills('millpair', 2, 100);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        // 总需求 2×10×10 = 200,库存 100 → 每栋 recipeRate = 0.5
        // 面粉合计 2×8×0.5×10 = 80;粮食合计消耗 2×10×0.5×10 = 100,恰好耗尽且绝不超扣
        $this->assertEqualsWithDelta(80, $this->amountOf($city, 'flour'), 0.01, '两栋磨坊合计产 80 面粉');
        $this->assertEqualsWithDelta(0, $this->amountOf($city, 'food'), 0.01, '共享库存被恰好耗尽,不超扣');
    }

    // 料充足:满足率为 1,数值与"未打折"的正确路径完全一致
    public function test_processing_with_enough_input_is_unaffected(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->cityWithMills('millfull', 1, 1000);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(80, $this->amountOf($city, 'flour'), 0.01, '料足时面粉 8/min × 10min');
        $this->assertEqualsWithDelta(900, $this->amountOf($city, 'food'), 0.01, '料足时粮食 10/min × 10min');
    }

    // 加工建筑用例的统一初值:只摆 N 座 P01 磨坊,人口 0(排除人口吃粮),资金 10000,粮食指定
    private function cityWithMills(string $un, int $count, float $food): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete(); // 确保城里只有磨坊
        for ($i = 0; $i < $count; $i++) {
            // 工人补满(P01 L1 需 3 人):本组用例验的是原料满足率,workerFactor 必须恒为 1.0
            CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'P01', 'level' => 1, 'x' => 1 + $i * 4, 'y' => 1, 'status' => 'active', 'assigned_workers' => 3]);
        }
        DB::table('cities')->where('id', $city->id)->update(['population' => 0, 'money' => 10000]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => $food]);
        return $city;
    }

    // 读资源现值(行不存在时按 0)
    private function amountOf(City $city, string $resourceId): float
    {
        return (float) (CityResource::where('city_id', $city->id)->where('resource_id', $resourceId)->value('amount') ?? 0);
    }

    private function moneyOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('money');
    }
}
