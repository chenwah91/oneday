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
        // 防日志伪造:仅接受长度与字符集合法的传入 ID,否则生成 UUID。
        //
        // 长度上限必须 <= audit_logs.request_id 的列宽 CHAR(36)(C6 安全回归发现的审计抑制漏洞):
        // 上限放到 128 时,玩家只要发一个 37~128 字符的 X-Request-ID,
        // AuditLogger 的 INSERT 就会在 STRICT_TRANS_TABLES 下报「Data too long」——
        // 事务内的成功审计连同整笔 Mutation 一起回滚,事务外的 SECURITY.AUTHORIZATION_FAILED /
        // REVISION_CONFLICT / SUSPICIOUS_ACTIVITY 更是直接写不进去(且 Security Log 排在审计之后也一并被跳过),
        // 攻击者可以借此把后台探测打成「零留痕的 500」。
        // 超长/非法一律退回生成 UUID(Fail Closed:宁可丢掉客户端的链路 ID,也不能丢审计)。
        $incoming = $request->headers->get('X-Request-ID');
        $requestId = (is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{1,36}$/', $incoming) === 1)
            ? $incoming
            : (string) Str::uuid();

        // 供本次请求内的日志与 ApiResponse 读取
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
