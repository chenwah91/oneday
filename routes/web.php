<?php

use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 基础设施探针(供中间件/健康检查测试)
Route::prefix('api')->group(function () {
    // _ping 仅用于测试/本地探活,生产环境不注册
    if (! app()->environment('production')) {
        Route::middleware('throttle:api')->get('/_ping', fn () => ApiResponse::ok(['data' => ['pong' => true]]));
    }

    // 健康检查探针不限流,避免探活请求被节流
    Route::get('/health', fn () => ApiResponse::ok([
        'data' => [
            'status'     => 'ok',
            'serverTime' => now()->toIso8601String(),
        ],
    ]));

    // 注册接口:独立限流(同 IP 每分钟 10 次),减缓账号枚举探测
    Route::middleware('throttle:register')->post('/auth/register', \App\Http\Controllers\Auth\RegisterController::class);

    // 登录接口:throttle:auth 仅做粗粒度按 IP 限流(每分钟 20 次)兜底 DoS;
    // 真正的按账号失败次数限制(每 15 分钟 5 次、与 IP 无关)在 LoginController 内实现
    Route::post('/auth/login', \App\Http\Controllers\Auth\LoginController::class)->middleware('throttle:auth');

    // CSRF cookie:公开接口,供 SPA 首次取用 XSRF-TOKEN
    Route::get('/csrf-cookie', [\App\Http\Controllers\Auth\SessionController::class, 'csrfCookie']);

    Route::middleware('auth:web')->group(function () {
        // 当前登录用户
        Route::get('/me', [\App\Http\Controllers\Auth\SessionController::class, 'me']);

        // 登出:失效 session 并写审计
        Route::post('/auth/logout', [\App\Http\Controllers\Auth\SessionController::class, 'logout']);

        // 城市只读快照:先结算再返回聚合状态
        Route::get('/city', [\App\Http\Controllers\City\CityController::class, 'show']);

        // 建造:完整安全链(幂等/Revision/占地/上限/资源/审计)
        Route::post('/city/build', \App\Http\Controllers\City\BuildController::class)->middleware('throttle:api');
    });
});

// 仅测试环境:用于验证异常渲染,绝不在生产暴露
if (app()->environment('testing')) {
    Route::get('/api/_boom', function () {
        throw new \RuntimeException('boom');
    });

    Route::get('/api/_forbidden', function () {
        abort(403);
    });

    Route::get('/api/_csrf', function () {
        throw new \Illuminate\Session\TokenMismatchException();
    });
}
