<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Definition\GameDataVersion;
use App\Game\Simulation\SimulationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $buildings = $city->buildingInstances()->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'buildingId' => $b->building_id, 'level' => $b->level,
                'x' => $b->x, 'y' => $b->y, 'status' => $b->status,
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
