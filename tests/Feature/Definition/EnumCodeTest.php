<?php

namespace Tests\Feature\Definition;

use App\Game\Definition\EnumCode;
use Database\Seeders\BuildingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

// 枚举值英文化(v3.2 §0.2 第二批)的守门测试:
// 定义表的 category / series_key / cost_type / branch 五列不得再出现中文,
// upgrade_to 必须是合法 building_id 或 NULL,
// 且「映射文档 / 后端 EnumCode / 前端 enum-names.js」三处不得漂移。
class EnumCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ---- 1. 定义表五个关键列无中文程序值 ----

    public function test_definition_columns_have_no_chinese_values(): void
    {
        foreach (EnumCode::COLUMNS as [$table, $column, $codeToChinese]) {
            $values = DB::table($table)->distinct()->pluck($column)->all();
            $this->assertNotEmpty($values, "{$table}.{$column} 没有数据,断言等于没跑");

            foreach ($values as $v) {
                $this->assertDoesNotMatchRegularExpression(
                    '/[^\x00-\x7F]/',
                    (string) $v,
                    "{$table}.{$column} 仍是中文程序值:{$v}"
                );
                $this->assertMatchesRegularExpression(
                    '/^[a-z][a-z0-9_]*$/',
                    (string) $v,
                    "{$table}.{$column} 出现非法 code:{$v}"
                );
                $this->assertArrayHasKey(
                    $v,
                    $codeToChinese,
                    "{$table}.{$column} 的 {$v} 不在 EnumCode 映射表里"
                );
            }

            $this->assertSame(
                count($codeToChinese),
                count($values),
                "{$table}.{$column} 的 distinct 值个数与 EnumCode 映射表不一致"
            );
        }
    }

    // 显示名保持中文:英文化只针对程序值,name 列不动
    public function test_display_names_stay_chinese(): void
    {
        foreach ([['building_definition', 'name'], ['resource_definition', 'name'], ['technology_definition', 'name'], ['era', 'name']] as [$table, $column]) {
            $rows = DB::table($table)->pluck($column)->all();
            $this->assertNotEmpty($rows);
            foreach ($rows as $name) {
                $this->assertMatchesRegularExpression(
                    '/[^\x00-\x7F]/',
                    (string) $name,
                    "{$table}.{$column} 应保留中文显示名,实为 {$name}"
                );
            }
        }
    }

    // ---- 2. upgrade_to 全部为合法 ID 或 NULL ----

    public function test_upgrade_to_is_valid_building_id_or_null(): void
    {
        $ids = DB::table('building_definition')->pluck('building_id')->all();
        $this->assertCount(94, $ids);

        $rows = DB::table('building_definition')->get(['building_id', 'upgrade_to_building_id']);
        $nulls = 0;

        foreach ($rows as $r) {
            if ($r->upgrade_to_building_id === null) {
                $nulls++;
                continue;
            }
            $this->assertContains(
                $r->upgrade_to_building_id,
                $ids,
                "{$r->building_id} 的 upgrade_to_building_id 指向不存在的建筑:{$r->upgrade_to_building_id}"
            );
        }

        // 10 条「终局」+ 26 条断链 = 36 条置 NULL(见 docs/templates/enum-code-map.md §6)
        $this->assertSame(36, $nulls, '置 NULL 的升级链应为 36 条(10 终局 + 26 断链)');
        $this->assertSame(58, 94 - $nulls, '可解析的升级链应为 58 条');
    }

    // JSON 数据源里 upgrade_to 已经是 building_id / null,不再是中文名
    public function test_buildings_json_upgrade_to_is_id_or_null(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/buildings.json')), true);
        $ids = array_column($rows, 'building_id');

        $nulls = 0;
        foreach ($rows as $r) {
            $this->assertArrayHasKey('upgrade_to', $r, "{$r['building_id']} 缺 upgrade_to 字段");
            if ($r['upgrade_to'] === null) {
                $nulls++;
                continue;
            }
            $this->assertContains($r['upgrade_to'], $ids, "{$r['building_id']} 的 upgrade_to 不是合法 building_id");
        }
        $this->assertSame(36, $nulls);
    }

    // ---- 3. Seeder 断链守门生效(喂假名字必须抛异常,不能静默变 NULL) ----

    public function test_seeder_throws_on_unresolvable_upgrade_target(): void
    {
        $rows = [$this->fakeBuildingRow('X01', '伐木场')];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/X01 的 upgrade_to/u');
        BuildingDefinitionSeeder::toRows($rows);
    }

    public function test_seeder_accepts_valid_id_and_null(): void
    {
        $out = BuildingDefinitionSeeder::toRows([
            $this->fakeBuildingRow('X01', 'X02'),
            $this->fakeBuildingRow('X02', null),
        ]);

        $this->assertSame('X02', $out[0]['upgrade_to_building_id']);
        $this->assertNull($out[1]['upgrade_to_building_id']);
    }

    private function fakeBuildingRow(string $id, ?string $upgradeTo): array
    {
        return [
            'building_id' => $id, 'era' => 'I', 'category' => 'housing', 'series' => 'residence',
            'name' => '测试建筑' . $id, 'max_count' => 1, 'footprint_w' => 1, 'footprint_h' => 1,
            'base_workers' => 0, 'base_build_seconds' => 1, 'tech_id' => null, 'upgrade_to' => $upgradeTo,
        ];
    }

    // ---- 4. 映射文档 ↔ 后端 EnumCode 逐行一致 ----

    public function test_enum_code_matches_mapping_document(): void
    {
        $expect = [
            'building_category' => EnumCode::BUILDING_CATEGORIES,
            'building_series'   => EnumCode::BUILDING_SERIES,
            'cost_type'         => EnumCode::COST_TYPES,
            'resource_category' => EnumCode::RESOURCE_CATEGORIES,
            'tech_branch'       => EnumCode::TECH_BRANCHES,
        ];

        foreach ($expect as $marker => $map) {
            $doc = $this->docTable($marker);
            ksort($doc);
            ksort($map);
            $this->assertSame($map, $doc, "enum-code-map.md 的 {$marker} 表与 EnumCode 不一致");
        }
    }

    // ---- 5. 前端 enum-names.js ↔ 后端 EnumCode 一致 ----

    public function test_frontend_enum_names_match_backend(): void
    {
        $expect = [
            'BUILDING_CATEGORY_NAMES' => EnumCode::BUILDING_CATEGORIES,
            'BUILDING_SERIES_NAMES'   => EnumCode::BUILDING_SERIES,
            'COST_TYPE_NAMES'         => EnumCode::COST_TYPES,
            'RESOURCE_CATEGORY_NAMES' => EnumCode::RESOURCE_CATEGORIES,
            'TECH_BRANCH_NAMES'       => EnumCode::TECH_BRANCHES,
        ];

        foreach ($expect as $constName => $map) {
            $js = $this->jsTable($constName);
            ksort($js);
            ksort($map);
            $this->assertSame($map, $js, "enum-names.js 的 {$constName} 与 EnumCode 不一致");
        }
    }

    // 前端配色表的键必须是合法 category code(不能再是中文)
    public function test_renderer_category_colors_use_english_codes(): void
    {
        $js = file_get_contents(public_path('game/js/renderer/buildings.js'));
        $this->assertTrue(
            (bool) preg_match('/const CATEGORY_COLOR = \{(.*?)\};/su', $js, $m),
            'buildings.js 找不到 CATEGORY_COLOR'
        );

        preg_match_all('/^\s*([^\s:]+):/mu', $m[1], $keys);
        $this->assertNotEmpty($keys[1]);
        foreach ($keys[1] as $k) {
            $this->assertArrayHasKey(
                $k,
                EnumCode::BUILDING_CATEGORIES,
                "CATEGORY_COLOR 的键 {$k} 不是合法 category code"
            );
        }
    }

    // Service Worker 缓存版本要随静态资源变更递增,且预缓存清单要带上新文件
    public function test_service_worker_precaches_enum_names(): void
    {
        $sw = file_get_contents(public_path('game/service-worker.js'));
        // v6:HUD 增加民生三值(幸福/健康/治安)+ 幸福警示色,hud.js / hud.css 有实质变更(M2-C2)
        $this->assertStringContainsString("const CACHE = 'apg-v6'", $sw);
        $this->assertStringContainsString("'/game/js/core/enum-names.js'", $sw);
    }

    // ---- 解析工具 ----

    // 从映射文档里取出 <!-- enum:xxx --> … <!-- /enum --> 之间那张表:code => 中文
    private function docTable(string $marker): array
    {
        $doc = file_get_contents(base_path('docs/templates/enum-code-map.md'));
        $this->assertNotFalse($doc, '找不到 docs/templates/enum-code-map.md');

        $start = strpos($doc, "<!-- enum:{$marker} -->");
        $this->assertNotFalse($start, "映射文档缺少 enum:{$marker} 标记");
        $end = strpos($doc, '<!-- /enum -->', $start);
        $this->assertNotFalse($end, "映射文档的 enum:{$marker} 没有闭合标记");

        $out = [];
        foreach (explode("\n", substr($doc, $start, $end - $start)) as $line) {
            $cells = explode('|', trim($line));
            if (count($cells) < 4) {
                continue;
            }
            $code = trim($cells[2], " `");
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
                continue; // 表头与分隔行
            }
            $out[$code] = trim($cells[1]);
        }

        return $out;
    }

    // 从前端 enum-names.js 里取出某个 export const 对象字面量:code => 中文
    private function jsTable(string $constName): array
    {
        $js = file_get_contents(public_path('game/js/core/enum-names.js'));
        $this->assertNotFalse($js, '找不到 public/game/js/core/enum-names.js');
        $this->assertTrue(
            (bool) preg_match('/export const ' . $constName . ' = \{(.*?)\n\};/su', $js, $m),
            "enum-names.js 缺少 {$constName}"
        );

        $out = [];
        preg_match_all("/^\s*([a-z][a-z0-9_]*):\s*'([^']*)',$/mu", $m[1], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $p) {
            $out[$p[1]] = $p[2];
        }

        return $out;
    }
}
