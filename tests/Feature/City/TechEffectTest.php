<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Simulation\SimConstants;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M2-B3 科技乘区(v3.2 §5 科技树的 effect_code 列)。
//
// 口径:§5 的 50 条科技 effect_code 清一色 `<branch>_base_efficiency_2pct`,
// 即「解锁一条科技 → 该科技所属分支的建筑基础效率 +2%」,同分支多条线性累加。
// 建筑归哪条分支由 building_definition.tech_id → technology_definition.branch 推出。
//
// 本文件的城市一律:人口 0(排除吃粮与税收)、资金 10000(排除维护欠费半停工)、
// 停在时代 I(§10.7 物流需求自时代 II 起算 → logistics 乘区恒 1.0)、工人补满(worker 乘区恒 1.0)。
// 于是唯一还会动的乘区就是 tech —— 每条断言的差值都只能由科技造成。
//
// 主力建筑:
//   F02 农田   分支 survival_agriculture(TECH_II_SUST)  粮食 14/min  维护资金 4/min  L1 需 4 人
//   R01 伐木营地 分支 industry_processing(TECH_I_IND)     木材 10/min  维护资金 1/min  L1 需 3 人
//   H01 帐篷   分支 governance_science_trade(TECH_I_CIV) 人口容量 18(容量类,不吃乘区)
class TechEffectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- 单科技命中 ----

    // 一条已解锁科技 → 同分支建筑 ×1.02,产出精确到小数
    public function test_single_unlocked_tech_multiplies_matching_building(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('techone', ['F02']);
        $this->unlockTech($city->id, 'TECH_I_SUST'); // survival_agriculture

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        // 14 × (1 + 0.02 × 1) = 14.28/min × 10min = 142.8
        $this->assertEqualsWithDelta(14.28, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(142.8, $this->amountOf($city, 'food'), 0.0001);
    }

    // 对照组:同一座城一条科技都不解锁 → 乘区停在占位 1.0,产出就是定义表的裸速率
    public function test_no_tech_keeps_multiplier_at_one(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('technone', ['F02']);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(14.0, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(140.0, $this->amountOf($city, 'food'), 0.0001);
    }

    // ---- 多科技叠加 ----

    // 同分支两条 → 线性叠加成 ×1.04(不是 1.02² 复利)
    public function test_two_techs_in_same_branch_stack_linearly(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('techtwo', ['F02']);
        $this->unlockTech($city->id, 'TECH_I_SUST', 'TECH_II_SUST');

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        // 14 × (1 + 0.02 × 2) = 14.56/min × 10min = 145.6(1.02² = 1.0404 → 145.656,精度足以区分)
        $this->assertEqualsWithDelta(14.56, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(145.6, $this->amountOf($city, 'food'), 0.0001);
    }

    // 整条分支铺满(10 条)→ ×1.20,且仍在 §13 的 2.75 硬帽之下(不被夹)
    public function test_full_branch_is_120_percent_and_below_cap(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('techfull', ['F02']);

        // 分支成员从定义表取,不在测试里背 10 个 ID(数值改版时这条用例跟着走)
        $branchTechs = DB::table('technology_definition')
            ->where('branch', 'survival_agriculture')->pluck('tech_id')->all();
        $this->assertCount(10, $branchTechs, '§5 每条分支恰好 10 个时代节点');
        $this->unlockTech($city->id, ...$branchTechs);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        // 14 × (1 + 0.02 × 10) = 16.8/min × 10min = 168
        $this->assertEqualsWithDelta(16.8, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(168.0, $this->amountOf($city, 'food'), 0.0001);
        // 满分支 1.20 < 2.75:§13 的帽此时不该介入(介入了上面就不是 16.8)
        $this->assertLessThan(SimConstants::MULTIPLIER_CAP, 1 + 10 * SimConstants::TECH_BRANCH_EFFICIENCY_BONUS);
    }

    // §13 硬帽同样管着 tech 这一格:乘区连乘超过 2.75 一律夹住(帽的落点唯一 = multiplierProduct)
    public function test_tech_slot_is_subject_to_multiplier_cap(): void
    {
        // 满分支科技 1.20 与将来的 NPC / 工具乘区叠起来:1.20 × 1.5 × 1.6 = 2.88 → 夹到 2.75
        $this->assertEqualsWithDelta(2.75, SimulationService::multiplierProduct(
            ['tech' => 1.20, 'npc' => 1.5, 'tool' => 1.6]
        ), 0.0001);
        // 单独 tech 一格被灌到离谱值也照样夹住(防止将来有人把加成写进 tech 格却绕开帽)
        $this->assertEqualsWithDelta(2.75, SimulationService::multiplierProduct(['tech' => 10.0]), 0.0001);
    }

    // ---- 不命中的建筑 ----

    // 别的分支的科技一条都不影响:同一座城里 F02 吃到加成、R01 分文不沾
    public function test_other_branch_buildings_are_untouched(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('techmix', ['F02', 'R01']);
        $this->unlockTech($city->id, 'TECH_I_SUST'); // 只解锁 survival_agriculture

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(14.28, $sim['grossProductionPerMin']['food'], 0.0001, 'F02 同分支 → ×1.02');
        $this->assertEqualsWithDelta(10.0, $sim['grossProductionPerMin']['wood'], 0.0001, 'R01 属工业分支 → 不受影响');
        $this->assertEqualsWithDelta(142.8, $this->amountOf($city, 'food'), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->amountOf($city, 'wood'), 0.0001);
    }

    // 反向:解锁其余四条分支各一条,农田照样一分不涨(证明加成确实按分支而不是"解锁数量")
    public function test_unrelated_branches_do_not_leak_into_other_buildings(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('techleak', ['F02']);
        $this->unlockTech($city->id, 'TECH_I_IND', 'TECH_I_CIV', 'TECH_I_LOG', 'TECH_I_DEF');

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(14.0, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(140.0, $this->amountOf($city, 'food'), 0.0001);
    }

    // 容量类产出不吃乘区(它在构建中间结构时就被提走,根本不进 grossOut):
    // 解锁住宅所在分支的科技,人口容量仍是定义表原值
    public function test_capacity_outputs_are_not_multiplied_by_tech(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('techcap', ['H01']);
        $this->unlockTech($city->id, 'TECH_I_CIV'); // H01 的分支 governance_science_trade

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(18.0, (float) $sim['populationCapacity'], '人口容量是容量类产出,不走七乘区');
    }

    // ---- 研究中不算数 / 解锁前后对比 ----

    // researching 状态没有任何效果(与建造科技闸门同一口径:在研不算解锁)
    public function test_researching_tech_has_no_effect(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('techwip', ['F02']);
        DB::table('city_technologies')->insert([
            'city_id' => $city->id, 'tech_id' => 'TECH_I_SUST', 'status' => 'researching',
            'started_at' => $base, 'finished_at' => $base->copy()->addHour(),
            'created_at' => $base, 'updated_at' => $base,
        ]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(14.0, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(140.0, $this->amountOf($city, 'food'), 0.0001);
    }

    // 同一座城解锁前后各结算一段:前 10 分钟按 14/min,后 10 分钟按 14.28/min
    public function test_settlement_differs_before_and_after_unlock(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('techswitch', ['F02']);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        SimulationService::simulate($city->fresh());
        $this->assertEqualsWithDelta(140.0, $this->amountOf($city, 'food'), 0.0001, '解锁前:裸速率');

        $this->unlockTech($city->id, 'TECH_I_SUST');

        Carbon::setTestNow($base->copy()->addMinutes(20));
        SimulationService::simulate($city->fresh());
        // 140 + 14.28 × 10 = 282.8:加成只作用于解锁之后的时段,不追溯前 10 分钟
        $this->assertEqualsWithDelta(282.8, $this->amountOf($city, 'food'), 0.0001);
    }

    // ---- 公共辅助 ----

    // 受控城市:清空初始建筑,按 $buildingIds 逐个摆 active 实例并补满工人;
    // 人口 0、资金 10000、资源全部清零、时代停在 I
    private function makeCity(string $un, array $buildingIds): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);
        DB::table('cities')->where('id', $city->id)->update(['population' => 0, 'money' => 10000]);

        $x = 1;
        foreach ($buildingIds as $bid) {
            $workers = (int) DB::table('building_level_definition')
                ->where('building_id', $bid)->where('level', 1)->value('worker_required');
            CityBuildingInstance::create([
                'city_id' => $city->id, 'building_id' => $bid, 'level' => 1,
                'x' => $x, 'y' => 1, 'status' => 'active', 'assigned_workers' => $workers,
            ]);
            $x += 4;
        }

        return $city->fresh();
    }

    private function amountOf(City $city, string $resourceId): float
    {
        return (float) (DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', $resourceId)->value('amount') ?? 0);
    }
}
