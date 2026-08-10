<?php

use App\Game\Population\WorkerBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M2-C1 人口/劳动力:补列 + 存档回填(v3.2 §10.1 / §10.3 / §10.4)
//
// 合并原则:MySQL 5.7 每条 ALTER TABLE 都要重建整张表,所以同一张表的新列一次加齐,
// 不拆成多个迁移文件(city_building_instances 一次 ALTER、cities 一次 ALTER)。
//
// 存档回填(§10.4「M2 接入劳动力系统时的存档兼容」)由 WorkerBackfill 执行:
//   1) 现有城市 population < 30 → 30(初始人口 10 → 30)
//   2) 按 building_id 排序对已建建筑逐栋补满工人,总分配 <= floor(population × 0.60)
// 迁移行为只执行一次,不是长期游戏规则:之后工人一律由玩家通过 /api/city/workers/assign 分配。
return new class extends Migration
{
    public function up(): void
    {
        // 建筑实例:本栋已分配的工人数(§12.1 建议字段 assigned_workers)
        Schema::table('city_building_instances', function (Blueprint $table) {
            $table->unsignedInteger('assigned_workers')->default(0)->after('level');
        });

        // 城市:粮食赤字/归零的起始时刻(两列一次加齐,避免二次重建表)
        //   food_deficit_since:粮食净变化持续为负的起点。本段(C1)不写不读,
        //     预留给 M2-C2 幸福系统的「连续赤字 >= 5 分钟 → happiness -1/分钟」(§10.1)。
        //   food_zero_since:粮食库存归零的起点,C1 的饥荒判定(归零持续 >= 10 分钟 → -1.0%/分钟)要用。
        Schema::table('cities', function (Blueprint $table) {
            $table->dateTime('food_deficit_since')->nullable()->after('population');
            $table->dateTime('food_zero_since')->nullable()->after('food_deficit_since');
        });

        WorkerBackfill::run(function (string $message) {
            // 迁移过程说明打印到控制台(测试环境静默,避免污染 PHPUnit 输出)
            if (PHP_SAPI === 'cli' && ! app()->environment('testing')) {
                fwrite(STDOUT, "  [m2c1-migrate] {$message}\n");
            }
        });
    }

    public function down(): void
    {
        // 只删列:回填过的人口/工人属于玩家存档,回滚不试图还原(还原不了,也不该猜)
        Schema::table('city_building_instances', function (Blueprint $table) {
            $table->dropColumn('assigned_workers');
        });
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['food_deficit_since', 'food_zero_since']);
        });
    }
};
