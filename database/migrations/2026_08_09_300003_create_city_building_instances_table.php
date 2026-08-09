<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 城市建筑实例
return new class extends Migration {
    public function up(): void
    {
        Schema::create('city_building_instances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('city_id');
            $table->string('building_id', 16);
            $table->unsignedTinyInteger('level')->default(1);
            $table->integer('x');
            $table->integer('y');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->index('city_id');
            $table->foreign('city_id')->references('id')->on('cities');
        });
    }
    public function down(): void { Schema::dropIfExists('city_building_instances'); }
};
