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

        // M3-D1 NPC 定义层落地(新增三张定义表,进 checksum 表清单)。
        // 已有数据的库由 2026_08_11_400004 迁移递增到同一版本。
        // 位置:必须排在下面 V3.3.1 市场那段**之前**(版本号严格升序,见下段的说明)。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.3.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-D1 NPC 定义层:12 条技能 + 10 级曲线 + 30 个 NPC 原型(v3.2 §6.1 / §6.2 / §6.3)',
            ]
        );

        // M3-D3 市场定义表落地(新增 market_definition 26 行,进 checksum 表清单)。
        // 已有数据的库由 2026_08_11_500003 迁移递增到同一版本。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        //    M3-D1 NPC 的 V3.3.0 应排在本行**之前** —— 两个 agent 并行落地时若 NPC 后到,
        //    合并的人要把 V3.3.0 那段挪到这一段上面,不能直接追加在下面。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.3.1'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-D3 市场定义层:market_definition 26 行(v3.2 §8 全表)+ base_liquidity 模型(9.C1)',
            ]
        );

        // M3-D2 工具定义层落地(新增 item_definition 24 行,进 checksum 表清单)
        // + RS027 水泥 / RS028 药品上市(market_definition 26 → 28 行,既有定义表的行集变了 → 吃次版本位)。
        // 已有数据的库由 2026_08_11_600005 迁移递增到同一版本。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.4.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-D2 工具定义层:item_definition 24 行(v3.2 §7)+ RS027 水泥 / RS028 药品上市(资源来源映射草案 §7)',
            ]
        );

        // M3-D4 事件定义层落地(新增 event_definition 30 行,进 checksum 表清单)。
        // 已有数据的库由 2026_08_11_700003 迁移递增到同一版本。
        // 为什么是**补丁位**:既有定义表一行一列都没动,所有城市的产出 / 成本 / 升级链完全不变;
        // 但 checksum 表清单多了一张表,指纹随之改变,所以仍要留一个版本号。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.4.1'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-D4 事件定义层:event_definition 30 行(v3.2 §9.2 全表)+ 条件/效果 DSL 结构化(9.D1)',
            ]
        );
    }
}
