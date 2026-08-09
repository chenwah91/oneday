<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 城市资源存量
return new class extends Migration {
    public function up(): void
    {
        Schema::create('city_resources', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id');
            $table->string('resource_id', 32);
            $table->decimal('amount', 18, 4)->default(0);
            $table->primary(['city_id', 'resource_id']);
            $table->foreign('city_id')->references('id')->on('cities');
        });
    }
    public function down(): void { Schema::dropIfExists('city_resources'); }
};
