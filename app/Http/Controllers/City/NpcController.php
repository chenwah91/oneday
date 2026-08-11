<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\NPC\NpcService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// NPC 端点:招募 / 派驻 / 撤下 / 辞退(M3-D1)。
//
// Controller 只做三件事(CLAUDE §11「Controller 必须保持简单」):
//   ① Allowlist 输入校验(类型 / 范围,业务上限一律交服务层在锁内判);
//   ② 所有权校验(要用 request 写 Security Log,所以留在这一层);
//   ③ 调服务层 → 统一响应。
// GameRuleException 不在此捕获,交 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)。
//
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)。
class NpcController extends Controller
{
    // 招募:**入参里没有 npc_id** —— 抽到谁由服务器掷点决定(CLAUDE §30「NPC 稀有度」明文
    // 属于客户端不能决定的东西)。客户端能提供的只有幂等键与并发版本号
    public function recruit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());

        return ApiResponse::ok(['data' => NpcService::recruit(
            $city,
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        )]);
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city_npc_id'          => ['required', 'integer', 'min:1'],
            'building_instance_id' => ['required', 'integer', 'min:1'],
            'idempotency_key'      => ['nullable', 'string', 'max:100'],
            'expected_revision'    => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());

        if ($denied = $this->denyIfNotOwner($request, $city, (int) $data['city_npc_id'])) {
            return $denied;
        }

        return ApiResponse::ok(['data' => NpcService::assign(
            $city,
            (int) $data['city_npc_id'],
            (int) $data['building_instance_id'],
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        )]);
    }

    public function unassign(Request $request): JsonResponse
    {
        $data = $this->validateNpcOnly($request);
        $city = CityFactory::createForUser($request->user());

        if ($denied = $this->denyIfNotOwner($request, $city, (int) $data['city_npc_id'])) {
            return $denied;
        }

        return ApiResponse::ok(['data' => NpcService::unassign(
            $city,
            (int) $data['city_npc_id'],
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        )]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        $data = $this->validateNpcOnly($request);
        $city = CityFactory::createForUser($request->user());

        if ($denied = $this->denyIfNotOwner($request, $city, (int) $data['city_npc_id'])) {
            return $denied;
        }

        return ApiResponse::ok(['data' => NpcService::dismiss(
            $city,
            (int) $data['city_npc_id'],
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        )]);
    }

    private function validateNpcOnly(Request $request): array
    {
        return $request->validate([
            'city_npc_id'       => ['required', 'integer', 'min:1'],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    // 所有权(CLAUDE §44):先全局查这一行,再比 city_id ——
    // 只在本城范围内查的话,「不存在」与「不属于本城」会混成同一个 404,
    // 越权尝试就不会留下任何痕迹(§67 的「多城市请求 Ownership 失败」检测项也就失效了)
    private function denyIfNotOwner(Request $request, $city, int $cityNpcId): ?JsonResponse
    {
        $npc = DB::table('city_npcs')->where('id', $cityNpcId)->first();
        if (! $npc) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        if ((int) $npc->city_id === (int) $city->id) {
            return null;
        }

        AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
            'actor_id' => $city->user_id, 'user_id' => $city->user_id,
            'entity_type' => 'city_npc', 'entity_id' => (string) $cityNpcId, 'reason_code' => 'NOT_OWNER',
        ]);
        // 审计负责业务可追溯,Security Log 负责异常检测(CLAUDE §60),两者并行不互相替代
        SecurityLogger::log('security.authorization_failed', [
            'user_id' => (int) $city->user_id, 'route' => $request->path(),
            'reason' => 'NOT_OWNER', 'entity_type' => 'city_npc', 'entity_id' => (string) $cityNpcId,
        ]);

        return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
    }
}
