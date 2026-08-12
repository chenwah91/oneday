<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

// R1-B 走查补漏的三条安全响应头(CLAUDE §73)+ 根路径跳转。
// CSP 刻意不测:还没加(要在真实游戏页面实测 PixiJS 白名单后再上,见 R1 计划「待下一步」)
class SecurityHeadersTest extends TestCase
{
    // 所有 Laravel 响应都带 nosniff 与 Referrer-Policy(中间件挂全局,拿健康探针验证)
    public function test_responses_carry_nosniff_and_referrer_policy(): void
    {
        $res = $this->get('/api/health');

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('Referrer-Policy', 'same-origin');
    }

    // HSTS 只在 HTTPS 响应里发:HTTP 下浏览器会忽略它,发了只是噪声
    public function test_hsts_only_on_https(): void
    {
        $http = $this->get('http://localhost/api/health');
        $this->assertFalse(
            $http->headers->has('Strict-Transport-Security'),
            'HTTP 响应不应带 HSTS'
        );

        $https = $this->get('https://localhost/api/health');
        $https->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    // 错误响应同样带头(中间件是 append,异常渲染后仍会路过)
    public function test_error_responses_carry_headers_too(): void
    {
        $res = $this->getJson('/api/definitions/npcs'); // 未登录 → 401

        $res->assertStatus(401);
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // 根路径直达游戏入口:玩家不该看到 Laravel 骨架 welcome 页
    public function test_root_redirects_to_game(): void
    {
        $this->get('/')->assertRedirect('/game/');
    }
}
