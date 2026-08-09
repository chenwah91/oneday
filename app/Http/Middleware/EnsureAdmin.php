<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\SecurityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// 仅管理员可过;非登录 401,登录非 admin 403 并写安全审计
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::fail(ErrorCode::AUTH_REQUIRED, 401);
        }
        if ($user->role !== 'admin') {
            AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                'actor_id' => $user->id, 'user_id' => $user->id,
                'reason_code' => 'NOT_ADMIN',
                'metadata_json' => ['path' => $request->path()],
            ]);
            // 非管理员访问后台是高风险信号,除审计外单独进 Security Log 便于告警(CLAUDE §60)
            SecurityLogger::log('security.authorization_failed', [
                'user_id' => $user->id, 'route' => $request->path(),
                'reason' => 'NOT_ADMIN', 'method' => $request->method(),
                'error_code' => ErrorCode::FORBIDDEN,
            ]);
            return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
        }
        return $next($request);
    }
}
