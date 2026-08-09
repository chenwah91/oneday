<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Simulation\SimulationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\Idempotency;
use App\Support\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 拆除:幂等 + Revision + 所有权校验 + 删除实例 + 审计(M1 不返还资源)
// GameRuleException 不在此捕获,交由 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)
class DemolishController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // idempotencyKey / expectedRevision 保持可选:强制化会破坏已发布 PWA 的契约
        $data = $request->validate([
            'instanceId'       => ['required', 'integer'],
            'idempotencyKey'   => ['nullable', 'string', 'max:100'],
            'expectedRevision' => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());
        $instanceId = (int) $data['instanceId'];
        $idempotencyKey = $data['idempotencyKey'] ?? null;
        $expectedRevision = isset($data['expectedRevision']) ? (int) $data['expectedRevision'] : null;
        // 请求指纹:只含业务参数,不含 expectedRevision(重试时 revision 可能已变)
        $requestHash = Idempotency::hash(AuditAction::BUILDING_DEMOLISH, ['instanceId' => $instanceId]);

        // 幂等:锁前先查。必须早于"实例是否存在"判定 —— 重放时实例已被删,否则会误报 NOT_FOUND
        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::BUILDING_DEMOLISH, $requestHash) !== null) {
            return ApiResponse::ok(['data' => [
                'revision'     => (int) DB::table('cities')->where('id', $city->id)->value('revision'),
                'demolishedId' => $instanceId,
            ]]);
        }

        // 所有权:先全局查实例(不能只在本城范围内查,否则"不存在"与"不属于本城"无法区分)
        $inst = DB::table('city_building_instances')->where('id', $instanceId)->first();
        if (! $inst) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        if ((int) $inst->city_id !== (int) $city->id) {
            AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId, 'reason_code' => 'NOT_OWNER',
            ]);
            // 审计负责业务可追溯,Security Log 负责异常检测(CLAUDE §60),两者并行不互相替代
            SecurityLogger::log('security.authorization_failed', [
                'user_id' => (int) $city->user_id, 'route' => $request->path(),
                'reason' => 'NOT_OWNER', 'entity_type' => 'building', 'entity_id' => (string) $instanceId,
            ]);
            return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
        }

        $newRevision = DB::transaction(function () use ($city, $instanceId, $inst, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后重新校验,关闭"锁前检查、锁后写入"之间的并发窗口(TOCTOU),不再删第二次
            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::BUILDING_DEMOLISH, $requestHash) !== null) {
                return (int) $locked->revision;
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先跑 Time Delta 结算(CLAUDE §51):拆除虽不校验资源,
            // 但必须先把"拆除前时段"的产出结清,否则被拆建筑这段时间应得的产出会丢失
            SimulationService::applyLocked($locked, now());

            // 限定 city_id 并校验影响行数:防止实例在所有权校验与加锁之间被并发拆除,产生"假成功"(revision 空涨)
            $affected = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)->delete();
            if ($affected !== 1) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            $rev = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $rev]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::BUILDING_DEMOLISH, $requestHash);
            }

            AuditLogger::record(AuditAction::BUILDING_DEMOLISH, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $rev,
                'before_json' => ['level' => $inst->level, 'x' => $inst->x, 'y' => $inst->y],
                'metadata_json' => ['buildingId' => $inst->building_id],
                'idempotency_key' => $idempotencyKey,
            ]);

            return $rev;
        });

        return ApiResponse::ok(['data' => ['revision' => $newRevision, 'demolishedId' => $instanceId]]);
    }
}
