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
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

// 封禁的第二道闸(W11-C1 任务4):挡住**已经登录**的被封玩家。
//
// 为什么光在登录口拦不够(Fail Closed):封禁发生时,该玩家可能正开着页面 ——
// session 已经建立,后续所有 /api/ 请求都不会再经过 LoginController。
// 只拦登录 = 「封了但他还能继续玩到 session 过期」,而 session 生命期通常按小时计。
//
// ══ 挂载位置:web 中间件组的**末尾**(bootstrap/app.php)═══════════════════════
// 必须在 StartSession 之后 —— 更早的话 $request->user() 恒为 null(session 还没起来,认不出人)。
// 路由级的 auth:web 排在它后面:对已登录的被封用户,本中间件先返回 401 ACCOUNT_BANNED
// (比 auth:web 的 AUTH_REQUIRED 更具体);对未登录请求它直接放行,由 auth:web 照常处理。
//
// ══ 只管 /api/*,且后台人员豁免 ═══════════════════════════════════════════════
//   · 非 api 路径(静态入口 / 健康检查)不拦:被封玩家看得到登录页是正常的;
//   · **后台人员(support 及以上)豁免**:封禁端点本身已禁止封管理角色,这里是第二层保险 ——
//     万一有人直接改库把某个 admin 的 banned_at 写上了,不能让整个运营团队被锁在门外
//     (那种时候恰恰最需要有人能进后台把它改回来)。
class EnsureNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user || $user->banned_at === null) {
            return $next($request);
        }

        if (Role::isStaff(is_string($user->role) ? $user->role : null)) {
            return $next($request);
        }

        // 立刻踢下线:不销毁 session 的话,这条 401 会在他每次刷新时重复触发,
        // 每次都写一条审计 —— 一个开着自动轮询的页面能在一夜之间灌满审计表。
        // 登出之后下一次请求就没有 user 了,会被 auth:web 用普通的 401 挡掉
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
            'actor_id' => $user->id, 'user_id' => $user->id,
            'reason_code' => ErrorCode::ACCOUNT_BANNED,
            'metadata_json' => [
                'path' => $request->path(), 'method' => $request->method(),
                'banned_at' => (string) $user->banned_at,
            ],
        ]);
        // 审计负责业务可追溯,Security Log 负责异常检测(CLAUDE §60),两者并行不互相替代
        SecurityLogger::log('security.authorization_failed', [
            'user_id' => $user->id, 'route' => $request->path(),
            'reason' => ErrorCode::ACCOUNT_BANNED, 'method' => $request->method(),
            'error_code' => ErrorCode::ACCOUNT_BANNED,
        ]);

        return ApiResponse::fail(ErrorCode::ACCOUNT_BANNED, 401);
    }
}
