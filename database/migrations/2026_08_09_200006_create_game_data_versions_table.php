<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 游戏数据版本(定位"玩家当时用的是哪一版数值")
return new class extends Migration {
    public function up(): void
    {
        Schema::create('game_data_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('version', 32)->unique();
            $table->char('checksum', 64)->nullable();
            $table->dateTime('deployed_at');
            $table->string('deployed_by', 64)->nullable();
            $table->text('notes')->nullable();
        });
    }
    public function down(): void { Schema::dropIfExists('game_data_versions'); }
};
