<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 幂等键补列(CLAUDE §49):city_id 定位归属城市;request_hash 记录请求指纹,
// 防止同一 key 被复用到不同操作/不同参数上而静默返回"成功"
return new class extends Migration {
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->after('user_id');
            $table->char('request_hash', 64)->nullable()->after('action');
            $table->index('city_id');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropIndex(['city_id']);
            $table->dropColumn(['city_id', 'request_hash']);
        });
    }
};
