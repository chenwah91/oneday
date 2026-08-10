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

// M2-C5 建筑生命周期:施工 / 升级计时(v3.2 §16.3)、懒完工、生产口径。
//
// 本组用例的城市一律停在时代 I(建实例时不走建造端点,所以不受时代 / 科技闸门影响),
// 人口置 0 排除人口吃粮与人口增长,资金给足排除维护欠费半停工 —— 断言的只有"这栋楼产没产"。
class ConstructionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // 一座「只有一栋 F02、人口 0、粮食 0、资金充足」的城
    private function bareCity(string $un): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();
        DB::table('cities')->where('id', $city->id)->update(['population' => 0, 'money' => 100000]);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);

        return $city->fresh();
    }

    // 往城里放一栋 F02,工人按该级需求补满(否则 workerFactor 会把产量打折,断言失去意义)
    private function putFarm(City $city, string $status, ?Carbon $finishedAt, int $level = 1): int
    {
        $required = (int) DB::table('building_level_definition')
            ->where('building_id', 'F02')->where('level', $level)->value('worker_required');

        $id = CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'F02', 'level' => $level,
            'x' => 1, 'y' => 1, 'status' => $status, 'assigned_workers' => $required,
        ])->id;

        // construction_finished_at 不在 Model 的 fillable 里(它不该由请求赋值),用查询构造器写
        DB::table('city_building_instances')->where('id', $id)
            ->update(['construction_finished_at' => $finishedAt]);

        return $id;
    }

    private function food(City $city): float
    {
        return (float) (DB::table('city_resources')
            ->where('city_id', $city->id)->where('resource_id', 'food')->value('amount') ?? 0);
    }

    // F02 该级的每分钟粮食产出(数值以定义表为准,不在测试里写死)
    private function farmRate(int $level = 1): float
    {
        $out = json_decode((string) DB::table('building_level_definition')
            ->where('building_id', 'F02')->where('level', $level)->value('output_json'), true);

        return (float) $out[0]['rate_per_min'];
    }

    private function simulateAt(City $city, Carbon $at): array
    {
        Carbon::setTestNow($at);

        return SimulationService::simulate($city->fresh());
    }

    // ---- 建造计时 ----

    // 建造落地即 constructing,完工时刻 = 服务器当前时间 + 该建筑 L1 的 duration_seconds
    public function test_build_starts_construction_with_server_finish_time(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);

        $u = User::create(['username' => 'ctBuild', 'name' => 'ctBuild', 'email' => 'ctb@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 1000]);
        // F01 是时代 I 建筑,新城默认就是时代 I;只需铺前置科技(M2-B4 闸门)
        $this->unlockTechFor($city->id, 'F01');

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F01', 'x' => 2, 'y' => 2])->assertOk();

        $duration = (int) DB::table('building_level_definition')->where('building_id', 'F01')->where('level', 1)->value('duration_seconds');
        $inst = DB::table('city_building_instances')->where('city_id', $city->id)->first();

        $this->assertSame('constructing', $inst->status);
        $this->assertSame(1, (int) $inst->level);
        $this->assertSame(
            $base->copy()->addSeconds($duration)->format('Y-m-d H:i:s'),
            Carbon::parse($inst->construction_finished_at)->format('Y-m-d H:i:s')
        );
    }

    // 施工中不生产:整段窗口一粒粮都不产
    public function test_constructing_building_produces_nothing(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctNoProd');
        $this->putFarm($city, 'constructing', $base->copy()->addHours(2));

        $this->simulateAt($city, $base->copy()->addMinutes(10));

        $this->assertSame(0.0, $this->food($city), '施工中的建筑不进生产集合');
    }

    // 对照组:同样一栋楼,状态是 active 就照常产 —— 证明上一条的 0 不是"本来就不产"
    public function test_active_building_produces(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctProd');
        $this->putFarm($city, 'active', null);

        $this->simulateAt($city, $base->copy()->addMinutes(10));

        $this->assertEqualsWithDelta($this->farmRate() * 10, $this->food($city), 0.01);
    }

    // ---- 窗口起点口径:窗口中途完工的,本次结算不产、下次才产 ----

    public function test_completion_inside_window_produces_from_next_settlement(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctWindow');
        // 完工点落在 base+5min:本次结算窗口是 [base, base+10min],完工点在窗口中途
        $id = $this->putFarm($city, 'constructing', $base->copy()->addMinutes(5));

        // 第一次结算(窗口 [base, base+10min]):翻正成 active,但完工点晚于窗口起点 → 本次不产
        $this->simulateAt($city, $base->copy()->addMinutes(10));
        $inst = DB::table('city_building_instances')->where('id', $id)->first();
        $this->assertSame('active', $inst->status, '到点了就该翻正');
        $this->assertNotNull($inst->construction_finished_at, '完工戳留到下次结算才清');
        $this->assertSame(0.0, $this->food($city), '窗口中途完工的建筑不得追溯整窗产出');

        // 第二次结算(窗口 [base+10min, base+20min]):完工点已在窗口起点之前 → 戳清空,正常产 10 分钟
        $this->simulateAt($city, $base->copy()->addMinutes(20));
        $this->assertNull(DB::table('city_building_instances')->where('id', $id)->value('construction_finished_at'));
        $this->assertEqualsWithDelta($this->farmRate() * 10, $this->food($city), 0.01);
    }

    // 完工点早于窗口起点(典型的离线回来):本次结算就按整窗算产出
    public function test_completion_before_window_start_produces_this_settlement(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctBeforeWindow');
        $this->putFarm($city, 'constructing', $base->copy()->addMinutes(1));

        // 先把窗口推过完工点(这一次不产),再结算下一个 10 分钟窗口
        $this->simulateAt($city, $base->copy()->addMinutes(2));
        $this->assertSame(0.0, $this->food($city));

        $this->simulateAt($city, $base->copy()->addMinutes(12));
        $this->assertEqualsWithDelta($this->farmRate() * 10, $this->food($city), 0.01);
    }

    // 离线封顶下的完工:离线 20h、建筑在 15h 前完工(早于封顶后的 12h 窗口起点)→ 整个 12h 窗口都算产出
    public function test_completion_before_capped_window_start_produces_full_window(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctOffline');
        // 仓储上限 1000(无仓库),12h 产量会顶到上限 —— 这里只验"确实产了整窗",用触顶断言即可
        $this->putFarm($city, 'constructing', $base->copy()->addHours(5)); // 20h 后回来看,即 15h 前完工

        $this->simulateAt($city, $base->copy()->addHours(20));

        $inst = DB::table('city_building_instances')->where('id', DB::table('city_building_instances')->where('city_id', $city->id)->value('id'))->first();
        $this->assertSame('active', $inst->status);
        $this->assertNull($inst->construction_finished_at, '完工点早于封顶窗口起点,戳应立即清空');
        $this->assertSame(1000.0, $this->food($city), '整个 12h 封顶窗口都在产出,顶到仓储上限');
    }

    // ---- 懒完工翻正 ----

    // 升级完工:status 回 active 且 level + 1(写级的唯一落点)
    public function test_lazy_completion_flips_upgrading_to_next_level(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctFlip');
        $id = $this->putFarm($city, 'upgrading', $base->copy()->addMinutes(1));

        // 未到点:不翻
        $this->simulateAt($city, $base->copy()->addSeconds(30));
        $this->assertSame('upgrading', DB::table('city_building_instances')->where('id', $id)->value('status'));
        $this->assertSame(1, (int) DB::table('city_building_instances')->where('id', $id)->value('level'));

        // 到点:翻正 + 升级
        $this->simulateAt($city, $base->copy()->addMinutes(2));
        $inst = DB::table('city_building_instances')->where('id', $id)->first();
        $this->assertSame('active', $inst->status);
        $this->assertSame(2, (int) $inst->level);
    }

    // 完工翻正不写审计:挂机轮询不该把审计表刷满(理由见 ConstructionService::settleFinished)
    public function test_lazy_completion_writes_no_audit(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctNoAudit');
        $this->putFarm($city, 'constructing', $base->copy()->addMinutes(1));

        $before = DB::table('audit_logs')->count();
        $this->simulateAt($city, $base->copy()->addMinutes(2));

        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    // ---- 升级期间的生产口径(v3.2 §3.2「升级时进入 upgrading:生产建筑默认暂停生产」) ----

    public function test_upgrading_pauses_production(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctPause');
        $this->putFarm($city, 'upgrading', $base->copy()->addHours(2));

        $this->simulateAt($city, $base->copy()->addMinutes(10));

        $this->assertSame(0.0, $this->food($city), 'upgrading 不在生产集合里 → 停产');
    }

    // 升级完工后按新等级产出(L2 速率 > L1 速率)
    public function test_production_uses_new_level_after_upgrade_completes(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctNewLevel');
        $id = $this->putFarm($city, 'upgrading', $base->copy()->addMinutes(1));
        // L2 需求工人可能与 L1 不同,补满避免 workerFactor 打折
        $requiredL2 = (int) DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 2)->value('worker_required');
        DB::table('city_building_instances')->where('id', $id)->update(['assigned_workers' => $requiredL2]);

        $this->simulateAt($city, $base->copy()->addMinutes(2));   // 翻正成 L2,本窗口不产
        $this->simulateAt($city, $base->copy()->addMinutes(12));  // 下一个 10 分钟窗口按 L2 产

        $this->assertEqualsWithDelta($this->farmRate(2) * 10, $this->food($city), 0.01);
        $this->assertGreaterThan($this->farmRate(1), $this->farmRate(2), 'L2 速率应高于 L1(否则本用例区分不出等级)');
    }

    // ---- 快照契约 ----

    public function test_snapshot_exposes_status_and_finished_at(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);

        $u = User::create(['username' => 'ctSnap', 'name' => 'ctSnap', 'email' => 'cts@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 1000]);
        $this->unlockTechFor($city->id, 'F01');

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F01', 'x' => 2, 'y' => 2])->assertOk();

        $res = $this->actingAs($u)->getJson('/api/city');
        $res->assertOk();
        $building = $res->json('data.city.buildings.0');

        $this->assertSame('constructing', $building['status']);
        $this->assertNotNull($building['construction_finished_at']);

        // 完工之后:status 回 active,完工戳被清成 null
        $duration = (int) DB::table('building_level_definition')->where('building_id', 'F01')->where('level', 1)->value('duration_seconds');
        Carbon::setTestNow($base->copy()->addSeconds($duration + 1));
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        Carbon::setTestNow($base->copy()->addSeconds($duration + 2));
        $building = $this->actingAs($u)->getJson('/api/city')->json('data.city.buildings.0');

        $this->assertSame('active', $building['status']);
        $this->assertNull($building['construction_finished_at']);
    }

    // ---- 拆除返还与生命周期的交叉:施工中拆除退 70%,且不留下幽灵实例 ----

    public function test_demolish_during_construction_cancels_and_refunds(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);

        $u = User::create(['username' => 'ctCancelBuild', 'name' => 'ctCancelBuild', 'email' => 'ctcb@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 100]);
        $this->unlockTechFor($city->id, 'F01');

        $woodBefore = (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F01', 'x' => 2, 'y' => 2])->assertOk();
        $id = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');

        // F01 L1 木材 10:建造扣 10,取消建造退 floor(10 × 0.7) = 7
        $this->assertSame($woodBefore - 10, (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount'));

        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id])->assertOk();

        $this->assertSame($woodBefore - 10 + 7, (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount'));
        $this->assertDatabaseMissing('city_building_instances', ['id' => $id]);
    }
}
