<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Population\WorkerService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 工人分配入口:校验意图 → WorkerService → 统一响应
// GameRuleException 不在此捕获,交由 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)
class WorkerAssignController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // workers 是绝对值设置(0 = 撤光);上限不在这里判(要看实例等级的 worker_required),
        // 这里只做类型/范围的 allowlist 校验,业务上限一律由服务层在锁内判定(CLAUDE §45)
        $data = $request->validate([
            'instanceId'       => ['required', 'integer', 'min:1'],
            'workers'          => ['required', 'integer', 'min:0', 'max:100000'],
            'idempotencyKey'   => ['nullable', 'string', 'max:100'],
            'expectedRevision' => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());

        $diff = WorkerService::assign(
            $city,
            (int) $data['instanceId'],
            (int) $data['workers'],
            $data['idempotencyKey'] ?? null,
            isset($data['expectedRevision']) ? (int) $data['expectedRevision'] : null
        );

        return ApiResponse::ok(['data' => $diff]);
    }
}
