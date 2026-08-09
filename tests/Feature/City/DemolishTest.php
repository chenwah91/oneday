<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_demolish_own_building(): void
    {
        $u = User::create(['username' => 'razer', 'name' => 'razer', 'email' => 'r@z.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;

        $this->actingAs($u)->postJson('/api/city/demolish', ['instanceId' => $id])->assertOk();
        $this->assertDatabaseMissing('city_building_instances', ['id' => $id]);
        $this->assertSame('BUILDING.DEMOLISH', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_cannot_demolish_others_building(): void
    {
        $ua = User::create(['username' => 'da', 'name' => 'da', 'email' => 'da@x.com', 'password' => 'password123']);
        $ca = CityFactory::createForUser($ua);
        $id = CityBuildingInstance::create(['city_id' => $ca->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;
        $ub = User::create(['username' => 'db', 'name' => 'db', 'email' => 'db@x.com', 'password' => 'password123']);
        CityFactory::createForUser($ub);

        $this->actingAs($ub)->postJson('/api/city/demolish', ['instanceId' => $id])->assertStatus(403);
        $this->assertDatabaseHas('city_building_instances', ['id' => $id]);
    }
}
