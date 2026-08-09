<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 审计日志:append-only,可追溯谁在哪个请求改了什么(SECURITY §54)
return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at', 6);
            $table->char('request_id', 36);
            $table->char('trace_id', 36)->nullable();
            $table->string('idempotency_key', 100)->nullable();

            $table->string('actor_type', 32);           // player | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            $table->string('action', 80);               // AUTH.LOGIN_SUCCESS 等稳定码
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();

            $table->unsignedBigInteger('city_revision_before')->nullable();
            $table->unsignedBigInteger('city_revision_after')->nullable();

            $table->string('status', 24);               // success | failed | rejected
            $table->string('reason_code', 80)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->char('user_agent_hash', 64)->nullable();

            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('delta_json')->nullable();
            $table->json('metadata_json')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('request_id', 'idx_audit_request');
            $table->index(['user_id', 'occurred_at'], 'idx_audit_user_time');
            $table->index(['action', 'occurred_at'], 'idx_audit_action_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
