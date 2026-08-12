<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 删除 building_definition 的五个死列(用户 2026-08-13 拍板「按建议删」,W11 审计发现):
//   population_min / governance_ratio_min / happiness_min —— §4 建造门槛的预留列,
//     全项目零代码读取,且 94 行数值**全是 0**(Seeder 一直硬写 0,从未有过设计数值);
//     将来若做建造门槛玩法,属新内容设计,届时连列带数值带 BuildService 闸门一起上;
//   base_workers / base_build_seconds —— 已被 building_level_definition 的
//     worker_required / duration_seconds(逐级各一值)取代的冗余列,零代码读取。
//
// 同批裁决**保留**的三列(不在本迁移):upgrade_to_building_id(跨代升级链,有真实数据
// 与 EnumCodeTest 整套守护,是未实现功能的数据地基)、resource_definition 的
// is_population_consumable / is_strategic(未来系统预留,不开编辑零成本)。
//
// 先例:2026_08_11_150001 删三列双口径(同样「会骗人的数据」问题,用户 2026-08-10 裁决)。
// MySQL 5.7:五列合并单条 ALTER,无 INSTANT DROP,94 行小表重建代价可忽略。
//
// down():只重建列结构(类型与默认值照建表迁移原样),**不回填数值**——
// 三个门槛列本来就恒 0,重建即恢复;base_workers / base_build_seconds 的原值仍留在
// database/data/buildings.json(Seeder 已停止读取这两个键,回滚后如需数值请手工回灌)。
return new class extends Migration
{
    private const DROPPED = [
        'population_min', 'governance_ratio_min', 'happiness_min',
        'base_workers', 'base_build_seconds',
    ];

    public function up(): void
    {
        $existing = array_values(array_filter(
            self::DROPPED,
            fn (string $column) => Schema::hasColumn('building_definition', $column)
        ));

        if ($existing === []) {
            return;
        }

        Schema::table('building_definition', function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }

    public function down(): void
    {
        Schema::table('building_definition', function (Blueprint $table) {
            // 类型与默认值逐列对齐 2026_08_09_200004 建表迁移
            if (! Schema::hasColumn('building_definition', 'base_workers')) {
                $table->integer('base_workers')->default(0);
            }
            if (! Schema::hasColumn('building_definition', 'base_build_seconds')) {
                $table->integer('base_build_seconds')->default(0);
            }
            if (! Schema::hasColumn('building_definition', 'population_min')) {
                $table->integer('population_min')->default(0);
            }
            if (! Schema::hasColumn('building_definition', 'governance_ratio_min')) {
                $table->decimal('governance_ratio_min', 5, 2)->default(0);
            }
            if (! Schema::hasColumn('building_definition', 'happiness_min')) {
                $table->integer('happiness_min')->default(0);
            }
        });
    }
};
