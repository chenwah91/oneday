<?php

namespace Tests\Feature;

use Tests\TestCase;

// 环境配置健全性:时区必须 UTC
class ConfigTest extends TestCase
{
    public function test_app_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }
}
