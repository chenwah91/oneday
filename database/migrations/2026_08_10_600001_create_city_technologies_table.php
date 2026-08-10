<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 城市科技 Runtime(M2-B1):某座城「在研 / 已解锁」了哪些科技。
//
// 与 technology_definition(什么是这项科技)严格分离(CLAUDE §12):
// 这里只存运行时状态,费用/时长/前置一律回定义表读,绝不冗余到本表 ——
// 后台调数值后,已在研的项目也应该按新定义结算,冗余一份就会出现两套真相。
//
// MySQL 5.7 兼容:不用生成列、不用 CHECK 约束、不用函数索引;
// status 用 VARCHAR 而不是 ENUM(改枚举值要 ALTER 重建整表,枚举语义交给应用层常量)。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_technologies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('city_id');
            $table->string('tech_id', 32);

            // researching = 在研(finished_at 到点后由 TechService::settleFinished 懒结算翻牌)
            // unlocked    = 已解锁
            $table->string('status', 16)->default('researching');

            $table->dateTime('started_at');
            // finished_at = started_at + technology_definition.research_minutes(下单时按当时定义算死,
            // 否则玩家研究到一半改数值会出现「进度条倒退」)
            $table->dateTime('finished_at');
            $table->timestamps();

            // 一座城对同一项科技只能有一行:重复研究 / 并发双开在数据库这一层就被挡死
            $table->unique(['city_id', 'tech_id'], 'uq_city_tech');
            // 快照与「是否有在研项」的判定都按 (city_id, status) 走
            $table->index(['city_id', 'status'], 'idx_city_tech_status');

            // 只对 city_id 建外键,与 city_building_instances 的做法保持一致:
            // 定义表主键(tech_id)不建外键,避免将来重排科技表被运行时数据卡住
            $table->foreign('city_id')->references('id')->on('cities');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_technologies');
    }
};
