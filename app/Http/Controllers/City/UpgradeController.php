<?php

namespace App\Http\Controllers\City;

use App\Game\Building\UpgradeService;
use App\Game\City\CityFactory;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 升级入口:校验意图 → UpgradeService → 统一响应
// GameRuleException 不在此捕获,交由 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)
class UpgradeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instanceId'       => ['required', 'integer'],
            'idempotencyKey'   => ['nullable', 'string', 'max:100'],
            'expectedRevision' => ['nullable', 'integer'],
        ]);

        $city = CityFactory::createForUser($request->user());

        $diff = UpgradeService::upgrade(
            $city, (int) $data['instanceId'],
            $data['idempotencyKey'] ?? null,
            isset($data['expectedRevision']) ? (int) $data['expectedRevision'] : null
        );

        return ApiResponse::ok(['data' => $diff]);
    }
}
