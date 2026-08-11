<?php

use Database\Seeders\ItemDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 工具 / 道具定义表(M3-D2,v3.2 §7 的 24 行)。
//
// 与 city_items(运行时)严格分离(CLAUDE §12):这里回答「什么是 IT017 工业工程师工具」,
// 运行时表回答「某玩家城里那件剩 43 点耐久、装在 3 号机械厂上的 IT017」。
// 耐久上限 / 效果 / 成本一律回定义表读,绝不冗余到运行时行 —— 后台调完数值,
// 已经在城里的工具也要按新定义结算(只有「剩余耐久」是运行时状态)。
//
// MySQL 5.7 兼容:不用 ENUM(改枚举值要 ALTER 重建整表,枚举语义交给 App\Game\Item\ItemCode)、
// 不用 CHECK、不用生成列 / 函数索引;比率与钱数一律 DECIMAL,不用 float。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_definition', function (Blueprint $table) {
            $table->string('item_id', 16)->primary();
            $table->string('name_key', 64);
            // §7 的 category(gathering_tool / mining_tool / …):**同一建筑内同 category 只取最高值**
            // 就是按这一列分组的(§7 明文,防止玩家堆一堆低级工具)
            $table->string('category', 32);
            $table->string('min_era', 4);
            // §7 的 equip_target_desc_zh 原文(伐木工 / 猎人 / 机械师…)。
            // 它描述的是「谁用这件工具」,是显示文案;**装备落点是建筑实例**
            //(§7「单建筑同类加成只取最高值」+ backlog §4.1 / B2「单建筑装备槽位数」都按建筑计)
            $table->string('equip_target_desc_zh', 64);

            // 耐久上限(点)。运行时的剩余耐久在 city_items.durability_left
            $table->unsignedInteger('durability');
            // 耐久档位 normal / industrial(ItemCode::TIER_*,B1 已批的划分)。
            // 每档「多少分钟扣 1 点」是运营参数,在 game_settings 里,不写死在这一行
            $table->string('durability_tier', 16);
            // 耐久口径 work_minutes / uses(ItemCode::DURABILITY_MODE_*)
            $table->string('durability_mode', 16);

            // §7 的效果三列原文:code / 数值 / 单位(percent | flat)。保留原文是为了后台一眼能对上文档
            $table->string('effect_code', 48);
            $table->decimal('effect_value', 10, 4);
            $table->string('unit', 16);
            // 效果的结构化表达(backlog §9 B3):{"specs":[{target,scope,op,value,scope_key}],"unmapped_zh":[…]}。
            // longText 而不是 json 列:5.7 的 json 列不能建函数索引,查询也全在 PHP 侧做,
            // 与 npc_definition.trait_json / building_level_definition.output_json 保持同一种存法
            $table->longText('effect_json')->nullable();

            // 制作成本 {资源 code: 数量}(§7 的 wood/stone/copper/bronze/iron/steel/electronic_components/money 八列)。
            // 存成 JSON 而不是八个列:§7 的成本矩阵极稀疏(24 行 × 8 列里 3/4 是 0),
            // 而且将来加一种材料就要 ALTER 一次表(5.7 无 INSTANT,见 backlog §11.4)
            $table->longText('craft_cost_json');

            // §7 的 crafting_source_desc_zh 原文 + 能精确对上的 94 栋建筑之一
            $table->string('crafting_source_desc_zh', 64);
            // NULL 有两种含义,靠下面的 crafting_unmapped_zh 区分:
            //   两列都空 → §7 明文的「手工制作」,不需要任何建筑;
            //   本列空而 unmapped 有值 → §7 点名的建筑在 94 栋里不存在,**不发明映射**,
            //     当前按「无建筑前置」放行,等建筑补齐或用户裁决后再回填(见交付汇报的对照表)
            $table->string('crafting_building_id', 16)->nullable();
            $table->string('crafting_unmapped_zh', 64)->nullable();

            // §7 的 trade_value。B5 已批:M3 **不做工具交易**,本列仅作将来「拆解返还」的基数
            $table->decimal('trade_value', 14, 4);
            // §7 的 note_zh:仅供后台显示,不参与计算
            $table->string('note', 191)->nullable();

            // 制作面板按「时代 + 分类」取候选
            $table->index(['min_era', 'category'], 'idx_item_def_era_category');
            // 「这栋建筑能做哪些工具」
            $table->index('crafting_building_id', 'idx_item_def_crafting_building');

            $table->foreign('min_era')->references('era_key')->on('era');
            // crafting_building_id → building_definition:定义表之间的引用完整性由 DDL 兜住,
            // 拼错一个建筑 code 会当场报错而不是「这件工具永远做不出来」
            $table->foreign('crafting_building_id')->references('building_id')->on('building_definition');
        });

        // 定义数据随迁移落库(而不是只放 Seeder)。
        //
        // 理由与 NPC(400001)/ 市场(500001)逐字相同:定义 Seeder 只在 `migrate:fresh --seed` 的
        // 全新库上跑,已有数据的库(开发 apg / 线上)跑完迁移后这张表会是**空的** ——
        // 制作永远返回 NOT_FOUND、tool 乘区永远 1.0,而版本 bump 也会因为「表是空的」而跳过,
        // 结果是「迁移全绿、功能全死、版本号还查不出来」这种最难排查的半上线状态。
        //
        // 幂等:表非空就完全不动(重跑迁移 / 已被后台改过数值的库都不会被覆盖);
        // 全新库上这里灌完之后,DatabaseSeeder 里的 ItemDefinitionSeeder 走 upsert,是无害的重刷。
        //
        // 但**只在已有数据的库上灌**:min_era 与 crafting_building_id 都有外键,
        // 全新库在 migrate 阶段 era / building_definition 还是空表(它们由 Seeder 填),
        // 这时候插进去必然违反外键。判据就是 era 有没有行 —— 与 400001 同一套路。
        if (! DB::table('era')->exists()) {
            return;
        }

        if (! DB::table('item_definition')->exists()) {
            DB::table('item_definition')->insert(ItemDefinitionSeeder::rows());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_definition');
    }
};
