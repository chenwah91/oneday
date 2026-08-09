<?php

namespace App\Http\Controllers\City;

use App\Game\Building\GameRuleException;
use App\Game\City\CityFactory;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 拆除:所有权校验 + 删除实例 + 审计(M1 不返还资源)
class DemolishController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['instanceId' => ['required', 'integer']]);
        $city = CityFactory::createForUser($request->user());
        $instanceId = (int) $data['instanceId'];

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
            return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
        }

        try {
            $newRevision = DB::transaction(function () use ($city, $instanceId, $inst) {
                $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

                // 限定 city_id 并校验影响行数:防止实例在所有权校验与加锁之间被并发拆除,产生"假成功"(revision 空涨)
                $affected = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)->delete();
                if ($affected !== 1) {
                    throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
                }

                $rev = (int) $locked->revision + 1;
                DB::table('cities')->where('id', $city->id)->update(['revision' => $rev]);
                AuditLogger::record(AuditAction::BUILDING_DEMOLISH, 'success', [
                    'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                    'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                    'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $rev,
                    'before_json' => ['level' => $inst->level, 'x' => $inst->x, 'y' => $inst->y],
                    'metadata_json' => ['buildingId' => $inst->building_id],
                ]);

                return $rev;
            });
        } catch (GameRuleException $e) {
            return ApiResponse::fail($e->errorCode, $e->status);
        }

        return ApiResponse::ok(['data' => ['revision' => $newRevision, 'demolishedId' => $instanceId]]);
    }
}
