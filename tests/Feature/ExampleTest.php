<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    // 根路径自 v1.4.0 起 302 到游戏入口(R1-B),不再渲染 welcome 页。
    // 跳转本身的断言在 SecurityHeadersTest::test_root_redirects_to_game,
    // 这里只保留骨架测试的原意:应用能启动并给出非 5xx 响应
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/game/');
    }
}
