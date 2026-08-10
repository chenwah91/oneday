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
        $res->assertJsonStructure(['success', 'error', 'request_id']);

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

    public function test_request_id_propagates_to_error_response(): void
    {
        $res = $this->getJson('/api/_boom', ['X-Request-ID' => 'fixed-err-id-123']);

        $res->assertStatus(500);
        $res->assertJson(['request_id' => 'fixed-err-id-123']);
        $res->assertHeader('X-Request-ID', 'fixed-err-id-123');
    }

    public function test_http_exception_preserves_status_and_hides_detail(): void
    {
        $res = $this->getJson('/api/_forbidden');
        $res->assertStatus(403);
        $res->assertJson(['success' => false, 'error' => 'FORBIDDEN']);
    }

    public function test_csrf_mismatch_maps_to_419(): void
    {
        $res = $this->getJson('/api/_csrf');
        $res->assertStatus(419);
        $res->assertJson(['success' => false, 'error' => 'CSRF_TOKEN_MISMATCH']);
    }
}
