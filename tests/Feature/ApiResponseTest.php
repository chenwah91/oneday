<?php

namespace Tests\Feature;

use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Tests\TestCase;

// ApiResponse 统一响应格式测试
class ApiResponseTest extends TestCase
{
    public function test_ok_wraps_data_with_success_true(): void
    {
        $res = ApiResponse::ok(['data' => ['status' => 'ok']]);
        $payload = json_decode($res->getContent(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('ok', $payload['data']['status']);
    }

    public function test_fail_includes_error_and_request_id_key(): void
    {
        $res = ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        $payload = json_decode($res->getContent(), true);

        $this->assertSame(404, $res->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('NOT_FOUND', $payload['error']);
        $this->assertArrayHasKey('requestId', $payload);
    }
}
