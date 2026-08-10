<?php

namespace Tests\Feature\Definition;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_counts_match_v31(): void
    {
        $this->assertSame(10, DB::table('era')->count());
        $this->assertSame(31, DB::table('resource_definition')->count());
        $this->assertSame(50, DB::table('technology_definition')->count());
        $this->assertSame(94, DB::table('building_definition')->count());
        $this->assertSame(282, DB::table('building_level_definition')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('game_data_versions')->count());
    }

    public function test_every_building_has_three_levels(): void
    {
        $bad = DB::table('building_level_definition')
            ->select('building_id')
            ->groupBy('building_id')
            ->havingRaw('COUNT(*) <> 3')
            ->get();
        $this->assertCount(0, $bad, '有建筑不是恰好3级');
    }

    public function test_referential_integrity(): void
    {
        // 每个建筑的 era_key 都存在
        $eraKeys = DB::table('era')->pluck('era_key')->all();
        $badEra = DB::table('building_definition')->whereNotIn('era_key', $eraKeys)->count();
        $this->assertSame(0, $badEra);

        // 每个 level 的 building_id 都存在
        $buildingIds = DB::table('building_definition')->pluck('building_id')->all();
        $badLevel = DB::table('building_level_definition')->whereNotIn('building_id', $buildingIds)->count();
        $this->assertSame(0, $badLevel);
    }

    // 无来源资源守门(v3.2-resource-source-mapping.md §8.3 第 3 条):
    // 任何出现在 cost / input 里的库存资源,必须至少有一条 output —— 否则该资源永远拿不到,
    // 下游建筑连锁锁死。允许留白的只有下面白名单里那两种,且必须写清为什么。
    public function test_every_consumed_resource_has_a_production_source(): void
    {
        // 明确留给 M3 市场 / 非产线来源的资源,改动这张表必须同步更新草案与 backlog
        $allowedWithoutOutput = [
            // 货币不由建筑产线产出:来源是税收、市场卖出与事件奖励(v3.2 §10.5 财政)
            'money',
            // 电子元件:草案 §6 的 E-①/②/③ 未裁决,V3.2.0 不造假产线,时代 X 建筑在 M3 市场开放前保持锁死
            'electronic_components',
        ];

        $stock = DB::table('resource_definition')->pluck('resource_id')->all();

        $produced = [];
        $consumed = [];
        foreach (DB::table('building_level_definition')->get(['cost_json', 'input_json', 'output_json']) as $r) {
            foreach (array_keys(json_decode($r->cost_json ?: '{}', true) ?: []) as $code) {
                $consumed[$code] = true;
            }
            foreach (json_decode($r->input_json ?: '[]', true) ?: [] as $e) {
                $consumed[$e['resource']] = true;
            }
            foreach (json_decode($r->output_json ?: '[]', true) ?: [] as $e) {
                $produced[$e['resource']] = true;
            }
        }

        $orphans = [];
        foreach ($stock as $code) {
            if (isset($consumed[$code]) && ! isset($produced[$code]) && ! in_array($code, $allowedWithoutOutput, true)) {
                $orphans[] = $code;
            }
        }

        $this->assertSame([], $orphans, '这些资源有下游需求却没有任何产出来源:' . implode('、', $orphans));

        // V3.2.0 补链的四种资源必须确实有来源了(防止白名单被顺手放宽)
        foreach (['clay', 'sand_gravel', 'cement', 'medicine'] as $code) {
            $this->assertArrayHasKey($code, $produced, "{$code} 应在 V3.2.0 补链后具备产出来源");
        }
    }

    // V3.2.0 补链的产出/投入落在指定建筑的指定等级上(草案 §2~§5 方案 A 的逐条数值)
    public function test_v320_resource_source_mapping_values(): void
    {
        $expected = [
            // building_id, level, 字段, 资源, 每分钟速率
            ['R02', 1, 'output_json', 'clay', 10],
            ['R02', 2, 'output_json', 'clay', 13.5],
            ['R02', 3, 'output_json', 'clay', 18],
            ['R02', 1, 'output_json', 'sand_gravel', 10],
            ['R02', 2, 'output_json', 'sand_gravel', 13.5],
            ['R02', 3, 'output_json', 'sand_gravel', 18],
            ['P06', 1, 'input_json', 'stone', 6],
            ['P06', 2, 'input_json', 'stone', 7.08],
            ['P06', 3, 'input_json', 'stone', 8.7],
            ['P06', 1, 'output_json', 'cement', 6],
            ['P06', 2, 'output_json', 'cement', 8.1],
            ['P06', 3, 'output_json', 'cement', 10.8],
            ['M01', 1, 'input_json', 'food', 6],
            ['M01', 2, 'input_json', 'food', 7.08],
            ['M01', 3, 'input_json', 'food', 8.7],
            ['M01', 1, 'output_json', 'medicine', 3],
            ['M01', 2, 'output_json', 'medicine', 4.05],
            ['M01', 3, 'output_json', 'medicine', 5.4],
        ];

        foreach ($expected as [$buildingId, $level, $column, $code, $rate]) {
            $json = DB::table('building_level_definition')
                ->where('building_id', $buildingId)->where('level', $level)->value($column);

            $rates = [];
            foreach (json_decode($json ?: '[]', true) ?: [] as $e) {
                $rates[$e['resource']] = $e['rate_per_min'];
            }

            $this->assertArrayHasKey($code, $rates, "{$buildingId} L{$level} 的 {$column} 缺 {$code}");
            $this->assertEqualsWithDelta($rate, $rates[$code], 0.001, "{$buildingId} L{$level} 的 {$code} 速率不符");
        }

        // 51 条水泥成本行一条都不改(草案 §4.2 A3-7):水泥仍然只出现在 cost,不出现在 input
        $cementInputs = 0;
        foreach (DB::table('building_level_definition')->pluck('input_json') as $json) {
            if (in_array('cement', array_column(json_decode($json ?: '[]', true) ?: [], 'resource'), true)) {
                $cementInputs++;
            }
        }
        $this->assertSame(0, $cementInputs, '水泥应只作为一次性建材出现在 cost,不应出现在 input');
    }

    // 数据形状变了必须留次版本号(CLAUDE §65 / 两份草案的 §8.2 与 §9)
    public function test_game_data_version_v320_recorded(): void
    {
        $this->assertTrue(
            DB::table('game_data_versions')->where('version', 'V3.2.0')->exists(),
            '资源补链 + 升级链重映射必须对应一条 V3.2.0 数据版本'
        );
    }

    public function test_cost_json_keys_are_known_resources_or_currency(): void
    {
        $resources = DB::table('resource_definition')->pluck('resource_id')->all();
        $level = DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 1)->first();
        $cost = json_decode($level->cost_json, true);
        foreach (array_keys($cost) as $res) {
            $this->assertContains($res, $resources, "成本资源 $res 不在 resource_definition");
        }
    }
}
