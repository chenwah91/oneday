<?php

namespace Tests\Feature\Definition;

use App\Game\City\CityFactory;
use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimulationService;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 资源 ID 英文化迁移的守门测试:
// 定义表 / 存档表 / 定义 JSON 三处都不得再出现中文资源键,rs_code 必须与映射文档一致
class ResourceCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    // 合法 code:小写字母 + 下划线
    private function assertValidCode(string $code, string $where): void
    {
        $this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $code, "$where 出现非法资源 ID:$code");
        $this->assertArrayHasKey($code, ResourceCode::CHINESE_NAMES, "$where 的 $code 不在 ResourceCode 映射表里");
    }

    public function test_resource_definition_uses_english_codes_and_keeps_chinese_names(): void
    {
        $rows = DB::table('resource_definition')->get(['resource_id', 'name']);
        $this->assertCount(31, $rows);

        foreach ($rows as $r) {
            $this->assertValidCode($r->resource_id, 'resource_definition');
            // 显示名保留中文:必含非 ASCII 字符
            $this->assertMatchesRegularExpression('/[^\x00-\x7F]/', $r->name, "resource_definition.name 应为中文显示名,实为 {$r->name}");
            $this->assertSame(ResourceCode::CHINESE_NAMES[$r->resource_id], $r->name);
        }
    }

    // rs_code 与 docs/templates/resource-code-map.md 的对照表逐行一致(文档即评审依据,不能漂移)
    public function test_rs_code_matches_mapping_document(): void
    {
        $doc = file_get_contents(base_path('docs/templates/resource-code-map.md'));
        $this->assertNotFalse($doc, '找不到映射文档');

        $expected = [];
        foreach (explode("\n", $doc) as $line) {
            $cells = explode('|', trim($line));
            if (count($cells) !== 7) {
                continue; // 只取「中文名 / code / rs_code / 类别 / 首次时代」5 列的表(第 1 节)
            }
            $code = trim($cells[2], " `");
            if (! preg_match('/^[a-z][a-z_]*$/', $code)) {
                continue; // 表头与分隔行
            }
            $rs = trim($cells[3]);
            $expected[$code] = str_contains($rs, '无') ? null : $rs;
        }

        $this->assertCount(31, $expected, '映射文档第 1 节应恰好列出 31 种库存资源');

        $actual = DB::table('resource_definition')->pluck('rs_code', 'resource_id')->all();
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, 'resource_definition.rs_code 与映射文档不一致');

        // §8 收录 RS001-RS026 共 26 条,其余 5 种资源 rs_code 为 NULL
        $this->assertSame(26, count(array_filter($actual)));
        $this->assertSame('RS001', $actual[ResourceCode::FOOD]);
        $this->assertSame('RS024', $actual[ResourceCode::MONEY]);
        $this->assertNull($actual[ResourceCode::HIGH_QUALITY_FOOD]);
    }

    // 定义 JSON:cost_json 的键、input_json/output_json 的 resource 值全部是英文 code
    public function test_building_level_json_has_no_chinese_resource_keys(): void
    {
        $rows = DB::table('building_level_definition')->get(['building_id', 'level', 'cost_json', 'input_json', 'output_json']);
        $this->assertCount(282, $rows);

        foreach ($rows as $r) {
            $where = "{$r->building_id} L{$r->level}";
            foreach (array_keys(json_decode($r->cost_json ?: '{}', true) ?: []) as $code) {
                $this->assertValidCode((string) $code, "$where cost_json");
            }
            foreach (['input_json', 'output_json'] as $col) {
                foreach (json_decode($r->{$col} ?: '[]', true) ?: [] as $entry) {
                    $this->assertValidCode((string) $entry['resource'], "$where $col");
                }
            }
        }
    }

    // 存档表:建城初始资源 + 一轮结算写入的资源行,全部是英文 code,没有中文残留
    public function test_city_resources_have_no_chinese_keys_after_simulation(): void
    {
        $u = User::create(['username' => 'codecity', 'name' => 'codecity', 'email' => 'cc@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        // 摆一座磨坊(吃 food 产 flour),让结算 upsert 出新的资源行
        CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'P01', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active']);
        $city->update(['last_simulated_at' => now()->subSeconds(600)]);
        SimulationService::simulate($city->fresh());

        $ids = DB::table('city_resources')->where('city_id', $city->id)->pluck('resource_id')->all();
        $this->assertNotEmpty($ids);
        foreach ($ids as $id) {
            $this->assertValidCode((string) $id, 'city_resources');
        }
        $this->assertContains(ResourceCode::FLOUR, $ids, '结算应写入 flour 行(证明产出键也是 code)');
    }

    public function test_resources_endpoint_returns_code_and_chinese_name(): void
    {
        $u = User::create(['username' => 'resviewer', 'name' => 'resviewer', 'email' => 'rv@x.com', 'password' => 'password123']);
        $res = $this->actingAs($u)->getJson('/api/definitions/resources');

        $res->assertOk();
        $res->assertJsonStructure(['data' => ['resources' => [['code', 'name', 'rsCode', 'category', 'era']]]]);
        $this->assertCount(31, $res->json('data.resources'));

        $byCode = [];
        foreach ($res->json('data.resources') as $r) {
            $byCode[$r['code']] = $r;
        }
        $this->assertSame('粮食', $byCode[ResourceCode::FOOD]['name']);
        $this->assertSame('RS001', $byCode[ResourceCode::FOOD]['rsCode']);
        $this->assertSame('木材', $byCode[ResourceCode::WOOD]['name']);
        $this->assertNull($byCode[ResourceCode::CEMENT]['rsCode'], '§8 未收录的资源 rsCode 应为 null');
    }

    public function test_resources_endpoint_requires_auth(): void
    {
        $this->getJson('/api/definitions/resources')->assertStatus(401);
    }

    // 数据版本:定义数值变了必须留版本号(CLAUDE §65)
    public function test_game_data_version_recorded(): void
    {
        $this->assertTrue(
            DB::table('game_data_versions')->where('version', 'V3.1.2')->exists(),
            '资源 code 迁移 + 粮耗 0.03 必须对应一条 V3.1.2 数据版本'
        );
    }
}
