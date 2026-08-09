<?php

use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 基础设施探针(供中间件/健康检查测试)
Route::prefix('api')->group(function () {
    Route::get('/_ping', fn () => ApiResponse::ok(['data' => ['pong' => true]]));
});

// 仅测试环境:用于验证异常渲染,绝不在生产暴露
if (app()->environment('testing')) {
    Route::get('/api/_boom', function () {
        throw new \RuntimeException('boom');
    });
}
