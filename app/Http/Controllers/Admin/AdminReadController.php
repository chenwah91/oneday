<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 后台只读:玩家 / 城市 / 审计
class AdminReadController extends Controller
{
    // 玩家列表:联查城市 id,仅输出安全字段(不含 password)
    public function players(): JsonResponse
    {
        $rows = DB::table('users as u')
            ->leftJoin('cities as c', 'c.user_id', '=', 'u.id')
            ->select('u.id', 'u.username', 'u.email', 'u.role', 'u.created_at', 'c.id as city_id')
            ->orderBy('u.id')->limit(500)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'username' => $r->username, 'email' => $r->email,
                'role' => $r->role, 'createdAt' => $r->created_at, 'cityId' => $r->city_id,
            ])->all();

        return ApiResponse::ok(['data' => ['players' => $rows]]);
    }

    // 玩家详情:基础信息 + 城市摘要(revision/population/money/建筑数)
    public function playerDetail(int $id): JsonResponse
    {
        // 仅取展示所需字段,password/remember_token 等敏感列不进内存
        $u = DB::table('users')->where('id', $id)->select('id', 'username', 'email', 'role', 'created_at')->first();
        if (! $u) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        $city = DB::table('cities')->where('user_id', $id)->first();
        $citySummary = null;
        if ($city) {
            $citySummary = [
                'id' => $city->id, 'revision' => $city->revision, 'population' => $city->population,
                'money' => (float) $city->money,
                'buildingCount' => DB::table('city_building_instances')->where('city_id', $city->id)->count(),
            ];
        }

        return ApiResponse::ok(['data' => [
            'player' => [
                'id' => $u->id, 'username' => $u->username, 'email' => $u->email,
                'role' => $u->role, 'createdAt' => $u->created_at,
            ],
            'city' => $citySummary,
        ]]);
    }

    // 审计列表:最近审计记录,支持按 action 过滤,limit 强制 clamp 到 [1,200]
    public function audit(Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->query('limit', 50)));
        $q = DB::table('audit_logs')->orderByDesc('id')->limit($limit);
        if ($action = $request->query('action')) {
            $q->where('action', (string) $action);
        }

        $rows = $q->get()->map(fn ($r) => [
            'id' => $r->id, 'occurredAt' => $r->occurred_at, 'action' => $r->action,
            'actorType' => $r->actor_type, 'actorId' => $r->actor_id, 'userId' => $r->user_id,
            'cityId' => $r->city_id, 'status' => $r->status, 'reasonCode' => $r->reason_code,
            'requestId' => $r->request_id,
        ])->all();

        return ApiResponse::ok(['data' => ['audit' => $rows]]);
    }
}
