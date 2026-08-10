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

    // 往城里放一栋 H01 住宅(worker_required = 0,不受用工乘区影响)
    private function putHousing(City $city, string $status, ?Carbon $finishedAt, int $level = 1): int
    {
        $id = CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'H01', 'level' => $level,
            'x' => 8, 'y' => 8, 'status' => $status, 'assigned_workers' => 0,
        ])->id;

        DB::table('city_building_instances')->where('id', $id)
            ->update(['construction_finished_at' => $finishedAt]);

        return $id;
    }

    // H01 该级的人口容量(数值以定义表为准,不在测试里写死)
    private function housingCapacity(int $level): float
    {
        $out = json_decode((string) DB::table('building_level_definition')
            ->where('building_id', 'H01')->where('level', $level)->value('output_json'), true);

        return (float) $out[0]['rate_per_min'];
    }

    // 人口增长对照用的城:10 座 H01(状态由参数决定)+ 1 座满员 F02,人口 100、粮食 500、资金充足
    private function cityWithHousingAndFarm(string $un, string $housingStatus, ?Carbon $finishedAt): City
    {
        $city = $this->bareCity($un);
        for ($i = 0; $i < 10; $i++) {
            $id = $this->putHousing($city, $housingStatus, $finishedAt);
            // putHousing 固定摆在 (8,8);这里只是让 10 座实例互不重叠(结算不校验占地,但别留下误导性数据)
            DB::table('city_building_instances')->where('id', $id)->update(['x' => 1 + $i * 2, 'y' => 8]);
        }
        $this->putFarm($city, 'active', null);
        DB::table('cities')->where('id', $city->id)->update(['population' => 100]);
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'food'], ['amount' => 500]
        );

        return $city->fresh();
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

    // ---- 升级期间的人口容量(v3.2 §3.2「住宅只保留 50% 人口容量,避免升级期间无风险」) ----

    // 对照组:active 的住宅提供全额容量
    public function test_active_housing_gives_full_population_capacity(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctHouseFull');
        $this->putHousing($city, 'active', null);

        $sim = $this->simulateAt($city, $base->copy()->addMinutes(10));

        $this->assertEqualsWithDelta($this->housingCapacity(1), $sim['populationCapacity'], 0.0001);
    }

    // 升级中的住宅:按**旧等级**容量的 50% 计入(level 列要到完工才 +1)
    public function test_upgrading_housing_keeps_half_population_capacity(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctHouseHalf');
        $this->putHousing($city, 'upgrading', $base->copy()->addHours(2));

        $sim = $this->simulateAt($city, $base->copy()->addMinutes(10));

        // H01 L1 人口容量 18 → 升级期间 9
        $this->assertEqualsWithDelta($this->housingCapacity(1) * 0.5, $sim['populationCapacity'], 0.0001);
    }

    // 打折基数是旧等级:L2 的住宅在升往 L3 的途中,按 L2 容量的一半而不是 L1 或 L3
    public function test_upgrading_housing_halves_the_old_level_capacity(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctHouseOldLevel');
        $this->putHousing($city, 'upgrading', $base->copy()->addHours(2), 2);

        $sim = $this->simulateAt($city, $base->copy()->addMinutes(10));

        $this->assertEqualsWithDelta($this->housingCapacity(2) * 0.5, $sim['populationCapacity'], 0.0001);
        $this->assertGreaterThan($this->housingCapacity(1), $this->housingCapacity(2), 'L2 容量应高于 L1(否则本用例区分不出基数)');
    }

    // 施工中的住宅一分容量都不给:那是一栋还没建成的楼(与「施工中不生产」同一条纪律)
    public function test_constructing_housing_gives_no_population_capacity(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctHouseBuilding');
        $this->putHousing($city, 'constructing', $base->copy()->addHours(2));

        $sim = $this->simulateAt($city, $base->copy()->addMinutes(10));

        $this->assertSame(0.0, (float) $sim['populationCapacity']);
    }

    // 升级完工后容量按新等级恢复全额。
    //
    // 注意中间那一次结算:完工点落在窗口中途 → 实例已翻成 active 但完工戳还没清,
    // 于是这一窗既不在生产集合、也不在容量集合里,人口容量短暂归零(波次 7 定下的窗口起点口径,
    // 对容量类是否也该照此办理已记入待裁决事项)。这里把两步都断言死,口径一改就立刻变红。
    public function test_population_capacity_returns_after_upgrade_completes(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->bareCity('ctHouseDone');
        $id = $this->putHousing($city, 'upgrading', $base->copy()->addMinutes(1));

        $simMid = $this->simulateAt($city, $base->copy()->addMinutes(2));
        $this->assertSame('active', DB::table('city_building_instances')->where('id', $id)->value('status'));
        $this->assertSame(2, (int) DB::table('city_building_instances')->where('id', $id)->value('level'));
        $this->assertSame(0.0, (float) $simMid['populationCapacity'], '完工窗口:戳未清,本次既不生产也不计容量');

        $simNext = $this->simulateAt($city, $base->copy()->addMinutes(3));
        $this->assertEqualsWithDelta($this->housingCapacity(2), $simNext['populationCapacity'], 0.0001, '下一次结算按新等级全额');
    }

    // 容量减半对人口增长的精确影响:同一座城,住宅 active 时能长、住宅 upgrading 时 housingFactor 归零
    //
    // 城市配置:人口 100、粮食 500、10 座 H01(全额容量 180)、1 座满员 F02(粮食 14/min)
    //   foodNetRate = 14 − 100×0.03 = 11 > 0            → foodFactor 1.0
    //   happiness 段起 60(建城默认)                     → happinessFactor 0.5 + (60−50)/40 = 0.75
    //   active:    housingUsage = 100/180 = 0.5556 < 0.80 → housingFactor 1.0
    //              rate = 0.002 × 1.0 × 1.0 × 0.75 = 0.0015 → 100 × 1.0015^10 = 101.5105 → 落库 101
    //   upgrading: 容量 90,housingUsage = 100/90 = 1.111 >= 1.00 → housingFactor 0 → rate 0 → 人口不动
    public function test_upgrading_housing_halves_capacity_and_stops_growth(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');

        Carbon::setTestNow($base);
        $active = $this->cityWithHousingAndFarm('ctGrowFull', 'active', null);
        $simActive = $this->simulateAt($active, $base->copy()->addMinutes(10));

        Carbon::setTestNow($base);
        $upgrading = $this->cityWithHousingAndFarm('ctGrowHalf', 'upgrading', $base->copy()->addHours(2));
        $simUpgrading = $this->simulateAt($upgrading, $base->copy()->addMinutes(10));

        $full = $this->housingCapacity(1) * 10;
        $this->assertEqualsWithDelta($full, $simActive['populationCapacity'], 0.0001, '10 座 H01 = 180');
        $this->assertEqualsWithDelta($full * 0.5, $simUpgrading['populationCapacity'], 0.0001, '全部升级中 = 90');

        $this->assertSame(101, (int) DB::table('cities')->where('id', $active->id)->value('population'));
        $this->assertEqualsWithDelta(0.15, $simActive['populationGrowthPerMin'], 0.0001, '100 × 0.0015');

        $this->assertSame(100, (int) DB::table('cities')->where('id', $upgrading->id)->value('population'), '容量减半后超容 → 停止增长');
        $this->assertSame(0.0, (float) $simUpgrading['populationGrowthPerMin']);
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
