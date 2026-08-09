<?php

namespace Tests\Feature\City;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CityRuntimeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('cities', ['user_id', 'revision', 'last_simulated_at', 'money', 'population', 'map_width', 'map_height']));
        $this->assertTrue(Schema::hasColumns('city_resources', ['city_id', 'resource_id', 'amount']));
        $this->assertTrue(Schema::hasColumns('city_building_instances', ['city_id', 'building_id', 'level', 'x', 'y', 'status']));
    }
}
