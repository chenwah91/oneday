<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// npc_definition 增加 trait_multiplier 列(W11-B 任务4):NPC 特性的**强度倍率**。
//
// 为什么要这一列:§6.3 的特性效果全在 trait_json 的 specs 里,而 trait_json 是**结构列**,
// 后台不可编辑(改它要重新过 ModifierSpec 的三重 allowlist,手写 JSON 迟早写出一条
// target 拼错的配置 —— 那种配置在运行时只会「静默不生效」,是最难查的一类线上问题)。
// 于是「这个 NPC 的特性是不是太强了」这个**真实的运营需求**在后台没有任何入口。
//
// 倍率把「调强调弱」满足到位,同时把结构性改动挡在 Seed + 迁移那条有 diff、可回滚的路上 ——
// 与 event_definition.effect_multiplier 是同一条思路、同一个理由(见 EventDefinition 顶部注释)。
//
// 语义:该 NPC trait_json 里**每一条 spec** 的 value 统一乘它(pct 与 flat 同乘)。
//   1.0000 = 与搬来之前完全一致(默认值,全表 150 行落地即零行为变化);
//   2.0000 = 「治理容量 +10%」变成 +20%;0 = 该 NPC 的特性整体失效(但工资口粮照收)。
//
// 只乘 **NPC 来源**:同一个消费点里的工具(§7 effect_json)与事件(city_active_modifiers)
// 投稿一律不乘 —— 那两类各有自己的强度旋钮(item 的 effect_value、event 的 effect_multiplier),
// 在这里连坐会让「调 NPC」变成「顺手调了工具」。
//
// DECIMAL(10,4) 与 event_definition.effect_multiplier 同型;NOT NULL DEFAULT 1.0000
// 保证既有 150 行不需要回填(MySQL 5.7 / MariaDB 上加带默认值的 DECIMAL 列不重建表)。
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('npc_definition') || Schema::hasColumn('npc_definition', 'trait_multiplier')) {
            return;
        }

        Schema::table('npc_definition', function (Blueprint $table) {
            // 放在 trait_json 后面:后台列表里「特性说明 → 特性结构 → 特性倍率」挨着,一眼看得出它调的是谁
            $table->decimal('trait_multiplier', 10, 4)->default(1)->after('trait_json');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('npc_definition') || ! Schema::hasColumn('npc_definition', 'trait_multiplier')) {
            return;
        }

        Schema::table('npc_definition', function (Blueprint $table) {
            $table->dropColumn('trait_multiplier');
        });
    }
};
