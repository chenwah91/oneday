<?php

namespace Tests\Feature\Definition;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DefinitionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_definition_tables_exist(): void
    {
        foreach (['era', 'resource_definition', 'technology_definition', 'building_definition', 'building_level_definition', 'game_data_versions'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "缺表 $t");
        }
        $this->assertTrue(Schema::hasColumns('building_definition', ['building_id', 'era_key', 'footprint_w', 'footprint_h', 'tech_id']));
        $this->assertTrue(Schema::hasColumns('building_level_definition', ['building_id', 'level', 'cost_json', 'output_json', 'capacity']));
    }
}
