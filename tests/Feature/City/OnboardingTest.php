<?php

namespace Tests\Feature\City;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_city_with_starting_resources(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'username' => 'cityfounder', 'email' => 'c@f.com', 'password' => 'password123',
        ]);
        $res->assertStatus(201);

        $user = User::where('username', 'cityfounder')->first();
        $this->assertDatabaseHas('cities', ['user_id' => $user->id]);
        $city = $user->city ?? \App\Models\City::where('user_id', $user->id)->first();
        $this->assertGreaterThanOrEqual(200, (float) $city->resources()->where('resource_id', 'wood')->value('amount'));
        $this->assertSame(10, $city->population);
    }
}
