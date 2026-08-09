<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creates_with_username_phone_and_hashes_password(): void
    {
        $user = User::create([
            'username' => 'zhangsan',
            'name'     => 'zhangsan',
            'email'    => 'z@example.com',
            'phone'    => '0123456789',
            'password' => 'password123',
        ]);

        $this->assertSame('zhangsan', $user->username);
        $this->assertNotSame('password123', $user->password); // 已哈希
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password123', $user->password));
        $this->assertArrayNotHasKey('password', $user->toArray()); // hidden
    }
}
