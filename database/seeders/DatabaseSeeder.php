<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seeder 顺序 = 外键依赖顺序:时代 → 资源 → 科技 → 建筑 → 建筑等级 → 数值版本。
        // M3 各系统的定义 Seeder 一律追加在 GameDataVersionSeeder **之前**自己的锚点内
        // (数值版本是最后一步:它要对已落库的全部定义做校验 / 记账)。
        $this->call([
            EraSeeder::class,
            ResourceDefinitionSeeder::class,
            TechnologyDefinitionSeeder::class,
            BuildingDefinitionSeeder::class,
            BuildingLevelDefinitionSeeder::class,

            // ================= M3 共享文件锚点(D0.4,W1-A 一次性预置)=================
            //
            // 纪律(backlog §10.2):每个任务只在自己系统的锚点块内增删,
            // 禁止重排、禁止格式化他人行、禁止在锚点外改动。锚点是纯注释,预置本身零行为变化。

            // ---- M3-NPC ----(W2-A:npc_skill_definition / npc_skill_level_curve / npc_definition)
            NpcDefinitionSeeder::class,
            // ---- /M3-NPC ----

            // ---- M3-ITEM ----(W3-A:item_definition 24 行)
            ItemDefinitionSeeder::class,
            // ---- /M3-ITEM ----

            // ---- M3-MARKET ----(W1-B / W2-B:market_definition 26 行)
            MarketDefinitionSeeder::class,
            // ---- /M3-MARKET ----

            // ---- M3-EVENT ----(W3-B:event_definition 30 行)
            EventDefinitionSeeder::class,
            // ---- /M3-EVENT ----

            // ---- M3-POWER ----(W4-A:电力若需要独立定义 Seed)
            // ---- /M3-POWER ----

            // ---- M3-DEFENSE ----(W4-B:威胁需求数值表)
            // ---- /M3-DEFENSE ----

            // ================= M3 锚点结束 =================

            GameDataVersionSeeder::class,
        ]);
    }
}
