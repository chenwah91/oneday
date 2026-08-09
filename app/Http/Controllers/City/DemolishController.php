<?php

namespace App\Http\Controllers\City;

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

        $newRevision = DB::transaction(function () use ($city, $instanceId, $inst) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();
            DB::table('city_building_instances')->where('id', $instanceId)->delete();
            $rev = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $rev]);
            AuditLogger::record(AuditAction::BUILDING_DEMOLISH, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $rev,
                'metadata_json' => ['buildingId' => $inst->building_id],
            ]);

            return $rev;
        });

        return ApiResponse::ok(['data' => ['revision' => $newRevision, 'demolishedId' => $instanceId]]);
    }
}
