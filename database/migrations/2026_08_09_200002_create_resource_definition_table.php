<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 资源定义(resource_id 与建筑成本/产出 JSON 的键一致)
// 注:建表时 resource_id 用的是中文资源名,已由 2026_08_10_200002 迁移改为英文 code,
// 中文名保留在 name 列作为显示名(见 docs/templates/resource-code-map.md)
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
