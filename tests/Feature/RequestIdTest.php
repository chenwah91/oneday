<?php

namespace Tests\Feature;

use Tests\TestCase;

// Request ID 中间件测试
class RequestIdTest extends TestCase
{
    public function test_response_has_request_id_header(): void
    {
        $res = $this->getJson('/api/_ping');

        $res->assertOk();
        $res->assertHeader('X-Request-ID');
        // 未带 X-Request-ID 时服务器应生成一个 UUID(36 位含连字符)
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $res->headers->get('X-Request-ID')
        );
    }

    public function test_incoming_request_id_is_preserved(): void
    {
        $res = $this->getJson('/api/_ping', ['X-Request-ID' => 'test-fixed-id-123']);

        $res->assertHeader('X-Request-ID', 'test-fixed-id-123');
    }
}
