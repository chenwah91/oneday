<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'error' => 'AUTH_REQUIRED']);
    }

    public function test_me_returns_current_user_when_authenticated(): void
    {
        $user = User::create(['username' => 'meuser', 'name' => 'meuser', 'email' => 'me@e.com', 'password' => 'password123']);

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['user' => ['username' => 'meuser']]]);
    }

    // W12:me 响应补注册时间 created_at(ISO 8601,由 Carbon 序列化);id/username/email 契约不变
    public function test_me_includes_created_at(): void
    {
        $user = User::create(['username' => 'meuser2', 'name' => 'meuser2', 'email' => 'me2@e.com', 'password' => 'password123']);

        $res = $this->actingAs($user)->getJson('/api/me')->assertOk();
        $this->assertNotNull($res->json('data.user.created_at'));
        $this->assertSame($user->created_at->toJSON(), $res->json('data.user.created_at'));
    }

    public function test_logout_ends_session(): void
    {
        $user = User::create(['username' => 'logoutuser', 'name' => 'logoutuser', 'email' => 'lo@e.com', 'password' => 'password123']);

        $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();
    }

    public function test_csrf_cookie_endpoint_returns_204(): void
    {
        $this->get('/api/csrf-cookie')->assertNoContent();
    }
}
