<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 建筑定义(静态;每级成本/产出在 building_level_definition)
return new class extends Migration {
    public function up(): void
    {
        Schema::create('building_definition', function (Blueprint $table) {
            $table->string('building_id', 16)->primary();
            $table->string('era_key', 4);
            $table->string('category', 32);
            $table->string('series_key', 64);
            $table->string('name', 96);
            $table->integer('max_count');
            $table->integer('footprint_w');
            $table->integer('footprint_h');
            $table->integer('base_workers');
            $table->integer('base_build_seconds');
            $table->string('tech_id', 32)->nullable();
            $table->integer('population_min')->default(0);
            $table->decimal('governance_ratio_min', 5, 2)->default(0);
            $table->integer('happiness_min')->default(0);
            $table->string('upgrade_to_building_id', 16)->nullable();
            $table->foreign('era_key')->references('era_key')->on('era');
        });
    }
    public function down(): void { Schema::dropIfExists('building_definition'); }
};
