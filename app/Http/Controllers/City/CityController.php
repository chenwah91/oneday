<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Definition\GameDataVersion;
use App\Game\Population\WorkerService;
use App\Game\Simulation\SimulationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 城市只读快照
class CityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $city = CityFactory::createForUser($user); // 幂等:兜底老账号

        $sim = SimulationService::simulate($city);
        $city = $city->fresh();

        $resources = $city->resources()->pluck('amount', 'resource_id')
            ->map(fn ($a) => (float) $a)->all();

        // 建筑列表联查该级的 worker_required:前端工人面板要「已分配 / 需求」两个数才画得出用工率,
        // 只给 assigned 会逼前端再拉一次 Definition 接口(§38 反 N+1)
        $buildings = DB::table('city_building_instances as ci')
            ->leftJoin('building_level_definition as bl', function ($j) {
                $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
            })
            ->where('ci.city_id', $city->id)
            ->orderBy('ci.id')
            ->get(['ci.id', 'ci.building_id', 'ci.level', 'ci.x', 'ci.y', 'ci.status', 'ci.assigned_workers', 'bl.worker_required'])
            ->map(fn ($b) => [
                'id' => (int) $b->id, 'buildingId' => $b->building_id, 'level' => (int) $b->level,
                'x' => (int) $b->x, 'y' => (int) $b->y, 'status' => $b->status,
                'assignedWorkers' => (int) $b->assigned_workers,
                'workerRequired'  => (int) $b->worker_required,
            ])->all();

        return ApiResponse::ok(['data' => [
            // dataVersion:当前全局数值版本(§64),前端可据此判断本地缓存的 Definition 是否过期
            'dataVersion' => GameDataVersion::current(),
            // serverTime:服务器权威时间(§11.1),施工倒计时等一切计时都要以它对时,绝不能用客户端时间
            'serverTime'  => now()->toIso8601String(),
            'city' => [
                'id'                 => $city->id,
                'name'               => $city->name,
                'revision'           => $city->revision,
                'population'         => $city->population,
                'populationCapacity' => $sim['populationCapacity'],
                // 人口名义增减(人/分钟,§10.3 口径,未夹人口容量):HUD 的人口趋势用
                'populationGrowthPerMin' => $sim['populationGrowthPerMin'],
                // 劳动力(§10.4):可用 = floor(人口 × 0.60);已分配 = 全城各建筑 assigned_workers 之和
                'availableWorkers'   => SimulationService::availableWorkers((int) $city->population),
                'assignedWorkers'    => WorkerService::totalAssigned((int) $city->id),
                'money'              => (float) $city->money,
                'mapWidth'           => $city->map_width,
                'mapHeight'          => $city->map_height,
                'storageCapacity'    => $sim['storageCapacity'],
                'lastSimulatedAt'    => $city->last_simulated_at->toIso8601String(),
                'resources'          => $resources,
                'ratesPerMin'        => $sim['ratesPerMin'],
                'buildings'          => $buildings,
            ],
        ]]);
    }
}
