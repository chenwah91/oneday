<?php

namespace Tests\Feature\Market;

use App\Game\City\CityFactory;
use App\Game\Market\MarketDefinition;
use App\Game\Market\PriceEngine;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M3-W5:贸易容量 → 单城成交量上限(backlog §5.4)+ 事件价格冲击的城市侧落点。
//
// 两条口径在这里被钉死:
//   ① 单城单窗上限 = min(流动性口径, (基础额度 + 全城 trade_capacity) × 系数 × 窗口分钟数);
//      trade_capacity = 0 的城市**仍能交易**(基础额度),只是做不了大宗 —— 不禁市是明确要求。
//   ② 价格冲击只作用于**买入侧**:卖出价一个子儿都不动。
//      少了这一条,「事件期间抛货、结束后买回」就是一台确定性印钞机(见 TradeService 的口径注释)。
class MarketTradeCapacityTest extends TestCase
{
    use RefreshDatabase;

    // 固定在 60 秒窗口边界上,整个用例内 epoch 不变(与 MarketTradeTest 同一手法)
    private const FROZEN_TS = 1800000000;

    // C02 城镇市场 L1 的贸易容量(building_levels.json)
    private const C02_TRADE = 450.0;

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

    // ---------- ① 城市侧额度 ----------

    // 没有任何贸易建筑的城市:额度 = 基础额度(不是 0,也不是流动性口径)
    public function test_city_without_market_buildings_gets_the_base_quota(): void
    {
        $def = MarketDefinition::find('food');
        $base = (float) GameSetting::get(GameSetting::MARKET_TRADE_CAPACITY_BASE_PER_MIN);

        // food 的流动性口径是 1000/窗,基础额度 200/分钟 × 1 分钟窗 = 200 → min 取 200
        $this->assertEqualsWithDelta(1000.0, MarketDefinition::windowQuota($def), 1e-6);
        $this->assertEqualsWithDelta($base, MarketDefinition::cityWindowQuota($def, 0.0), 1e-6);
        $this->assertGreaterThan(0.0, MarketDefinition::cityWindowQuota($def, 0.0), '没建市场的城市不该被禁市');
    }

    // 建了市场之后额度跟着涨,涨到流动性口径就封顶(min 的另一半)
    public function test_trade_capacity_raises_the_quota_up_to_the_liquidity_cap(): void
    {
        $def = MarketDefinition::find('food');
        $base = (float) GameSetting::get(GameSetting::MARKET_TRADE_CAPACITY_BASE_PER_MIN);

        $this->assertEqualsWithDelta($base + 450.0, MarketDefinition::cityWindowQuota($def, 450.0), 1e-6);
        // 贸易容量大到超过流动性口径时,流动性那一半说了算
        $this->assertEqualsWithDelta(1000.0, MarketDefinition::cityWindowQuota($def, 5000.0), 1e-6);
    }

    // 窗长可调:额度按**窗口分钟数**折算,把窗口改成 30 秒不会让额度凭空翻倍
    public function test_quota_scales_with_window_length(): void
    {
        $def = MarketDefinition::find('food');
        $full = MarketDefinition::tradeThroughputQuota(400.0);

        $this->setSetting(GameSetting::MARKET_WINDOW_SECONDS, 30);

        $this->assertEqualsWithDelta($full / 2, MarketDefinition::tradeThroughputQuota(400.0), 1e-6);
    }

    // 端到端:超过城市侧额度的单子被拒,且错误里同时给出两条口径(等下一窗 vs 去建市场)
    public function test_order_over_city_quota_is_rejected_with_both_caps(): void
    {
        [$user, $city] = $this->makeCity('tcquota');
        $base = (int) GameSetting::get(GameSetting::MARKET_TRADE_CAPACITY_BASE_PER_MIN);

        $res = $this->actingAs($user)->postJson('/api/market/buy', [
            'resource_code' => 'food', 'quantity' => $base + 1,
        ]);

        $res->assertStatus(422)->assertJson(['error' => 'MARKET_LIMIT_REACHED']);
        $this->assertEqualsWithDelta($base, (float) $res->json('details.window_quota'), 1e-6);
        $this->assertEqualsWithDelta(1000.0, (float) $res->json('details.liquidity_quota'), 1e-6, '流动性口径要一并给出');
        $this->assertEqualsWithDelta(0.0, (float) $res->json('details.trade_capacity'), 1e-6);
        $this->assertSame(0, DB::table('city_market_orders')->count(), '被拒的订单不该留下流水');
    }

    // 同一笔单子:建两栋 C02(贸易容量 900)之后就买得动了 —— 这是 C 系列建筑第一次有意义
    public function test_building_markets_unlocks_the_same_order(): void
    {
        [$user, $city] = $this->makeCity('tcunlock');
        $base = (int) GameSetting::get(GameSetting::MARKET_TRADE_CAPACITY_BASE_PER_MIN);
        $quantity = $base + 100;

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'food', 'quantity' => $quantity])
            ->assertStatus(422)->assertJson(['error' => 'MARKET_LIMIT_REACHED']);

        $this->addBuilding($city, 'C02');
        $this->addBuilding($city, 'C02');

        $res = $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'food', 'quantity' => $quantity])
            ->assertOk();

        // 200 + 900 = 1100 已经越过 food 的流动性口径 1000 → min 取 1000。
        // 断言的是 min 本身:贸易容量堆得再高,也不会突破「这个市场一窗吃得下多少」
        $this->assertEqualsWithDelta(
            min(1000.0, $base + self::C02_TRADE * 2),
            (float) $res->json('data.trade.window_quota'),
            1e-6,
            '成交响应里的额度必须是城市侧口径(玩家真正能用的那条)'
        );
    }

    // ---------- ② 事件价格冲击 ----------

    // 买入侧:成交价按 (1 + pct) 抬高,审计与响应都要能回答「贵在哪」
    public function test_price_shock_raises_the_buy_price_only(): void
    {
        [$user, $city] = $this->makeCity('tcshock', 1000000.0, ['iron' => 200]);
        $def = MarketDefinition::find('iron');
        $mid = PriceEngine::priceFor($def, PriceEngine::currentEpoch());

        $this->addPriceShock($city, 'iron', 0.40);

        $buy = $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 10])->assertOk();
        $sell = $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 10])->assertOk();

        $this->assertEqualsWithDelta(0.40, (float) $buy->json('data.trade.event_price_pct'), 1e-6);
        $this->assertEqualsWithDelta(0.0, (float) $sell->json('data.trade.event_price_pct'), 1e-6, '卖出侧必须完全不受冲击');

        // 买入单价 ≈ 基准价 × 1.40 ×(1 + 滑点)×(1 + 费率);卖出单价 < 基准价(只有滑点与费率)
        $this->assertGreaterThan($mid * 1.40, (float) $buy->json('data.trade.unit_price'));
        $this->assertLessThan($mid, (float) $sell->json('data.trade.unit_price'));
    }

    // 反刷:价格冲击期间「买了立刻卖」必须仍然亏钱(冲击只加重买入侧,永远不会变成套利入口)
    public function test_round_trip_still_loses_money_during_a_price_shock(): void
    {
        [$user, $city] = $this->makeCity('tcarb', 1000000.0);
        $this->addPriceShock($city, 'iron', 0.50);

        $before = (float) DB::table('cities')->where('id', $city->id)->value('money');

        $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => 20])->assertOk();
        $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => 20])->assertOk();

        $after = (float) DB::table('cities')->where('id', $city->id)->value('money');
        $this->assertLessThan($before, $after, '价格冲击期间的往返居然赚钱了 —— 冲击一定被接到了卖出侧');
    }

    // 全服价格不受城市事件影响(这条是 PriceEngine 那一段裁决的可执行版本)
    public function test_city_event_never_moves_the_global_price(): void
    {
        [$user, $city] = $this->makeCity('tcglobal');
        $before = PriceEngine::priceFor(MarketDefinition::find('iron'), PriceEngine::currentEpoch());

        $this->addPriceShock($city, 'iron', 0.50);

        $this->assertSame($before, PriceEngine::priceFor(MarketDefinition::find('iron'), PriceEngine::currentEpoch()));
        $this->assertSame(1.0, PriceEngine::EVENT_MULTIPLIER_DEFAULT);

        // 价目表端点(全服共享)同样不该动
        $row = collect($this->actingAs($user)->getJson('/api/market/prices')->assertOk()->json('data.prices'))
            ->firstWhere('resource_code', 'iron');
        $this->assertEqualsWithDelta($before, (float) $row['price'], 1e-6);
    }

    // ---------- 夹具 ----------

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

    private function addBuilding(City $city, string $buildingId): void
    {
        static $x = 20;
        $x = ($x + 2) % 60;

        CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => $buildingId, 'level' => 1,
            'x' => $x, 'y' => 9, 'status' => 'active', 'assigned_workers' => 0,
        ]);
    }

    // 事件写下的价格冲击(scope=resource):等价于 EVT_OIL_SHOCK / EVT_SPECULATION 触发后的那一行
    private function addPriceShock(City $city, string $resourceId, float $pct): void
    {
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => ModifierTarget::MARKET_PRICE_PCT,
            'scope' => ModifierSpec::SCOPE_RESOURCE, 'scope_key' => $resourceId,
            'op' => ModifierSpec::OP_PCT, 'value' => $pct,
            'starts_at' => now()->copy()->subMinute(),
            'ends_at' => now()->copy()->addMinutes(15),
            'created_at' => now(),
        ]);
    }

    private function setSetting(string $key, mixed $value): void
    {
        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['value_json' => json_encode($value), 'description' => GameSetting::DEFINITIONS[$key]['description'], 'updated_at' => now()]
        );
        GameSetting::flush();
    }
}
