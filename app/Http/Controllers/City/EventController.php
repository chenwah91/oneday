<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Event\EventCode;
use App\Game\Event\EventRuntimeService;
use App\Game\Event\EventService;
use App\Game\Simulation\SimulationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 随机事件端点:列表 / 结算(M3-D4)。
//
// Controller 只做三件事(CLAUDE §11「Controller 必须保持简单」):
//   ① Allowlist 输入校验(类型 / 范围 / 枚举);
//   ② 所有权校验(要用 request 写 Security Log,所以留在这一层);
//   ③ 调服务层 → 统一响应。
// GameRuleException 不在此捕获,交 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)。
//
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)。
class EventController extends Controller
{
    // GET /api/city/events —— 先结算再返回。
    //
    // 为什么这里也要跑一遍结算:事件是**懒结算**的,不跑就永远不会触发新事件,
    // 玩家打开事件面板却空空如也。与 /api/city 快照同一条路径:先 simulate 再 settle。
    public function index(Request $request): JsonResponse
    {
        $city = CityFactory::createForUser($request->user());

        $sim = SimulationService::simulate($city);
        EventRuntimeService::settle($city, $sim);

        return ApiResponse::ok(['data' => ['events' => EventService::snapshot((int) $city->id)]]);
    }

    // POST /api/city/events/resolve —— §70 五道校验全在服务层,这里只挡输入与越权
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_instance_id' => ['required', 'integer', 'min:1'],
            // choice 只认 a/b/c(§9.2 的三个选项)。没有选项的事件必须不传 —— 服务层会拒
            'choice'            => ['nullable', 'string', 'in:' . implode(',', EventCode::OPTIONS)],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());

        if ($denied = $this->denyIfNotOwner($request, $city, (int) $data['event_instance_id'])) {
            return $denied;
        }

        return ApiResponse::ok(['data' => EventService::resolve(
            $city,
            (int) $data['event_instance_id'],
            $data['choice'] ?? null,
            $data['idempotency_key'] ?? null,
            isset($data['expected_revision']) ? (int) $data['expected_revision'] : null
        )]);
    }

    // 所有权(CLAUDE §44 / §70 第一道):先全局查这一行,再比 city_id ——
    // 只在本城范围内查的话,「不存在」与「不属于本城」会混成同一个 404,
    // 越权尝试就不会留下任何痕迹(§67 的「多城市请求 Ownership 失败」检测项也就失效了)
    private function denyIfNotOwner(Request $request, $city, int $instanceId): ?JsonResponse
    {
        $instance = DB::table('city_events')->where('id', $instanceId)->first(['id', 'city_id']);
        if (! $instance) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        if ((int) $instance->city_id === (int) $city->id) {
            return null;
        }

        AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
            'actor_id' => $city->user_id, 'user_id' => $city->user_id,
            'entity_type' => 'city_event', 'entity_id' => (string) $instanceId, 'reason_code' => 'NOT_OWNER',
        ]);
        // 审计负责业务可追溯,Security Log 负责异常检测(CLAUDE §60),两者并行不互相替代
        SecurityLogger::log('security.authorization_failed', [
            'user_id' => (int) $city->user_id, 'route' => $request->path(),
            'reason' => 'NOT_OWNER', 'entity_type' => 'city_event', 'entity_id' => (string) $instanceId,
        ]);

        return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
    }
}
