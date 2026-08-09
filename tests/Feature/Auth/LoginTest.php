<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'username' => 'loginuser', 'name' => 'loginuser',
            'email' => 'l@example.com', 'password' => 'password123',
        ]);
    }

    public function test_login_succeeds(): void
    {
        $this->makeUser();
        $res = $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'password123']);

        $res->assertOk();
        $res->assertJson(['success' => true, 'data' => ['user' => ['username' => 'loginuser']]]);
        $this->assertAuthenticated();
        $this->assertSame('AUTH.LOGIN_SUCCESS', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeUser();
        $res = $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'wrongpass']);

        $res->assertStatus(401);
        $res->assertJson(['success' => false, 'error' => 'BAD_CREDENTIALS']);
        $this->assertGuest();
        $this->assertSame('AUTH.LOGIN_FAILED', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_login_is_rate_limited_after_5_failures(): void
    {
        $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'wrongpass']);
        }
        $res = $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'password123']);
        $res->assertStatus(429);
        $res->assertJson(['success' => false, 'error' => 'TOO_MANY_REQUESTS']);
    }

    public function test_account_lockout_is_ip_independent(): void
    {
        $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'wrongpass'], ['REMOTE_ADDR' => '1.1.1.1']);
        }
        // 换一个完全不同的 IP 再试第 6 次:按账号限流应仍然拦住,证明与 IP 无关
        $res = $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'wrongpass'], ['REMOTE_ADDR' => '2.2.2.2']);
        $res->assertStatus(429);
        $res->assertJson(['success' => false, 'error' => 'TOO_MANY_REQUESTS']);
    }

    public function test_failed_login_audit_redacts_non_username_shaped_input(): void
    {
        $this->makeUser();
        $res = $this->postJson('/api/auth/login', [
            'username' => 'S0me!VeryLongMistypedPassword#WithSymbols',
            'password' => 'wrongpass',
        ]);

        $res->assertStatus(401);
        $row = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('AUTH.LOGIN_FAILED', $row->action);
        $metadata = json_decode($row->metadata_json);
        $this->assertSame('[redacted]', $metadata->username);
    }
}
