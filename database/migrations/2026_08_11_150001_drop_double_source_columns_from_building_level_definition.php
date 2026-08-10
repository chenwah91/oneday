<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 删除 building_level_definition 的三列双口径:happiness_bonus / governance_bonus / defense_score。
//
// 背景(STATUS「待用户拍板 §2」,用户 2026-08-10 裁决:删列):
// 这三列与同表 output_json 是**两套并不相等的口径**,150+ 行数值对不上
// (例:A01 L2 的 output_json 治理容量 108,而 governance_bonus 列写 104;
//  K01 学堂 governance_bonus 列 30,output_json 里根本没有治理容量)。
// 结算自始至终只认 output_json(单一来源,见 SimulationService / EraService 的注释),
// 三列既不参与任何计算,又挂在后台可编辑字段里 —— 运营改了以为生效,其实什么都没发生。
// 与其留一份「会骗人的数据」,不如物理删掉:数值缺口(幸福/国防真正要用时)走 output_json 补。
//
// MySQL 5.7:三列合并成单条 ALTER(Laravel 的 dropColumn 传数组即生成一条 ALTER … DROP a, DROP b, DROP c),
// 5.7 无 INSTANT DROP COLUMN,整表要重建一次 —— 282 行的定义表,重建代价可忽略。
//
// down():只重建列结构(允许 NULL、不带默认),**不回填数值**。
// 原值来自已删除的 JSON 数据源,回滚后请重新 seed;审计与版本历史照旧不回滚(append-only)。
return new class extends Migration
{
    private const DROPPED = ['happiness_bonus', 'governance_bonus', 'defense_score'];

    public function up(): void
    {
        $existing = array_values(array_filter(
            self::DROPPED,
            fn (string $column) => Schema::hasColumn('building_level_definition', $column)
        ));

        if ($existing === []) {
            return;
        }

        Schema::table('building_level_definition', function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }

    public function down(): void
    {
        Schema::table('building_level_definition', function (Blueprint $table) {
            foreach (self::DROPPED as $column) {
                if (! Schema::hasColumn('building_level_definition', $column)) {
                    $table->decimal($column, 12, 2)->nullable();
                }
            }
        });
    }
};
