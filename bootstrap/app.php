<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\EnsureRequestId::class);
        $middleware->alias(['admin' => \App\Http\Middleware\EnsureAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // 只接管 api/* 或期望 JSON 的请求;其余走 Laravel 默认
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return \App\Support\ApiResponse::fail(
                    \App\Support\ErrorCode::VALIDATION_ERROR,
                    422,
                    ['errors' => $e->errors()]
                );
            }

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return \App\Support\ApiResponse::fail(\App\Support\ErrorCode::AUTH_REQUIRED, 401);
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return \App\Support\ApiResponse::fail(\App\Support\ErrorCode::NOT_FOUND, 404);
            }

            if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
                return \App\Support\ApiResponse::fail(\App\Support\ErrorCode::TOO_MANY_REQUESTS, 429);
            }

            // CSRF 校验失败:TokenMismatchException 不实现 HttpExceptionInterface,需单独判断
            if ($e instanceof \Illuminate\Session\TokenMismatchException) {
                return \App\Support\ApiResponse::fail(\App\Support\ErrorCode::CSRF_TOKEN_MISMATCH, 419);
            }

            // 其余 HTTP 异常:保留原始状态码,仅 5xx 写日志(普通 4xx 客户端错误不记录)
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    403 => \App\Support\ErrorCode::FORBIDDEN,
                    405 => \App\Support\ErrorCode::METHOD_NOT_ALLOWED,
                    // 419:Laravel 在到达此处前已将 TokenMismatchException 转换为普通 HttpException(见 Handler::prepareException),
                    // 故这里按状态码兜底识别为 CSRF 错误
                    419 => \App\Support\ErrorCode::CSRF_TOKEN_MISMATCH,
                    default => \App\Support\ErrorCode::HTTP_ERROR,
                };
                if ($status >= 500) {
                    \Illuminate\Support\Facades\Log::error($e->getMessage(), ['exception' => $e]);
                }
                return \App\Support\ApiResponse::fail($code, $status);
            }

            // 其余未知异常:写日志,对外只给稳定错误码(生产隐藏细节)
            \Illuminate\Support\Facades\Log::error($e->getMessage(), ['exception' => $e]);

            return \App\Support\ApiResponse::fail(\App\Support\ErrorCode::INTERNAL_ERROR, 500);
        });
    })->create();
