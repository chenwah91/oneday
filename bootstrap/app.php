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
        // 安全响应头挂全局(append = 出站最后一道):所有 Laravel 响应统一带上,静态文件另由服务器配置
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias(['admin' => \App\Http\Middleware\EnsureAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // 只接管 api/* 或期望 JSON 的请求;其余走 Laravel 默认
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // 自带响应的异常(如限流器 response 回调抛出的 HttpResponseException):
            // 抛出方已经决定了响应,这里必须放行给 Laravel 原生处理,否则会被下面的兜底分支吃成 500。
            // 注意:本 render 回调在 Handler 里先于 HttpResponseException 的原生分支执行(见 Handler::render)
            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return null;
            }

            // 游戏规则/安全校验失败:统一在此转 ApiResponse(G12),Controller 不再各写 try/catch。
            // 必须排在通用 HttpException 分支之前,否则原始 status 会被兜底分支覆盖。
            if ($e instanceof \App\Support\GameRuleException) {
                $userId = $request->user()?->id;
                $route = $request->route()?->getName() ?? $request->path();

                // 事务内抛出的冲突不能在事务里写审计(会被 ROLLBACK 一起滚掉),
                // render 执行时事务早已回滚,此处补写才落得住。
                if ($e->errorCode === \App\Support\ErrorCode::REVISION_CONFLICT) {
                    \App\Support\AuditLogger::record(\App\Support\AuditAction::SECURITY_REVISION_CONFLICT, 'rejected', [
                        'actor_id' => $userId, 'user_id' => $userId,
                        'reason_code' => \App\Support\ErrorCode::REVISION_CONFLICT,
                        'metadata_json' => ['route' => $route, 'method' => $request->method()],
                    ]);
                    \App\Support\SecurityLogger::log('security.revision_conflict', [
                        'user_id' => $userId, 'route' => $route, 'method' => $request->method(),
                        'error_code' => \App\Support\ErrorCode::REVISION_CONFLICT,
                    ]);
                }

                // 同一 Idempotency-Key 被复用到别的操作/参数:属可疑行为,同样在事务外补写
                if ($e->errorCode === \App\Support\ErrorCode::IDEMPOTENCY_KEY_REUSED) {
                    \App\Support\AuditLogger::record(\App\Support\AuditAction::SECURITY_SUSPICIOUS_ACTIVITY, 'rejected', [
                        'actor_id' => $userId, 'user_id' => $userId,
                        'reason_code' => \App\Support\ErrorCode::IDEMPOTENCY_KEY_REUSED,
                        'metadata_json' => ['reason' => 'idempotency-key-reuse', 'route' => $route, 'method' => $request->method()],
                    ]);
                    \App\Support\SecurityLogger::log('security.suspicious_activity', [
                        'user_id' => $userId, 'route' => $route, 'method' => $request->method(),
                        'reason' => 'idempotency-key-reuse',
                        'error_code' => \App\Support\ErrorCode::IDEMPOTENCY_KEY_REUSED,
                    ]);
                }

                // 越权被拒(如 UpgradeService 的 NOT_OWNER):审计已在抛出点写过,这里只补 Security Log
                if ($e->errorCode === \App\Support\ErrorCode::FORBIDDEN) {
                    \App\Support\SecurityLogger::log('security.authorization_failed', [
                        'user_id' => $userId, 'route' => $route, 'method' => $request->method(),
                        'error_code' => \App\Support\ErrorCode::FORBIDDEN,
                    ]);
                }

                // details 非空时并进响应(如 ERA_REQUIRED 的逐维条件清单);为空则响应结构保持原样
                return \App\Support\ApiResponse::fail(
                    $e->errorCode,
                    $e->status,
                    $e->details ? ['details' => $e->details] : []
                );
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
