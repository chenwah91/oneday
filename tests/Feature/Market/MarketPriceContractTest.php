<?php

namespace Tests\Feature\Market;

use App\Game\City\CityFactory;
use App\Game\Market\MarketDefinition;
use App\Game\Market\PriceEngine;
use App\Game\Market\TradeService;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Models\City;
use App\Models\User;
use App\Support\GameSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M3-W7 契约缺口 ④⑤⑥:GET /api/market/prices 的三组新字段。
//
//   ④ buy_price_pct(逐资源)  本城买入侧的事件价格冲击。**只读显示值**,不乘进全服价格;
//   ⑤ effective_liquidity(逐资源)+ slippage_coefficient / max_slippage_rate(顶层)
//                              前端算买卖预估所需的三个数,缺一个预估就与服务器对不上;
//   ⑥ market_max_order_quantity(顶层)  单笔数量硬上限(§69),前端据此提前拦住超大单。
//
// 反面同样重要(假失败层):
//   · 全服口径的 price / base_price **绝不能**被本城的事件冲击污染 ——
//     污染了就等于「一座城市的事件改了全服行情」,同一 epoch 内价格恒定这条反刷前提当场失效;
//   · 卖出侧没有对应字段(口径上恒 0),否则「事件期间抛货、事件后买回」是台印钞机;
//   · MARKET_PRICE_SECRET 及由它派生的任何东西一律不下发。
//
// 时间冻结在固定时刻 = 冻结 epoch:价格是 (资源, epoch) 的纯函数,不冻结断言就不可复现。
class MarketPriceContractTest extends TestCase
{
    use RefreshDatabase;

    // 与 MarketAntiAbuseTest 同一个基准时刻(epoch = 30000000,窗长 60 秒)
    private const FROZEN_TS = 1800000000;

    // 该 epoch 下 iron 的服务器基准价(base 22 × (1 + HMAC 噪声),已夹取并落到 4 位小数)。
    // 密钥是 phpunit.xml 里固定的 MARKET_PRICE_SECRET,所以这个数在 CI / 本地完全一致
    private const IRON_PRICE = 20.8804;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::FROZEN_TS));
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $un): User
    {
        return User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
    }

    // 直接写一行生效中的 city_active_modifiers(事件写下的那种)
    private function addPriceModifier(City $city, float $value, ?string $resourceId = null): void
    {
        DB::table('city_active_modifiers')->insert([
            'city_id'   => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target'    => ModifierTarget::MARKET_PRICE_PCT,
            'scope'     => $resourceId === null ? ModifierSpec::SCOPE_CITY : ModifierSpec::SCOPE_RESOURCE,
            'scope_key' => $resourceId,
            'op'        => ModifierSpec::OP_PCT, 'value' => $value,
            'starts_at' => now()->copy()->subMinute(),
            'ends_at'   => now()->copy()->addMinutes(30),
            'created_at' => now(),
        ]);
    }

    private function pricesFor(User $user): array
    {
        $res = $this->actingAs($user)->getJson('/api/market/prices');
        $res->assertOk();

        return $res->json('data');
    }

    private function rowOf(array $data, string $resourceId): array
    {
        $row = collect($data['prices'])->firstWhere('resource_code', $resourceId);
        $this->assertNotNull($row, "{$resourceId} 必须在价目表里");

        return $row;
    }

    // ---------- ④ buy_price_pct ----------

    // 没有任何事件在生效时:每一行都必须**存在**这个字段且为 0(不是缺省不给)。
    // 缺省不给会逼前端写 `?? 0`,而「字段没有」与「冲击为 0」在排查时是两件事
    public function test_buy_price_pct_defaults_to_zero_on_every_row(): void
    {
        $user = $this->makeUser('bpp0');
        CityFactory::createForUser($user);

        foreach ($this->pricesFor($user)['prices'] as $row) {
            $this->assertArrayHasKey('buy_price_pct', $row, "{$row['resource_code']} 缺 buy_price_pct");
            $this->assertEqualsWithDelta(0.0, $row['buy_price_pct'], 1e-9);
        }
    }

    // 资源作用域(EVT_OIL_SHOCK 那种「石油/燃料 +40%」)只落在被点名的那一种资源上
    public function test_resource_scoped_impact_lands_on_that_resource_only(): void
    {
        $user = $this->makeUser('bppres');
        $city = CityFactory::createForUser($user);
        $this->addPriceModifier($city, 0.40, 'iron');

        $data = $this->pricesFor($user);

        $this->assertEqualsWithDelta(0.40, $this->rowOf($data, 'iron')['buy_price_pct'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $this->rowOf($data, 'coal')['buy_price_pct'], 1e-9, '没被点名的资源不受影响');
    }

    // 全城作用域 + 资源作用域**相加**(§9.2 的 EVT_GLOBAL_CRISIS 与 EVT_OIL_SHOCK 就是这种关系),
    // 口径与 TradeService 消费点用的 ConsumptionPoint::pctForResource 逐字一致
    public function test_city_scope_and_resource_scope_add_up(): void
    {
        $user = $this->makeUser('bppadd');
        $city = CityFactory::createForUser($user);
        $this->addPriceModifier($city, 0.10);           // 全市场 +10%
        $this->addPriceModifier($city, 0.40, 'iron');   // 石油冲击式的单资源 +40%

        $data = $this->pricesFor($user);

        $this->assertEqualsWithDelta(0.50, $this->rowOf($data, 'iron')['buy_price_pct'], 1e-9, '两个作用域相加');
        $this->assertEqualsWithDelta(0.10, $this->rowOf($data, 'coal')['buy_price_pct'], 1e-9, '只吃到全市场那一份');
    }

    // **假失败 1** —— 全服价格绝不能被本城冲击污染。
    // price / base_price / buy_price / sell_price 四个字段在有无冲击时必须逐字相同 ——
    // 一旦有人「顺手把 pct 乘进 price」,这条立刻红:那等于让一名玩家的随机事件改所有人的行情
    public function test_server_wide_price_is_never_polluted_by_the_city_impact(): void
    {
        $clean = $this->makeUser('bppclean');
        CityFactory::createForUser($clean);
        $before = $this->rowOf($this->pricesFor($clean), 'iron');

        $hit = $this->makeUser('bpphit');
        $city = CityFactory::createForUser($hit);
        $this->addPriceModifier($city, 0.40, 'iron');
        $after = $this->rowOf($this->pricesFor($hit), 'iron');

        $this->assertEqualsWithDelta(self::IRON_PRICE, $before['price'], 1e-9);
        $this->assertSame($before['price'], $after['price'], '全服基准价不许被本城事件推动');
        $this->assertSame($before['base_price'], $after['base_price']);
        $this->assertSame($before['buy_price'], $after['buy_price'], 'buy_price 是零滑点参考价,同样是全服口径');
        $this->assertSame($before['sell_price'], $after['sell_price']);
        // 变的只有这一项
        $this->assertEqualsWithDelta(0.40, $after['buy_price_pct'], 1e-9);
    }

    // **假失败 2** —— 卖出侧没有任何对应字段。
    // 两侧同步上抬 = 「事件期间抛货、事件后买回」的确定性印钞机(ModifierTarget 里写死的裁决)
    public function test_no_sell_side_impact_field_is_exposed(): void
    {
        $user = $this->makeUser('bppsell');
        $city = CityFactory::createForUser($user);
        $this->addPriceModifier($city, 0.40, 'iron');

        $row = $this->rowOf($this->pricesFor($user), 'iron');

        $this->assertArrayNotHasKey('sell_price_pct', $row, '卖出侧口径上恒 0,不许给字段暗示它可以非 0');
        $this->assertArrayNotHasKey('price_pct', $row, '含糊的字段名会被当成双侧通用');
    }

    // 还没建城的账号(纯 GET 不建城):一律 0,且端点不得留下任何城市行
    public function test_endpoint_never_creates_a_city(): void
    {
        $user = $this->makeUser('bppnocity');

        $data = $this->pricesFor($user);

        $this->assertSame(0, City::where('user_id', $user->id)->count(), '价目表是纯 GET,不许顺手建城');
        $this->assertEqualsWithDelta(0.0, $this->rowOf($data, 'iron')['buy_price_pct'], 1e-9);
    }

    // 另一名玩家的事件冲击绝不出现在我的价目表上(冲击是城市级实例)
    public function test_another_players_impact_is_not_visible(): void
    {
        $victim = $this->makeUser('bppmine');
        CityFactory::createForUser($victim);

        $other = $this->makeUser('bpptheirs');
        $this->addPriceModifier(CityFactory::createForUser($other), 0.40, 'iron');

        $this->assertEqualsWithDelta(0.0, $this->rowOf($this->pricesFor($victim), 'iron')['buy_price_pct'], 1e-9);
    }

    // 过期的 modifier 不计入(与其余消费点同一条时间窗口口径)
    public function test_expired_impact_is_not_counted(): void
    {
        $user = $this->makeUser('bppexp');
        $city = CityFactory::createForUser($user);

        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => ModifierTarget::MARKET_PRICE_PCT, 'scope' => ModifierSpec::SCOPE_RESOURCE,
            'scope_key' => 'iron', 'op' => ModifierSpec::OP_PCT, 'value' => 0.40,
            'starts_at' => now()->copy()->subHours(2),
            'ends_at'   => now()->copy()->subHour(),
            'created_at' => now(),
        ]);

        $this->assertEqualsWithDelta(0.0, $this->rowOf($this->pricesFor($user), 'iron')['buy_price_pct'], 1e-9);
    }

    // ---------- ⑤ 滑点预估参数 ----------

    // 顶层三个数 + 逐资源的有效流动性,凑齐前端算滑点率的整条公式:
    //     滑点率 = min(max_slippage_rate, slippage_coefficient × 数量 / effective_liquidity)
    public function test_slippage_estimate_parameters_match_the_server(): void
    {
        $user = $this->makeUser('slip');
        CityFactory::createForUser($user);

        $data = $this->pricesFor($user);

        $this->assertEqualsWithDelta(0.5, $data['slippage_coefficient'], 1e-9, '9.C4 批准 k = 0.5');
        $this->assertEqualsWithDelta(TradeService::MAX_SLIPPAGE_RATE, $data['max_slippage_rate'], 1e-9);

        // iron 有效流动性 = 定义表 1364 × 全局倍率 1
        $iron = $this->rowOf($data, 'iron');
        $this->assertEqualsWithDelta(1364.0, $iron['effective_liquidity'], 1e-9);
        $this->assertEqualsWithDelta(
            MarketDefinition::effectiveLiquidity(MarketDefinition::find('iron')),
            $iron['effective_liquidity'],
            1e-9
        );

        // 拿下发的参数算一遍 50 手 iron 的滑点率,必须等于服务器公式的结果
        $expected = min($data['max_slippage_rate'], $data['slippage_coefficient'] * 50 / $iron['effective_liquidity']);
        $this->assertEqualsWithDelta(0.5 * 50 / 1364.0, $expected, 1e-12);
    }

    // 全局流动性倍率是后台设定(前端拿不到),所以下发的必须是**已经乘过倍率**的有效值 ——
    // 下发 base_liquidity 让前端自己乘,预估必然与服务器对不上
    public function test_effective_liquidity_follows_the_admin_multiplier(): void
    {
        $user = $this->makeUser('slipmul');
        CityFactory::createForUser($user);

        GameSetting::set(GameSetting::MARKET_LIQUIDITY_MULTIPLIER, 0.5, null, 'test');
        GameSetting::flush();
        MarketDefinition::flush();

        $this->assertEqualsWithDelta(682.0, $this->rowOf($this->pricesFor($user), 'iron')['effective_liquidity'], 1e-9);
    }

    // **假失败 3** —— 定价密钥与由它派生的一切一律不下发。
    // 玩家能算出下一窗的价 = 无风险套利(PriceEngine 顶部「服务器权威」那一整段的意义)
    public function test_price_secret_and_next_window_price_are_not_exposed(): void
    {
        $user = $this->makeUser('slipsecret');
        CityFactory::createForUser($user);

        $body = $this->actingAs($user)->getJson('/api/market/prices')->getContent();

        foreach (['secret', 'noise', 'hmac', 'next_price', 'next_window_price'] as $needle) {
            $this->assertStringNotContainsString($needle, strtolower($body), "响应里不该出现 {$needle}");
        }
        // 反向确认:下一窗的价格确实与本窗不同(所以「不下发」是有意义的)
        $this->assertNotSame(
            PriceEngine::priceFor(MarketDefinition::find('iron'), PriceEngine::currentEpoch()),
            PriceEngine::priceFor(MarketDefinition::find('iron'), PriceEngine::currentEpoch() + 1)
        );
    }

    // ---------- ⑥ 单笔数量硬上限 ----------

    public function test_max_order_quantity_is_exposed_and_follows_the_setting(): void
    {
        $user = $this->makeUser('maxqty');
        CityFactory::createForUser($user);

        $this->assertSame(1000000, $this->pricesFor($user)['market_max_order_quantity']);

        GameSetting::set(GameSetting::MARKET_MAX_ORDER_QUANTITY, 500, null, 'test');
        GameSetting::flush();

        $this->assertSame(500, $this->pricesFor($user)['market_max_order_quantity']);

        // 下发的必须与服务端真正拦人的那道闸是同一个数(§45:预估对不上就等于没有预估)
        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 501])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);
    }

    // 端点仍然需要登录(与其余市场端点同一条)
    public function test_requires_auth(): void
    {
        $this->getJson('/api/market/prices')->assertStatus(401);
    }
}
