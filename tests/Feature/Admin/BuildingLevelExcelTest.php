<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Xlsx\XlsxReader;
use App\Support\Xlsx\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// 建筑等级 Excel 导出 / 导入(W13-2)的验收面。
//
// 导出:200 + 合法 xlsx(能被自家读取器读回)+ 表头正确 + 按 building_id, level 排序。
// 导入:11 步铁模板批量版的六道闸逐条钉死 ——
//   改现有行 + 新增 L4 行落库、GDV bump 一次、汇总审计;
//   等级断档 422 且整体回滚;非法 building_id / 非法 JSON / 未知列 / 缺 reason 422;
//   文件里没有的现有行一律不动(绝不 DELETE);全无变化时不 bump 版本不写审计。
class BuildingLevelExcelTest extends TestCase
{
    use RefreshDatabase;

    // 与 AdminDefinitionController::XLSX_COLUMNS 逐列一致(导出表头 = 导入 allowlist)
    private const HEADER = [
        'building_id', '名称', 'level',
        'duration_seconds', 'worker_required',
        'maintenance_money_per_min', 'maintenance_food_per_min', 'maintenance_fuel_per_min', 'power_per_min',
        'capacity',
        'output_json', 'input_json', 'cost_json',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(string $un = 'exceladmin'): User
    {
        // role 已不可批量赋值,测试里用 forceFill 显式提权(与 AdminDefinitionExpansionTest 同款)
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    private function player(string $un = 'excelplayer'): User
    {
        return User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
    }

    private function versions(): int
    {
        return DB::table('game_data_versions')->count();
    }

    private function levelRow(string $buildingId, int $level): ?object
    {
        return DB::table('building_level_definition')
            ->where('building_id', $buildingId)->where('level', $level)->first();
    }

    // 按表头顺序把一条现有定义行摊平成导入行;$overrides 按列名覆盖(补 L4 时以 L3 为模板)
    private function fileRow(string $buildingId, int $templateLevel, array $overrides = []): array
    {
        $r = $this->levelRow($buildingId, $templateLevel);
        $this->assertNotNull($r, "测试模板行不存在:{$buildingId} L{$templateLevel}");

        $row = [
            'building_id'               => $buildingId,
            '名称'                      => '',
            'level'                     => $templateLevel,
            'duration_seconds'          => (int) $r->duration_seconds,
            'worker_required'           => (int) $r->worker_required,
            'maintenance_money_per_min' => (float) $r->maintenance_money_per_min,
            'maintenance_food_per_min'  => (float) $r->maintenance_food_per_min,
            'maintenance_fuel_per_min'  => (float) $r->maintenance_fuel_per_min,
            'power_per_min'             => (float) $r->power_per_min,
            'capacity'                  => (float) $r->capacity,
            'output_json'               => $r->output_json,
            'input_json'                => $r->input_json,
            'cost_json'                 => $r->cost_json,
        ];
        foreach ($overrides as $column => $value) {
            $row[$column] = $value;
        }

        // 按 HEADER 顺序输出,保证与导出文件同构
        return array_map(fn ($column) => $row[$column], self::HEADER);
    }

    private function xlsxUpload(array $dataRows, ?array $header = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'blt');
        XlsxWriter::write($path, array_merge([$header ?? self::HEADER], $dataRows));

        return new UploadedFile($path, 'levels.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function import(User $user, UploadedFile $file, array $extra = ['reason' => 'W13-2 批量调整']): TestResponse
    {
        return $this->actingAs($user)->post(
            '/api/admin/definitions/building-levels/import',
            ['file' => $file] + $extra,
            ['Accept' => 'application/json']
        );
    }

    // ==================== 导出 ====================

    public function test_export_returns_valid_xlsx_with_expected_header_and_order(): void
    {
        $res = $this->actingAs($this->admin())->get('/api/admin/definitions/building-levels/export');
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));

        // 响应是 BinaryFileResponse:文件必须是合法 zip / xlsx,能被自家读取器读回
        $path = $res->baseResponse->getFile()->getPathname();
        $sheet = XlsxReader::read($path);

        $this->assertSame(self::HEADER, $sheet[1], '表头与导入 allowlist 逐列一致');
        // 种子 282 行(94 栋 × 3 级)+ 表头
        $this->assertCount(283, $sheet);

        // 按 building_id, level 排序:首行数据是 A01 L1,且名称列带中文显示名
        $first = $sheet[2];
        $this->assertSame('A01', $first[0]);
        $this->assertSame(1, $first[2]);
        $this->assertSame(
            DB::table('building_definition')->where('building_id', 'A01')->value('name'),
            $first[1]
        );

        // JSON 列以 JSON 文本原样进单元格(能 decode 回结构)
        $this->assertIsArray(json_decode((string) $first[12], true), 'cost_json 单元格必须是合法 JSON');
    }

    public function test_export_requires_edit_definition_permission(): void
    {
        $this->actingAs($this->player())->get('/api/admin/definitions/building-levels/export')->assertStatus(403);
    }

    // ==================== 导入:成功路径 ====================

    public function test_import_updates_existing_row_and_inserts_new_level(): void
    {
        $admin = $this->admin();
        $versionsBefore = $this->versions();
        $f01L2Before = (array) $this->levelRow('F01', 2);
        $workerBefore = (int) $this->levelRow('F01', 1)->worker_required;

        $file = $this->xlsxUpload([
            // 改现有行:F01 L1 的工期改成 999(其余列照抄现值)
            $this->fileRow('F01', 1, ['duration_seconds' => 999]),
            // 新增等级行:F02 L4 以 L3 为模板,改个成本(等级无上限的核心场景)
            $this->fileRow('F02', 3, ['level' => 4, 'cost_json' => '{"wood":66,"money":33}']),
        ]);

        $res = $this->import($admin, $file);
        $res->assertOk()->assertJson(['data' => [
            'updated'            => 1,
            'inserted'           => 1,
            'unchanged'          => 0,
            'buildings_affected' => ['F01', 'F02'],
        ]]);
        $this->assertNotNull($res->json('data.version'));

        // 更新落库:只有 duration_seconds 变了,其它列原值不动
        $f01 = $this->levelRow('F01', 1);
        $this->assertSame(999, (int) $f01->duration_seconds);
        $this->assertSame($workerBefore, (int) $f01->worker_required);

        // 新增落库:L4 存在、cost_json 生效、cost_type 沿用最近一档既有 code(不新增枚举值)
        $f02L4 = $this->levelRow('F02', 4);
        $this->assertNotNull($f02L4);
        $this->assertSame(['wood' => 66, 'money' => 33], json_decode($f02L4->cost_json, true));
        $this->assertSame('upgrade_l2_l3', $f02L4->cost_type);

        // 文件里没有的现有行一律不动(绝不 DELETE / 绝不误改)
        $this->assertSame($f01L2Before, (array) $this->levelRow('F01', 2));
        $this->assertSame(283, DB::table('building_level_definition')->count(), '282 + 新增 1,绝无删行');

        // GDV bump 一次(不是每行一次)
        $this->assertSame($versionsBefore + 1, $this->versions());

        // 汇总审计:定位到具体格的 before/after + metadata 统计
        $audit = DB::table('audit_logs')->where('action', 'ADMIN.CONFIG_CHANGE')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('building_level_definition', $audit->entity_type);
        $this->assertSame('excel_import', $audit->entity_id);
        $this->assertSame('W13-2 批量调整', $audit->reason_code);
        $this->assertSame(999.0, (float) json_decode($audit->after_json, true)['F01:1.duration_seconds']);
        $meta = json_decode($audit->metadata_json, true);
        $this->assertSame(1, $meta['updated']);
        $this->assertSame(1, $meta['inserted']);
        $this->assertContains('F02:4', $meta['inserted_rows']);
    }

    // 文件与库完全一致:不 bump 版本、不写审计(空版本稀释 §65 的回查价值),照常返回统计
    public function test_import_with_no_changes_does_not_bump_version(): void
    {
        $admin = $this->admin();
        $versionsBefore = $this->versions();
        $auditsBefore = DB::table('audit_logs')->where('action', 'ADMIN.CONFIG_CHANGE')->count();

        $res = $this->import($admin, $this->xlsxUpload([$this->fileRow('F01', 1)]));
        $res->assertOk()->assertJson(['data' => ['updated' => 0, 'inserted' => 0, 'unchanged' => 1]]);

        $this->assertSame($versionsBefore, $this->versions());
        $this->assertSame($auditsBefore, DB::table('audit_logs')->where('action', 'ADMIN.CONFIG_CHANGE')->count());
    }

    // ==================== 导入:六道闸 ====================

    // 等级断档:只给 L5(现有 1..3,缺 4)→ 422 整体回滚,一行都不许写
    public function test_import_rejects_level_gap_and_rolls_back(): void
    {
        $versionsBefore = $this->versions();

        $res = $this->import($this->admin(), $this->xlsxUpload([
            $this->fileRow('F01', 3, ['level' => 5]),
        ]));

        $res->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);
        $this->assertStringContainsString('断档', json_encode($res->json('row_errors'), JSON_UNESCAPED_UNICODE));
        $this->assertNull($this->levelRow('F01', 5));
        $this->assertSame($versionsBefore, $this->versions());
    }

    public function test_import_rejects_unknown_building_id(): void
    {
        $res = $this->import($this->admin(), $this->xlsxUpload([
            $this->fileRow('F01', 1, ['building_id' => 'ZZZ']),
        ]));

        $res->assertStatus(422);
        $errors = $res->json('row_errors');
        $this->assertSame('building_id', $errors[0]['column']);
        $this->assertStringContainsString('不能新建建筑', $errors[0]['reason']);
    }

    public function test_import_rejects_invalid_json_cell(): void
    {
        $before = $this->levelRow('F01', 1)->output_json;

        $res = $this->import($this->admin(), $this->xlsxUpload([
            $this->fileRow('F01', 1, ['output_json' => '这不是 JSON']),
        ]));

        $res->assertStatus(422);
        $this->assertSame('output_json', $res->json('row_errors')[0]['column']);
        $this->assertSame($before, $this->levelRow('F01', 1)->output_json, '被拒的导入一个字节都不许写');
    }

    // 未知列(不在 allowlist)→ 422:防手滑把别的表贴进来
    public function test_import_rejects_unknown_header_column(): void
    {
        $res = $this->import($this->admin(), $this->xlsxUpload(
            [array_merge($this->fileRow('F01', 1), ['x'])],
            array_merge(self::HEADER, ['hacker_column'])
        ));

        $res->assertStatus(422);
        $this->assertSame('hacker_column', $res->json('row_errors')[0]['column']);
    }

    public function test_import_requires_reason(): void
    {
        $this->import($this->admin(), $this->xlsxUpload([$this->fileRow('F01', 1)]), [])
            ->assertStatus(422);
    }

    // 数值列与单格编辑器同一套 FIELD_MAX / 整数特判(批量入口不能是绕过上限的后门)
    public function test_import_enforces_field_max_and_integer_rules(): void
    {
        $res = $this->import($this->admin(), $this->xlsxUpload([
            $this->fileRow('F01', 1, ['duration_seconds' => 604801]),
            $this->fileRow('F01', 2, ['worker_required' => 3.5]),
        ]));

        $res->assertStatus(422);
        $columns = array_column($res->json('row_errors'), 'column');
        $this->assertContains('duration_seconds', $columns);
        $this->assertContains('worker_required', $columns);
    }

    public function test_import_requires_edit_definition_permission(): void
    {
        $this->import($this->player(), $this->xlsxUpload([$this->fileRow('F01', 1)]))
            ->assertStatus(403);
    }

    // 传一个根本不是 xlsx 的文件:422 而不是 500
    public function test_import_rejects_non_xlsx_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'blt');
        file_put_contents($path, '不是 zip');
        $file = new UploadedFile($path, 'levels.xlsx', 'application/octet-stream', null, true);

        $this->import($this->admin(), $file)->assertStatus(422);
    }
}
