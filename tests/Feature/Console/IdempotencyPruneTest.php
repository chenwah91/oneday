<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// idempotency:prune 只删已过期的行;未过期与历史 NULL 行必须保留
class IdempotencyPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_only_expired_rows(): void
    {
        $user = User::factory()->create();

        DB::table('idempotency_keys')->insert([
            // 已过期:应删
            ['user_id' => $user->id, 'key' => 'k-expired', 'action' => 'BUILDING.BUILD',
             'response_status' => 200, 'created_at' => now()->subDays(2), 'expires_at' => now()->subDay()],
            // 未过期:应留
            ['user_id' => $user->id, 'key' => 'k-alive', 'action' => 'BUILDING.BUILD',
             'response_status' => 200, 'created_at' => now(), 'expires_at' => now()->addDay()],
            // 历史行(补列前写入,expires_at 为 NULL):保守起见不删
            ['user_id' => $user->id, 'key' => 'k-legacy', 'action' => 'BUILDING.BUILD',
             'response_status' => 200, 'created_at' => now()->subDays(30), 'expires_at' => null],
        ]);

        $this->artisan('idempotency:prune')
            ->expectsOutputToContain('已清理过期幂等键 1 行')
            ->assertExitCode(0);

        $keys = DB::table('idempotency_keys')->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['k-alive', 'k-legacy'], $keys);
    }
}
