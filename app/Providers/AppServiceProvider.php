<?php

namespace App\Providers;

use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\SecurityLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // api 分组基础限流:每 IP 每分钟 60 次(登录等更严的限流在 P2 单独定义)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip())->response(self::rejected('api'));
        });

        // 登录限流:此处仅作粗粒度按 IP 的 DoS 防护(每 IP 每分钟 20 次),
        // 真正按账号、与 IP 无关的失败次数限制在 LoginController 中实现(见该文件顶部注释)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip())->response(self::rejected('auth'));
        });

        // 注册限流:同 IP 每分钟 10 次,减缓「用户名/邮箱是否已占用」的账号枚举探测
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip())->response(self::rejected('register'));
        });

        // 快照限流(CLAUDE §48 要求 Snapshot Refresh 限流):按用户每分钟 30 次。
        // 前端 10 秒轮询一次 = 6 次/分钟,留 5 倍余量;未登录请求退化按 IP 计数
        RateLimiter::for('snapshot', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip())->response(self::rejected('snapshot'));
        });
    }

    // 限流触发时的统一响应:审计 + Security Log,并保持与全局异常渲染一致的 429 响应体。
    // 注意响应体必须仍是 ApiResponse::fail(TOO_MANY_REQUESTS, 429):
    // 带 response 回调后 Laravel 会抛 HttpResponseException 直接返回此响应,不再经过全局异常 render。
    private static function rejected(string $limiter): callable
    {
        return function (Request $request, array $headers) use ($limiter) {
            $userId = $request->user()?->id;
            $route = $request->route()?->getName() ?? $request->path();

            AuditLogger::record(AuditAction::SECURITY_RATE_LIMIT, 'rejected', [
                'actor_id' => $userId, 'user_id' => $userId,
                'reason_code' => ErrorCode::TOO_MANY_REQUESTS,
                'metadata_json' => ['limiter' => $limiter, 'route' => $route, 'method' => $request->method()],
            ]);

            SecurityLogger::log('security.rate_limit', [
                'user_id' => $userId, 'limiter' => $limiter,
                'route' => $route, 'method' => $request->method(),
                'error_code' => ErrorCode::TOO_MANY_REQUESTS,
            ]);

            // 保留 Laravel 原生的 Retry-After / X-RateLimit-* 头,前端退避逻辑不受影响
            return ApiResponse::fail(ErrorCode::TOO_MANY_REQUESTS, 429)->withHeaders($headers);
        };
    }
}
