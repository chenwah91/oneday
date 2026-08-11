<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Item\ItemService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 工具端点:制作 / 装备 / 卸下(M3-D2)。
//
// Controller 只做三件事(CLAUDE §11「Controller 必须保持简单」):
//   ① Allowlist 输入校验(类型 / 范围,业务上限一律交服务层在锁内判);
//   ② 所有权校验(要用 request 写 Security Log,所以留在这一层);
//   ③ 调服务层 → 统一响应。
// GameRuleException 不在此捕获,交 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)。
//
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)。
class ItemController extends Controller
{
    // 制作:客户端只提交 item_id —— 成本 / 耐久 / 效果 / 时代与建筑前置全部由服务器从
    // item_definition 读(CLAUDE §45「不要信任客户端传来的建筑价格 / 产量」)
    public function craft(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 长度对齐 item_definition.item_id 的 VARCHAR(16)
            'item_id'           => ['required', 'string', 'max:16'],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());

        return ApiResponse::ok(['data' => ItemService::craft(
            $city,
            (string) $data['item_id'],
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        )]);
    }

    public function equip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city_item_id'         => ['required', 'integer', 'min:1'],
            'building_instance_id' => ['required', 'integer', 'min:1'],
            'idempotency_key'      => ['nullable', 'string', 'max:100'],
            'expected_revision'    => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());

        if ($denied = $this->denyIfNotOwner($request, $city, (int) $data['city_item_id'])) {
            return $denied;
        }

        return ApiResponse::ok(['data' => ItemService::equip(
            $city,
            (int) $data['city_item_id'],
            (int) $data['building_instance_id'],
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        )]);
    }

    public function unequip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city_item_id'      => ['required', 'integer', 'min:1'],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());

        if ($denied = $this->denyIfNotOwner($request, $city, (int) $data['city_item_id'])) {
            return $denied;
        }

        return ApiResponse::ok(['data' => ItemService::unequip(
            $city,
            (int) $data['city_item_id'],
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        )]);
    }

    // 所有权(CLAUDE §44):先全局查这一行,再比 city_id ——
    // 只在本城范围内查的话,「不存在」与「不属于本城」会混成同一个 404,
    // 越权尝试就不会留下任何痕迹(§67 的「多城市请求 Ownership 失败」检测项也就失效了)
    private function denyIfNotOwner(Request $request, $city, int $cityItemId): ?JsonResponse
    {
        $item = DB::table('city_items')->where('id', $cityItemId)->first();
        if (! $item) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        if ((int) $item->city_id === (int) $city->id) {
            return null;
        }

        AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
            'actor_id' => $city->user_id, 'user_id' => $city->user_id,
            'entity_type' => 'city_item', 'entity_id' => (string) $cityItemId, 'reason_code' => 'NOT_OWNER',
        ]);
        // 审计负责业务可追溯,Security Log 负责异常检测(CLAUDE §60),两者并行不互相替代
        SecurityLogger::log('security.authorization_failed', [
            'user_id' => (int) $city->user_id, 'route' => $request->path(),
            'reason' => 'NOT_OWNER', 'entity_type' => 'city_item', 'entity_id' => (string) $cityItemId,
        ]);

        return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
    }
}
