<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BuildingDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/buildings.json')), true);

        // 名称→ID 映射,用于把"升级去向"(名称)解析为 building_id
        $nameToId = [];
        foreach ($rows as $r) {
            $nameToId[$r['name']] = $r['building_id'];
        }

        $insert = array_map(function ($r) use ($nameToId) {
            $upgradeName = $r['upgrade_to'] ?? '';
            $upgradeId = ($upgradeName !== '' && $upgradeName !== '终局' && isset($nameToId[$upgradeName]))
                ? $nameToId[$upgradeName] : null;

            return [
                'building_id'          => $r['building_id'],
                'era_key'              => $r['era'],
                'category'             => $r['category'],
                'series_key'           => $r['series'],
                'name'                 => $r['name'],
                'max_count'            => $r['max_count'],
                'footprint_w'          => $r['footprint_w'],
                'footprint_h'          => $r['footprint_h'],
                'base_workers'         => $r['base_workers'],
                'base_build_seconds'   => $r['base_build_seconds'],
                'tech_id'              => $r['tech_id'],
                'population_min'       => 0,
                'governance_ratio_min' => 0,
                'happiness_min'        => 0,
                'upgrade_to_building_id' => $upgradeId,
            ];
        }, $rows);

        DB::table('building_definition')->insert($insert);
    }
}
