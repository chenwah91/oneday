<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TechnologyDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/technologies.json')), true);
        DB::table('technology_definition')->insert(array_map(fn ($r) => [
            'tech_id'               => $r['tech_id'],
            'era_key'               => $r['era'],
            'branch'                => $r['branch'],
            'name'                  => $r['name'],
            'knowledge_cost'        => $r['knowledge_cost'],
            'research_minutes'      => $r['research_minutes'],
            'prerequisite_tech_ids' => json_encode($r['prerequisite_tech_ids'], JSON_UNESCAPED_UNICODE),
            'unlock_building_ids'   => json_encode($r['unlock_building_ids'], JSON_UNESCAPED_UNICODE),
        ], $rows));
    }
}
