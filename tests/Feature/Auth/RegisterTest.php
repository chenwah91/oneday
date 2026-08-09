<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_succeeds_and_logs_in(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'username' => 'wangwu',
            'email'    => 'w@example.com',
            'password' => 'password123',
            'phone'    => '0111222333',
        ]);

        $res->assertStatus(201);
        $res->assertJson(['success' => true, 'data' => ['user' => ['username' => 'wangwu']]]);
        $res->assertJsonMissingPath('data.user.password');
        $this->assertDatabaseHas('users', ['username' => 'wangwu', 'email' => 'w@example.com']);
        $this->assertAuthenticated();
        $this->assertSame('AUTH.REGISTER', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_register_requires_valid_input(): void
    {
        $this->postJson('/api/auth/register', ['username' => 'ab', 'email' => 'bad', 'password' => 'short'])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'VALIDATION_ERROR']);
    }

    public function test_register_rejects_duplicate_username(): void
    {
        User::create(['username' => 'dup', 'name' => 'dup', 'email' => 'a@a.com', 'password' => 'password123']);

        $this->postJson('/api/auth/register', [
            'username' => 'dup', 'email' => 'b@b.com', 'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_phone_is_optional(): void
    {
        $this->postJson('/api/auth/register', [
            'username' => 'nophone', 'email' => 'n@n.com', 'password' => 'password123',
        ])->assertStatus(201);
    }
}
