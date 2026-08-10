<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\CityResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // 需要 building_level_definition
    }

    private function makeCity(): City
    {
        $u = User::create(['username' => 'simuser', 'name' => 'simuser', 'email' => 's@s.com', 'password' => 'password123']);
        return CityFactory::createForUser($u);
    }

    public function test_farm_produces_food_over_time(): void
    {
        $city = $this->makeCity();
        // 放一座 F02 基础农田 L1(输出 粮食 14/min),active,工人补满(不补满 workerFactor 会打折)
        CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active', 'assigned_workers' => 4]);
        $foodBefore = (float) CityResource::where('city_id', $city->id)->where('resource_id', 'food')->value('amount');

        // 把 last_simulated_at 往前拨 60 秒,模拟经过 1 分钟
        $city->update(['last_simulated_at' => now()->subSeconds(60)]);
        SimulationService::simulate($city->fresh());

        $foodAfter = (float) CityResource::where('city_id', $city->id)->where('resource_id', 'food')->value('amount');
        // 1 分钟:+14 粮食产出 − 人口(30)×0.03×1=0.9 消耗 = 净 +13.1(未触顶前)
        // 新城无住宅 → populationCapacity=0 → housingFactor=0 → 人口不增长,粮耗整段恒定
        $this->assertEqualsWithDelta($foodBefore + 13.1, $foodAfter, 0.5);
    }

    public function test_food_never_below_zero(): void
    {
        $city = $this->makeCity();
        // 清空粮食,无产出建筑,人口消耗应把粮食夹在 0
        CityResource::where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => 0.5]);
        $city->update(['last_simulated_at' => now()->subSeconds(600)]);
        SimulationService::simulate($city->fresh());
        $food = (float) CityResource::where('city_id', $city->id)->where('resource_id', 'food')->value('amount');
        $this->assertGreaterThanOrEqual(0, $food);
    }
}
