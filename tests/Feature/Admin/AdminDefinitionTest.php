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
        // role 已不可批量赋值,测试里用 forceFill 显式提权
        $user = User::create(['username' => 'defadmin', 'name' => 'defadmin', 'email' => 'da@a.com', 'password' => 'password123']);
        $user->forceFill(['role' => 'admin'])->save();
        return $user;
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

    // 负数 value 会被拒绝:防止负的 maintenance_money_per_min 让模拟结算的 max(0, ...) 变成无上限生钱
    public function test_rejects_negative_value(): void
    {
        $before = DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 1)->value('maintenance_money_per_min');
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/building-level', [
            'buildingId' => 'F02', 'level' => 1, 'field' => 'maintenance_money_per_min', 'value' => -5, 'reason' => '平衡性调整',
        ])->assertStatus(422);
        $this->assertEquals($before, DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 1)->value('maintenance_money_per_min'));
    }

    // reason 超过 80 字符(audit_logs.reason_code 列上限)应被拒绝,且不能留下部分写入
    public function test_rejects_overlong_reason(): void
    {
        $before = (int) DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 1)->value('worker_required');
        $auditCountBefore = DB::table('audit_logs')->count();
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/building-level', [
            'buildingId' => 'F02', 'level' => 1, 'field' => 'worker_required', 'value' => $before + 1,
            'reason' => str_repeat('原', 100),
        ])->assertStatus(422);
        $this->assertSame($before, (int) DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 1)->value('worker_required'));
        $this->assertSame($auditCountBefore, DB::table('audit_logs')->count());
    }
}
