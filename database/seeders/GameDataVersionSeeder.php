<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GameDataVersionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.1.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M1 初始数值:10时代/31资源/94建筑/282等级/50科技',
            ]
        );

        // 全新库直接写到当前 seed 数据对应的版本;
        // 已有数据的库由 2026_08_10_200002 迁移末尾的 GameDataVersion::bump 递增到同一版本
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.1.2'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => '资源ID英文化(中文名保留为显示名)+ 人均粮耗 0.1→0.03(v3.1 §10.1)',
            ]
        );
    }
}
