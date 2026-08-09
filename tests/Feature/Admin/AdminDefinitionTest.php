<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDefinitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function admin(): User
    {
        return User::create(['username' => 'defadmin', 'name' => 'defadmin', 'email' => 'da@a.com', 'password' => 'password123', 'role' => 'admin']);
    }

    public function test_edit_building_level_field_audits_and_bumps_version(): void
    {
        $before = (int) DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 1)->value('worker_required');
        $res = $this->actingAs($this->admin())->postJson('/api/admin/definitions/building-level', [
            'buildingId' => 'F02', 'level' => 1, 'field' => 'worker_required', 'value' => $before + 2, 'reason' => '平衡性调整',
        ]);
        $res->assertOk();
        $this->assertSame($before + 2, (int) DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 1)->value('worker_required'));
        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertNotNull($audit->before_json);
        $this->assertSame('平衡性调整', $audit->reason_code);
        // 版本递增
        $this->assertGreaterThanOrEqual(2, DB::table('game_data_versions')->count());
    }

    public function test_rejects_non_allowlisted_field(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/building-level', [
            'buildingId' => 'F02', 'level' => 1, 'field' => 'building_id', 'value' => 999, 'reason' => 'x',
        ])->assertStatus(422);
    }

    public function test_requires_reason(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/building-level', [
            'buildingId' => 'F02', 'level' => 1, 'field' => 'worker_required', 'value' => 5,
        ])->assertStatus(422);
    }
}
