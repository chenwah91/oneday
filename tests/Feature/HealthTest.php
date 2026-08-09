<?php

namespace Tests\Feature;

use Tests\TestCase;

// 健康检查端点测试
class HealthTest extends TestCase
{
    public function test_health_returns_ok_with_request_id(): void
    {
        $res = $this->getJson('/api/health');

        $res->assertOk();
        $res->assertJson(['success' => true, 'data' => ['status' => 'ok']]);
        $res->assertJsonStructure(['success', 'data' => ['status', 'serverTime']]);
        $res->assertHeader('X-Request-ID');
    }
}
