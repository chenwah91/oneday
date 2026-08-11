<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Modifier\ModifierBus;
use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;
use App\Game\Modifier\Providers\EventMultiplierProvider;
use App\Game\Modifier\Providers\LogisticsMultiplierProvider;
use App\Game\Modifier\Providers\NpcMultiplierProvider;
use App\Game\Modifier\Providers\PowerMultiplierProvider;
use App\Game\Modifier\Providers\TechMultiplierProvider;
use App\Game\Modifier\Providers\ToolMultiplierProvider;
use App\Game\Modifier\Providers\WorkerMultiplierProvider;
use App\Game\Simulation\SimConstants;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

// M3-D0 结算内核 modifier 总线(app/Game/Modifier)。
//
// 本文件验的是**结构与契约**,不是玩法数值:
//   ① 七乘区每一格都恰好有一个 Provider(少一格 = 某些实例会拿到不完整的乘区表);
//   ② 四个后接线的槽(power / npc / tool / event)在**没有任何投稿**时恒 1.0 = 接入前的历史行为;
//   ③ 三个已接线槽(worker / tech / logistics)的输出与重构前逐槽一致(黄金样本);
//   ④ flat 通道在没有投稿者时恒 0.0,有投稿者时按 target 求和;
//   ⑤ 非产量 target 的消费点登记表唯一且完整(D0.3)。
//
// 玩法数值的回归由既有 379 条测试负责(WorkerAssignTest / TechEffectTest / LogisticsTest …),
// 本次重构一条都没改 —— 那才是「零行为变化」的真正验收标准。
class ModifierBusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---- ① 结构:七格各有其主 ----

    public function test_default_bus_covers_every_slot_exactly_once(): void
    {
        $bus = ModifierBus::default();

        // 槽名与顺序都与 §10.11 的固定名单一致(顺序不影响乘积,固定下来只为 diff 可比)
        $this->assertSame(ModifierTarget::SLOTS, $bus->slots());
        $this->assertCount(7, ModifierTarget::SLOTS, '§10.11 生产总公式的七项是固定名单,不许扩名');

        // 每个 Provider 认领的槽 = 它注册进去的那一格
        foreach (ModifierTarget::SLOTS as $slot) {
            $this->assertSame($slot, $bus->provider($slot)->slot());
        }
    }

    public function test_slot_can_only_be_claimed_once(): void
    {
        $this->expectException(LogicException::class);

        (new ModifierBus())
            ->register(new WorkerMultiplierProvider())
            ->register(new WorkerMultiplierProvider());
    }

    public function test_provider_with_unknown_slot_is_rejected(): void
    {
        $rogue = new class extends MultiplierProvider {
            public function slot(): string { return 'happiness_flat'; } // flat 通道不是乘区
            public function multiplierFor(array $unit): float { return 1.0; }
        };

        $this->expectException(LogicException::class);
        (new ModifierBus())->register($rogue);
    }

    public function test_incomplete_bus_fails_loudly_on_prepare(): void
    {
        // 缺 event 一格:multiplierProduct 对缺键是"静默按 1.0",不炸出来就成了隐形数值 bug
        $bus = (new ModifierBus())
            ->register(new WorkerMultiplierProvider())
            ->register(new PowerMultiplierProvider())
            ->register(new LogisticsMultiplierProvider())
            ->register(new TechMultiplierProvider())
            ->register(new NpcMultiplierProvider())
            ->register(new ToolMultiplierProvider());

        $this->expectException(LogicException::class);
        $bus->prepare($this->context(), []);
    }

    public function test_bus_requires_prepare_before_reading(): void
    {
        $this->expectException(LogicException::class);
        ModifierBus::default()->multipliersFor($this->unit());
    }

    // ---- ② 占位槽恒 1.0 ----

    // power / npc / tool / event 四格在**没有任何投稿**时必须恒为 1.0(= 接入前的历史行为),
    // 否则就是偷偷改了全服产量。
    //
    // 四格如今都已接线(npc W2-A / tool W3-A / event W3-B / power W4-A),但这条不变量仍然成立
    // 且更重要了:空城(没 NPC、没工具、没事件、没耗电建筑)拿到的必须还是精确的 1.0。
    // power 那一格的具体曲线与缺电打折在 PowerTest 里逐档验
    public function test_placeholder_slots_return_exactly_one(): void
    {
        $bus = ModifierBus::default();
        $bus->prepare($this->context(), []);

        $placeholders = [
            ModifierTarget::SLOT_POWER,
            ModifierTarget::SLOT_NPC,
            ModifierTarget::SLOT_TOOL,
            ModifierTarget::SLOT_EVENT,
        ];

        // 换几种差别很大的实例:占位槽不该对任何输入有反应
        $units = [
            $this->unit(),
            $this->unit(['buildingId' => 'P01', 'workerRequired' => 0, 'assignedWorkers' => 0]),
            $this->unit(['grossIn' => ['food' => 10.0], 'grossOut' => ['flour' => 8.0], 'maintMoney' => 99.0]),
        ];

        foreach ($placeholders as $slot) {
            foreach ($units as $u) {
                $this->assertSame(1.0, $bus->provider($slot)->multiplierFor($u), "{$slot} 占位槽必须恒 1.0");
            }
        }

        // 四格连乘也必须精确等于 1.0(浮点上 1.0×1.0 是精确的,这里锁死"没人偷偷动手")
        $m = $bus->multipliersFor($this->unit(['workerRequired' => 0]));
        $this->assertSame(1.0, $m[ModifierTarget::SLOT_POWER] * $m[ModifierTarget::SLOT_NPC]
            * $m[ModifierTarget::SLOT_TOOL] * $m[ModifierTarget::SLOT_EVENT]);
    }

    // ---- ③ 黄金样本:三个已接线槽与重构前逐槽一致 ----

    // worker(§10.4):min(1, 已分配 / 需求);需求 0 恒 1.0;派超了不加成;闸门关掉恒 1.0
    public function test_worker_slot_matches_pre_refactor_formula(): void
    {
        $bus = ModifierBus::default();
        $bus->prepare($this->context(), []);
        $worker = $bus->provider(ModifierTarget::SLOT_WORKER);

        $this->assertSame(1.0, $worker->multiplierFor($this->unit(['workerRequired' => 4, 'assignedWorkers' => 4])));
        $this->assertSame(0.5, $worker->multiplierFor($this->unit(['workerRequired' => 4, 'assignedWorkers' => 2])));
        $this->assertSame(0.25, $worker->multiplierFor($this->unit(['workerRequired' => 4, 'assignedWorkers' => 1])));
        $this->assertSame(0.0, $worker->multiplierFor($this->unit(['workerRequired' => 4, 'assignedWorkers' => 0])));
        $this->assertSame(1.0, $worker->multiplierFor($this->unit(['workerRequired' => 4, 'assignedWorkers' => 9])),
            '派超了也不加成(min 夹住 1.0)');
        $this->assertSame(1.0, $worker->multiplierFor($this->unit(['workerRequired' => 0, 'assignedWorkers' => 0])),
            '住宅 / 仓库这类不需要人的建筑恒 1.0');
    }

    // 用工闸门关闭(game_settings.worker_gate_enabled = false)→ 全部恒 1.0
    public function test_worker_slot_respects_gate_setting(): void
    {
        DB::table('game_settings')->where('setting_key', GameSetting::WORKER_GATE_ENABLED)
            ->update(['value_json' => json_encode(false)]);
        GameSetting::flush();

        $bus = ModifierBus::default();
        $bus->prepare($this->context(), []);

        $this->assertSame(1.0, $bus->provider(ModifierTarget::SLOT_WORKER)
            ->multiplierFor($this->unit(['workerRequired' => 4, 'assignedWorkers' => 0])));
    }

    // tech(§5):1 + 0.02 × 该建筑所属分支的已解锁条数;别的分支不外溢
    public function test_tech_slot_matches_pre_refactor_formula(): void
    {
        $city = $this->makeCity('modtech', ['F02', 'R01']);
        $this->unlockTech($city->id, 'TECH_I_SUST'); // survival_agriculture

        $bus = ModifierBus::default();
        $bus->prepare($this->context(['cityId' => (int) $city->id, 'buildingIds' => ['F02', 'R01']]), []);
        $tech = $bus->provider(ModifierTarget::SLOT_TECH);

        $this->assertEqualsWithDelta(1.02, $tech->multiplierFor($this->unit(['buildingId' => 'F02'])), 0.0001);
        $this->assertEqualsWithDelta(1.0, $tech->multiplierFor($this->unit(['buildingId' => 'R01'])), 0.0001,
            'R01 属工业分支,不吃农业分支的加成');

        // 同分支两条 → 线性叠加 1.04(不是 1.02² 复利)
        $this->unlockTech($city->id, 'TECH_II_SUST');
        $bus2 = ModifierBus::default();
        $bus2->prepare($this->context(['cityId' => (int) $city->id, 'buildingIds' => ['F02']]), []);
        $this->assertEqualsWithDelta(1.04, $bus2->provider(ModifierTarget::SLOT_TECH)
            ->multiplierFor($this->unit(['buildingId' => 'F02'])), 0.0001);
    }

    // logistics(§10.7):需求 = Σ(输入 + 输出) × distanceFactor,时代 I 不计需求;
    // 负载 → 物流率的曲线仍由 SimulationService 的两个纯函数负责,Provider 不抄第二份
    public function test_logistics_slot_matches_pre_refactor_formula(): void
    {
        $farm = $this->unit(['grossOut' => ['food' => 14.0]]);

        // 时代 I:闸门未开,需求恒 0 → 满产
        $era1 = ModifierBus::default();
        $era1->prepare($this->context(['eraOrder' => 1]), [$farm]);
        /** @var LogisticsMultiplierProvider $p1 */
        $p1 = $era1->provider(ModifierTarget::SLOT_LOGISTICS);
        $this->assertEqualsWithDelta(0.0, $p1->demandPerMin(), 0.0001);
        $this->assertEqualsWithDelta(1.0, $p1->multiplierFor($farm), 0.0001);
        $this->assertFalse($p1->congestion());

        // 时代 II 且一条路都没有:需求 14 / 容量 0 → 负载 14 → 触底 0.25 + 拥堵警报
        $era2 = ModifierBus::default();
        $era2->prepare($this->context(['eraOrder' => 2]), [$farm]);
        /** @var LogisticsMultiplierProvider $p2 */
        $p2 = $era2->provider(ModifierTarget::SLOT_LOGISTICS);
        $this->assertEqualsWithDelta(14.0, $p2->demandPerMin(), 0.0001);
        $this->assertEqualsWithDelta(14.0, $p2->load(), 0.0001, '14 / max(1, 0)');
        $this->assertEqualsWithDelta(SimConstants::LOGISTICS_FACTOR_MIN, $p2->multiplierFor($farm), 0.0001);
        $this->assertTrue($p2->congestion());

        // 时代 II + 一条路(容量 140)+ 11 座农田:需求 154 → 负载 1.10 → 线性档 0.88
        $eleven = array_fill(0, 11, $farm);
        $era2b = ModifierBus::default();
        $era2b->prepare($this->context([
            'eraOrder'   => 2,
            'capacities' => [ModifierContext::CAP_TRANSPORT => 140.0],
        ]), $eleven);
        /** @var LogisticsMultiplierProvider $p3 */
        $p3 = $era2b->provider(ModifierTarget::SLOT_LOGISTICS);
        $this->assertEqualsWithDelta(154.0, $p3->demandPerMin(), 0.0001, '11 × 14');
        $this->assertEqualsWithDelta(1.1, $p3->load(), 0.0001);
        $this->assertEqualsWithDelta(0.88, $p3->multiplierFor($farm), 0.0001, '1 − 0.3 × (0.10 / 0.25)');
        $this->assertFalse($p3->congestion(), '负载 1.10 <= 1.25 → 还没到拥堵警报线');
        // 曲线口径的唯一落点仍是 SimulationService 的纯函数,Provider 与它必须逐点一致
        $this->assertSame(SimulationService::logisticsFactor($p3->load()), $p3->multiplierFor($farm));

        // 磨坊:需求同时算输入与输出(10 + 8 = 18),不是只算产出
        $mill = $this->unit(['grossIn' => ['food' => 10.0], 'grossOut' => ['flour' => 8.0]]);
        $era2c = ModifierBus::default();
        $era2c->prepare($this->context(['eraOrder' => 2]), [$mill]);
        /** @var LogisticsMultiplierProvider $p4 */
        $p4 = $era2c->provider(ModifierTarget::SLOT_LOGISTICS);
        $this->assertEqualsWithDelta(18.0, $p4->demandPerMin(), 0.0001);
    }

    // 端到端黄金样本:三个已接线槽同时生效,产出精确到小数。
    // F02 农田 14 粮食/min,派 2/4 工人(worker 0.5),解锁一条农业科技(tech 1.02),
    // 时代 II 且无路(需求 14 / 容量 0 → logistics 0.25)。
    // 乘数积 = 0.5 × 1.02 × 0.25 = 0.1275 → 14 × 0.1275 = 1.785/min → 10 分钟 = 17.85
    public function test_golden_sample_three_wired_slots_together(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('modgolden', ['F02'], 2);
        DB::table('city_building_instances')->where('city_id', $city->id)->update(['assigned_workers' => 2]);
        $this->unlockTech($city->id, 'TECH_I_SUST');

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());

        $this->assertEqualsWithDelta(1.785, $sim['grossProductionPerMin']['food'], 0.0001);
        $this->assertEqualsWithDelta(17.85, $this->amountOf($city, 'food'), 0.0001);
        // 维护资金不受任何乘区影响:4/min × 10min = 40
        $this->assertEqualsWithDelta(9960.0, $this->moneyOf($city), 0.0001);

        // 同一组输入喂给总线,乘数积必须等于 0.1275(与上面的产出互为印证)
        $bus = ModifierBus::default();
        $bus->prepare($this->context([
            'cityId'      => (int) $city->id,
            'eraOrder'    => 2,
            'buildingIds' => ['F02'],
        ]), [$this->unit(['grossOut' => ['food' => 14.0]])]);
        $m = $bus->multipliersFor($this->unit([
            'buildingId' => 'F02', 'workerRequired' => 4, 'assignedWorkers' => 2,
        ]));
        $this->assertEqualsWithDelta(0.1275, SimulationService::multiplierProduct($m), 0.0001);
        $this->assertSame(ModifierTarget::SLOTS, array_keys($m), '七格齐全且顺序固定');
    }

    // ---- ④ flat 通道(D0.2) ----

    public function test_flat_channels_are_zero_before_m3_systems(): void
    {
        $bus = ModifierBus::default();
        $bus->prepare($this->context(), []);

        foreach (ModifierTarget::FLAT_TARGETS as $target) {
            $this->assertSame(0.0, $bus->flat($target), "{$target} 在无投稿者时必须恒 0.0(= 接入前的历史行为)");
            $this->assertSame(0.0, $bus->flat($target, 0.0, 30.0), '按段取值同样为 0.0');
        }
    }

    // 通道是真的通的:Provider 一投稿,同 target 的 flat 就按和累计
    public function test_flat_channel_sums_specs_by_target(): void
    {
        $donor = new class extends MultiplierProvider {
            public function slot(): string { return ModifierTarget::SLOT_NPC; }
            public function multiplierFor(array $unit): float { return 1.0; }
            public function flatSpecs(): array
            {
                return [
                    ModifierSpec::flat(ModifierTarget::HAPPINESS_FLAT, -6.0),
                    ModifierSpec::flat(ModifierTarget::HAPPINESS_FLAT, 1.5),
                    ModifierSpec::flat(ModifierTarget::SECURITY_FLAT, 4.0),
                ];
            }
        };

        $bus = (new ModifierBus())
            ->register(new WorkerMultiplierProvider())
            ->register(new PowerMultiplierProvider())
            ->register(new LogisticsMultiplierProvider())
            ->register(new TechMultiplierProvider())
            ->register($donor)
            ->register(new ToolMultiplierProvider())
            ->register(new EventMultiplierProvider());
        $bus->prepare($this->context(), []);

        $this->assertEqualsWithDelta(-4.5, $bus->flat(ModifierTarget::HAPPINESS_FLAT), 0.0001);
        $this->assertEqualsWithDelta(4.0, $bus->flat(ModifierTarget::SECURITY_FLAT), 0.0001);
        // 没人投稿的 target 仍是 0.0(不是报错,也不是 null)
        $this->assertSame(0.0, $bus->flat(ModifierTarget::MARKET_FEE_PCT));
    }

    // ---- ⑤ 名单与 Spec 的 allowlist ----

    public function test_consumption_point_registry_is_complete_and_disjoint(): void
    {
        $this->assertNotEmpty(ModifierTarget::CONSUMPTION_POINTS);

        foreach (ModifierTarget::CONSUMPTION_POINTS as $target => $meta) {
            $this->assertNotEmpty($meta['consumer'] ?? null, "{$target} 必须登记唯一消费点");
            $this->assertNotEmpty($meta['wave'] ?? null, "{$target} 必须登记接线波次");
            $this->assertNotEmpty($meta['desc'] ?? null, "{$target} 必须有中文说明");
            // 非产量 target 不许与七乘区重名:重名就意味着同一个效果有两条生效路径(双计)
            $this->assertNotContains($target, ModifierTarget::SLOTS);
            $this->assertNotContains($target, ModifierTarget::FLAT_TARGETS);
        }

        $all = ModifierTarget::all();
        $this->assertSame(array_values(array_unique($all)), array_values($all), 'target 名单不得有重复');
    }

    public function test_modifier_spec_rejects_unregistered_values(): void
    {
        // 合法:全城 flat
        $spec = ModifierSpec::flat(ModifierTarget::HAPPINESS_FLAT, -6.0);
        $this->assertSame(ModifierSpec::OP_FLAT, $spec->op);
        $this->assertTrue($spec->appliesTo(ModifierSpec::SCOPE_BUILDING_INSTANCE, '7'), 'scope=city 对任何对象都成立');

        // 合法:限定到单栋实例
        $scoped = ModifierSpec::pct(ModifierTarget::SLOT_EVENT, 0.30, ModifierSpec::SCOPE_BUILDING_INSTANCE, '7');
        $this->assertTrue($scoped->appliesTo(ModifierSpec::SCOPE_BUILDING_INSTANCE, '7'));
        $this->assertFalse($scoped->appliesTo(ModifierSpec::SCOPE_BUILDING_INSTANCE, '8'));

        $cases = [
            'target 未登记' => fn () => ModifierSpec::flat('happiness_boost', 1.0),
            'scope 未登记'  => fn () => new ModifierSpec(ModifierTarget::SLOT_EVENT, 'planet', ModifierSpec::OP_PCT, 0.1, 'x'),
            'op 未登记'     => fn () => new ModifierSpec(ModifierTarget::SLOT_EVENT, ModifierSpec::SCOPE_CITY, 'mul', 0.1),
            'city 带 key'   => fn () => new ModifierSpec(ModifierTarget::SLOT_EVENT, ModifierSpec::SCOPE_CITY, ModifierSpec::OP_PCT, 0.1, '7'),
            '非 city 缺 key' => fn () => new ModifierSpec(ModifierTarget::SLOT_EVENT, ModifierSpec::SCOPE_RESOURCE, ModifierSpec::OP_PCT, 0.1),
        ];

        foreach ($cases as $label => $case) {
            try {
                $case();
                $this->fail("{$label}:应当被 allowlist 拒绝");
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    // ---- 公共辅助 ----

    // 合成一行"建筑实例中间结构",默认是一座满员的 F02 农田
    private function unit(array $overrides = []): array
    {
        return $overrides + [
            'instanceId'      => 1,
            'buildingId'      => 'F02',
            'level'           => 1,
            'grossOut'        => [],
            'grossIn'         => [],
            'maintMoney'      => 0.0,
            'maintFood'       => 0.0,
            'workerRequired'  => 4,
            'assignedWorkers' => 4,
            'multipliers'     => [],
            'maintRate'       => 1.0,
        ];
    }

    // 合成准备段上下文。默认:不存在的城(cityId 0)+ 空建筑列表 → 科技 Provider 第一步就返回
    private function context(array $overrides = []): ModifierContext
    {
        $o = $overrides + [
            'cityId'       => 0,
            'eraOrder'     => 1,
            'buildingIds'  => [],
            'capacities'   => [],
            'city'         => (object) ['id' => 0],
            'now'          => now(),
            'totalMinutes' => 10.0,
        ];

        return new ModifierContext(
            cityId: $o['cityId'],
            eraOrder: $o['eraOrder'],
            buildingIds: $o['buildingIds'],
            capacities: $o['capacities'],
            city: $o['city'],
            now: $o['now'],
            totalMinutes: $o['totalMinutes'],
        );
    }

    // 受控城市:清空初始建筑,按 $buildingIds 摆 active 实例并补满工人;
    // 人口 0(排除吃粮与税收)、资金 10000(排除维护欠费)、资源清零
    private function makeCity(string $un, array $buildingIds, int $eraOrder = 1): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);
        DB::table('cities')->where('id', $city->id)
            ->update(['population' => 0, 'money' => 10000, 'era_order' => $eraOrder]);

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

    private function moneyOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('money');
    }
}
