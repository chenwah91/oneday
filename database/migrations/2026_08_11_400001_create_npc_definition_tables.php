<?php

use Database\Seeders\NpcDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NPC 定义层三张表(M3-D1,v3.2 §6.1 / §6.2 / §6.3)。
//
// 与 city_npcs(运行时)严格分离(CLAUDE §12):这里回答「什么是 N012 铁匠」,
// 运行时表回答「某玩家城里那个 3 级、士气 62 的 N012」。工资 / 口粮 / 初始技能一律回定义表读,
// 绝不冗余到运行时表 —— 后台调完数值,已经在城里的 NPC 也要按新定义结算。
//
// MySQL 5.7 兼容:不用 ENUM(改枚举值要 ALTER 重建整表,枚举语义交给 App\Game\NPC\NpcCode)、
// 不用 CHECK、不用生成列 / 函数索引;所有比率与钱数一律 DECIMAL,不用 float。
return new class extends Migration
{
    public function up(): void
    {
        // §6.1 通用技能 12 条
        Schema::create('npc_skill_definition', function (Blueprint $table) {
            $table->string('skill_id', 32)->primary();
            $table->string('name_key', 64);
            // 对齐 App\Game\Modifier\ModifierTarget 的 target 名单(七乘区 / flat 通道 / 消费点)。
            // 允许 NULL:§6.1 里医疗与物流两条技能的效果(医疗容量、运输容量)在 D0.3 里
            // 还没有登记消费点,与其在这里编一个不存在的 target,不如留空等对应波次登记
            $table->string('effect_target', 64)->nullable();
            $table->string('effect_desc_zh', 191);
        });

        // §6.2 技能等级曲线 10 行(1~10 级)
        Schema::create('npc_skill_level_curve', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->primary();
            // 到下一级所需 XP(**增量**,不是累计);10 级为 0 = 满级
            $table->unsignedInteger('xp_to_next');
            // 主技能效率加成:0.0350 = +3.5%。DECIMAL 不用 float —— 乘区参与经济结算,浮点误差会被放大
            $table->decimal('primary_bonus', 6, 4);
            // 相关维护减免上限(§6.2),消费点 maintenance_cost_pct 由 W3-A 接线,这里先落数据
            $table->decimal('maintenance_reduction_cap', 6, 4);
        });

        // §6.3 NPC 原型 30 行
        Schema::create('npc_definition', function (Blueprint $table) {
            $table->string('npc_id', 16)->primary();
            $table->string('name_key', 64);
            $table->string('category', 32);
            $table->string('min_era', 4);
            $table->string('primary_skill_id', 32);
            $table->unsignedSmallInteger('initial_skill_value');
            $table->unsignedTinyInteger('initial_skill_level');
            $table->unsignedTinyInteger('max_level');
            $table->decimal('wage_per_min', 10, 2);
            $table->decimal('food_per_min', 8, 3);
            $table->string('rarity', 16);
            // 获取来源:驱动规则的四个英文 code(NpcCode::SOURCES);§6.3 的自然语言原文留在 recruit_desc_zh
            $table->string('recruit_source', 32);
            $table->string('recruit_desc_zh', 191);
            $table->string('trait_desc_zh', 191);
            // 特性的结构化表达(backlog §9 A2):{"specs":[{target,scope,op,value,scope_key}],"unmapped_zh":[…]}。
            // longText 而不是 json 列:5.7 的 json 列不能建函数索引,查询也全在 PHP 侧做,
            // 与 building_level_definition.output_json 保持同一种存法
            $table->longText('trait_json')->nullable();

            $table->index(['min_era', 'rarity'], 'idx_npc_def_era_rarity');
            $table->index('recruit_source', 'idx_npc_def_source');

            $table->foreign('min_era')->references('era_key')->on('era');
            $table->foreign('primary_skill_id')->references('skill_id')->on('npc_skill_definition');
        });

        // 定义数据随迁移落库(而不是只放 Seeder)。
        //
        // 理由:定义 Seeder 只在 `migrate:fresh --seed` 的全新库上跑,已有数据的库(开发 apg / 线上)
        // 跑完迁移后这三张表会是**空的** —— 招募永远返回 NPC_NOT_AVAILABLE、乘区永远 1.0,
        // 而 2026_08_11_400004 的版本 bump 也会因为「表是空的」而跳过,
        // 结果是「迁移全绿、功能全死、版本号还查不出来」这种最难排查的半上线状态。
        // 与 2026_08_10_500001(game_settings 随迁移灌行)同一条理由:跑过迁移的库必须能直接用。
        //
        // 幂等:表非空就完全不动(重跑迁移 / 已被后台改过数值的库都不会被覆盖);
        // 全新库上这里灌完之后,DatabaseSeeder 里的 NpcDefinitionSeeder 走 upsert,是无害的重刷。
        //
        // 但**只在已有数据的库上灌**:npc_definition.min_era 有外键指向 era.era_key,
        // 全新库在 migrate 阶段 era 还是空表(它由 EraSeeder 填),这时候插进去必然违反外键。
        // 所以判据就是 era 有没有行 —— 有行 = 已有数据的库(线上 / 开发 apg),要就地补齐;
        // 没行 = 全新库,数据交给紧随其后的 DatabaseSeeder,顺序天然正确。
        //
        // 顺序 = 外键依赖顺序:技能 → 曲线 → 原型(npc_definition 的 primary_skill_id 指向技能表)。
        if (! DB::table('era')->exists()) {
            return;
        }

        $data = json_decode(file_get_contents(database_path('data/npcs.json')), true);

        if (! DB::table('npc_skill_definition')->exists()) {
            DB::table('npc_skill_definition')->insert(NpcDefinitionSeeder::skillRows($data['skills']));
        }
        if (! DB::table('npc_skill_level_curve')->exists()) {
            DB::table('npc_skill_level_curve')->insert(NpcDefinitionSeeder::curveRows($data['level_curve']));
        }
        if (! DB::table('npc_definition')->exists()) {
            DB::table('npc_definition')->insert(NpcDefinitionSeeder::npcRows($data['npcs'], $data['skills']));
        }
    }

    public function down(): void
    {
        // 顺序 = 外键依赖的反向:先删引用方,再删被引用方
        Schema::dropIfExists('npc_definition');
        Schema::dropIfExists('npc_skill_level_curve');
        Schema::dropIfExists('npc_skill_definition');
    }
};
