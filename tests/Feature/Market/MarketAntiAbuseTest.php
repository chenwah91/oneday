<?php

namespace Tests\Feature\Market;

use App\Game\City\CityFactory;
use App\Game\Market\MarketDefinition;
use App\Game\Market\PriceEngine;
use App\Models\City;
use App\Models\User;
use App\Support\GameSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 反刷验证(v3.2 §13「市场不允许无限买卖形成永动机」+ §15「市场防套利」+ backlog §11.2)。
//
// ══ 同 epoch 往返必亏的闭式证明 ══════════════════════════════════════════════════
// 同一 epoch 内基准价 P 恒定(移动平均只取已结束的窗口)。设滑点率 s、手续费率 f、数量 q:
//     买入付出 = P·q·(1 + s)(1 + f)
//     卖出收到 = P·q·(1 − s)(1 − f)
//     净额     = P·q·[(1 − s)(1 − f) − (1 + s)(1 + f)]
//              = P·q·[(1 − s − f + sf) − (1 + s + f + sf)]
//              = **−2·P·q·(s + f)**
// 交叉项 sf 完全抵消,净额化简成一个只含 s 与 f 的负数 —— 只要手续费率 f > 0,
// 无论价格怎么波动、数量取多少,同窗往返都是**确定的亏损**,不存在参数组合能让它转正。
// 按 §8 的 f = 0.03,即使数量小到滑点可忽略,往返回收率也只有 0.97/1.03 ≈ 94.17%(净亏 5.83%)。
// 下面的用例既验行为(钱变少了),也验这条闭式(亏损额恰好等于 2·P·q·(s+f))。
class MarketAntiAbuseTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN_TS = 1800000000;

    protected function setUp(): void
    {
        parent::setUp();
        // 冻结时间 = 冻结 epoch:同一个用例里的买与卖必须落在同一个价格窗口,
        // 否则测的就不是「同窗往返」而是「跨窗投机」了
        Carbon::setTestNow(Carbon::createFromTimestamp(self::FROZEN_TS));
        $this->seed();

        // 本文件验的是**四机制的数学**(手续费 / 滑点 / 移动平均 / 流动性口径的成交量上限),
        // 不是城市侧的贸易额度。W5 起单窗上限还要再 min 一层「贸易吞吐口径」(backlog §5.4),
        // 而这些用例的城市一栋市场建筑都没有 → 基础额度 200 会先把大额往返挡下,
        // 测的就不再是原本那件事了。把基础额度调到远高于流动性口径,城市侧那一层恒不生效,
        // 用例继续验流动性口径的额度。城市侧额度本身另有专门用例(MarketTradeCapacityTest)
        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => GameSetting::MARKET_TRADE_CAPACITY_BASE_PER_MIN],
            [
                'value_json'  => json_encode(1000000),
                'description' => GameSetting::DEFINITIONS[GameSetting::MARKET_TRADE_CAPACITY_BASE_PER_MIN]['description'],
                'updated_at'  => now(),
            ]
        );
        GameSetting::flush();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    /** @return array{0: User, 1: City} */
    private function makeCity(string $name, float $money = 10000000.0, array $resources = []): array
    {
        $user = User::create(['username' => $name, 'name' => $name, 'email' => $name . '@example.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($user);

        DB::table('cities')->where('id', $city->id)->update(['money' => $money]);
        // 顺带把仓储垫高,避免大额买入撞上仓储上限(仓储另有专门用例)
        foreach ($resources as $code => $amount) {
            DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => $code], ['amount' => $amount]);
        }

        return [$user, $city->fresh()];
    }

    private function moneyOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('money');
    }

    private function heldOf(City $city, string $resource): float
    {
        return (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $resource)->value('amount');
    }

    // ---- 噪声套利:同 epoch 买了立刻卖,必亏 ----

    // §15 明文用例「市场防套利:同一资源连续大额买卖 → 手续费+滑点后无法无风险获利」。
    // 覆盖四个波动率档 × 三种数量,任何一组转正都说明四机制被削弱了
    public function test_same_epoch_round_trip_always_loses_money(): void
    {
        // [资源, 数量] —— 买 + 卖都计入同一个单窗额度,所以 2×数量 必须 ≤ 单窗额度,
        // 走的是完全合法的正常成交(额度本身另有专门用例)
        $cases = [
            ['food', 1],                   // 波动率 0.04,流动性 10000,单窗额度 1000
            ['food', 500],                 // 恰好用满单窗额度的极限往返
            ['iron', 1],                   // 波动率 0.07,流动性 1364,单窗额度 136.4
            ['iron', 60],
            ['coal', 120],                 // 波动率 0.08,流动性 2500,单窗额度 250
            ['electronic_components', 5],  // 波动率 0.10,流动性 211,单窗额度 21.1
            ['advanced_materials', 1],     // 波动率 0.12,流动性 41,单窗额度 4.1(最容易出问题的一档)
            ['advanced_materials', 2],
        ];

        foreach ($cases as $index => [$resource, $quantity]) {
            [$user, $city] = $this->makeCity('arb' . $index);
            $before = $this->moneyOf($city);
            // 建城初始资源里本来就有 food 等资源,起点不一定是 0 —— 记下来跟往返后对比
            $heldBefore = $this->heldOf($city, $resource);

            $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => $resource, 'quantity' => $quantity])->assertOk();
            $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => $resource, 'quantity' => $quantity])->assertOk();

            $after = $this->moneyOf($city);
            $this->assertLessThan($before, $after, sprintf('%s × %d 同窗往返居然没亏钱 —— 四机制被削弱了', $resource, $quantity));

            // 货必须原样回到起点(往返之后只剩亏损,不剩库存):
            // 否则「亏了钱」有可能只是因为货没卖完,不能算证明
            $this->assertEqualsWithDelta($heldBefore, $this->heldOf($city, $resource), 0.0001, $resource . ' 往返后库存必须回到原点');
        }
    }

    // 闭式验证:亏损额必须恰好等于 2·P·q·(s + f)。
    // 只断言「亏了」不够 —— 亏损额对不上公式,说明某一机制的系数被接错了
    public function test_round_trip_loss_matches_the_closed_form(): void
    {
        // 50:买 50 + 卖 50 = 100,落在 iron 单窗额度 136.4 之内
        $quantity = 50;
        [$user, $city] = $this->makeCity('closedform');

        $def = MarketDefinition::find('iron');
        $price = PriceEngine::priceFor($def, PriceEngine::currentEpoch());
        $slippageRate = (float) GameSetting::get(GameSetting::MARKET_SLIPPAGE_COEFFICIENT) * $quantity / MarketDefinition::effectiveLiquidity($def);
        $feeRate = MarketDefinition::effectiveFeeRate($def);

        $before = $this->moneyOf($city);
        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => $quantity])->assertOk();
        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => $quantity])->assertOk();
        $actualLoss = $before - $this->moneyOf($city);

        $expectedLoss = 2 * $price * $quantity * ($slippageRate + $feeRate);

        // 容差 0.02:两笔成交各自被 cities.money 的 DECIMAL(16,2) 精度截过一次
        $this->assertEqualsWithDelta($expectedLoss, $actualLoss, 0.02, '亏损额必须等于 2·P·q·(s+f)');
        $this->assertGreaterThan(0, $expectedLoss);
    }

    // 手续费单独就足以堵死「数量小到滑点可忽略」的纯噪声套利:
    // 往返回收率上限 = (1−f)/(1+f) = 0.97/1.03 ≈ 94.17%,永远够不到 100%
    public function test_fee_alone_blocks_zero_slippage_arbitrage(): void
    {
        // 把滑点关掉,只留手续费 —— 这是四机制里最弱的配置,它都必须亏
        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => GameSetting::MARKET_SLIPPAGE_COEFFICIENT],
            ['value_json' => json_encode(0.0), 'description' => GameSetting::DEFINITIONS[GameSetting::MARKET_SLIPPAGE_COEFFICIENT]['description'], 'updated_at' => now()]
        );
        GameSetting::flush();

        [$user, $city] = $this->makeCity('feeonly');
        $before = $this->moneyOf($city);

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 1])->assertOk();
        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 1])->assertOk();

        $recovery = ($this->moneyOf($city) - $before + $this->roundTripGross('iron', 1)) / $this->roundTripGross('iron', 1);
        $this->assertLessThan(1.0, $recovery, '零滑点时手续费必须独自把往返压在 100% 以下');
        $this->assertLessThan($before, $this->moneyOf($city));
    }

    private function roundTripGross(string $resource, int $quantity): float
    {
        return PriceEngine::priceFor(MarketDefinition::find($resource), PriceEngine::currentEpoch()) * $quantity;
    }

    // 永动机检测:同一窗内反复往返,资金必须单调递减,绝不出现回升
    public function test_repeated_round_trips_drain_money_monotonically(): void
    {
        [$user, $city] = $this->makeCity('perpetual');
        $previous = $this->moneyOf($city);

        // food 单窗额度 1000,10 次 × 20 的往返(共 400)完全在额度内
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'food', 'quantity' => 20])->assertOk();
            $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'food', 'quantity' => 20])->assertOk();

            $now = $this->moneyOf($city);
            $this->assertLessThan($previous, $now, '第 ' . ($i + 1) . ' 轮往返之后资金没有减少 = 出现了永动机');
            $previous = $now;
        }
    }

    // 就算玩家肯烧钱刷,成交量上限也会先把他挡住:
    // 反复往返最终一定撞上单窗额度,而不是无限循环下去
    public function test_round_trip_loop_eventually_hits_the_window_quota(): void
    {
        [$user, $city] = $this->makeCity('loopcap', 10000000.0, ['advanced_materials' => 0]);
        // advanced_materials 流动性 41 → 单窗额度 4.1,一次买 2 卖 2 就用掉 4
        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'advanced_materials', 'quantity' => 2])->assertOk();
        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'advanced_materials', 'quantity' => 2])->assertOk();

        // 第三笔:本窗已用 4,额度 4.1,再来 2 就超了
        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'advanced_materials', 'quantity' => 2])
            ->assertStatus(422)->assertJson(['error' => 'MARKET_LIMIT_REACHED']);
    }

    // ---- 并发双花 ----

    // 真并发在 PHPUnit 里跑不出来(RefreshDatabase 把整轮包在一个事务里,第二条连接看不见夹具数据),
    // 与 AuditChainTest 同一套办法:用 DB::listen 断言「锁确实加在了该加的地方、且加在所有写入之前」。
    // 少了这把锁,两个同时卖同一批库存的请求就会各自读到扣减前的余额,双花成立
    public function test_trade_locks_the_city_row_before_any_write(): void
    {
        [$user] = $this->makeCity('locking', 10000000.0, ['iron' => 100]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 10])->assertOk();

        $lockIndex = null;
        $firstWriteIndex = null;
        foreach ($queries as $i => $sql) {
            if ($lockIndex === null && str_contains($sql, 'from `cities`') && str_contains($sql, 'for update')) {
                $lockIndex = $i;
            }
            if ($firstWriteIndex === null
                && (str_starts_with($sql, 'update `cities`') || str_starts_with($sql, 'insert into `city_market_orders`'))) {
                $firstWriteIndex = $i;
            }
        }

        $this->assertNotNull($lockIndex, '成交前必须对 cities 行加 FOR UPDATE 锁,否则并发卖同一批库存会双花');
        $this->assertNotNull($firstWriteIndex);
        $this->assertLessThan($firstWriteIndex, $lockIndex, '锁必须在任何写入之前拿到,锁在写之后等于没锁');
    }

    // 双花的逻辑侧:库存 10,连续两笔各卖 6。
    // 第二笔必须被拒 —— 这证明余额是在锁内重新读出来的,而不是沿用请求进来时的旧快照
    public function test_second_oversell_is_rejected_against_the_post_trade_balance(): void
    {
        [$user, $city] = $this->makeCity('doublespend', 100.0, ['iron' => 10]);

        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 6])->assertOk();
        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 6])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);

        $this->assertEqualsWithDelta(4.0, (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'iron')->value('amount'), 0.0001);
        $this->assertSame(1, DB::table('city_market_orders')->count(), '只能成交一笔');
    }

    // 乐观锁侧的双花:两次请求带同一个 expected_revision(典型的「双击提交」),只有第一笔算数
    public function test_double_submit_with_same_expected_revision_settles_once(): void
    {
        [$user, $city] = $this->makeCity('doubleclick', 10000000.0, ['iron' => 100]);
        $revision = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $body = ['resource_code' => 'iron', 'quantity' => 10, 'expected_revision' => $revision];
        $this->actingAs($user)->postJson('/api/market/sell', $body)->assertOk();
        $this->actingAs($user)->postJson('/api/market/sell', $body)
            ->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);

        $this->assertSame(1, DB::table('city_market_orders')->count());
        $this->assertEqualsWithDelta(90.0, (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'iron')->value('amount'), 0.0001);
    }

    // 被拒的交易绝不能留下任何痕迹(事务回滚要干净:流水 / 额度 / revision 全部不动)
    public function test_rejected_trade_leaves_no_trace(): void
    {
        [$user, $city] = $this->makeCity('rollback', 1.0);
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);

        $this->assertSame(0, DB::table('city_market_orders')->count());
        $this->assertSame(0, DB::table('city_market_quota')->count(), '被拒的交易不能占用成交额度');
        $this->assertSame($revisionBefore, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
        $this->assertEqualsWithDelta(1.0, $this->moneyOf($city), 0.0001);
    }

    // 资金与库存的绝对下界:任何成交之后都不能出现负数(§52 Invariant)
    public function test_no_trade_can_produce_a_negative_balance(): void
    {
        [$user, $city] = $this->makeCity('nonegative', 10000000.0, ['iron' => 100]);

        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 100])->assertOk();
        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 1])->assertStatus(422);

        $this->assertGreaterThanOrEqual(0.0, $this->moneyOf($city));
        $this->assertSame(0, DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count());
    }
}
