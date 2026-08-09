<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// 请求 ID:透传或生成 UUID,写入 Context 供日志关联,并回写响应头
class EnsureRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // 防日志伪造:仅接受长度与字符集合法的传入 ID,否则生成 UUID
        $incoming = $request->headers->get('X-Request-ID');
        $requestId = (is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $incoming) === 1)
            ? $incoming
            : (string) Str::uuid();

        // 供本次请求内的日志与 ApiResponse 读取
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
