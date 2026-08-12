<?php

namespace Tests\Feature\Admin;

use App\Game\Building\ConstructionService;
use App\Game\City\CityFactory;
use App\Game\Defense\DefenseService;
use App\Game\Market\MarketDefinition;
use App\Game\Market\PriceEngine;
use App\Game\NPC\NpcBonus;
use App\Game\Simulation\SimConstants;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameRuleException;
use App\Support\GameSetting;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// W11-A:game_settings 大扩展 + 设定机制补丁。
//
// ══ 这个文件守什么 ═══════════════════════════════════════════════════════════
//   ① **零行为变化**:新登记的每一个键,默认值必须逐一等于它替换掉的那个常量的旧值。
//      表驱动地逐条比,手误改错一个数就立刻变红 —— 这是整批改造唯一的安全网;
//   ② **机制**:'integer'(拒绝 3.5)/ 'depends'(跨键约束)/ 'group' / 'deprecated' 四件;
//   ③ **黄金样本**:每组抽 1~2 个键,真的改一次,断言结算 / 端点的数值按公式跟着动 ——
//      光有「键登记了」不算接线成功,得有人真读它。
class GameSettingExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // ---------- 夹具 ----------

    private function admin(string $username = 'w11admin'): User
    {
        $user = User::create([
            'username' => $username, 'name' => $username,
            'email' => $username . '@example.com', 'password' => 'password123',
        ]);
        $user->forceFill(['role' => Role::ADMIN])->save();

        return $user;
    }

    // 直接改设定值(绕过 HTTP,但仍走 set() 的完整校验链)
    private function setValue(string $key, mixed $value): void
    {
        GameSetting::set($key, $value, null, 'W11-A 测试');
    }

    private function makeCity(string $un, array $buildings = [], int $population = 40, float $food = 500.0, float $money = 10000.0): City
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        $x = 1;
        foreach ($buildings as $bid => $count) {
            $workers = (int) DB::table('building_level_definition')
                ->where('building_id', $bid)->where('level', 1)->value('worker_required');
            for ($i = 0; $i < $count; $i++) {
                CityBuildingInstance::create([
                    'city_id' => $city->id, 'building_id' => $bid, 'level' => 1,
                    'x' => $x, 'y' => 1, 'status' => 'active', 'assigned_workers' => $workers,
                ]);
                $x += 4;
            }
        }

        DB::table('cities')->where('id', $city->id)->update(['population' => $population, 'money' => $money]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => $food]);

        return $city;
    }

    // ==========================================================================================
    // ① 零行为变化:新键默认值 === 被替换常量的旧值
    // ==========================================================================================

    // 逐条对照表。左边是新登记的 key,右边是它接管之前那个常量的**现行值**。
    //
    // 为什么右边写字面量而不是直接引常量:常量本身也可能被人改坏,两边都引同一个常量等于什么都没验。
    // 这里写死 v3.2 / backlog 已批准的数值,任何一侧被动过都会红。
    private static function defaultsTable(): array
    {
        return [
            // core
            GameSetting::MAX_OFFLINE_SECONDS               => 43200,   // SimConstants::MAX_OFFLINE_SECONDS
            GameSetting::SEGMENT_MINUTES                   => 30,      // SimConstants::SEGMENT_MINUTES

            // population
            GameSetting::FOOD_PER_CAPITA_PER_MIN           => 0.03,    // §10.1
            GameSetting::WORKER_RATIO                      => 0.60,    // §10.4
            GameSetting::POPULATION_BASE_GROWTH_PER_MIN    => 0.002,   // §10.3 BASE_GROWTH_PER_MIN
            GameSetting::FOOD_SHORTAGE_MINUTES             => 3,
            GameSetting::FOOD_SHORTAGE_LOSS_PER_MIN        => -0.005,
            GameSetting::FOOD_ZERO_GRACE_MINUTES           => 10,
            GameSetting::FOOD_ZERO_LOSS_PER_MIN            => -0.01,
            GameSetting::HOUSING_USAGE_FULL                => 0.80,
            GameSetting::HOUSING_FACTOR_AT_CAP             => 0.2,
            GameSetting::HAPPINESS_FACTOR_ZERO_BELOW       => 50.0,
            GameSetting::HAPPINESS_FACTOR_FULL_AT          => 70.0,
            GameSetting::HAPPINESS_FACTOR_AT_FLOOR         => 0.5,
            GameSetting::INITIAL_POPULATION                => 30,      // SimConstants::START_POPULATION
            GameSetting::BASE_STORAGE                      => 1000,

            // happiness
            GameSetting::HAPPINESS_BASE                    => 60.0,
            GameSetting::HAPPINESS_RISE_PER_MIN            => 0.5,
            GameSetting::HAPPINESS_FALL_PER_MIN            => 1.0,
            GameSetting::HAPPINESS_HOUSING_BONUS           => 10.0,
            GameSetting::HAPPINESS_HOUSING_GOOD_USAGE      => 0.90,
            GameSetting::HAPPINESS_HOUSING_OVER_PENALTY    => -15.0,
            GameSetting::HAPPINESS_HOUSING_OVER_SPAN       => 0.20,
            // 原 HAPPINESS_COVERAGE_BONUS = 5.0 一个常量喂两行,拆键后两边都必须仍是 5.0
            GameSetting::HAPPINESS_MEDICAL_BONUS           => 5.0,
            GameSetting::HAPPINESS_SECURITY_BONUS          => 5.0,
            GameSetting::FOOD_QUALITY_FLOUR_BREAD_COVERAGE => 0.30,
            GameSetting::FOOD_QUALITY_FLOUR_BREAD_BONUS    => 5.0,
            GameSetting::FOOD_QUALITY_PROCESSED_COVERAGE   => 0.50,
            GameSetting::FOOD_QUALITY_PROCESSED_BONUS      => 10.0,
            GameSetting::FOOD_QUALITY_HIGH_COVERAGE        => 0.50,
            GameSetting::FOOD_QUALITY_HIGH_BONUS           => 15.0,
            GameSetting::FOOD_DEFICIT_GRACE_MINUTES        => 5,
            GameSetting::HAPPINESS_DEFICIT_PENALTY_PER_MIN => 1.0,

            // fiscal
            GameSetting::TAX_PER_CAPITA_ERA_1              => 0.02,
            GameSetting::TAX_ERA_MULTIPLIER                => 1.5,
            GameSetting::MAINTENANCE_ARREARS_FACTOR        => 0.50,
            GameSetting::FISCAL_WARNING_YELLOW_MINUTES     => 10.0,
            GameSetting::FISCAL_WARNING_RED_MINUTES        => 3.0,

            // governance
            GameSetting::GOVERNANCE_LOAD_GOOD              => 0.80,
            GameSetting::GOVERNANCE_LOAD_TIGHT             => 1.00,
            GameSetting::GOVERNANCE_LOAD_OVER              => 1.25,
            GameSetting::GOVERNANCE_EFFICIENCY_GOOD        => 1.00,
            GameSetting::GOVERNANCE_EFFICIENCY_TIGHT       => 0.90,
            GameSetting::GOVERNANCE_EFFICIENCY_OVER        => 0.70,
            GameSetting::GOVERNANCE_EFFICIENCY_COLLAPSE    => 0.50,

            // logistics
            GameSetting::LOGISTICS_MIN_ERA_ORDER           => 2,
            GameSetting::TRANSPORT_LOAD_TIGHT              => 1.00,
            GameSetting::TRANSPORT_LOAD_OVER               => 1.25,
            GameSetting::LOGISTICS_FACTOR_AT_OVER          => 0.70,

            // tech
            GameSetting::TECH_BRANCH_EFFICIENCY_BONUS      => 0.02,
            // 单线研究:接入前 TechService 用 exists() 判定「已有在研就拒绝」,等价于上限 1
            GameSetting::RESEARCH_PARALLEL_LIMIT           => 1,
            GameSetting::TECH_RESEARCH_MINUTES_MULTIPLIER  => 1,       // 接入前 = 定义表原值
            GameSetting::TECH_KNOWLEDGE_COST_MULTIPLIER    => 1,

            // npc
            GameSetting::NPC_TOTAL_CAP                     => 1.50,
            GameSetting::NPC_JOB_MISMATCH_RATE             => 0.25,

            // building
            GameSetting::CONSTRUCTION_DURATION_MULTIPLIER  => 1,       // 接入前 = 定义表原值
            GameSetting::BUILD_COST_MULTIPLIER             => 1,
            GameSetting::UPGRADE_COST_MULTIPLIER           => 1,
            GameSetting::DEMOLISH_REFUND_RATE              => 0.50,    // ConstructionService::DEMOLISH_REFUND_RATE
            GameSetting::CANCEL_REFUND_RATE                => 0.70,    // ConstructionService::CANCEL_REFUND_RATE
            GameSetting::UPGRADING_HOUSING_CAPACITY_RATE   => 0.50,

            // market / event
            GameSetting::MARKET_VOLATILITY_MULTIPLIER      => 1,       // 接入前 = 定义表逐资源 volatility 原值
            GameSetting::EVENT_EFFECT_MULTIPLIER_GLOBAL    => 1,       // 接入前 = 逐事件 effect_multiplier 原值
        ];
    }

    // 每个新数值键的登记默认值 = 它替换掉的旧常量值(逐一相等,一个都不许漂)
    public function test_every_new_numeric_default_equals_the_constant_it_replaced(): void
    {
        foreach (self::defaultsTable() as $key => $expected) {
            $this->assertArrayHasKey($key, GameSetting::DEFINITIONS, "未登记的新键:{$key}");
            // 用 float 比:JSON 没有「整数值的浮点数」,登记值写 1 与常量的 1.0 是同一个数
            $this->assertEqualsWithDelta(
                (float) $expected,
                (float) GameSetting::DEFINITIONS[$key]['default'],
                1e-12,
                "{$key} 的登记默认值与迁移前的常量值不一致(= 悄悄改了游戏规则)"
            );
            // 从库里读出来的也必须是同一个数(建表迁移按 DEFINITIONS 灌行,抄错一样会红)
            $this->assertEqualsWithDelta((float) $expected, (float) GameSetting::get($key), 1e-12, $key);
        }
    }

    // 三个新开关的默认值必须是「接入前的历史行为」= 全部开着
    public function test_new_gate_switches_default_to_historical_behaviour(): void
    {
        $this->assertTrue(GameSetting::get(GameSetting::MAINTENANCE_ENABLED));
        $this->assertTrue(GameSetting::get(GameSetting::LOGISTICS_GATE_ENABLED));
        $this->assertTrue(GameSetting::get(GameSetting::DEFENSE_GATE_ENABLED));
    }

    // 与 SimConstants 的交叉复核:常量仍留在原处作为「默认值的出处」,两边不许分家
    public function test_registered_defaults_still_match_the_source_constants(): void
    {
        $pairs = [
            [GameSetting::MAX_OFFLINE_SECONDS, SimConstants::MAX_OFFLINE_SECONDS],
            [GameSetting::SEGMENT_MINUTES, SimConstants::SEGMENT_MINUTES],
            [GameSetting::FOOD_PER_CAPITA_PER_MIN, SimConstants::FOOD_PER_CAPITA_PER_MIN],
            [GameSetting::WORKER_RATIO, SimConstants::WORKER_RATIO],
            [GameSetting::BASE_STORAGE, SimConstants::BASE_STORAGE],
            [GameSetting::INITIAL_POPULATION, SimConstants::START_POPULATION],
            [GameSetting::HAPPINESS_BASE, SimConstants::HAPPINESS_BASE],
            [GameSetting::HAPPINESS_MEDICAL_BONUS, SimConstants::HAPPINESS_COVERAGE_BONUS],
            [GameSetting::HAPPINESS_SECURITY_BONUS, SimConstants::HAPPINESS_COVERAGE_BONUS],
            [GameSetting::NPC_TOTAL_CAP, SimConstants::NPC_TOTAL_CAP],
            [GameSetting::TECH_BRANCH_EFFICIENCY_BONUS, SimConstants::TECH_BRANCH_EFFICIENCY_BONUS],
            [GameSetting::DEMOLISH_REFUND_RATE, ConstructionService::DEMOLISH_REFUND_RATE],
            [GameSetting::CANCEL_REFUND_RATE, ConstructionService::CANCEL_REFUND_RATE],
        ];

        foreach ($pairs as [$key, $constant]) {
            $this->assertEqualsWithDelta((float) $constant, (float) GameSetting::get($key), 1e-12, $key);
        }
    }

    // ==========================================================================================
    // ② 机制:group / integer / depends / deprecated
    // ==========================================================================================

    // 每个登记键都必须归到一个合法分组(后台按组渲染,漏一个就会掉出面板)
    public function test_every_registered_key_has_a_valid_group(): void
    {
        foreach (GameSetting::DEFINITIONS as $key => $meta) {
            $this->assertArrayHasKey('group', $meta, "{$key} 没有 group");
            $this->assertContains($meta['group'], GameSetting::GROUPS, "{$key} 的 group 不在 GROUPS 白名单里");
        }
    }

    // 后台列表必须把四件元数据都透出来,否则前端渲染不出分组 / 步进 / 只读 / 联动提示
    public function test_settings_endpoint_exposes_group_integer_depends_and_deprecated(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/settings');
        $res->assertOk();
        $settings = collect($res->json('data.settings'))->keyBy('setting_key');

        $core = $settings[GameSetting::MAX_OFFLINE_SECONDS];
        $this->assertSame('core', $core['group']);
        $this->assertTrue($core['integer']);
        $this->assertFalse($core['deprecated']);
        $this->assertNull($core['depends']);

        // 跨键约束要能被前端看见(「红警阈值不能高于黄警」这种提示不该等提交才报)
        $red = $settings[GameSetting::FISCAL_WARNING_RED_MINUTES];
        $this->assertSame('fiscal', $red['group']);
        $this->assertSame(
            [GameSetting::DEPENDS_LTE => GameSetting::FISCAL_WARNING_YELLOW_MINUTES],
            $red['depends']
        );

        // 死键:代码里已无任何消费点,后台渲染成只读置底
        $dead = $settings[GameSetting::EVENT_DEFENSE_OK_SECURITY_MIN];
        $this->assertTrue($dead['deprecated'], '已停用的治安代理阈值必须被标成 deprecated');

        // 非整数键不许被误标(0.03 的人均粮耗被标成整数就直接不可编辑了)
        $this->assertFalse($settings[GameSetting::FOOD_PER_CAPITA_PER_MIN]['integer']);
    }

    // integer:小数一律拒绝,整数与「整值的浮点数」一律放行
    public function test_integer_keys_reject_fractional_values(): void
    {
        // 拒绝路径:3.5 段没有意义
        try {
            $this->setValue(GameSetting::SEGMENT_MINUTES, 45.5);
            $this->fail('整数键收下了小数');
        } catch (GameRuleException) {
            // 拒绝之后值一定没被改动
            $this->assertSame(30, GameSetting::get(GameSetting::SEGMENT_MINUTES));
        }

        try {
            $this->setValue(GameSetting::EVENT_MAX_ACTIVE, 2.5);
            $this->fail('存量计数键(event_max_active)收下了小数');
        } catch (GameRuleException) {
            $this->assertSame(3, GameSetting::get(GameSetting::EVENT_MAX_ACTIVE));
        }

        // 放行路径:真整数
        $this->setValue(GameSetting::SEGMENT_MINUTES, 45);
        $this->assertSame(45, GameSetting::get(GameSetting::SEGMENT_MINUTES));

        // 放行路径:整值的 float —— JSON 里 60.0 与 60 无法区分,拒绝它等于拒绝合法输入
        $this->setValue(GameSetting::SEGMENT_MINUTES, 60.0);
        $this->assertEqualsWithDelta(60.0, (float) GameSetting::get(GameSetting::SEGMENT_MINUTES), 1e-12);

        // 非整数键不受影响
        $this->setValue(GameSetting::FOOD_PER_CAPITA_PER_MIN, 0.045);
        $this->assertEqualsWithDelta(0.045, (float) GameSetting::get(GameSetting::FOOD_PER_CAPITA_PER_MIN), 1e-12);
    }

    // depends(lte):拆除返还不得高于取消返还(§10.9 防拆建套利)
    public function test_depends_lte_rejects_inverted_refund_rates(): void
    {
        try {
            $this->setValue(GameSetting::DEMOLISH_REFUND_RATE, 0.9); // 当前 cancel = 0.70
            $this->fail('拆除返还被允许高于取消返还 = 拆建套利的口子');
        } catch (GameRuleException) {
            $this->assertEqualsWithDelta(0.50, (float) GameSetting::get(GameSetting::DEMOLISH_REFUND_RATE), 1e-12);
        }

        // 先抬高上界,再改下界 —— 这条正常运营路径必须走得通(比较的是**当前生效值**,不是登记默认值)
        $this->setValue(GameSetting::CANCEL_REFUND_RATE, 0.95);
        $this->setValue(GameSetting::DEMOLISH_REFUND_RATE, 0.9);
        $this->assertEqualsWithDelta(0.9, (float) GameSetting::get(GameSetting::DEMOLISH_REFUND_RATE), 1e-12);
    }

    // depends(gte):幸福因子的上拐点不得低于下拐点,否则线性段反向
    public function test_depends_gte_rejects_inverted_happiness_factor_knees(): void
    {
        $this->expectException(GameRuleException::class);
        $this->setValue(GameSetting::HAPPINESS_FACTOR_FULL_AT, 40.0); // 当前 zero_below = 50
    }

    // depends 也补挂在存量键上:灾害并发上限不得高于总并发上限
    public function test_depends_covers_existing_keys(): void
    {
        try {
            $this->setValue(GameSetting::EVENT_MAX_ACTIVE_DISASTER, 5); // 当前 event_max_active = 3
            $this->fail('灾害上限被允许高于总上限');
        } catch (GameRuleException) {
            $this->assertSame(1, GameSetting::get(GameSetting::EVENT_MAX_ACTIVE_DISASTER));
        }

        // 威胁分档:紧张阈值不得高于安全阈值
        try {
            $this->setValue(GameSetting::DEFENSE_THREAT_COVERAGE_TENSE, 2.0); // 当前 safe = 1.0
            $this->fail('紧张档阈值被允许高于安全档阈值');
        } catch (GameRuleException) {
            $this->assertEqualsWithDelta(0.6, (float) GameSetting::get(GameSetting::DEFENSE_THREAT_COVERAGE_TENSE), 1e-12);
        }
    }

    // §13 四机制不许被后台关停:滑点系数与手续费倍率的下限锁在 0.01
    public function test_anti_arbitrage_minimums_reject_zero(): void
    {
        foreach ([GameSetting::MARKET_SLIPPAGE_COEFFICIENT, GameSetting::MARKET_FEE_RATE_MULTIPLIER] as $key) {
            $this->assertSame(0.01, GameSetting::DEFINITIONS[$key]['min']);
            try {
                $this->setValue($key, 0);
                $this->fail("{$key} 被允许调到 0 = §13 的反套利机制可以被后台关停");
            } catch (GameRuleException) {
                // 下限本身可以填
                $this->setValue($key, 0.01);
                $this->assertEqualsWithDelta(0.01, (float) GameSetting::get($key), 1e-12);
            }
        }
    }

    // ==========================================================================================
    // ③ 黄金样本:改一个键,数值按公式跟着动
    // ==========================================================================================

    // population:worker_ratio 0.60 → 0.30,可用工人减半
    public function test_golden_worker_ratio_halves_available_workers(): void
    {
        $this->assertSame(60, SimulationService::availableWorkers(100)); // floor(100 × 0.60)

        $this->setValue(GameSetting::WORKER_RATIO, 0.30);

        $this->assertSame(30, SimulationService::availableWorkers(100));
    }

    // core:max_offline_seconds 调小之后,离线结算的 elapsedSeconds 立刻按新封顶截断
    public function test_golden_max_offline_seconds_caps_elapsed(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        $city = $this->makeCity('w11off');

        // 默认 12h 封顶:离开 24h 只结算 43200 秒
        Carbon::setTestNow($base->copy()->addHours(24));
        $this->assertSame(43200, SimulationService::simulate($city->fresh())['elapsedSeconds']);

        $this->setValue(GameSetting::MAX_OFFLINE_SECONDS, 3600);

        Carbon::setTestNow($base->copy()->addHours(48));
        $this->assertSame(3600, SimulationService::simulate($city->fresh())['elapsedSeconds']);
    }

    // happiness:三个拐点改了之后,线性段的斜率必须跟着走 ——
    // 这正是「裸 40.0 改成派生式」要守住的东西(旧代码在这里会算出 0.75,曲线断在 0.875)
    public function test_golden_happiness_factor_slope_is_derived_from_the_knees(): void
    {
        // 默认:50 → 0.5,70 → 1.0,60 落在正中间 = 0.75
        $this->assertEqualsWithDelta(0.75, SimulationService::happinessFactor(60.0), 1e-9);

        // 把上拐点搬到 90:除数 = (90 − 50) / (1 − 0.5) = 80 → 60 处 = 0.5 + 10/80 = 0.625
        $this->setValue(GameSetting::HAPPINESS_FACTOR_FULL_AT, 90.0);
        $this->assertEqualsWithDelta(0.625, SimulationService::happinessFactor(60.0), 1e-9);
        // 终点仍然恰好落在 1.0(斜率是派生的,不会断)
        $this->assertEqualsWithDelta(1.0, SimulationService::happinessFactor(90.0), 1e-9);

        // 再把起点抬到 0.75:除数 = 40 / 0.25 = 160 → 60 处 = 0.75 + 10/160 = 0.8125
        $this->setValue(GameSetting::HAPPINESS_FACTOR_AT_FLOOR, 0.75);
        $this->assertEqualsWithDelta(0.8125, SimulationService::happinessFactor(60.0), 1e-9);
        $this->assertEqualsWithDelta(1.0, SimulationService::happinessFactor(90.0), 1e-9);
    }

    // fiscal:tax_era_multiplier 改了之后,人均税额的指数曲线跟着走
    public function test_golden_tax_era_multiplier_changes_the_curve(): void
    {
        // 时代 III:0.02 × 1.5^2 = 0.045
        $this->assertEqualsWithDelta(0.045, SimulationService::taxPerCapitaPerMin(3), 1e-9);

        $this->setValue(GameSetting::TAX_ERA_MULTIPLIER, 2.0);

        // 0.02 × 2^2 = 0.08
        $this->assertEqualsWithDelta(0.08, SimulationService::taxPerCapitaPerMin(3), 1e-9);

        // 时代 I 不受倍率影响(指数为 0),只受人均税额本身影响
        $this->assertEqualsWithDelta(0.02, SimulationService::taxPerCapitaPerMin(1), 1e-9);
        $this->setValue(GameSetting::TAX_PER_CAPITA_ERA_1, 0.05);
        $this->assertEqualsWithDelta(0.05, SimulationService::taxPerCapitaPerMin(1), 1e-9);
    }

    // fiscal:maintenance_enabled 关掉 = 全服免维护(资金不再被扣、预警恒 none、不再半停工)
    public function test_golden_maintenance_switch_stops_all_upkeep(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // A01 行政所 L1:维护资金 7/min;人口 40、治理容量 80 → 负载 0.5 → 效率 1.00、税收 0.8/min
        $city = $this->makeCity('w11maint', ['A01' => 1], 40, 500.0, 10000.0);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $sim = SimulationService::simulate($city->fresh());
        $this->assertEqualsWithDelta(7.0, $sim['maintenanceMoneyPerMin'], 1e-9);
        $this->assertEqualsWithDelta(9938.0, (float) $city->fresh()->money, 1e-4); // 10000 + 8 − 70

        $this->setValue(GameSetting::MAINTENANCE_ENABLED, false);

        Carbon::setTestNow($base->copy()->addMinutes(20));
        $sim = SimulationService::simulate($city->fresh());
        // 维护速率归零 → 只剩税收进账,一分维护都不扣
        $this->assertEqualsWithDelta(0.0, $sim['maintenanceMoneyPerMin'], 1e-9);
        $this->assertEqualsWithDelta(9946.0, (float) $city->fresh()->money, 1e-4); // 9938 + 0.8×10
        // 维护为 0 的城市付不起是不可能的 → 预警恒 none、不半停工
        $this->assertSame('none', $sim['fiscalWarning']);
        $this->assertFalse($sim['maintenanceArrears']);
        $this->assertEqualsWithDelta(1.0, $sim['maintenanceRate'], 1e-9);
    }

    // governance:四档效率是设定值,改一档只动那一档
    public function test_golden_governance_efficiency_tier_is_configurable(): void
    {
        $this->assertEqualsWithDelta(0.90, SimulationService::governanceEfficiency(0.95), 1e-9);

        $this->setValue(GameSetting::GOVERNANCE_EFFICIENCY_TIGHT, 0.40);

        $this->assertEqualsWithDelta(0.40, SimulationService::governanceEfficiency(0.95), 1e-9);
        // 其余三档一个都没动
        $this->assertEqualsWithDelta(1.00, SimulationService::governanceEfficiency(0.5), 1e-9);
        $this->assertEqualsWithDelta(0.70, SimulationService::governanceEfficiency(1.1), 1e-9);
        $this->assertEqualsWithDelta(0.50, SimulationService::governanceEfficiency(2.0), 1e-9);
    }

    // logistics:拐点物流率改了之后,线性段与拥堵段仍在拐点处连续(不许出现跳变)
    public function test_golden_logistics_factor_at_over_is_configurable(): void
    {
        $this->assertEqualsWithDelta(0.70, SimulationService::logisticsFactor(1.25), 1e-9);

        $this->setValue(GameSetting::LOGISTICS_FACTOR_AT_OVER, 0.50);

        $this->assertEqualsWithDelta(0.50, SimulationService::logisticsFactor(1.25), 1e-9);
        // 拐点之后接 §3.3 的比例式,并被新的上限压住 → 仍然连续、仍然单调
        $this->assertEqualsWithDelta(0.50, SimulationService::logisticsFactor(1.5), 1e-9);
        // 线性段中点:1.125 处 = 1.0 − (1.0 − 0.5) × 0.125/0.25 = 0.75
        $this->assertEqualsWithDelta(0.75, SimulationService::logisticsFactor(1.125), 1e-9);
        // 负载不超过 tight 时永远不打折
        $this->assertEqualsWithDelta(1.0, SimulationService::logisticsFactor(0.9), 1e-9);
    }

    // logistics:总开关关掉 → 乘区恒 1.0(拥堵不再降产),但需求 / 负载 / 拥堵警报的读数照常给
    public function test_golden_logistics_gate_switch(): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($base);
        // F02 农田:产粮 14/min、无输入 → 运输需求 14;一栋运输建筑都没有 → 运输容量 0 → 负载 14 → 物流率触底 0.25。
        // 时代闸门要求 era_order >= 2,否则需求整条归零(时代 I 没有任何建筑能产运力)
        $city = $this->makeCity('w11log', ['F02' => 1], 40, 500.0, 100000.0);
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'II', 'era_order' => 2]);

        Carbon::setTestNow($base->copy()->addMinutes(10));
        $on = SimulationService::simulate($city->fresh());
        $this->assertEqualsWithDelta(14.0, $on['transportDemandPerMin'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $on['logisticsFactor'], 1e-9);
        $this->assertTrue($on['transportCongestion']);
        $this->assertEqualsWithDelta(14.0 * 0.25, $on['grossProductionPerMin']['food'], 1e-9);

        $this->setValue(GameSetting::LOGISTICS_GATE_ENABLED, false);

        Carbon::setTestNow($base->copy()->addMinutes(20));
        $off = SimulationService::simulate($city->fresh());
        // 乘区恢复满额 → 产量回到名义值
        $this->assertEqualsWithDelta(1.0, $off['logisticsFactor'], 1e-9);
        $this->assertEqualsWithDelta(14.0, $off['grossProductionPerMin']['food'], 1e-9);
        // 读数一个都没被抹掉:还看得见「本来堵成什么样」
        $this->assertEqualsWithDelta(14.0, $off['transportDemandPerMin'], 1e-9);
        $this->assertEqualsWithDelta(14.0, $off['transportLoad'], 1e-9);
        $this->assertTrue($off['transportCongestion']);
    }

    // tech:research_parallel_limit = 2 之后可以同时研究两项
    public function test_golden_research_parallel_limit_allows_two_projects(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $u = User::create(['username' => 'w11tech', 'name' => 'w11tech', 'email' => 'w11tech@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'knowledge'],
            ['amount' => 1000.0]
        );

        // 默认单线:第二项被拒
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])->assertOk();
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_IND'])
            ->assertStatus(422)->assertJson(['error' => 'RESEARCH_IN_PROGRESS']);

        $this->setValue(GameSetting::RESEARCH_PARALLEL_LIMIT, 2);

        // 上限 2:第二项放行,第三项仍被拒
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_IND'])->assertOk();
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_CIV'])
            ->assertStatus(422)->assertJson(['error' => 'RESEARCH_IN_PROGRESS']);

        $this->assertSame(2, DB::table('city_technologies')
            ->where('city_id', $city->id)->where('status', 'researching')->count());
    }

    // tech:知识花费倍率作用在扣费上(向上取整,零头算玩家的)
    public function test_golden_knowledge_cost_multiplier_scales_the_charge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $u = User::create(['username' => 'w11cost', 'name' => 'w11cost', 'email' => 'w11cost@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'knowledge'],
            ['amount' => 1000.0]
        );

        $cost = (int) DB::table('technology_definition')->where('tech_id', 'TECH_I_SUST')->value('knowledge_cost');

        $this->setValue(GameSetting::TECH_KNOWLEDGE_COST_MULTIPLIER, 2.5);

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])->assertOk();

        $left = (float) DB::table('city_resources')
            ->where('city_id', $city->id)->where('resource_id', 'knowledge')->value('amount');
        $this->assertEqualsWithDelta(1000.0 - ceil($cost * 2.5), $left, 1e-4);
    }

    // npc:岗位不匹配折扣改了之后,单 NPC 倍率按 1 + 主技能加成 × 折扣 跟着动
    public function test_golden_npc_job_mismatch_rate(): void
    {
        // 建筑要一个明确的对口技能,NPC 主技能故意填成别的 → 走不匹配分支
        $building = ['category' => 'agriculture', 'series_key' => 'F', 'outputs' => [], 'instance_id' => 1];
        $npc = ['primary_skill_id' => 'SKILL_MILITARY', 'skill_level' => 3, 'specs' => []];
        $curve = [3 => 0.40];

        // 默认 0.25:1 + 0.40 × 0.25 = 1.10
        $this->assertEqualsWithDelta(1.10, NpcBonus::forNpc($npc, $building, $curve), 1e-9);

        $this->setValue(GameSetting::NPC_JOB_MISMATCH_RATE, 0.5);

        // 1 + 0.40 × 0.50 = 1.20
        $this->assertEqualsWithDelta(1.20, NpcBonus::forNpc($npc, $building, $curve), 1e-9);
    }

    // building:建造成本全局倍率作用在扣料上(资金与材料同乘,向上取整)
    public function test_golden_build_cost_multiplier_scales_the_charge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $u = User::create(['username' => 'w11build', 'name' => 'w11build', 'email' => 'w11build@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'wood'], ['amount' => 1000]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'stone'], ['amount' => 1000]);
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'II', 'era_order' => 2, 'money' => 10000]);
        $this->unlockTechFor($city->id, 'F02');

        $cost = json_decode((string) DB::table('building_level_definition')
            ->where('building_id', 'F02')->where('level', 1)->value('cost_json'), true);
        $wood = (float) $cost['wood'];

        $this->setValue(GameSetting::BUILD_COST_MULTIPLIER, 3.0);

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2])->assertOk();

        $left = (float) DB::table('city_resources')
            ->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $this->assertEqualsWithDelta(1000.0 - $wood * 3.0, $left, 1e-4, '建造材料必须按 build_cost_multiplier 收');
    }

    // building:返还比例改了之后,拆除返还跟着走(返还与成本倍率同源,不给拆建套利留缝)
    public function test_golden_demolish_refund_rate(): void
    {
        $material = ConstructionService::cumulativeMaterialCost('F02', 1);
        $this->assertNotSame([], $material);

        $atHalf = ConstructionService::scale($material, ConstructionService::demolishRefundRate());

        $this->setValue(GameSetting::DEMOLISH_REFUND_RATE, 0.25);

        $atQuarter = ConstructionService::scale($material, ConstructionService::demolishRefundRate());

        foreach ($material as $res => $amount) {
            $this->assertEqualsWithDelta(floor($amount * 0.50), (float) ($atHalf[$res] ?? 0), 1e-9, $res);
            $this->assertEqualsWithDelta(floor($amount * 0.25), (float) ($atQuarter[$res] ?? 0), 1e-9, $res);
        }
    }

    // market:波动全局倍率调到 0 → 价格退化成「基础价 × 供需漂移」,完全不抖
    public function test_golden_market_volatility_multiplier(): void
    {
        $def = MarketDefinition::find('iron');
        $epoch = PriceEngine::currentEpoch();

        $withNoise = PriceEngine::priceFor($def, $epoch);

        $this->setValue(GameSetting::MARKET_VOLATILITY_MULTIPLIER, 0);
        MarketDefinition::flush();

        $noNoise = PriceEngine::priceFor($def, $epoch);

        // 空服:买卖量都是 0,底噪相等 → imbalance = 0 → target = 基础价
        $this->assertEqualsWithDelta(round($def['base_price'], 4), $noNoise, 1e-4);
        $this->assertNotEqualsWithDelta($withNoise, $noNoise, 1e-6, '默认倍率下必须存在扰动,否则这条断言验不出任何东西');
    }

    // defense:总开关关掉 → 威胁档恒安全、EVT_RAID 一律零损失
    public function test_golden_defense_gate_switch(): void
    {
        // 时代 I、国防值 0 → 覆盖率 0 → 危险档
        $city = (object) ['id' => 0, 'era_order' => 1];
        $before = DefenseService::evaluate($city, ['defenseScore' => 0.0]);
        $this->assertSame(DefenseService::LEVEL_HIGH, $before['threat_level']);
        $this->assertGreaterThan(0.0, DefenseService::raidLossPct($before));

        $this->setValue(GameSetting::DEFENSE_GATE_ENABLED, false);

        $after = DefenseService::evaluate($city, ['defenseScore' => 0.0]);
        $this->assertSame(DefenseService::LEVEL_LOW, $after['threat_level']);
        $this->assertSame(0, $after['threat_rank']);
        // 读数照常给出(止血不等于两眼一抹黑)
        $this->assertEqualsWithDelta($before['threat_demand'], $after['threat_demand'], 1e-9);
        $this->assertEqualsWithDelta($before['coverage'], $after['coverage'], 1e-9);
        $this->assertSame(0.0, DefenseService::raidLossPct($after));
    }
}
