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

    // 注册接口:限流防刷
    Route::middleware('throttle:api')->post('/auth/register', \App\Http\Controllers\Auth\RegisterController::class);

    // 登录接口:同 用户名+IP 每分钟 5 次(更严格的独立限流)
    Route::post('/auth/login', \App\Http\Controllers\Auth\LoginController::class)->middleware('throttle:auth');
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
