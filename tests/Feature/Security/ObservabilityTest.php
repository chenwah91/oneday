<?php

namespace Tests\Feature\Security;

use App\Game\City\CityFactory;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Phase 2 可观测性地基:全局异常 render / 安全审计码 / Security Log 通道 / 快照限流
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function actingUser(string $name = 'observer'): User
    {
        $u = User::create(['username' => $name, 'name' => $name, 'email' => $name.'@o.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'wood'], ['amount' => 1000]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'stone'], ['amount' => 1000]);
        // 本文件用 F02(时代 II)当"随便一个建造请求"来验可观测性,与时代 / 科技闸门无关,
        // 把城市置于时代 II 并铺好前置科技,免得请求先被 ERA_REQUIRED / TECH_NOT_UNLOCKED 挡下
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'II', 'era_order' => 2]);
        $this->unlockTechFor($city->id, 'F02');

        return $u;
    }

    // 关键回归:REVISION_CONFLICT 在事务内抛出,若在事务内写审计会被 ROLLBACK 一起抹掉。
    // 审计落点必须在全局 render(事务已回滚),这条测试就是防止有人把它挪回事务里。
    public function test_revision_conflict_writes_security_audit_outside_transaction(): void
    {
        $u = $this->actingUser('revconflict');

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 8, 'y' => 8, 'expected_revision' => 999])
            ->assertStatus(409)->assertJson(['success' => false, 'error' => 'REVISION_CONFLICT']);

        $row = DB::table('audit_logs')->where('action', 'SECURITY.REVISION_CONFLICT')->first();
        $this->assertNotNull($row, 'REVISION_CONFLICT 审计被事务回滚吞掉了');
        $this->assertSame('rejected', $row->status);
        $this->assertSame((int) $u->id, (int) $row->user_id);
        $this->assertSame('api/city/build', json_decode((string) $row->metadata_json, true)['route']);

        // 冲突不得改变任何游戏状态
        $this->assertSame(0, (int) City::where('user_id', $u->id)->value('revision'));
        $this->assertDatabaseMissing('city_building_instances', ['x' => 8, 'y' => 8]);
    }

    public function test_idempotency_key_reuse_writes_suspicious_activity_audit(): void
    {
        $u = $this->actingUser('keyreuser');
        $city = City::where('user_id', $u->id)->first();
        $key = 'observability-reuse-key';

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2, 'idempotency_key' => $key])->assertOk();
        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');

        // 同一 key 换成 upgrade → 409
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $instanceId, 'idempotency_key' => $key])
            ->assertStatus(409)->assertJson(['success' => false, 'error' => 'IDEMPOTENCY_KEY_REUSED']);

        $row = DB::table('audit_logs')->where('action', 'SECURITY.SUSPICIOUS_ACTIVITY')->first();
        $this->assertNotNull($row);
        $this->assertSame('IDEMPOTENCY_KEY_REUSED', $row->reason_code);
        $this->assertStringContainsString('idempotency-key-reuse', (string) $row->metadata_json);
    }

    public function test_city_create_audit_is_written_once_only(): void
    {
        $this->postJson('/api/auth/register', [
            'username' => 'foundercity', 'email' => 'fc@f.com', 'password' => 'password123',
        ])->assertStatus(201);

        $u = User::where('username', 'foundercity')->first();

        // 快照接口每次都会调 CityFactory::createForUser 兜底,但不得重复写审计
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        $this->actingAs($u)->getJson('/api/city')->assertOk();

        $rows = DB::table('audit_logs')->where('action', 'CITY.CREATE')->get();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $u->id, (int) $rows[0]->user_id);
        $this->assertNotNull($rows[0]->city_id);
        // 初始资源摘要进 metadata,便于回查「这号开局给了多少」
        $this->assertStringContainsString('wood', (string) $rows[0]->metadata_json);
    }

    // G12:业务异常改由全局 render 输出后,响应体结构必须与旧的 Controller try/catch 完全一致
    public function test_game_rule_error_response_shape_is_unchanged(): void
    {
        $u = User::create(['username' => 'brokeguy', 'name' => 'brokeguy', 'email' => 'bg@b.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);
        // 时代 / 科技闸门都排在材料校验之前(M2-B6 / M2-B4),两道都过了才验得到 INSUFFICIENT_RESOURCE 的响应结构
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'II', 'era_order' => 2]);
        $this->unlockTechFor($city->id, 'F02');

        $res = $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 1, 'y' => 1], ['X-Request-ID' => 'fixed-rule-id-9']);

        $res->assertStatus(422);
        $res->assertJson(['success' => false, 'error' => 'INSUFFICIENT_RESOURCE', 'request_id' => 'fixed-rule-id-9']);
        $res->assertJsonStructure(['success', 'error', 'request_id']);
        $res->assertHeader('X-Request-ID', 'fixed-rule-id-9');
    }

    // G18:快照接口必须限流(CLAUDE §48),否则最贵的 GET 可被无限刷
    public function test_snapshot_is_rate_limited(): void
    {
        $u = $this->actingUser('snapflood');

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($u)->getJson('/api/city')->assertOk();
        }

        $res = $this->actingAs($u)->getJson('/api/city');
        $res->assertStatus(429);
        $res->assertJson(['success' => false, 'error' => 'TOO_MANY_REQUESTS']);
        $res->assertJsonStructure(['success', 'error', 'request_id']);

        // 限流触发必须留痕(CLAUDE §48)
        $row = DB::table('audit_logs')->where('action', 'SECURITY.RATE_LIMIT')->first();
        $this->assertNotNull($row);
        $this->assertSame('snapshot', json_decode((string) $row->metadata_json, true)['limiter']);
    }

    // E1:security 通道可写,且不因配置缺失抛异常
    public function test_security_log_channel_is_writable(): void
    {
        $this->assertSame('daily', config('logging.channels.security.driver'));
        $this->assertSame(30, (int) config('logging.channels.security.days'));

        Log::channel('security')->info('security.channel_smoke_test', ['route' => 'tests/observability']);

        // 能取到 logger 实例即代表通道配置有效
        $this->assertNotNull(Log::channel('security'));
    }
}
