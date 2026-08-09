<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 建筑等级定义(L1/L2/L3 每级的成本/时间/产出/维护/加成)
return new class extends Migration {
    public function up(): void
    {
        Schema::create('building_level_definition', function (Blueprint $table) {
            $table->string('building_id', 16);
            $table->unsignedTinyInteger('level');
            $table->string('cost_type', 24);
            $table->json('cost_json');
            $table->integer('duration_seconds');
            $table->integer('worker_required');
            $table->json('input_json')->nullable();
            $table->json('output_json')->nullable();
            $table->decimal('maintenance_money_per_min', 14, 4)->default(0);
            $table->decimal('maintenance_food_per_min', 14, 4)->default(0);
            $table->decimal('maintenance_fuel_per_min', 14, 4)->default(0);
            $table->decimal('power_per_min', 14, 4)->default(0);
            $table->decimal('happiness_bonus', 12, 2)->default(0);
            $table->decimal('governance_bonus', 12, 2)->default(0);
            $table->decimal('defense_score', 12, 2)->default(0);
            $table->decimal('capacity', 14, 2)->default(0);
            $table->primary(['building_id', 'level']);
            $table->foreign('building_id')->references('building_id')->on('building_definition');
        });
    }
    public function down(): void { Schema::dropIfExists('building_level_definition'); }
};
