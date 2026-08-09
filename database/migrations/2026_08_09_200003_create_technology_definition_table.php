<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 科技定义
return new class extends Migration {
    public function up(): void
    {
        Schema::create('technology_definition', function (Blueprint $table) {
            $table->string('tech_id', 32)->primary();
            $table->string('era_key', 4);
            $table->string('branch', 32);
            $table->string('name', 96);
            $table->integer('knowledge_cost');
            $table->decimal('research_minutes', 10, 2);
            $table->json('prerequisite_tech_ids')->nullable();
            $table->json('unlock_building_ids')->nullable();
            $table->foreign('era_key')->references('era_key')->on('era');
        });
    }
    public function down(): void { Schema::dropIfExists('technology_definition'); }
};
