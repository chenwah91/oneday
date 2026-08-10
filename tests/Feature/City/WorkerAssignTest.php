<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 工人分配 POST /api/city/workers/assign(v3.2 §10.4)
// 覆盖:分配成功 / 超需求 / 超劳动力 / 越权 / 幂等 / Revision 冲突 / 审计 / workerFactor 生效
class WorkerAssignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // 一座城 + 一座 F02(L1 需 4 人),时间冻结在建城时刻 → 结算 elapsed 恒为 0,断言不受产量干扰
    private function makeCityWithFarm(string $un): array
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $id = CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'F02', 'level' => 1,
            'x' => 1, 'y' => 1, 'status' => 'active',
        ])->id;

        return [$u, $city, $id];
    }

    public function test_assign_workers_success(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('warm1');
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $res = $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $id, 'workers' => 4]);

        $res->assertOk()->assertJson(['success' => true, 'data' => [
            'building'         => ['id' => $id, 'assignedWorkers' => 4, 'workerRequired' => 4],
            // 初始人口 30 → availableWorkers = floor(30 × 0.60) = 18
            'availableWorkers' => 18,
            'assignedWorkers'  => 4,
        ]]);
        $this->assertSame(4, (int) DB::table('city_building_instances')->where('id', $id)->value('assigned_workers'));
        $this->assertSame($revisionBefore + 1, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
    }

    // 绝对值语义:再发一次 workers=0 就是撤光,不是增量
    public function test_assign_zero_unassigns(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('warm2');
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $id, 'workers' => 4])->assertOk();
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $id, 'workers' => 0])->assertOk();

        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $id)->value('assigned_workers'));
    }

    // 超编:一栋 F02 L1 最多只要 4 人,第 5 个人没有岗位
    public function test_assign_more_than_required_is_rejected(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('warm3');

        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $id, 'workers' => 5])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);
        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $id)->value('assigned_workers'));
    }

    // 超劳动力:人口 5 → availableWorkers = floor(5 × 0.60) = 3,派 4 人无人可派
    public function test_assign_more_than_available_workers_is_rejected(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('warm4');
        DB::table('cities')->where('id', $city->id)->update(['population' => 5]);

        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $id, 'workers' => 4])
            ->assertStatus(422)->assertJson(['error' => 'WORKER_NOT_AVAILABLE']);
        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $id)->value('assigned_workers'));

        // 3 人(= 上限)可以派进去,证明拒绝的是"超出的那一个人",不是整条规则写错
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $id, 'workers' => 3])->assertOk();
        $this->assertSame(3, (int) DB::table('city_building_instances')->where('id', $id)->value('assigned_workers'));
    }

    // 全城合计口径:多栋建筑共享同一批劳动力,第二栋要按"已被别人占走的人数"判定
    public function test_available_workers_counted_city_wide(): void
    {
        [$u, $city, $farmId] = $this->makeCityWithFarm('warm5');
        // A01 L1 需 5 人;人口 30 → 可用 18。先给农田 4 人,再给 A01 5 人,合计 9,够用
        $adminId = CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'A01', 'level' => 1,
            'x' => 6, 'y' => 6, 'status' => 'active',
        ])->id;

        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $farmId, 'workers' => 2])->assertOk();
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $adminId, 'workers' => 5])->assertOk();

        // 人口降到 10 → 可用 floor(10×0.60)=6,A01 已占 5;农田想加到 4 → 5+4=9 > 6,必须被拒
        DB::table('cities')->where('id', $city->id)->update(['population' => 10]);
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $farmId, 'workers' => 4])
            ->assertStatus(422)->assertJson(['error' => 'WORKER_NOT_AVAILABLE']);

        // 但"只减不增"永远放行:否则人口暴跌后玩家会被锁死在超编状态里
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $farmId, 'workers' => 1])->assertOk();
        $this->assertSame(1, (int) DB::table('city_building_instances')->where('id', $farmId)->value('assigned_workers'));
    }

    public function test_cannot_assign_workers_to_another_players_building(): void
    {
        [$ua, $ca, $ida] = $this->makeCityWithFarm('warmOwner');
        $ub = User::create(['username' => 'warmAttacker', 'name' => 'warmAttacker', 'email' => 'wa@x.com', 'password' => 'password123']);
        CityFactory::createForUser($ub);

        $this->actingAs($ub)->postJson('/api/city/workers/assign', ['instanceId' => $ida, 'workers' => 4])
            ->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);

        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $ida)->value('assigned_workers'));
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_assign_is_idempotent(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('warm6');
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $body = ['instanceId' => $id, 'workers' => 4, 'idempotencyKey' => 'worker-fixed-key-1'];
        $this->actingAs($u)->postJson('/api/city/workers/assign', $body)->assertOk();
        // 之间手动改成 1 人:重放不得把它再改回 4(重放 = 回旧结果,不重复执行)
        DB::table('city_building_instances')->where('id', $id)->update(['assigned_workers' => 1]);
        $this->actingAs($u)->postJson('/api/city/workers/assign', $body)->assertOk();

        $this->assertSame(1, (int) DB::table('city_building_instances')->where('id', $id)->value('assigned_workers'));
        $this->assertSame($revisionBefore + 1, (int) DB::table('cities')->where('id', $city->id)->value('revision'), 'revision 只涨一次');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'WORKER.ASSIGN')->count(), '审计只写一条');
    }

    public function test_stale_revision_is_rejected(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('warm7');
        $current = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->actingAs($u)->postJson('/api/city/workers/assign', [
            'instanceId' => $id, 'workers' => 4, 'expectedRevision' => $current + 99,
        ])->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);

        $this->assertSame(0, (int) DB::table('city_building_instances')->where('id', $id)->value('assigned_workers'));
    }

    public function test_assign_writes_audit_with_before_after(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('warm8');
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $id, 'workers' => 2])->assertOk();
        $this->actingAs($u)->postJson('/api/city/workers/assign', ['instanceId' => $id, 'workers' => 4])->assertOk();

        $row = DB::table('audit_logs')->where('action', 'WORKER.ASSIGN')->latest('id')->first();
        $this->assertSame('success', $row->status);
        $this->assertSame((string) $id, $row->entity_id);
        $this->assertSame(['assigned' => 2], json_decode($row->before_json, true));
        $this->assertSame(['assigned' => 4], json_decode($row->after_json, true));
        $this->assertSame(['assigned' => 2], json_decode($row->delta_json, true));
        $this->assertSame((int) $city->fresh()->revision, (int) $row->city_revision_after);
    }

    // ---- workerFactor 接入乘区:min(1, assigned / worker_required) ----

    // 半员(2/4)产出减半:F02 名义 14/min → 实际 7/min
    public function test_worker_factor_halves_output(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->cityWithStaffedFarm('wf-half', 2, 100);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        // workerFactor = 2/4 = 0.5 → 14 × 0.5 = 7/min;人口 0 → 无人口吃粮
        $this->assertEqualsWithDelta(7.0, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(170.0, $this->foodOf($city), 0.0001, '100 + 7 × 10');
    }

    // 满员(4/4):不打折,与 M1 行为一致
    public function test_worker_factor_full_staff_is_unaffected(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->cityWithStaffedFarm('wf-full', 4, 100);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(240.0, $this->foodOf($city), 0.0001, '100 + 14 × 10');
    }

    // 无人:产出归零(建筑空转),但维护费照扣
    public function test_worker_factor_zero_stops_production(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->cityWithStaffedFarm('wf-zero', 0, 100);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(100.0, $this->foodOf($city), 0.0001, '没人上工就不产粮');
        // F02 维护 资金 4/min × 10min = 40,不受用工率影响
        $this->assertEqualsWithDelta(9960.0, (float) DB::table('cities')->where('id', $city->id)->value('money'), 0.0001);
    }

    // worker_required = 0 的建筑(住宅 H01)恒 1.0:不会因为没派人而失效
    public function test_zero_worker_building_always_full_factor(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $u = User::create(['username' => 'wf-house', 'name' => 'wf-house', 'email' => 'wfh@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'H01', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active']);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(18.0, (float) $sim['populationCapacity'], '住宅没有工人需求,容量照常提供');
    }

    // 一座城 + 一座指定用工数的 F02,人口 0(排除人口吃粮),粮食/资金给定
    private function cityWithStaffedFarm(string $un, int $workers, float $food): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'F02', 'level' => 1,
            'x' => 1, 'y' => 1, 'status' => 'active', 'assigned_workers' => $workers,
        ]);
        DB::table('cities')->where('id', $city->id)->update(['population' => 0, 'money' => 10000]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => $food]);

        return $city;
    }

    private function foodOf(City $city): float
    {
        return (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->value('amount');
    }
}
