<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // 需要 building_level_definition / resource_definition
    }

    public function test_snapshot_requires_auth(): void
    {
        $this->getJson('/api/city')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'error' => 'AUTH_REQUIRED']);
    }

    public function test_snapshot_returns_city_state(): void
    {
        $u = User::create(['username' => 'snapuser', 'name' => 'snapuser', 'email' => 'sn@s.com', 'password' => 'password123']);
        CityFactory::createForUser($u);

        $res = $this->actingAs($u)->getJson('/api/city');
        $res->assertOk();
        $res->assertJson(['success' => true, 'data' => ['city' => ['population' => 10, 'mapWidth' => 20]]]);
        $res->assertJsonStructure(['data' => ['city' => ['resources', 'ratesPerMin', 'storageCapacity', 'buildings']]]);
    }
}
