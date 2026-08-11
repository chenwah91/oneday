<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// npc_definition 增加 name_zh 列(M3-D1 收尾:NPC 池 30 → 150)。
//
// 为什么要这一列:150 条原型里有 120 条是扩充草案带进来的,草案已经给出了逐条中文名
// (name_zh,已校验零重名)。没有这一列,前端只能拿 name_key(npc.N087.name)当人名显示,
// 后台在 150 行里也认不出谁是谁。
//
// 为什么可空:N001~N030 的中文名尚待项目负责人拟定并批准,这一波**不编名字** ——
// 服务端编出来的占位名会被当成正式名传播出去,再改就是「改名」而不是「起名」了。
// 契约上 null 的含义是明确的:前端回落 name_key(NpcService::toContract 原样下发 null)。
//
// 单条 ALTER:MySQL 5.7 / MariaDB 上加一个可空 varchar 是 in-place 操作,不重建表。
// 列宽 64 与 name_key 同宽 —— 中文名最长的是「卫长城」这类三到四字,64 字节远够用。
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('npc_definition') || Schema::hasColumn('npc_definition', 'name_zh')) {
            return;
        }

        Schema::table('npc_definition', function (Blueprint $table) {
            $table->string('name_zh', 64)->nullable()->after('name_key');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('npc_definition') || ! Schema::hasColumn('npc_definition', 'name_zh')) {
            return;
        }

        Schema::table('npc_definition', function (Blueprint $table) {
            $table->dropColumn('name_zh');
        });
    }
};
