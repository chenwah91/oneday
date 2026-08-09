<?php

namespace Tests\Feature;

use Tests\TestCase;

// 异常渲染与错误隐藏测试(phpunit.xml 已设 APP_DEBUG=false)
class ExceptionRenderTest extends TestCase
{
    public function test_unhandled_api_exception_is_hidden_and_stable(): void
    {
        $res = $this->getJson('/api/_boom');

        $res->assertStatus(500);
        $res->assertJson(['success' => false, 'error' => 'INTERNAL_ERROR']);
        $res->assertJsonStructure(['success', 'error', 'requestId']);

        // 不得泄露异常原文与堆栈
        $body = $res->getContent();
        $this->assertStringNotContainsString('boom', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
    }

    public function test_not_found_api_route_returns_stable_json(): void
    {
        $res = $this->getJson('/api/_definitely_missing');

        $res->assertStatus(404);
        $res->assertJson(['success' => false, 'error' => 'NOT_FOUND']);
    }
}
