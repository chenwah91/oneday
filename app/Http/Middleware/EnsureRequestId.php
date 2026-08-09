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
        $incoming = $request->headers->get('X-Request-ID');
        $requestId = ($incoming !== null && $incoming !== '') ? $incoming : (string) Str::uuid();

        // 供本次请求内的日志与 ApiResponse 读取
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
