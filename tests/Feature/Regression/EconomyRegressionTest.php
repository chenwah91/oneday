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
}
