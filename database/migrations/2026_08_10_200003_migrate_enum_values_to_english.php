<?php

use App\Game\Definition\EnumCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 定义表枚举值中文化 → 英文 code(v3.2 §0.2「Canonical English Game Data Standard」第二批)
//
// 覆盖范围(对照 docs/templates/enum-code-map.md):
//   1) building_definition.category          12 个中文 → housing / food_production / …
//   2) building_definition.series_key        29 个中文 → residence / agriculture / …
//   3) building_level_definition.cost_type   建造 / L1→L2升级 / L2→L3升级 → build / upgrade_l1_l2 / upgrade_l2_l3
//   4) resource_definition.category          6 个中文 → raw_material / currency / knowledge / food / energy / processed_good
//   5) technology_definition.branch          5 个中文 → survival_agriculture / … / defense
//
// 一律用 PHP 映射数组逐个 distinct 值 UPDATE,不用 SQL REPLACE(REPLACE 会把「仓储」这类
// 互为子串的值改坏,例如「粮食加工」与「食品加工」、「国防」与「城防」)。
//
// 迁移前先断言:库里出现的每一个 distinct 现值都必须在映射表里(或已经是目标形态),
// 有漏网的直接抛异常中止 —— 宁可失败,不可静默漏转。
//
// upgrade_to_building_id 说明:该列本来就存 building_id / NULL(旧 Seeder 按名字反查后写入),
// 中文只存在于 buildings.json 的 upgrade_to 字段。所以这里只做**校验**不改值:
// 非 NULL 值必须是合法 building_id,否则抛异常。真正的修复在 JSON 数据源与 BuildingDefinitionSeeder。
//
// 不 bump GameDataVersion:本波次的版本号由负责人统一收尾时递增。
return new class extends Migration {
    public function up(): void
    {
        foreach (EnumCode::COLUMNS as [$table, $column, $codeToChinese]) {
            // 中文 => code
            $this->convert($table, $column, array_flip($codeToChinese), array_keys($codeToChinese));
        }

        $this->assertUpgradeTargets();
    }

    public function down(): void
    {
        foreach (EnumCode::COLUMNS as [$table, $column, $codeToChinese]) {
            // code => 中文(反向)
            $this->convert($table, $column, $codeToChinese, array_values($codeToChinese));
        }
    }

    // 单列转换:先断言覆盖率,再按 distinct 值逐个 UPDATE
    private function convert(string $table, string $column, array $map, array $targets): void
    {
        $distinct = DB::table($table)->distinct()->pluck($column)->filter(fn ($v) => $v !== null)->all();

        $missing = [];
        foreach ($distinct as $v) {
            if (isset($map[$v]) || in_array($v, $targets, true)) {
                continue; // 已经是目标形态:迁移中断后重跑 / 新库 seed 后再跑都会走到这里
            }
            $missing[] = $v;
        }
        if ($missing) {
            throw new RuntimeException(
                "{$table}.{$column} 映射表未覆盖以下现值,迁移中止:" . implode('、', $missing)
            );
        }

        $changed = 0;
        foreach ($distinct as $from) {
            $to = $map[$from] ?? null;
            if ($to === null || $to === $from) {
                continue;
            }
            $changed += DB::table($table)->where($column, $from)->update([$column => $to]);
        }

        if ($changed > 0) {
            $this->note("{$table}.{$column}:" . count($distinct) . " 个值,更新 {$changed} 行");
        }
    }

    // upgrade_to_building_id 只校验不改值:非 NULL 必须指向存在的 building_id
    private function assertUpgradeTargets(): void
    {
        $ids = DB::table('building_definition')->pluck('building_id')->all();
        if (! $ids) {
            return; // 全新库,seed 之前表是空的
        }

        $bad = DB::table('building_definition')
            ->whereNotNull('upgrade_to_building_id')
            ->whereNotIn('upgrade_to_building_id', $ids)
            ->pluck('upgrade_to_building_id', 'building_id')
            ->all();

        if ($bad) {
            $pairs = [];
            foreach ($bad as $from => $to) {
                $pairs[] = "{$from}→{$to}";
            }
            throw new RuntimeException(
                'building_definition.upgrade_to_building_id 指向不存在的建筑,迁移中止:' . implode('、', $pairs)
            );
        }

        $nulls = DB::table('building_definition')->whereNull('upgrade_to_building_id')->count();
        $this->note("upgrade_to_building_id 校验通过:" . (count($ids) - $nulls) . ' 条有效链接,' . $nulls . ' 条 NULL(10 终局 + 26 断链,见 enum-code-map.md §6)');
    }

    // 迁移过程说明打印到控制台(测试环境静默,避免污染 PHPUnit 输出)
    private function note(string $message): void
    {
        if (PHP_SAPI === 'cli' && ! app()->environment('testing')) {
            fwrite(STDOUT, "  [enum-migrate] {$message}\n");
        }
    }
};
