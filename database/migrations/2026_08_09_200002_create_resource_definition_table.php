<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 资源定义(resource_id 用中文资源名,与建筑成本/产出 JSON 的键一致)
return new class extends Migration {
    public function up(): void
    {
        Schema::create('resource_definition', function (Blueprint $table) {
            $table->string('resource_id', 32)->primary();
            $table->string('name', 64)->unique();
            $table->string('category', 32);
            $table->string('first_era', 4);
            $table->boolean('is_population_consumable')->default(false);
            $table->boolean('is_strategic')->default(false);
            $table->foreign('first_era')->references('era_key')->on('era');
        });
    }
    public function down(): void { Schema::dropIfExists('resource_definition'); }
};
