<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// resource_definition 增加 rs_code:对照 v3.1 §8「资源市场价格」的 RS001-RS026 编号
// §8 未收录的资源(铁制工具/水泥/加工食品/药品/高品质粮食)存 NULL
return new class extends Migration {
    public function up(): void
    {
        Schema::table('resource_definition', function (Blueprint $table) {
            $table->string('rs_code', 8)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('resource_definition', function (Blueprint $table) {
            $table->dropColumn('rs_code');
        });
    }
};
