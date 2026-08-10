<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Technology\TechService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 科技研究入口:校验意图 → TechService → 统一响应
// GameRuleException 不在此捕获,交由 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)
//
// 请求里没有 city_id:一个玩家一座城,城市一律由 CityFactory 按当前 session 用户取,
// 客户端无从指定别人的城(CLAUDE §44「不能只因为客户端知道一个 cityId 就允许操作」)
class ResearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // 只做类型/长度的 allowlist 校验;tech_id 是否存在、前置/时代/费用是否满足
        // 一律由服务层在锁内判定(CLAUDE §45)
        // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
        $data = $request->validate([
            'tech_id'           => ['required', 'string', 'max:32'],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());

        $diff = TechService::research(
            $city,
            (string) $data['tech_id'],
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        );

        return ApiResponse::ok(['data' => $diff]);
    }
}
