<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M2-C2 幸福度:cities 补 happiness 列(v3.2 §10.2)
//
// 合并原则(与 2026_08_10_300001 一致):MySQL 5.7 没有 INSTANT ADD COLUMN,
// 每条 ALTER TABLE 都要重建整张表,所以本次只有这一列也仍然用单条批量 ALTER 加齐。
//
// 存档迁移:列声明为 NOT NULL DEFAULT 60,MySQL/MariaDB 在 ADD COLUMN 时会用默认值填满已有行,
// 因此现有城市自动初始化成 §10.2 的 baseHappiness = 60,不需要再补一条无 WHERE 的 UPDATE。
//
// 为什么是 double 不是 integer:§10.2 的收敛速度是 ±0.5 / ±1.0 每分钟,
// 短段结算(几秒)一次只挪零点几,取整会把斜率整个抹平。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->double('happiness')->default(60)->after('population');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('happiness');
        });
    }
};
