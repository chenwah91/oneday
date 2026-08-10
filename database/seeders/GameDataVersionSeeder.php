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

        // 已有数据的库由 2026_08_10_400001 迁移递增到同一版本
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.1.3'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => '枚举值英文化:building category/series/cost_type/resource category/tech branch → 英文 code(v3.2 §0.2 第二批)',
            ]
        );

        // 次版本递增(数据形状变化):已有数据的库由 2026_08_11_100002 迁移递增到同一版本
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.2.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'RESOURCE_SOURCE_MAPPING(黏土/砂石/水泥/药品补链)+ BUILDING_UPGRADE_REMAP(6 条跨代升级链重映射)',
            ]
        );

        // 定义表列形状变化(删三列双口径):已有数据的库由 2026_08_11_200001 迁移递增到同一版本。
        // ⚠️ 本方法内的插入顺序 = 版本号升序,新增版本一律追加在**末尾**:
        // GameDataVersion::current() 取的是 id 最大的一行,插反了会让「当前版本」回退到旧版本号
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.2.1'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => '删除 building_level_definition 的 happiness_bonus / governance_bonus / defense_score 三列双口径(单一来源 output_json)',
            ]
        );
    }
}
