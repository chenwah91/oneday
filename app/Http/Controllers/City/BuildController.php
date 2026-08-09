<?php

namespace App\Http\Controllers\City;

use App\Game\Building\BuildService;
use App\Game\City\CityFactory;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 建造入口:校验意图 → BuildService → 统一响应
// GameRuleException 不在此捕获,交由 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)
class BuildController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'buildingId'       => ['required', 'string', 'max:16'],
            'x'                => ['required', 'integer', 'min:0', 'max:999'],
            'y'                => ['required', 'integer', 'min:0', 'max:999'],
            'idempotencyKey'   => ['nullable', 'string', 'max:100'],
            'expectedRevision' => ['nullable', 'integer'],
        ]);

        $city = CityFactory::createForUser($request->user());

        $diff = BuildService::build(
            $city, $data['buildingId'], (int) $data['x'], (int) $data['y'],
            $data['idempotencyKey'] ?? null,
            isset($data['expectedRevision']) ? (int) $data['expectedRevision'] : null
        );

        return ApiResponse::ok(['data' => $diff]);
    }
}
