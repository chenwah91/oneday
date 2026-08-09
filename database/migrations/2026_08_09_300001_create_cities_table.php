<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 玩家城市 Runtime
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('name', 64);
            $table->unsignedBigInteger('revision')->default(0);
            $table->dateTime('last_simulated_at');
            $table->decimal('money', 16, 2)->default(0);
            $table->integer('population')->default(0);
            $table->integer('map_width');
            $table->integer('map_height');
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }
    public function down(): void { Schema::dropIfExists('cities'); }
};
