<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\City\EraService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 时代升级入口:校验意图 → EraService → 统一响应
// GameRuleException 不在此捕获,交由 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)
//
// 没有业务参数:升到「下一个时代」,目标时代由服务器按 cities.era_order + 1 决定,
// 客户端既不能指定城市(一个玩家一座城,城由 session 用户取),也不能指定要升到哪一代(CLAUDE §31/§44)
class EraUpgradeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // 只做类型/长度的 allowlist 校验;条件是否达标一律由服务层在锁内判定(CLAUDE §45)
        // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
        $data = $request->validate([
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());

        $diff = EraService::upgrade(
            $city,
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        );

        return ApiResponse::ok(['data' => $diff]);
    }
}
