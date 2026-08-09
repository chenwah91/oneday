<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_guest_denied(): void
    {
        $this->getJson('/api/admin/players')->assertStatus(401);
    }

    public function test_player_forbidden_and_audited(): void
    {
        $u = User::create(['username' => 'plainplayer', 'name' => 'plainplayer', 'email' => 'p@p.com', 'password' => 'password123']);
        $this->actingAs($u)->getJson('/api/admin/players')
            ->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_admin_allowed(): void
    {
        $u = User::create(['username' => 'bossadmin', 'name' => 'bossadmin', 'email' => 'a@a.com', 'password' => 'password123', 'role' => 'admin']);
        $this->actingAs($u)->getJson('/api/admin/players')->assertOk();
    }

    public function test_promote_command(): void
    {
        User::create(['username' => 'tobeadmin', 'name' => 'tobeadmin', 'email' => 't@a.com', 'password' => 'password123']);
        $this->artisan('admin:promote tobeadmin')->assertExitCode(0);
        $this->assertSame('admin', User::where('username', 'tobeadmin')->value('role'));
    }
}
