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
