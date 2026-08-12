<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 封禁 / 解禁玩家(W11-C1 任务4)。权限 ban_player(admin 及以上,见 App\Support\Role)。
//
// ══ 绝不删除任何玩家数据 ═══════════════════════════════════════════════════════
// 封禁 = users 上多一个时间戳,城市 / 资源 / 建筑 / NPC / 审计一行不动。
// 解禁 = 把时间戳置回 NULL,账号完整复原。这条是硬红线:
// 「封号顺手清档」在申诉翻案时无法回滚,而封号误判在任何运营系统里都是常态。
//
// ══ 三道判定 ═══════════════════════════════════════════════════════════════════
//   ① 目标不存在              → 404
//   ② 目标是管理角色(support 及以上)→ 422。后台账号的处置走 manage_admin / admin:promote 那条线,
//      不给「一个 admin 把另一个 admin 锁在门外」的自助入口(也顺带挡住自封自己)
//   ③ 已是目标状态            → 幂等:返回当前状态,changed=false,**不重复写审计**。
//      重复封禁写第二条审计会让「他被封过几次」这个统计失真
//
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)。
class AdminBanController extends Controller
{
    // POST /api/admin/players/{id}/ban
    public function ban(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            // reason 至少 5 字(§63 强制填原因,「abc」等于没填);
            // 上限 80 对齐 audit_logs.reason_code 列宽 —— 超长会导致写审计失败、事务回滚且不留痕
            'reason' => ['required', 'string', 'min:5', 'max:80'],
        ]);

        return $this->apply($request, $id, true, trim((string) $data['reason']));
    }

    // POST /api/admin/players/{id}/unban
    //
    // 解禁的 reason 是可选的:封禁必须说明理由(那是对玩家的处罚),
    // 解禁是恢复原状,填不填都不改变结果;填了就进审计的 reason_code
    public function unban(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'min:5', 'max:80'],
        ]);

        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';

        return $this->apply($request, $id, false, $reason !== '' ? $reason : 'UNBAN');
    }

    // 封 / 解共用的落地路径:事务 + 行锁 + 幂等 + 审计
    private function apply(Request $request, int $id, bool $ban, string $reason): JsonResponse
    {
        $admin = $request->user();

        $result = DB::transaction(function () use ($id, $ban, $reason, $admin) {
            // lockForUpdate:锁住该行直到提交。没有它,两个管理员同时封同一个人会各读到
            // banned_at=NULL,各写一条审计 —— before/after 里就出现了两次「从未封禁 → 已封禁」
            $target = DB::table('users')->where('id', $id)->lockForUpdate()
                ->first(['id', 'username', 'role', 'banned_at', 'ban_reason']);

            if (! $target) {
                return ['outcome' => 'not_found'];
            }

            $role = is_string($target->role) ? $target->role : null;
            if ($ban && Role::isStaff($role)) {
                return ['outcome' => 'staff'];
            }

            $alreadyBanned = $target->banned_at !== null;

            // 幂等:已经是目标状态就原样返回,不写库也不写审计
            if ($alreadyBanned === $ban) {
                return ['outcome' => 'ok', 'changed' => false, 'target' => $target];
            }

            $now = now();
            $after = $ban
                ? ['banned_at' => $now->format('Y-m-d H:i:s'), 'ban_reason' => $reason]
                : ['banned_at' => null, 'ban_reason' => null];

            DB::table('users')->where('id', $id)->update($after + ['updated_at' => $now]);

            AuditLogger::record(
                $ban ? AuditAction::ADMIN_PLAYER_BAN : AuditAction::ADMIN_PLAYER_UNBAN,
                'success',
                [
                    'actor_type' => 'admin', 'actor_id' => $admin->id,
                    // user_id 是**被处置的玩家**;city_id 留空 —— 封禁是账号级动作,
                    // 与某座城市无关,挂上去只会污染该城的审计视图(按玩家查走 idx_audit_user_time)
                    'user_id' => (int) $target->id,
                    'entity_type' => 'user', 'entity_id' => (string) $target->id,
                    'reason_code' => $reason,
                    'before_json' => ['banned_at' => $target->banned_at, 'ban_reason' => $target->ban_reason],
                    'after_json'  => $after,
                    'metadata_json' => ['username' => $target->username, 'role' => $role],
                ]
            );

            // 返回给调用方的是**写入后**的状态,不是锁到的旧行
            $target->banned_at = $after['banned_at'];
            $target->ban_reason = $after['ban_reason'];

            return ['outcome' => 'ok', 'changed' => true, 'target' => $target];
        });

        if ($result['outcome'] === 'not_found') {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        if ($result['outcome'] === 'staff') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['id' => ['不可封禁管理角色账号,后台账号的处置走角色管理']],
            ]);
        }

        $target = $result['target'];

        return ApiResponse::ok(['data' => [
            // changed=false 表示「本次没有改变任何东西」(幂等命中),前端据此不必再弹成功提示
            'changed' => $result['changed'],
            'player'  => [
                'id'         => (int) $target->id,
                'username'   => $target->username,
                'role'       => $target->role,
                'banned'     => $target->banned_at !== null,
                'banned_at'  => $target->banned_at,
                'ban_reason' => $target->ban_reason,
            ],
        ]]);
    }
}
