<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\Role;
use App\Support\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 后台访问控制(CLAUDE §63 角色分级)。两种用法:
//   middleware('admin')                  => 兜底门槛:任意管理角色(support 及以上)可过
//   middleware('admin:edit_definition')  => 按权限梯度判定,权限表见 App\Support\Role
// 非登录 401;登录但不满足 403 并写审计 + Security Log。player 角色一律拒。
class EnsureAdmin
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::fail(ErrorCode::AUTH_REQUIRED, 401);
        }

        // role 列理论上非空,但取到非字符串(null / 脏数据)时按未知角色处理 => Fail Closed
        $role = is_string($user->role) ? $user->role : null;

        // 不带参数保持原有语义(能进后台即可);带参数时按权限判。
        // Role::isStaff / Role::allows 对未知角色、未知权限一律返回 false,不会「猜测放行」
        $allowed = $permission === null
            ? Role::isStaff($role)
            : Role::allows($role, $permission);

        if (! $allowed) {
            // 区分两类拒绝:压根不是后台人员(NOT_ADMIN) vs 是后台人员但级别不够(MISSING_PERMISSION)
            $reason = Role::isStaff($role) ? 'MISSING_PERMISSION' : 'NOT_ADMIN';

            AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                'actor_id' => $user->id, 'user_id' => $user->id,
                'reason_code' => $reason,
                'metadata_json' => [
                    'path' => $request->path(),
                    'role' => $role,
                    // 缺哪个权限被拒:后续排查「谁该升级角色」时的关键线索
                    'required_permission' => $permission,
                ],
            ]);
            // 非管理员访问后台是高风险信号,除审计外单独进 Security Log 便于告警(CLAUDE §60)
            SecurityLogger::log('security.authorization_failed', [
                'user_id' => $user->id, 'route' => $request->path(),
                'reason' => $reason, 'method' => $request->method(),
                'error_code' => ErrorCode::FORBIDDEN,
            ]);

            return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
        }

        return $next($request);
    }
}
