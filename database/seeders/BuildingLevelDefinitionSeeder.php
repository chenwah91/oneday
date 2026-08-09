<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BuildingLevelDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/building_levels.json')), true);

        $insert = array_map(fn ($r) => [
            'building_id'               => $r['building_id'],
            'level'                     => $r['level'],
            'cost_type'                 => $r['cost_type'],
            'cost_json'                 => json_encode($r['cost'], JSON_UNESCAPED_UNICODE),
            'duration_seconds'          => $r['duration_seconds'],
            'worker_required'           => $r['worker_required'],
            'input_json'                => json_encode($r['input'], JSON_UNESCAPED_UNICODE),
            'output_json'               => json_encode($r['output'], JSON_UNESCAPED_UNICODE),
            'maintenance_money_per_min' => $r['maintenance']['money_per_min'],
            'maintenance_food_per_min'  => $r['maintenance']['food_per_min'],
            'maintenance_fuel_per_min'  => $r['maintenance']['fuel_per_min'],
            'power_per_min'             => $r['maintenance']['power_per_min'],
            'happiness_bonus'           => $r['happiness_bonus'],
            'governance_bonus'          => $r['governance_bonus'],
            'defense_score'             => $r['defense_score'],
            'capacity'                  => $r['capacity'],
        ], $rows);

        // 分块插入(282 行),避免单条 SQL 过长
        foreach (array_chunk($insert, 100) as $chunk) {
            DB::table('building_level_definition')->insert($chunk);
        }
    }
}
