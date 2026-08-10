<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // 测试夹具:直接把科技标成已解锁(正常路径只能走 POST /api/city/research 再等研究时长)。
    //
    // M2-B4 起建造带科技闸门(building_definition.tech_id 必须已解锁),验的是「资源 / 占地 / 幂等 /
    // Revision」等语义的用例必须先把前置科技铺好,否则会被闸门提前挡下,断言的就不再是原来那件事。
    // 闸门本身的正反用例在 BuildTest / ConstructionTest 里单独写。
    protected function unlockTech(int $cityId, string ...$techIds): void
    {
        $now = now();
        foreach ($techIds as $techId) {
            DB::table('city_technologies')->updateOrInsert(
                ['city_id' => $cityId, 'tech_id' => $techId],
                ['status' => 'unlocked', 'started_at' => $now, 'finished_at' => $now,
                    'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    // 同上,但按 building_id 反查它的前置科技,免得每个用例都要背 building → tech 的对照表
    protected function unlockTechFor(int $cityId, string ...$buildingIds): void
    {
        $techIds = DB::table('building_definition')
            ->whereIn('building_id', $buildingIds)
            ->whereNotNull('tech_id')
            ->pluck('tech_id')->unique()->all();

        if ($techIds) {
            $this->unlockTech($cityId, ...$techIds);
        }
    }
}
