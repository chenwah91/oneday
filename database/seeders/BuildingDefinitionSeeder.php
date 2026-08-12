<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BuildingDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/buildings.json')), true);

        DB::table('building_definition')->insert(self::toRows($rows));
    }

    // buildings.json 的行 → building_definition 的插入行
    // 独立成静态方法便于测试断链守门(见 tests/Feature/Definition/EnumCodeTest.php)
    public static function toRows(array $rows): array
    {
        // upgrade_to 现在直接存 building_id 或 null(v3.2 §0.2 英文化第二批),
        // 不再按中文名反查 —— 旧写法解析不到就静默变 NULL,36 条断链因此一直没被发现。
        $ids = array_flip(array_column($rows, 'building_id'));

        return array_map(function ($r) use ($ids) {
            $upgradeTo = $r['upgrade_to'] ?? null;

            // 断链守门:非 null 的升级去向必须是 building_id 之一,否则直接失败,不再静默丢链
            if ($upgradeTo !== null && ! isset($ids[$upgradeTo])) {
                throw new RuntimeException(
                    "buildings.json:{$r['building_id']} 的 upgrade_to「{$upgradeTo}」不是合法 building_id"
                );
            }

            // 五个死列已物理删除(2026_08_13_300001,用户拍板删5留3):
            // population_min/governance_ratio_min/happiness_min(恒0无读取)、
            // base_workers/base_build_seconds(被 level 表逐级列取代)。
            // buildings.json 里仍保留 base_workers/base_build_seconds 两个键(v3.2 历史设计数据),此处刻意不读
            return [
                'building_id'          => $r['building_id'],
                'era_key'              => $r['era'],
                'category'             => $r['category'],
                'series_key'           => $r['series'],
                'name'                 => $r['name'],
                'max_count'            => $r['max_count'],
                'footprint_w'          => $r['footprint_w'],
                'footprint_h'          => $r['footprint_h'],
                'tech_id'              => $r['tech_id'],
                'upgrade_to_building_id' => $upgradeTo,
            ];
        }, $rows);
    }
}
