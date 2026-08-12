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

// 交易 API:成功链 + 服务器定价 + 四机制逐个生效(每条都配一条「关掉它就不生效」的假失败)
// + 余额 / 库存 / 仓储 / 幂等 / Revision / 审计。
class MarketTradeTest extends TestCase
{
    use RefreshDatabase;

    // 固定在 60 秒窗口边界上,保证整个用例内 epoch 不变、价格可被测试侧原样重算
    private const FROZEN_TS = 1800000000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::FROZEN_TS));
        $this->seed();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    /** @return array{0: User, 1: City} */
    private function makeCity(string $name, float $money = 100000.0, array $resources = []): array
    {
        $user = User::create(['username' => $name, 'name' => $name, 'email' => $name . '@example.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($user);

        DB::table('cities')->where('id', $city->id)->update(['money' => $money]);
        foreach ($resources as $code => $amount) {
            DB::table('city_resources')->updateOrInsert(
                ['city_id' => $city->id, 'resource_id' => $code],
                ['amount' => $amount]
            );
        }

        return [$user, $city->fresh()];
    }

    private function moneyOf(City $city): float
    {
        return (float) DB::table('cities')->where('id', $city->id)->value('money');
    }

    private function amountOf(City $city, string $code): float
    {
        return (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $code)->value('amount');
    }

    // 按当前定义 + 当前设定,原样重算一笔交易应有的成交结果(测试侧的独立实现)。
    // 刻意不复用 TradeService 的代码:两边各算一次,公式抄错才有可能被发现
    private function expected(string $resourceId, string $side, int $quantity): array
    {
        $def = MarketDefinition::find($resourceId);
        $mid = PriceEngine::priceFor($def, PriceEngine::currentEpoch());

        $slippageRate = (float) GameSetting::get(GameSetting::MARKET_SLIPPAGE_COEFFICIENT) * $quantity / MarketDefinition::effectiveLiquidity($def);
        $feeRate = MarketDefinition::effectiveFeeRate($def);

        $effective = $side === 'buy' ? $mid * (1 + $slippageRate) : $mid * (1 - $slippageRate);
        $gross = $effective * $quantity;
        $fee = $gross * $feeRate;

        return [
            'mid'         => $mid,
            'fee'         => $fee,
            'slippage'    => abs($mid * $slippageRate * $quantity),
            'money_delta' => round($side === 'buy' ? -($gross + $fee) : max(0.0, $gross - $fee), 2),
        ];
    }

    private function setSetting(string $key, mixed $value): void
    {
        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['value_json' => json_encode($value), 'description' => GameSetting::DEFINITIONS[$key]['description'], 'updated_at' => now()]
        );
        GameSetting::flush();
    }

    // ---- 成功链 ----

    public function test_buy_deducts_money_adds_resource_and_bumps_revision(): void
    {
        [$user, $city] = $this->makeCity('buyer');
        $expected = $this->expected('iron', 'buy', 10);
        $revisionBefore = (int) $city->revision;

        $res = $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10]);

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertEqualsWithDelta(100000.0 + $expected['money_delta'], $this->moneyOf($city), 0.01, '资金变动必须与服务器算价完全一致');
        $this->assertEqualsWithDelta(10.0, $this->amountOf($city, 'iron'), 0.0001);
        $this->assertSame($revisionBefore + 1, (int) DB::table('cities')->where('id', $city->id)->value('revision'));

        // 成交明细回给前端(前端显示的成交价必须是服务端算的,不是自己乘出来的)
        $this->assertSame('buy', $res->json('data.trade.side'));
        $this->assertEqualsWithDelta($expected['mid'], (float) $res->json('data.trade.mid_price'), 0.0001);
        $this->assertEqualsWithDelta($expected['money_delta'], (float) $res->json('data.trade.money_delta'), 0.01);
    }

    public function test_sell_adds_money_and_removes_resource(): void
    {
        [$user, $city] = $this->makeCity('seller', 100.0, ['iron' => 50]);
        $expected = $this->expected('iron', 'sell', 10);

        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 10])->assertOk();

        $this->assertEqualsWithDelta(100.0 + $expected['money_delta'], $this->moneyOf($city), 0.01);
        $this->assertEqualsWithDelta(40.0, $this->amountOf($city, 'iron'), 0.0001);
        $this->assertGreaterThan(0, $expected['money_delta'], '卖出必须真的拿到钱');
    }

    // B1 裁决③ / M.2 残留①:电子元件全服 0 产出,市场是时代 X 的唯一来源。
    // city_resources 里连行都没有,买入必须能把这一行建出来
    public function test_can_buy_a_resource_the_city_has_never_held(): void
    {
        [$user, $city] = $this->makeCity('erax');

        $this->assertDatabaseMissing('city_resources', ['city_id' => $city->id, 'resource_id' => 'electronic_components']);

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'electronic_components', 'quantity' => 5])->assertOk();

        $this->assertEqualsWithDelta(5.0, $this->amountOf($city, 'electronic_components'), 0.0001);
    }

    // ---- 服务器权威定价(v3.2 §15「市场服务器定价」)----

    // 客户端塞任何价格字段都必须被无视:validate 的 allowlist 根本不收它
    public function test_client_supplied_price_is_ignored(): void
    {
        [$userA, $cityA] = $this->makeCity('honest');
        [$userB, $cityB] = $this->makeCity('cheater');

        $this->actingAs($userA)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10])->assertOk();
        $this->actingAs($userB)->postJson('/api/market/buy', [
            'resource_code' => 'iron', 'quantity' => 10,
            // 伪造字段全上:成交价 / 单价 / 手续费 / 滑点 / 基准价
            'unit_price' => 0.01, 'price' => 0.01, 'mid_price' => 0.01, 'fee' => 0, 'slippage' => 0, 'money_delta' => -1,
        ])->assertOk();

        $this->assertSame($this->moneyOf($cityA), $this->moneyOf($cityB), '伪造价格的玩家必须付出与老实玩家完全一样的钱');
    }

    // 成交价必须等于「本 epoch 的服务器基准价」,而不是别的窗口的价
    public function test_recorded_mid_price_equals_server_epoch_price(): void
    {
        [$user, $city] = $this->makeCity('pricecheck');
        $epoch = PriceEngine::currentEpoch();

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10])->assertOk();

        $order = DB::table('city_market_orders')->where('city_id', $city->id)->first();
        $this->assertSame($epoch, (int) $order->window_index);
        $this->assertEqualsWithDelta(PriceEngine::priceFor(MarketDefinition::find('iron'), $epoch), (float) $order->mid_price, 0.0001);
    }

    // ---- §13 四机制:逐个「生效」+ 逐个「关掉就不生效」(假失败验证)----

    // ① 手续费:把全局倍率压到**登记下限** 0.01 之后,同一笔买入必须便宜出恰好那一份手续费之差。
    //
    // 为什么不是压到 0:§13 的四道反套利机制不许被后台关停,W11-A 起 market_fee_rate_multiplier
    // 的登记下限就是 0.01(填 0 直接 VALIDATION_ERROR,连手改库都会在读取时被打回默认值)。
    // 「假失败验证」的力度一点没丢 —— 费率小两个数量级时差额照样量得出来,而且永远大于 0
    public function test_fee_is_charged_and_shrinks_to_the_registered_floor(): void
    {
        [$userA, $cityA] = $this->makeCity('feeon');
        $withFee = $this->expected('iron', 'buy', 10);
        $this->actingAs($userA)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10])->assertOk();

        $this->setSetting(GameSetting::MARKET_FEE_RATE_MULTIPLIER, 0.01);

        [$userB, $cityB] = $this->makeCity('feefloor');
        $atFloor = $this->expected('iron', 'buy', 10);
        $this->actingAs($userB)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10])->assertOk();

        $paidWithFee = 100000.0 - $this->moneyOf($cityA);
        $paidAtFloor = 100000.0 - $this->moneyOf($cityB);

        $this->assertGreaterThan($paidAtFloor, $paidWithFee, '手续费没生效');
        $this->assertEqualsWithDelta($withFee['fee'] - $atFloor['fee'], $paidWithFee - $paidAtFloor, 0.02, '差额必须恰好等于两档手续费之差');
        // §13:手续费永远关不掉 —— 压到下限也仍然收得到
        $this->assertGreaterThan(0.0, $atFloor['fee']);
        $this->assertLessThan($withFee['fee'], $atFloor['fee']);
    }

    // 手续费倍率 0 属于「关停 §13 机制」,登记下限拦住它(写入路径 422)
    public function test_fee_multiplier_zero_is_rejected(): void
    {
        $this->expectException(\App\Support\GameRuleException::class);
        GameSetting::set(GameSetting::MARKET_FEE_RATE_MULTIPLIER, 0, null, '试图免手续费');
    }

    // ③ 滑点:把系数压到**登记下限** 0.01 之后,同一笔买入必须便宜出那一份滑点差(含其上的手续费)。
    //
    // 同 ①:§13 不许关停滑点,W11-A 起 market_slippage_coefficient 的登记下限就是 0.01,
    // 填 0 一律 422。压到下限时滑点小两个数量级,但仍然 > 0 —— 往返永远亏,这才是这条机制的意义
    public function test_slippage_is_charged_and_shrinks_to_the_registered_floor(): void
    {
        [$userA, $cityA] = $this->makeCity('slipon');
        $this->actingAs($userA)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 100])->assertOk();
        $orderA = DB::table('city_market_orders')->where('city_id', $cityA->id)->first();

        $this->setSetting(GameSetting::MARKET_SLIPPAGE_COEFFICIENT, 0.01);

        [$userB, $cityB] = $this->makeCity('slipfloor');
        $this->actingAs($userB)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 100])->assertOk();
        $orderB = DB::table('city_market_orders')->where('city_id', $cityB->id)->first();

        $this->assertGreaterThan(0, (float) $orderA->slippage_rate, '滑点没生效');
        // §13:滑点关不掉 —— 压到下限仍然 > 0,只是比默认档小得多
        $this->assertGreaterThan(0.0, (float) $orderB->slippage_rate, '系数压到下限后滑点仍必须存在');
        $this->assertLessThan((float) $orderA->slippage_rate, (float) $orderB->slippage_rate);
        $this->assertLessThan(
            100000.0 - $this->moneyOf($cityA),
            100000.0 - $this->moneyOf($cityB),
            '滑点压到下限后同样一笔买入必须更便宜(这就是滑点在收钱的证据)'
        );

        // 滑点率 = 0.5 × 数量 / 有效流动性(9.C4)
        $expectedRate = 0.5 * 100 / MarketDefinition::effectiveLiquidity(MarketDefinition::find('iron'));
        $this->assertEqualsWithDelta($expectedRate, (float) $orderA->slippage_rate, 0.0001);
    }

    // ③' 滑点方向:买入把价格推高、卖出把价格压低,两个方向都对玩家不利
    public function test_slippage_pushes_price_against_the_trader(): void
    {
        [$userA, $cityA] = $this->makeCity('dirbuy');
        [$userB, $cityB] = $this->makeCity('dirsell', 100.0, ['iron' => 500]);

        $this->actingAs($userA)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 100])->assertOk();
        $this->actingAs($userB)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 100])->assertOk();

        $buy = DB::table('city_market_orders')->where('city_id', $cityA->id)->first();
        $sell = DB::table('city_market_orders')->where('city_id', $cityB->id)->first();

        // 断到「基准价 × 手续费」之外去:只跟 mid_price 比的话,光有手续费也能过 —— 那就验不出滑点的方向了
        $feeRate = MarketDefinition::effectiveFeeRate(MarketDefinition::find('iron'));

        $this->assertGreaterThan(
            (float) $buy->mid_price * (1 + $feeRate),
            (float) $buy->unit_price,
            '买入实付单价必须高于「基准价 + 手续费」—— 高出来的那部分才是滑点'
        );
        $this->assertLessThan(
            (float) $sell->mid_price * (1 - $feeRate),
            (float) $sell->unit_price,
            '卖出实收单价必须低于「基准价 − 手续费」—— 低下去的那部分才是滑点'
        );
    }

    // ② 成交量上限:单笔超过单窗额度直接拒
    public function test_single_order_over_window_quota_is_rejected(): void
    {
        [$user, $city] = $this->makeCity('bigorder');
        $quota = MarketDefinition::windowQuota(MarketDefinition::find('iron'));

        $res = $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => (int) ceil($quota) + 1]);

        $res->assertStatus(422)->assertJson(['error' => 'MARKET_LIMIT_REACHED']);
        $this->assertEqualsWithDelta(100000.0, $this->moneyOf($city), 0.0001, '被拒的订单一分钱都不能扣');
        $this->assertSame(0, DB::table('city_market_orders')->count());
    }

    // ② 成交量上限:同一窗内累计超额同样被拒(买卖合并计入一个额度)
    public function test_cumulative_window_quota_counts_buys_and_sells_together(): void
    {
        [$user, $city] = $this->makeCity('cumul', 100000.0, ['iron' => 500]);
        $quota = MarketDefinition::windowQuota(MarketDefinition::find('iron'));
        $half = (int) floor($quota / 2);

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => $half])->assertOk();
        // 卖出同样计入额度:再来一笔 half 就把这一窗用满,第三笔必须被拒
        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => $half])->assertOk();

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => $half])
            ->assertStatus(422)->assertJson(['error' => 'MARKET_LIMIT_REACHED']);

        $used = (float) DB::table('city_market_quota')
            ->where('city_id', $city->id)->where('resource_id', 'iron')->value('traded_qty');
        $this->assertEqualsWithDelta($half * 2, $used, 0.0001, '额度累计必须买卖同计');
    }

    // ② 成交量上限:每小时总额(9.C7 的 20 × 单窗上限)
    public function test_hourly_quota_caps_total_volume_across_windows(): void
    {
        [$user, $city] = $this->makeCity('hourly', 100000000.0, ['iron' => 100000]);
        $def = MarketDefinition::find('iron');
        $windowQuota = MarketDefinition::windowQuota($def);
        $hourlyQuota = MarketDefinition::hourlyQuota($def);
        $epoch = PriceEngine::currentEpoch();

        // 直接把前 59 个窗口的额度写满(等价于「这一小时已经交易了 59 窗」),
        // 逐窗真的下单要跑 59 次请求,没有额外的验证价值
        $rows = [];
        for ($i = 1; $i <= 59; $i++) {
            $rows[] = ['city_id' => $city->id, 'resource_id' => 'iron', 'window_index' => $epoch - $i, 'traded_qty' => $windowQuota];
        }
        DB::table('city_market_quota')->insert($rows);

        // 59 窗 × 单窗额度 已经远超「20 × 单窗额度」的小时上限 → 本窗第一笔就该被拒
        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 1])
            ->assertStatus(422)->assertJson(['error' => 'MARKET_LIMIT_REACHED']);

        $this->assertEqualsWithDelta($windowQuota * 20, $hourlyQuota, 0.0001, '9.C7:每小时上限 = 20 × 单窗上限');
    }

    // ---- 余额 / 库存 / 仓储 ----

    public function test_buy_rejects_when_money_is_insufficient(): void
    {
        [$user, $city] = $this->makeCity('broke', 5.0);

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);

        $this->assertEqualsWithDelta(5.0, $this->moneyOf($city), 0.0001);
        $this->assertSame(0, DB::table('city_market_orders')->count());
    }

    public function test_sell_rejects_when_inventory_is_insufficient(): void
    {
        [$user, $city] = $this->makeCity('empty', 100.0, ['iron' => 3]);

        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 10])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);

        $this->assertEqualsWithDelta(3.0, $this->amountOf($city, 'iron'), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->moneyOf($city), 0.0001);
    }

    // 买入撑爆仓储:直接拒绝,不静默截断(付了钱却没拿到货只会变成客服工单)
    public function test_buy_rejects_when_storage_would_overflow(): void
    {
        [$user, $city] = $this->makeCity('fullstore', 100000.0, ['iron' => 995]);

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10])
            ->assertStatus(422)->assertJson(['error' => 'STORAGE_FULL']);

        $this->assertEqualsWithDelta(995.0, $this->amountOf($city, 'iron'), 0.0001);
        $this->assertEqualsWithDelta(100000.0, $this->moneyOf($city), 0.0001);
    }

    // ---- 可交易性 ----

    public function test_non_tradeable_and_capacity_contract_resources_are_rejected(): void
    {
        [$user, $city] = $this->makeCity('nottradeable', 100000.0, ['knowledge' => 100]);

        foreach (['knowledge', 'money', 'electricity'] as $code) {
            $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => $code, 'quantity' => 1])
                ->assertStatus(422)->assertJson(['error' => 'RESOURCE_NOT_TRADEABLE']);
            $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => $code, 'quantity' => 1])
                ->assertStatus(422)->assertJson(['error' => 'RESOURCE_NOT_TRADEABLE']);
        }

        $this->assertSame(0, DB::table('city_market_orders')->count());
    }

    // 未在 market_definition 登记的资源(含压根不存在的 code)一律按「不在市场上」处理
    public function test_unlisted_resources_are_rejected(): void
    {
        [$user] = $this->makeCity('unlisted');

        // iron_tools / processed_food 是真实存在的库存资源,但 §8 没给它们市场价 → 不在市场上。
        // (cement 曾经也在这份名单里,V3.4.0 起它按草案 §7 上市成 RS027,已移出)
        foreach (['iron_tools', 'processed_food', 'unobtanium', ''] as $code) {
            $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => $code, 'quantity' => 1])
                ->assertStatus(422);
        }
    }

    // ---- 停市开关 ----

    public function test_market_closed_blocks_trades_but_not_prices(): void
    {
        [$user] = $this->makeCity('closed');
        $this->setSetting(GameSetting::MARKET_ENABLED, false);

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 1])
            ->assertStatus(422)->assertJson(['error' => 'MARKET_CLOSED']);
        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 1])
            ->assertStatus(422)->assertJson(['error' => 'MARKET_CLOSED']);

        // 停市期间行情照看(玩家要知道什么时候值得回来),只有买卖被挡
        $this->actingAs($user)->getJson('/api/market/prices')->assertOk()->assertJson(['data' => ['market_enabled' => false]]);
    }

    // 停市时连幂等键都不该落:重开市后旧 key 会带着旧参数重放
    public function test_market_closed_does_not_burn_the_idempotency_key(): void
    {
        [$user] = $this->makeCity('closedkey');
        $this->setSetting(GameSetting::MARKET_ENABLED, false);

        $body = ['resource_code' => 'iron', 'quantity' => 1, 'idempotency_key' => 'closed-key-1'];
        $this->actingAs($user)->postJson('/api/market/buy', $body)->assertStatus(422);

        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    // ---- 幂等 / Revision ----

    public function test_repeated_idempotency_key_settles_only_once(): void
    {
        [$user, $city] = $this->makeCity('idem');
        $revisionBefore = (int) $city->revision;

        $body = ['resource_code' => 'iron', 'quantity' => 10, 'idempotency_key' => 'market-fixed-key-1'];
        $this->actingAs($user)->postJson('/api/market/buy', $body)->assertOk();
        $moneyAfterFirst = $this->moneyOf($city);
        // 重放:直接回旧结果,不再扣一次钱、不再发一次货
        $this->actingAs($user)->postJson('/api/market/buy', $body)->assertOk();

        $this->assertEqualsWithDelta($moneyAfterFirst, $this->moneyOf($city), 0.0001, '资金只能扣一次');
        $this->assertEqualsWithDelta(10.0, $this->amountOf($city, 'iron'), 0.0001, '货只能发一次');
        $this->assertSame($revisionBefore + 1, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
        $this->assertSame(1, DB::table('city_market_orders')->count(), '流水只能有一条');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'MARKET.BUY')->count());
    }

    // 同一个 key 换参数 / 换方向 → 409(典型的客户端重试串味)
    public function test_reusing_a_key_for_different_parameters_is_rejected(): void
    {
        [$user, $city] = $this->makeCity('keyreuse', 100000.0, ['iron' => 100]);

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10, 'idempotency_key' => 'k1'])->assertOk();

        // 换数量
        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 11, 'idempotency_key' => 'k1'])
            ->assertStatus(409)->assertJson(['error' => 'IDEMPOTENCY_KEY_REUSED']);
        // 换资源
        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'coal', 'quantity' => 10, 'idempotency_key' => 'k1'])
            ->assertStatus(409);
        // 换方向(buy → sell):必须被挡,否则「重试」会变成反向成交
        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 10, 'idempotency_key' => 'k1'])
            ->assertStatus(409);

        $this->assertSame(1, DB::table('city_market_orders')->count());
    }

    public function test_stale_revision_is_rejected(): void
    {
        [$user, $city] = $this->makeCity('rev');
        $current = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->actingAs($user)->postJson('/api/market/buy', [
            'resource_code' => 'iron', 'quantity' => 10, 'expected_revision' => $current + 99,
        ])->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);

        $this->assertEqualsWithDelta(100000.0, $this->moneyOf($city), 0.0001);
        $this->assertSame(0, DB::table('city_market_orders')->count());
    }

    // ---- 输入边界(§69「防止负数 / NaN / 超大数字」)----

    public function test_invalid_quantities_are_rejected_by_validation(): void
    {
        [$user, $city] = $this->makeCity('badinput');

        $cases = [0, -1, -1000000, 1.5, '10.5', 'abc', null, true, [], 1.0e9, PHP_INT_MAX];
        foreach ($cases as $quantity) {
            $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => $quantity])
                ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);
        }

        // 超过后台设定的单笔硬上限
        $this->actingAs($user)->postJson('/api/market/buy', [
            'resource_code' => 'iron', 'quantity' => (int) GameSetting::get(GameSetting::MARKET_MAX_ORDER_QUANTITY) + 1,
        ])->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);

        $this->assertEqualsWithDelta(100000.0, $this->moneyOf($city), 0.0001);
        $this->assertSame(0, DB::table('city_market_orders')->count());
    }

    public function test_missing_fields_are_rejected(): void
    {
        [$user] = $this->makeCity('missing');

        $this->actingAs($user)->postJson('/api/market/buy', ['quantity' => 1])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron'])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/market/buy', [])->assertStatus(422);
    }

    // ---- 认证与越权 ----

    // 未登录一律 401(市场三个端点都在 auth:web 组内)
    public function test_guests_cannot_reach_the_market(): void
    {
        $this->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 1])->assertStatus(401);
        $this->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 1])->assertStatus(401);
        $this->getJson('/api/market/prices')->assertStatus(401);
    }

    // 交易端点不接收 city_id —— 城市由登录身份反查,结构上不存在「操作别人的城市」这条路。
    // 这条用例守的是这个结构:A 的交易只能动 A 的城,B 一分钱一份货都不能少
    public function test_a_players_trade_never_touches_another_players_city(): void
    {
        [$userA, $cityA] = $this->makeCity('playera', 100000.0, ['iron' => 100]);
        [, $cityB] = $this->makeCity('playerb', 100000.0, ['iron' => 100]);

        // 连 city_id 都塞进去试试:它不在 validate 的 allowlist 里,会被直接丢掉
        $this->actingAs($userA)->postJson('/api/market/sell', [
            'resource_code' => 'iron', 'quantity' => 10, 'city_id' => $cityB->id,
        ])->assertOk();

        $this->assertEqualsWithDelta(100.0, $this->amountOf($cityB, 'iron'), 0.0001, 'B 的库存不能被 A 动到');
        $this->assertEqualsWithDelta(100000.0, $this->moneyOf($cityB), 0.0001, 'B 的资金不能被 A 动到');
        $this->assertEqualsWithDelta(90.0, $this->amountOf($cityA, 'iron'), 0.0001);
    }

    // ---- 审计与流水(§56 / §69)----

    public function test_audit_and_order_row_capture_the_full_trade(): void
    {
        [$user, $city] = $this->makeCity('audited');

        $this->actingAs($user)->postJson('/api/market/buy', [
            'resource_code' => 'iron', 'quantity' => 10, 'idempotency_key' => 'audit-key-1',
        ])->assertOk();

        $audit = DB::table('audit_logs')->where('action', 'MARKET.BUY')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $user->id, (int) $audit->user_id);
        $this->assertSame((int) $city->id, (int) $audit->city_id);
        $this->assertSame('market_order', $audit->entity_type);
        $this->assertSame('audit-key-1', $audit->idempotency_key);
        $this->assertNotNull($audit->request_id);
        $this->assertSame(0, (int) $audit->city_revision_before);
        $this->assertSame(1, (int) $audit->city_revision_after);

        // §56:经济类日志必须带资源变化
        $delta = json_decode($audit->delta_json, true);
        $this->assertSame(10, $delta['iron']);
        $this->assertLessThan(0, $delta['money']);

        // §56 市场专用字段(价格投诉要能拆回「基准价 × 滑点 × 手续费」三段)
        $meta = json_decode($audit->metadata_json, true);
        foreach (['resource', 'quantity', 'unit_price', 'mid_price', 'fee', 'slippage', 'money_delta', 'window_index'] as $key) {
            $this->assertArrayHasKey($key, $meta, '审计缺字段:' . $key);
        }
        $this->assertSame('iron', $meta['resource']);

        // §69:成交流水可追踪 buyer / resource / quantity / price / fee / timestamp / request_id
        $order = DB::table('city_market_orders')->latest('id')->first();
        $this->assertSame((int) $user->id, (int) $order->user_id);
        $this->assertSame('iron', $order->resource_id);
        $this->assertSame('buy', $order->side);
        $this->assertSame($audit->request_id, $order->request_id, '流水与审计必须能靠 request_id 对上');
        $this->assertEqualsWithDelta((float) $delta['money'], (float) $order->money_delta, 0.0001);
    }

    // 审计里记的资金变动必须与数据库实际变动完全一致(cities.money 只有 2 位小数,
    // 不先取整就会差出一个数据库四舍五入的尾巴,对账时永远找不到来源)
    public function test_audit_delta_matches_the_actual_money_movement(): void
    {
        [$user, $city] = $this->makeCity('exact');
        $before = $this->moneyOf($city);

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'copper', 'quantity' => 7])->assertOk();

        $delta = json_decode(DB::table('audit_logs')->where('action', 'MARKET.BUY')->latest('id')->first()->delta_json, true);
        $this->assertSame(round($before + $delta['money'], 2), round($this->moneyOf($city), 2));
    }

    // ---- 价目端点 ----

    public function test_prices_endpoint_returns_current_epoch_and_next_window(): void
    {
        [$user] = $this->makeCity('prices');

        $res = $this->actingAs($user)->getJson('/api/market/prices');

        $res->assertOk();
        $this->assertSame(PriceEngine::currentEpoch(), $res->json('data.window_index'));
        $this->assertSame(60, $res->json('data.window_seconds'));
        $this->assertCount(28, $res->json('data.prices'));

        // 下一窗时刻 = 本窗起点 + 窗口秒数
        $this->assertSame(
            PriceEngine::epochStartsAt(PriceEngine::currentEpoch())->addSeconds(60)->toIso8601String(),
            $res->json('data.next_window_at')
        );

        $iron = collect($res->json('data.prices'))->firstWhere('resource_code', 'iron');
        $this->assertTrue($iron['tradeable']);
        $this->assertGreaterThan($iron['price'], $iron['buy_price'], '买价 = 价格 ×(1+费率)');
        $this->assertLessThan($iron['price'], $iron['sell_price'], '卖价 = 价格 ×(1−费率)');
        $this->assertEqualsWithDelta(136.4, $iron['window_quota'], 0.01, 'iron 流动性 1364 的 10%');

        $knowledge = collect($res->json('data.prices'))->firstWhere('resource_code', 'knowledge');
        $this->assertFalse($knowledge['tradeable']);
        $this->assertNull($knowledge['buy_price']);
    }
}
