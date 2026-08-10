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

// M2-C4 物流(v3.2 §10.7 物流 + §3.3 等级状态公式 + §10.11 生产总公式)
// 外加 §13 生产倍率硬上限。
//
// 与 FiscalTest / HappinessTest 同一纪律:每条断言都在注释里写清算式,
// 任何一个系数被改坏(distanceFactor、四档拐点、0.70 / 0.25 两个锚点、2.75 硬帽)都必须立刻变红。
//
// 常用建筑(L1,除非另注):
//   F02 农田  产粮 14/min      / 工人 4 / 维护资金 4   → 运输需求 14
//   P01 磨坊  吃粮 10 产面粉 8 / 工人 3 / 维护资金 2   → 运输需求 18(输入 + 输出)
//   T02 道路  运输容量 140     / 工人 0 / 维护资金 2   → 运输需求 0(容量类产出不占运力)
//                              T02 L2 运输容量 189
//   H01 住宅  人口容量 18      S01 储藏坑 仓储容量 150   A01 行政所 治理容量 80
class LogisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- §10.7 distanceFactor(M2 恒 1.0) ----

    // M2 拍板:distanceFactor = 1.0,地图距离惩罚留 M3 大地图。
    // 常量被改成别的值 → 下面「需求 === 输入+输出的裸和」这条立刻变红
    public function test_distance_factor_is_one_and_demand_equals_raw_sum(): void
    {
        $this->assertSame(1.0, SimConstants::LOGISTICS_DISTANCE_FACTOR, 'M2 §10.7:distanceFactor = 1.0');

        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // F02(需求 14)+ P01(需求 10 + 8 = 18)→ 裸和 32;× distanceFactor 1.0 = 32
        $city = $this->makeCity('logdist', ['F02' => 1, 'P01' => 1], 2);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(32.0, $sim['transportDemandPerMin'], 0.0001,
            '运输需求 = Σ(生产建筑每分钟输入 + 输出) × 1.0');
    }

    // ---- §10.7 transportLoad = transportDemand / max(1, transportCapacity) ----

    public function test_transport_load_formula(): void
    {
        // 正常分母
        $this->assertEqualsWithDelta(0.1, SimulationService::transportLoad(14, 140), 0.0001);
        $this->assertEqualsWithDelta(1.1, SimulationService::transportLoad(154, 140), 0.0001);
        // 容量 0 时分母取 1(v3.2 原文的 max(1, …),不是除零)
        $this->assertEqualsWithDelta(14.0, SimulationService::transportLoad(14, 0), 0.0001);
        // 容量小于 1 时同样按 1 兜底
        $this->assertEqualsWithDelta(14.0, SimulationService::transportLoad(14, 0.5), 0.0001);
        // 没有生产建筑 → 需求 0 → 负载 0
        $this->assertEqualsWithDelta(0.0, SimulationService::transportLoad(0, 0), 0.0001);
    }

    // ---- §10.7 logisticsFactor 分档 ----

    public function test_logistics_factor_bands(): void
    {
        // <= 0.80 → 1.00
        $this->assertEqualsWithDelta(1.0, SimulationService::logisticsFactor(0.0), 0.0001);
        $this->assertEqualsWithDelta(1.0, SimulationService::logisticsFactor(0.80), 0.0001);
        // 0.80 ~ 1.00 → §10.7 只写「轻微运输延迟」,没写降产 → 仍是 1.00
        $this->assertEqualsWithDelta(1.0, SimulationService::logisticsFactor(0.9), 0.0001);
        $this->assertEqualsWithDelta(1.0, SimulationService::logisticsFactor(1.00), 0.0001);
        // 1.00 ~ 1.25 → 从 1.00 线性下降至 0.70,斜率 = 0.30 / 0.25 = 1.2 每单位负载
        $this->assertEqualsWithDelta(0.94, SimulationService::logisticsFactor(1.05), 0.0001); // 1 − 0.3×(0.05/0.25)
        $this->assertEqualsWithDelta(0.88, SimulationService::logisticsFactor(1.10), 0.0001); // 1 − 0.3×0.4
        $this->assertEqualsWithDelta(0.85, SimulationService::logisticsFactor(1.125), 0.0001); // 1 − 0.3×0.5
        $this->assertEqualsWithDelta(0.70, SimulationService::logisticsFactor(1.25), 0.0001);
        // > 1.25 → 接 §3.3 的 clamp(容量/需求, 0.25, 1),被 0.70 的上限压住 → 拐点处连续、无跳变
        $this->assertEqualsWithDelta(0.70, SimulationService::logisticsFactor(1.2501), 0.0001);
        $this->assertEqualsWithDelta(0.50, SimulationService::logisticsFactor(2.0), 0.0001);   // 1/2
        $this->assertEqualsWithDelta(1 / 3, SimulationService::logisticsFactor(3.0), 0.0001);  // 1/3
        // 下限 0.25(§3.3 clamp 下限 / §15 回归表「物流率不低于 0.25」):负载 4 恰好触底,再高也不再往下
        $this->assertEqualsWithDelta(0.25, SimulationService::logisticsFactor(4.0), 0.0001);
        $this->assertEqualsWithDelta(0.25, SimulationService::logisticsFactor(14.0), 0.0001);
        $this->assertEqualsWithDelta(0.25, SimulationService::logisticsFactor(9999.0), 0.0001);

        // 单调不增:任取一串递增负载,物流率不得回升(防止有人把最后一档写成 1/load 不夹上限)
        $prev = 1.01;
        foreach ([0.5, 0.9, 1.0, 1.1, 1.2, 1.25, 1.3, 2.0, 5.0, 50.0] as $load) {
            $f = SimulationService::logisticsFactor($load);
            $this->assertLessThanOrEqual($prev + 1e-9, $f, "负载 {$load} 处物流率回升了");
            $prev = $f;
        }
    }

    // ---- 运输容量:唯一来源 = output_json 的 transport_capacity ----

    public function test_transport_capacity_aggregates_from_output_json(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('logcap', ['T02' => 2], 2); // 140 × 2 = 280

        // 再加一座 T02 L2(运输容量 189)→ 280 + 189 = 469
        CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'T02', 'level' => 2,
            'x' => 15, 'y' => 15, 'status' => 'active', 'assigned_workers' => 0,
        ]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(469.0, $sim['transportCapacity'], 0.0001, 'T02 L1×2 140×2 + L2 189');
        // 道路自己不产生运输需求(transport_capacity 是容量类产出,不进 grossOut)
        $this->assertEqualsWithDelta(0.0, $sim['transportDemandPerMin'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $sim['logisticsFactor'], 0.0001);
    }

    // 容量类产出一律不占运力:住宅 / 储藏坑 / 行政所 / 道路摆满也是零需求
    public function test_capacity_outputs_do_not_consume_transport(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('logcapout', ['H01' => 1, 'S01' => 1, 'A01' => 1, 'T02' => 1], 2);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(0.0, $sim['transportDemandPerMin'], 0.0001,
            '人口/仓储/治理/运输容量都不是「每分钟入库的资源」,不占运力');
        $this->assertEqualsWithDelta(0.0, $sim['transportLoad'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $sim['logisticsFactor'], 0.0001);
        $this->assertFalse($sim['transportCongestion']);
    }

    // ---- 时代闸门(本次补充假设:时代 I 不计物流需求) ----

    // 时代 I:全表没有任何建筑能产运输容量(最早的 T02 是时代 II),所以不计需求 → 满产
    public function test_era_one_has_no_transport_demand(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('logera1', ['F02' => 1], 1, 100.0, 10000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertSame(1, SimConstants::LOGISTICS_MIN_ERA_ORDER - 1, '闸门起算时代 = II');
        $this->assertEqualsWithDelta(0.0, $sim['transportDemandPerMin'], 0.0001, '时代 I 不计物流需求');
        $this->assertEqualsWithDelta(1.0, $sim['logisticsFactor'], 0.0001);
        // 粮食 = 100 + 14 × 1.00 × 10 = 240(满产)
        $this->assertEqualsWithDelta(240.0, $this->amountOf($city, 'food'), 0.0001);
    }

    // 时代 II 且一条路都没有:需求 14 / 容量 0 → 负载 14 → 物流率触底 0.25,产量打到四分之一
    public function test_era_two_without_roads_falls_to_floor(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('logera2', ['F02' => 1], 2, 100.0, 10000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(14.0, $sim['transportDemandPerMin'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $sim['transportCapacity'], 0.0001);
        $this->assertEqualsWithDelta(14.0, $sim['transportLoad'], 0.0001, '14 / max(1, 0)');
        $this->assertEqualsWithDelta(0.25, $sim['logisticsFactor'], 0.0001);
        $this->assertTrue($sim['transportCongestion'], '负载 14 > 1.25 → 拥堵警报');
        // 粮食 = 100 + 14 × 0.25 × 10 = 135(时代 I 的话是 240)
        $this->assertEqualsWithDelta(135.0, $this->amountOf($city, 'food'), 0.0001);
        $this->assertEqualsWithDelta(3.5, $sim['ratesPerMin']['food'], 0.0001, '净速率也是物流打折口径');
        // 维护不受物流影响:F02 4/min × 10 = 40
        $this->assertEqualsWithDelta(9960.0, $this->moneyOf($city), 0.0001);
    }

    // 同一座城补一条路:容量 140 → 负载 14/140 = 0.1 → 物流率回到 1.00,产量恢复满额。
    // 这条与上一条构成对照:上面那 135 确实来自物流,不是别的因素
    public function test_one_road_restores_full_production(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('logroad', ['F02' => 1, 'T02' => 1], 2, 100.0, 10000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(140.0, $sim['transportCapacity'], 0.0001);
        $this->assertEqualsWithDelta(0.1, $sim['transportLoad'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $sim['logisticsFactor'], 0.0001);
        $this->assertFalse($sim['transportCongestion']);
        // 粮食 = 100 + 14 × 1.00 × 10 = 240
        $this->assertEqualsWithDelta(240.0, $this->amountOf($city, 'food'), 0.0001);
        // 维护 = F02 4 + T02 2 = 6/min × 10 = 60
        $this->assertEqualsWithDelta(9940.0, $this->moneyOf($city), 0.0001);
    }

    // 1.00 ~ 1.25 线性档在真实结算里生效:11 座 F02(需求 154)+ 1 条路(容量 140)→ 负载 1.10 → 0.88
    public function test_linear_band_applies_in_settlement(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('loglinear', ['F02' => 11, 'T02' => 1], 2, 100.0, 10000.0);

        // 只推进 1 分钟:11 座农田满产 154/min,10 分钟会顶到 BASE_STORAGE 1000,精确断言就没了
        Carbon::setTestNow($base->copy()->addMinutes(1));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(154.0, $sim['transportDemandPerMin'], 0.0001, '11 × 14');
        $this->assertEqualsWithDelta(1.1, $sim['transportLoad'], 0.0001, '154 / 140');
        $this->assertEqualsWithDelta(0.88, $sim['logisticsFactor'], 0.0001, '1 − 0.3 × (0.10 / 0.25)');
        $this->assertFalse($sim['transportCongestion'], '负载 1.10 <= 1.25 → 还没到拥堵警报线');
        // 粮食 = 100 + 154 × 0.88 × 1 = 235.52(满产的话是 254,触底 0.25 的话是 138.5)
        $this->assertEqualsWithDelta(235.52, $this->amountOf($city, 'food'), 0.0001);
        // 维护 = 11×4 + 2 = 46/min × 1 = 46
        $this->assertEqualsWithDelta(9954.0, $this->moneyOf($city), 0.0001);
    }

    // 物流率与其它乘区同级:同时打折产出与投入,不会出现「按满产吃料、按低产出货」的凭空损耗
    public function test_logistics_scales_input_and_output_together(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // P01 磨坊单栋:需求 = 吃粮 10 + 产面粉 8 = 18;无路 → 负载 18 → 物流率 0.25
        $city = $this->makeCity('logmill', ['P01' => 1], 2, 100.0, 10000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(18.0, $sim['transportDemandPerMin'], 0.0001);
        $this->assertEqualsWithDelta(0.25, $sim['logisticsFactor'], 0.0001);
        // 需求 = 10 × 0.25 × 10min = 25 <= 库存 100 → recipeRate = 1.0
        // 面粉 = 8 × 0.25 × 10 = 20;粮食 = 100 − 25 = 75
        $this->assertEqualsWithDelta(20.0, $this->amountOf($city, 'flour'), 0.0001);
        $this->assertEqualsWithDelta(75.0, $this->amountOf($city, 'food'), 0.0001);
        $this->assertEqualsWithDelta(2.0, $sim['grossProductionPerMin']['flour'], 0.0001, '8 × 0.25');
        $this->assertEqualsWithDelta(2.5, $sim['grossConsumptionPerMin']['food'], 0.0001, '10 × 0.25');
    }

    // 物流与维护欠费叠乘:两者相互独立,产出 = 基础 × 物流 0.25 × 欠费 0.5
    public function test_logistics_stacks_with_maintenance_arrears(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // 资金 10 < 应付维护 40 → 欠费半停工;时代 II 无路 → 物流 0.25
        $city = $this->makeCity('logarr', ['F02' => 1], 2, 100.0, 10.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertTrue($sim['maintenanceArrears']);
        $this->assertEqualsWithDelta(0.25, $sim['logisticsFactor'], 0.0001);
        // 粮食 = 100 + 14 × 0.25 × 0.5 × 10 = 117.5
        $this->assertEqualsWithDelta(117.5, $this->amountOf($city, 'food'), 0.0001);
    }

    // ---- §13 生产倍率硬上限 ----

    public function test_multiplier_product_clamps_at_hard_cap(): void
    {
        // 上限值抄 §13 原文:普通系统 2.75×,终局特殊建筑 3.25×
        $this->assertSame(2.75, SimConstants::MULTIPLIER_CAP);
        $this->assertSame(3.25, SimConstants::MULTIPLIER_CAP_ENDGAME);

        // 七乘区全 1.0 → 1.0(M2 现状,不该被硬帽改动)
        $this->assertEqualsWithDelta(1.0, SimulationService::multiplierProduct(
            ['worker' => 1.0, 'power' => 1.0, 'logistics' => 1.0, 'tech' => 1.0, 'npc' => 1.0, 'tool' => 1.0, 'event' => 1.0]
        ), 0.0001);

        // 未超帽:原样返回
        $this->assertEqualsWithDelta(2.25, SimulationService::multiplierProduct(['a' => 1.5, 'b' => 1.5]), 0.0001);
        $this->assertEqualsWithDelta(2.75, SimulationService::multiplierProduct(['a' => 2.75]), 0.0001);

        // 多乘数叠加超帽:2.0 × 2.0 = 4.0 → 夹到 2.75
        $this->assertEqualsWithDelta(2.75, SimulationService::multiplierProduct(['a' => 2.0, 'b' => 2.0]), 0.0001);
        // 逐格看着都"不过分",连乘就爆表:1.5 × 1.5 × 1.5 = 3.375 → 2.75。
        // 这正是 §13 要防的「NPC + 工具 + 科技 + 事件」四件套叠乘
        $this->assertEqualsWithDelta(2.75, SimulationService::multiplierProduct(
            ['tech' => 1.5, 'npc' => 1.5, 'tool' => 1.5]
        ), 0.0001);
        // 七格各 1.2:1.2^7 = 3.583… → 同样夹到 2.75
        $this->assertEqualsWithDelta(2.75, SimulationService::multiplierProduct(
            ['worker' => 1.2, 'power' => 1.2, 'logistics' => 1.2, 'tech' => 1.2, 'npc' => 1.2, 'tool' => 1.2, 'event' => 1.2]
        ), 0.0001);

        // 终局档:同一组乘数传 3.25 的帽子 → 3.375 夹到 3.25(M2 尚无建筑走这一档,先把口子留好并锁死数值)
        $this->assertEqualsWithDelta(3.25, SimulationService::multiplierProduct(
            ['tech' => 1.5, 'npc' => 1.5, 'tool' => 1.5], SimConstants::MULTIPLIER_CAP_ENDGAME
        ), 0.0001);

        // 打折方向不受硬帽影响:硬帽只封加成,不得把 < 1 的乘数积抬上去
        $this->assertEqualsWithDelta(0.5, SimulationService::multiplierProduct(['worker' => 0.5]), 0.0001);
        $this->assertEqualsWithDelta(0.125, SimulationService::multiplierProduct(
            ['worker' => 0.5, 'logistics' => 0.5, 'event' => 0.5]
        ), 0.0001);
        $this->assertEqualsWithDelta(0.0, SimulationService::multiplierProduct(['worker' => 0.0, 'tech' => 999.0]), 0.0001);
    }

    // ---- 快照契约 ----

    // /api/city 必须带 logistics 五件套(全 snake_case)
    public function test_snapshot_exposes_logistics_fields(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('logsnap', ['F02' => 1], 2, 100.0, 10000.0);

        $res = $this->actingAs(User::where('username', 'logsnap')->first())->getJson('/api/city');
        $res->assertOk();
        $res->assertJsonStructure(['data' => ['city' => [
            'logistics' => ['capacity', 'demand_per_min', 'load', 'factor', 'congestion'],
        ]]]);

        // 时代 II、一座 F02、没有路:需求 14 / 容量 0 → 负载 14 → 物流率 0.25 + 拥堵
        $snapshot = $res->json('data.city.logistics');
        $this->assertEqualsWithDelta(0.0, $snapshot['capacity'], 0.0001);
        $this->assertEqualsWithDelta(14.0, $snapshot['demand_per_min'], 0.0001);
        $this->assertEqualsWithDelta(14.0, $snapshot['load'], 0.0001);
        $this->assertEqualsWithDelta(0.25, $snapshot['factor'], 0.0001);
        $this->assertTrue($snapshot['congestion']);

        // city_id 未用到,仅确保上面的 makeCity 真的建了城(避免断言全落在空对象上)
        $this->assertNotNull($city->id);
    }

    // ---- 公共辅助 ----

    // 受控城市:清空初始建筑,按 [buildingId => 数量] 摆放(工人一律补满该级需求),
    // 再覆写时代 / 人口 / 粮食 / 资金。人口固定 0:本文件验的是物流,不要让人口吃粮与增长混进算式
    private function makeCity(
        string $un,
        array $buildings,
        int $eraOrder,
        float $food = 100.0,
        float $money = 10000.0
    ): City {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        $slot = 0;
        foreach ($buildings as $bid => $count) {
            $workers = (int) DB::table('building_level_definition')
                ->where('building_id', $bid)->where('level', 1)->value('worker_required');
            for ($i = 0; $i < $count; $i++) {
                CityBuildingInstance::create([
                    'city_id' => $city->id, 'building_id' => $bid, 'level' => 1,
                    // 摆成 4×4 网格:只要 (x, y) 不重复即可(测试夹具直接落库,不走占地校验)
                    'x' => 1 + ($slot % 4) * 4, 'y' => 1 + intdiv($slot, 4) * 4,
                    'status' => 'active', 'assigned_workers' => $workers,
                ]);
                $slot++;
            }
        }

        // era_key 只作显示,所有比较运算读 era_order(见 2026_08_10_700001 迁移注释)
        DB::table('cities')->where('id', $city->id)->update([
            'population' => 0,
            'money' => $money,
            'era_key' => $eraOrder >= 2 ? 'II' : 'I',
            'era_order' => $eraOrder,
        ]);
        DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', 'food')->update(['amount' => $food]);

        return $city;
    }

    private function amountOf(City $city, string $resourceId): float
    {
        return (float) (DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', $resourceId)->value('amount') ?? 0);
    }

    private function moneyOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('money');
    }
}
