<?php

namespace Tests\Feature\City;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefinitionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_lists_buildable_buildings(): void
    {
        $u = User::create(['username' => 'defviewer', 'name' => 'defviewer', 'email' => 'd@v.com', 'password' => 'password123']);
        $res = $this->actingAs($u)->getJson('/api/definitions/buildings');
        $res->assertOk();
        $res->assertJsonStructure(['data' => ['buildings' => [['buildingId', 'name', 'footprint' => ['w', 'h'], 'level1' => ['cost']]]]]);
        // 94 座
        $this->assertCount(94, $res->json('data.buildings'));
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/definitions/buildings')->assertStatus(401);
    }
}
