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
        // 封禁拦截(W11-C1):挂在 web 组**末尾** —— 必须排在 StartSession 之后,
        // 否则 $request->user() 恒为 null(session 还没起来就认不出被封的是谁)。
        // 不挂全局 append:全局组跑在 session 之前,同样认不出人。
        // 它自己只对 /api/* 且已登录的非后台账号生效,其余一律直接放行(见该中间件顶部注释)
        $middleware->web(append: [\App\Http\Middleware\EnsureNotBanned::class]);
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
                // 「已登录玩家在扫后台 API」是最有价值的入侵信号(§60 授权失败必记 / §67 该行为要形成 security_flags),
                // 必须留痕。后台组从 auth:web 改成 auth:admin(2026-08-15)之后,这条路径不再经过 EnsureAdmin ——
                // Authenticate 提前抛 AuthenticationException,原本必然写下的 SECURITY.AUTHORIZATION_FAILED
                // 会整条消失。这一段就是把那个信号补回原处。
                //
                // 只在**持有玩家会话**时记:未登录访客打后台端点是常态噪音(扫描器 / 收藏夹 / 探针),
                // 全记等于把真信号淹掉。web guard 显式取,不受此刻默认 guard 已被切成 admin 的影响。
                if ($request->is('api/admin/*') && \Illuminate\Support\Facades\Auth::guard('web')->check()) {
                    $probeId = \Illuminate\Support\Facades\Auth::guard('web')->id();
                    $route = $request->path();

                    \App\Support\AuditLogger::record(\App\Support\AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                        'actor_id' => $probeId, 'user_id' => $probeId,
                        // 与 EnsureAdmin 的 NOT_ADMIN 刻意区分:那条是「有后台会话但角色不够」,
                        // 这条是「压根没有后台会话,拿玩家身份来敲门」——处置优先级不同
                        'reason_code' => 'NO_ADMIN_SESSION',
                        'metadata_json' => ['path' => $route, 'method' => $request->method()],
                    ]);
                    \App\Support\SecurityLogger::log('security.authorization_failed', [
                        'user_id' => $probeId, 'route' => $route, 'method' => $request->method(),
                        'reason' => 'NO_ADMIN_SESSION',
                        'error_code' => \App\Support\ErrorCode::AUTH_REQUIRED,
                    ]);
                }

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
