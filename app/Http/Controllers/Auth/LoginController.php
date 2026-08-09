<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
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

        $ok = $user && Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password']]);

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

            return ApiResponse::fail(ErrorCode::BAD_CREDENTIALS, 401);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $user = Auth::user();

        AuditLogger::record(AuditAction::AUTH_LOGIN_SUCCESS, 'success', [
            'actor_id' => $user->id,
            'user_id'  => $user->id,
        ]);

        return ApiResponse::ok([
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]],
        ]);
    }
}
