<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\Role;
use App\Support\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

// 后台专用登录 / 登出:走 admin guard,与玩家的 web guard 完全独立(CLAUDE §43 / §63)。
//
// ══ 为什么要有这个控制器(2026-08-15 用户实测踩到的问题)══════════════════════════
// 游戏(/game/)与后台(/admin/)同源共用一个浏览器 session。原先两边都打 /api/auth/login
// 写同一个 web guard 登录键:管理员登进后台后,再在游戏页登录玩家号,后台的每个请求就变成了
// 玩家身份 —— 整片 /api/admin/* 当场 403。审计实证:12:26:32 chenwah_admin 登录 →
// 12:26:56 chenwah91 登录 → 之后 api/admin/* 全部 NOT_ADMIN role=player。
// 拆成两个 guard 之后,同一个浏览器可以同时挂着「玩家已登录」和「管理员已登录」,互不覆盖。
//
// ══ 第二个目的:后台只接受管理员 ═════════════════════════════════════════════════
// 玩家账号即使用户名密码全对,也进不了后台(403)。EnsureAdmin 仍然照常在每个请求上再判一次 ——
// 这里是第一道闸,中间件是第二道:管理员登录后被降级 / 角色被改脏,靠的就是第二道兜住(Fail Closed)。
class AdminAuthController extends Controller
{
    // POST /api/admin/auth/login
    //
    // 校验顺序是刻意的(与 LoginController 同一口径):
    //   输入校验 → 查用户 → 密码校验 → 封禁检查 → staff 角色检查
    // 后两道一律排在密码校验**之后**:排在之前的话,任何人拿一个用户名就能试出
    // 「这个号被封了 / 这个号是管理员」,等于白送一个账号枚举面。
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:100'],
        ]);

        // 按 DB 实际匹配方式解析账号,确保限流 key 与登录校验用的是同一个账号
        $user = User::where('username', $credentials['username'])->first();

        // ⚠️ 限流 key 与玩家登录**共用同一个命名空间**(LoginController:34 是同一套写法)。
        //
        // 这是「玩家登入与 admin 登入完全分开」(用户 2026-08-16)的**唯一刻意例外**,
        // 分开反而会削弱安全:两个门各开一个 5 次窗口 = 每个账号白送 10 次猜测。
        // 账号锁定的语义本来就是「按账号」而不是「按入口」—— 攻击者从哪道门试都是在试同一个密码。
        // 会话、审计、端点、前端文案已经全部分家,只有这个计数器有意合并。
        $key = 'login:'.($user ? 'id:'.$user->id : 'anon:'.sha1(mb_strtolower($credentials['username'])));

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::fail(ErrorCode::TOO_MANY_REQUESTS, 429);
        }

        // 用 validate() 而不是 attempt():validate 只校验凭证、**不建立任何登录态**。
        // attempt 会先把人登进来,后面封禁 / 非管理员两条分支就得再 logout 一次把它拆掉 ——
        // 那是「建了再收」,漏收一次就等于放行。这里全部校验通过之后才 login,从构造上不可能漏。
        $ok = $user && Auth::guard('admin')->validate([
            'username' => $credentials['username'],
            'password' => $credentials['password'],
        ]);

        if (! $ok) {
            RateLimiter::hit($key, 900);

            // 审计中的用户名仅在符合注册时的用户名格式时才保留原文,否则脱敏为占位符,
            // 避免用户误把密码敲进用户名框时,密码原文被写入审计日志
            $safeUsername = preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', $credentials['username']) === 1
                ? $credentials['username']
                : '[redacted]';

            // 用 ADMIN.LOGIN_FAILED 而不是玩家侧的 AUTH.LOGIN_FAILED:两道门的审计完全分家,
            // 运营按 action 一查就知道这是「有人在敲后台」而不是「有人在爆破玩家号」
            AuditLogger::record(AuditAction::ADMIN_LOGIN_FAILED, 'failed', [
                // 账号**存在**(只是密码错)时把 id 记上:不记的话 user_id 恒为 NULL,
                // 运营用 GET /api/admin/audit?user_id=N 永远查不出「谁在爆破这个管理员」,
                // 只能靠 metadata 里的用户名字符串人肉翻 —— 而那个字符串脱敏时就可能没了。
                // 账号不存在时保持 null(归不到任何账号,不硬凑)
                'actor_id'      => $user?->id,
                'user_id'       => $user?->id,
                'reason_code'   => 'BAD_CREDENTIALS',
                'metadata_json' => ['username' => $safeUsername],
            ]);

            SecurityLogger::log('security.login_failed', [
                'user_id'    => $user?->id,
                'route'      => $request->path(),
                'reason'     => 'BAD_CREDENTIALS',
                'error_code' => ErrorCode::BAD_CREDENTIALS,
            ]);

            // 与玩家登录端点**完全同一个响应**(401 BAD_CREDENTIALS):
            // 账号不存在 / 密码错在这里不可区分,不给账号枚举留缝
            return ApiResponse::fail(ErrorCode::BAD_CREDENTIALS, 401);
        }

        // 封禁 Fail Closed:密码对了不等于能进来(口径与 LoginController 一致)。
        // 这里不需要 logout —— 上面用的是 validate(),压根没建立登录态。
        // 同样不 clear 限流:失败的登录不该给账号重置任何计数器
        if ($user->banned_at !== null) {
            AuditLogger::record(AuditAction::ADMIN_LOGIN_FAILED, 'rejected', [
                'actor_id'      => $user->id,
                'user_id'       => $user->id,
                'reason_code'   => ErrorCode::ACCOUNT_BANNED,
                'metadata_json' => ['banned_at' => (string) $user->banned_at],
            ]);
            SecurityLogger::log('security.login_failed', [
                'user_id'    => $user->id,
                'route'      => $request->path(),
                'reason'     => ErrorCode::ACCOUNT_BANNED,
                'error_code' => ErrorCode::ACCOUNT_BANNED,
            ]);

            return ApiResponse::fail(ErrorCode::ACCOUNT_BANNED, 401);
        }

        // 非后台人员:密码对、没被封,但角色不是 support 及以上 → 403,进不了后台。
        //
        // 为什么这里敢明说「不是管理员」而不是含糊地回 401:能走到这一步的前提是
        // **他已经掌握了这个账号的密码**,告诉他「此账号不是管理员」没有额外信息泄露;
        // 反过来,运营 / 客服自己登错号时看到明确原因才不会一头雾水地反复试密码。
        // role 取到非字符串(null / 脏数据)时按未知角色处理 → Fail Closed(与 EnsureAdmin 同口径)
        $role = is_string($user->role) ? $user->role : null;
        if (! Role::isStaff($role)) {
            AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                'actor_id'      => $user->id,
                'user_id'       => $user->id,
                'reason_code'   => 'NOT_ADMIN',
                'metadata_json' => ['path' => $request->path(), 'role' => $role],
            ]);
            // 拿着正确密码去敲后台门是高风险信号,除审计外单独进 Security Log 便于告警(CLAUDE §60)
            SecurityLogger::log('security.authorization_failed', [
                'user_id'    => $user->id,
                'route'      => $request->path(),
                'reason'     => 'NOT_ADMIN',
                'method'     => $request->method(),
                'error_code' => ErrorCode::FORBIDDEN,
            ]);

            return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
        }

        RateLimiter::clear($key);
        Auth::guard('admin')->login($user);

        // 只换 session id、保留 session 数据(§43 防会话固定)。
        // 保留数据是关键:同一个浏览器里玩家的登录键就存在这个 session 里,
        // 换成 invalidate() 会把正在玩游戏的那个身份一起抹掉。
        // 副作用:regenerate() 会顺带换掉 CSRF token —— 两边前端都是每次请求现读 XSRF-TOKEN cookie
        // (public/game/js/core/api.js 与 public/admin/js/core/api.js),响应里已带上新 cookie,下一次请求自愈
        $request->session()->regenerate();

        AuditLogger::record(AuditAction::ADMIN_LOGIN, 'success', [
            'actor_id'   => $user->id,
            'user_id'    => $user->id,
            'actor_type' => 'admin',
            'metadata_json' => ['role' => $role],
        ]);

        // 响应形状与玩家登录端点保持一致(id / username / email);
        // 角色与权限清单由 GET /api/admin/me 给,前端按那个渲染导航
        return ApiResponse::ok([
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]],
        ]);
    }

    // POST /api/admin/auth/logout
    //
    // ⚠️ 只 logout admin guard,**绝不** session()->invalidate() / regenerateToken()。
    // 取舍写在这里免得以后有人「顺手对齐玩家登出」:后台与游戏同源共用一个 session,
    // invalidate() 会把同一个浏览器里正在玩游戏的玩家一起踢下线,regenerateToken() 会让
    // 游戏页手上的 CSRF token 当场失效(下一次写操作 419)。退管理员的门,不该动玩家的桌子。
    // 安全性不因此打折:admin guard 的登录键已被移除,后续 /api/admin/* 一律 401。
    public function logout(Request $request): JsonResponse
    {
        $user = Auth::guard('admin')->user();
        $userId = $user->id;
        $role = is_string($user->role) ? $user->role : null;

        Auth::guard('admin')->logout();

        // ADMIN.LOGOUT:与玩家的 AUTH.LOGOUT 分开成两个码(用户 2026-08-16「玩家登入和 admin 登入
        // 需要完全分开」)。靠 actor_type 字段区分不够 —— 运营按 action 统计时两边会混进同一堆
        AuditLogger::record(AuditAction::ADMIN_LOGOUT, 'success', [
            'actor_id'      => $userId,
            'user_id'       => $userId,
            'actor_type'    => 'admin',
            'metadata_json' => ['role' => $role],
        ]);

        // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
        return ApiResponse::ok(['data' => ['logged_out' => true]]);
    }
}
