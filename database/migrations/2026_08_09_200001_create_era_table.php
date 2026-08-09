<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 文明时代
return new class extends Migration {
    public function up(): void
    {
        Schema::create('era', function (Blueprint $table) {
            $table->string('era_key', 4)->primary();
            $table->integer('era_order')->unique();
            $table->string('name', 64);
        });
    }
    public function down(): void { Schema::dropIfExists('era'); }
};
