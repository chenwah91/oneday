<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// HTTP 安全响应头(CLAUDE §73,R1-B 走查补漏)。
//
// 只加三条零风险的;CSP 刻意不在这里加 —— PixiJS 的 blob:/data: 纹理与内联样式
// 需要在真实游戏页面上实测出白名单,盲加会把渲染打挂(§73「不要为了省事 script-src *」),
// 已记入 R1 计划「待下一步」。
//
// 注意:本中间件只覆盖走 Laravel 的响应(API / 重定向 / Blade)。public/game/ 的静态文件
// 由 Web 服务器直接吐,要靠 .htaccess / nginx 配置补同样的头 —— 见 docs/deploy.md。
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 禁止浏览器嗅探 MIME(把 JSON/上传物当脚本执行的老攻击面)
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Referrer 不出站:游戏 URL 不含敏感段,但没有任何理由把玩家的来路送给外链
        $response->headers->set('Referrer-Policy', 'same-origin');

        // HSTS 只在 HTTPS 响应里发(HTTP 下浏览器本来就会忽略它,发了也是噪声);
        // 本地开发是 HTTP,自然不带。线上若走 Cloudflare/反代终止 TLS,isSecure() 会是 false,
        // 届时要么配 TrustProxies,要么由反代层发 HSTS —— 已记入部署清单
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
