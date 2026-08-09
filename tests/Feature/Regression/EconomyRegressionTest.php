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

    private function cityWithFarm(string $un): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active']);
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
        $foodA0 = (float) CityResource::where('city_id', $cA->id)->where('resource_id', '粮食')->value('amount');
        Carbon::setTestNow($base->copy()->addSeconds(600));
        SimulationService::simulate($cA->fresh());
        $foodA1 = (float) CityResource::where('city_id', $cA->id)->where('resource_id', '粮食')->value('amount');

        // 城 B:分两段 300s + 300s 结算
        Carbon::setTestNow($base);
        $cB = $this->cityWithFarm('deltaB');
        $foodB0 = (float) CityResource::where('city_id', $cB->id)->where('resource_id', '粮食')->value('amount');
        Carbon::setTestNow($base->copy()->addSeconds(300));
        SimulationService::simulate($cB->fresh());
        Carbon::setTestNow($base->copy()->addSeconds(600));
        SimulationService::simulate($cB->fresh());
        $foodB1 = (float) CityResource::where('city_id', $cB->id)->where('resource_id', '粮食')->value('amount');

        $this->assertEqualsWithDelta($foodA1 - $foodA0, $foodB1 - $foodB0, 0.01, '600s 单次结算净变化应与 300+300 分段结算一致');

        // 同一 now 再结算一次(elapsed=0),应无变化(幂等)
        SimulationService::simulate($cB->fresh());
        $foodB2 = (float) CityResource::where('city_id', $cB->id)->where('resource_id', '粮食')->value('amount');
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
        // A01 max_count=1
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'A01', 'x' => 1, 'y' => 1])->assertOk();
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'A01', 'x' => 6, 'y' => 6])
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
        $foodBefore = (float) CityResource::where('city_id', $city->id)->where('resource_id', '粮食')->value('amount');
        $city->update(['last_simulated_at' => now()->subSeconds(600)]);
        SimulationService::simulate($city->fresh());
        $foodAfter = (float) CityResource::where('city_id', $city->id)->where('resource_id', '粮食')->value('amount');
        // 10 分钟:F02 产 14/min × 10 − 人口10×0.1×10 = 140 − 10 = 130(未触顶前;起始 300~500,+130 < 1000)
        $this->assertEqualsWithDelta($foodBefore + 130, $foodAfter, 0.5);
    }

    // ---- M1 缺陷 0.2:建造/升级/拆除必须在锁内先跑 Time Delta 结算 ----

    // 过期快照不能消费(资源侧):离线期间被吃掉的木材,不能拿来付建造费
    public function test_stale_snapshot_resource_cannot_be_spent(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);

        $u = User::create(['username' => 'staleres', 'name' => 'staleres', 'email' => 'staleres@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        // E02 木炭窑:每分钟吃 木材 6(净速率为负),城里没有产木材的建筑
        CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'E02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active']);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', '木材')->update(['amount' => 30]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', '石料')->update(['amount' => 1000]);
        DB::table('cities')->where('id', $city->id)->update(['money' => 1000]);

        // 10 分钟吃掉 60 木材,只有 30 → 结算后木材必然为 0
        Carbon::setTestNow($base->copy()->addMinutes(10));

        // 不先调快照,直接建造 F02(需木材 20):必须按结算后的 0 判定,而不是旧快照的 30
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 10, 'y' => 10])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);
        $this->assertDatabaseMissing('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F02']);

        // 建造失败时整个事务回滚(结算写入一并回滚,last_simulated_at 未推进,不会丢时间);
        // 再走一次只读快照结算,木材落到 0 —— 证明那 30 木材确实已被消耗殆尽、不可用于建造
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        $wood = (float) CityResource::where('city_id', $city->id)->where('resource_id', '木材')->value('amount');
        $this->assertSame(0.0, $wood, '结算后木材应为 0,而非旧快照的 30');
    }

    // 过期快照不能消费(资金侧):离线期间被维护费扣光的资金,不能拿来付建造费
    public function test_stale_snapshot_money_cannot_be_spent(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);

        $city = $this->cityWithFarm('stalemoney'); // 已有一座 F02(维护 资金 4/min)
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 1000]);
        DB::table('cities')->where('id', $city->id)->update(['money' => 30]);

        // 10 分钟维护费 40,只有 30 → 结算后资金必然为 0
        Carbon::setTestNow($base->copy()->addMinutes(10));

        // 建造 F02(需资金 12):必须按结算后的 0 判定,而不是旧的 cities.money=30
        $this->actingAs($city->user)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 10, 'y' => 10])
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
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', '木材')->update(['amount' => 1000]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', '石料')->update(['amount' => 1000]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', '粮食')->update(['amount' => 400]);
        DB::table('cities')->where('id', $city->id)->update(['money' => 1000]);

        // 前 10 分钟城里没有农田:只有人口吃粮 10×0.1×10 = 10,粮食 400 → 390
        Carbon::setTestNow($base->copy()->addMinutes(10));
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 2, 'y' => 2])->assertOk();

        $food = (float) CityResource::where('city_id', $city->id)->where('resource_id', '粮食')->value('amount');
        $this->assertEqualsWithDelta(390, $food, 0.01, '建造时应按"建造前的建筑集合"结算');

        // 同一时刻(经过 0 秒)再取快照:新农田不得倒补建成之前的 10 分钟产量
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        $foodAfterSnapshot = (float) CityResource::where('city_id', $city->id)->where('resource_id', '粮食')->value('amount');
        $this->assertEqualsWithDelta(390, $foodAfterSnapshot, 0.01, '新建筑不得追溯生产建成前的时段');
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

        $foodA = (float) CityResource::where('city_id', $cA->id)->where('resource_id', '粮食')->value('amount');
        $foodB = (float) CityResource::where('city_id', $cB->id)->where('resource_id', '粮食')->value('amount');
        $this->assertEqualsWithDelta($foodB, $foodA, 0.01, '48h 的产出应与 12h 相同');
        // 粮食净速率 = 14 − 139×0.1 = 0.1/min,12h(720min)产 72:400 → 472
        $this->assertEqualsWithDelta(472, $foodA, 0.01);

        $moneyA = (float) DB::table('cities')->where('id', $cA->id)->value('money');
        $moneyB = (float) DB::table('cities')->where('id', $cB->id)->value('money');
        $this->assertEqualsWithDelta($moneyB, $moneyA, 0.01, '48h 的维护扣款应与 12h 相同');
        // 维护 资金 4/min × 720min = 2880:100000 → 97120
        $this->assertEqualsWithDelta(97120, $moneyA, 0.01);

        // last_simulated_at 仍推进到 now(不是只推进封顶那 12h),否则未结算的时间会积压后被反复重算
        $lastA = Carbon::parse(DB::table('cities')->where('id', $cA->id)->value('last_simulated_at'));
        $this->assertSame($base->copy()->addHours(48)->format('Y-m-d H:i:s'), $lastA->format('Y-m-d H:i:s'));
    }

    // 封顶用例的统一初值:人口 139 让粮食净速率恰为 +0.1/min,12h 只产 72,不会触到 1000 仓储上限
    private function normalizeForCapTest(City $city): void
    {
        DB::table('cities')->where('id', $city->id)->update(['population' => 139, 'money' => 100000]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', '粮食')->update(['amount' => 400]);
    }
}
