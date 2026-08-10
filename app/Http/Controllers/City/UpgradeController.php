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
        // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
        $data = $request->validate([
            'instance_id'       => ['required', 'integer'],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer'],
        ]);

        $city = CityFactory::createForUser($request->user());

        $diff = UpgradeService::upgrade(
            $city, (int) $data['instance_id'],
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        );

        return ApiResponse::ok(['data' => $diff]);
    }
}
