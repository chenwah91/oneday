<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

// 登录:用户名 + 密码,成功后重建 session
// 失败限流按「已解析账号 id」为 key,与 IP 无关:DB 排序规则(utf8mb4_unicode_ci)不区分大小写/重音,
// josé/jöse/jose 会命中同一账号行;若按原始 username 分桶,每个变体各开一个新的限流窗口,等于形同虚设,
// 且仅靠 IP 维度还会被换 IP 绕过。这里统一解析到账号 id 后再限流,两类绕过都会被同一个计数器拦住。
class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:100'],
        ]);

        // 按 DB 实际匹配方式解析账号,确保限流 key 与登录校验用的是同一个账号
        $user = User::where('username', $credentials['username'])->first();

        // 未解析到账号时退化为「用户名小写哈希」兜底 key,同样不含 IP
        $key = 'login:'.($user ? 'id:'.$user->id : 'anon:'.sha1(mb_strtolower($credentials['username'])));

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::fail(ErrorCode::TOO_MANY_REQUESTS, 429);
        }

        // 显式指定 web guard(不用默认 guard):项目自 2026-08-15 起还有一个后台专用的 admin guard,
        // 玩家登录端点无论如何都只能写玩家那把锁 —— 靠「默认 guard 恰好是 web」是隐式依赖,不该赌
        $ok = $user && Auth::guard('web')->attempt(['username' => $credentials['username'], 'password' => $credentials['password']]);

        if (! $ok) {
            RateLimiter::hit($key, 900);

            // 审计中的用户名仅在符合注册时的用户名格式时才保留原文,否则脱敏为占位符,
            // 避免用户误把密码敲进用户名框时,密码原文被写入审计日志
            $safeUsername = preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', $credentials['username']) === 1
                ? $credentials['username']
                : '[redacted]';

            AuditLogger::record(AuditAction::AUTH_LOGIN_FAILED, 'failed', [
                'reason_code'   => 'BAD_CREDENTIALS',
                'metadata_json' => ['username' => $safeUsername],
            ]);

            // Security Log 与审计并行(CLAUDE §60):只带账号 id 与原因,绝不写用户名原文与密码
            SecurityLogger::log('security.login_failed', [
                'user_id'    => $user?->id,
                'route'      => $request->path(),
                'reason'     => 'BAD_CREDENTIALS',
                'error_code' => ErrorCode::BAD_CREDENTIALS,
            ]);

            return ApiResponse::fail(ErrorCode::BAD_CREDENTIALS, 401);
        }

        // 封禁 Fail Closed(W11-C1 任务4):密码对了不等于能进来。
        //
        // 检查刻意排在**密码校验之后**:放在之前的话,任何人拿一个用户名就能试出「这个号被封了」,
        // 等于送了一个账号枚举面。走到这里的前提是他本来就知道密码,不构成额外泄漏。
        // Auth::attempt 已经把登录态建起来了,所以必须先 logout 再返回 —— 否则这一记 401 之后
        // 他手里已经握着一个有效 session,下一个请求就直接进去了(只是会被 EnsureNotBanned 再拦一次,
        // 但那已经是「靠第二道闸兜底」而不是这一道自己关严)。
        // 同样不 regenerate session、不 clear 限流:失败的登录不该给账号重置任何计数器
        if ($user->banned_at !== null) {
            Auth::guard('web')->logout();

            AuditLogger::record(AuditAction::AUTH_LOGIN_FAILED, 'rejected', [
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

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user = Auth::guard('web')->user();

        // 管理员登录单独标注 actor_type,便于审计日志区分「管理员」与「普通玩家」的登录事件
        AuditLogger::record(AuditAction::AUTH_LOGIN_SUCCESS, 'success', [
            'actor_id'   => $user->id,
            'user_id'    => $user->id,
            'actor_type' => $user->role === 'admin' ? 'admin' : 'player',
        ]);

        return ApiResponse::ok([
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]],
        ]);
    }
}
