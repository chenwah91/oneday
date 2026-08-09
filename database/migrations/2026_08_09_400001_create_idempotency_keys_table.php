<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 幂等键:同一用户同一 key 的经济操作只执行一次
return new class extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('key', 100);
            $table->string('action', 80);
            $table->integer('response_status')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->dateTime('expires_at')->nullable();
            $table->unique(['user_id', 'key']);
        });
    }
    public function down(): void { Schema::dropIfExists('idempotency_keys'); }
};
