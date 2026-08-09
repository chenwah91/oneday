<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Game Data Version 贯通(§64 / §65):
//   cities.game_data_version     = 这座城「以哪一版数值开局」
//   audit_logs.game_data_version = 这条审计发生时线上跑的是哪一版数值
// 两列都可空:本迁移之前建的城 / 写的审计一律留 NULL,不做回填臆测(回填等于伪造历史)。
// VARCHAR(16) 对齐版本号形态 V3.1.0,比 game_data_versions.version 的 32 更紧,够用且省索引成本。
return new class extends Migration {
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('game_data_version', 16)->nullable()->after('map_height');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('game_data_version', 16)->nullable()->after('user_agent_hash');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('game_data_version');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('game_data_version');
        });
    }
};
