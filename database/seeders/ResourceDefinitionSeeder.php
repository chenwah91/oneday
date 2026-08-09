<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ResourceDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/resources.json')), true);
        DB::table('resource_definition')->insert(array_map(fn ($r) => [
            // resource_id 为英文 code(见 docs/templates/resource-code-map.md),name 保留中文显示名
            'resource_id'              => $r['resource_id'],
            'name'                     => $r['name'],
            'rs_code'                  => $r['rs_code'] ?? null,
            'category'                 => $r['category'],
            'first_era'                => $r['first_era'],
            'is_population_consumable' => $r['is_population_consumable'] ? 1 : 0,
            'is_strategic'             => $r['is_strategic'] ? 1 : 0,
        ], $rows));
    }
}
