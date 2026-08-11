<?php

namespace Tests\Feature\Market;

use App\Game\City\CityFactory;
use App\Game\Market\MarketDefinition;
use App\Game\Market\PriceEngine;
use App\Game\Market\TradeService;
use App\Models\User;
use App\Support\GameSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 定价内核:确定性 / 移动平均漂移 / 夹取边界 / 服务器权威。
class MarketPricingTest extends TestCase
{
    use RefreshDatabase;

    // 只为满足 city_market_orders 的外键而存在的一座城(本组用例不碰它的资源)
    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $user = User::create(['username' => 'pricing', 'name' => 'pricing', 'email' => 'pricing@example.com', 'password' => 'password123']);
        $this->cityId = (int) CityFactory::createForUser($user)->id;
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function def(string $resourceId): array
    {
        return MarketDefinition::find($resourceId);
    }

    // 直接往成交流水里插一条历史成交(不走交易链):
    // 移动平均只读这张表,构造供需失衡最省事的办法就是造流水
    private function pushOrder(string $resourceId, string $side, float $quantity, int $windowIndex): void
    {
        DB::table('city_market_orders')->insert([
            'city_id' => $this->cityId, 'user_id' => 1, 'resource_id' => $resourceId,
            'side' => $side, 'quantity' => $quantity,
            'mid_price' => 1, 'slippage_rate' => 0, 'unit_price' => 1, 'fee' => 0, 'slippage' => 0, 'money_delta' => 0,
            'window_index' => $windowIndex, 'request_id' => null, 'idempotency_key' => null, 'created_at' => now(),
        ]);
        // 流水是移动平均的数据源,插完必须清掉请求级缓存,否则读到的是插入前的聚合
        PriceEngine::flushVolumes();
    }

    // ---- 确定性(反刷的地基)----

    // 同一个 epoch 重算多少次都必须是同一个价:
    // 只要同一窗内报价会变,就存在「报价 A 下单、成交价 B」的裂缝
    public function test_price_is_deterministic_within_one_epoch(): void
    {
        $epoch = PriceEngine::currentEpoch();
        $def = $this->def('iron');

        $first = PriceEngine::priceFor($def, $epoch);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($first, PriceEngine::priceFor($def, $epoch), '同一 epoch 的价格必须完全相同');
        }
    }

    // 换一个 epoch 就必须换一个价(否则「波动」名存实亡,移动平均也无从谈起)
    public function test_price_changes_across_epochs(): void
    {
        $def = $this->def('advanced_materials');
        $epoch = PriceEngine::currentEpoch();

        $series = [];
        for ($i = 0; $i < 12; $i++) {
            $series[] = PriceEngine::priceFor($def, $epoch + $i);
        }

        $this->assertGreaterThan(6, count(array_unique($series)), '连续 12 窗应该出现多个不同价格');
    }

    // 时间推进跨过窗口边界 → epoch +1;窗口内推进 → epoch 不变
    public function test_epoch_advances_with_window_seconds(): void
    {
        $window = PriceEngine::windowSeconds();
        $this->assertSame(60, $window, '9.C2 批准的窗口是 60 秒');

        $base = Carbon::createFromTimestamp(1800000000); // 恰好落在 60 秒边界上
        Carbon::setTestNow($base);
        $epoch = PriceEngine::currentEpoch();

        Carbon::setTestNow($base->copy()->addSeconds($window - 1));
        $this->assertSame($epoch, PriceEngine::currentEpoch(), '窗口内不换价');

        Carbon::setTestNow($base->copy()->addSeconds($window));
        $this->assertSame($epoch + 1, PriceEngine::currentEpoch(), '跨过窗口边界必须换价');
    }

    // 窗口秒数是后台可调的:改成 30 秒后,同样的墙钟时间落到不同的 epoch 编号
    public function test_window_seconds_is_configurable(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1800000000));
        $at60 = PriceEngine::currentEpoch();

        $this->setSetting(GameSetting::MARKET_WINDOW_SECONDS, 30);

        $this->assertSame(30, PriceEngine::windowSeconds());
        $this->assertSame($at60 * 2, PriceEngine::currentEpoch());
    }

    // 服务器权威:换一把密钥,同一 epoch 的价格必须变。
    // 客户端拿不到密钥 → 就算把公式逐行复刻也预测不了下一窗的价(CLAUDE §30 / §66)
    public function test_price_depends_on_server_secret(): void
    {
        $def = $this->def('iron');
        $epoch = PriceEngine::currentEpoch();

        $withDefaultSecret = PriceEngine::priceFor($def, $epoch);

        config(['market.price_secret' => 'a-completely-different-secret']);
        $withOtherSecret = PriceEngine::priceFor($def, $epoch);

        $this->assertNotEquals($withDefaultSecret, $withOtherSecret, '密钥不同却算出同一个价 = 密钥没进公式');
    }

    // 密钥全缺时 noise 恒 0(Fail Safe:宁可不波动,也不用公开可推导的兜底密钥)
    public function test_missing_secret_disables_noise_instead_of_using_a_guessable_one(): void
    {
        config(['market.price_secret' => null, 'app.key' => null]);
        PriceEngine::resetWarningState();

        $this->assertSame(0.0, PriceEngine::noise('iron', 12345, 0.07));
    }

    // ---- 移动平均漂移(§8.1 + 9.C3)----

    // 全服买入远多于卖出 → imbalance > 0 → 目标价上移
    public function test_excess_demand_drives_price_up(): void
    {
        $def = $this->def('iron');
        $epoch = PriceEngine::currentEpoch();
        $baseline = PriceEngine::priceFor($def, $epoch);

        // 往前一个已结束的窗口里塞一笔大额买入(数量取有效流动性量级,才压得过 5% 底噪)
        $this->pushOrder('iron', TradeService::SIDE_BUY, MarketDefinition::effectiveLiquidity($def) * 5, $epoch - 1);

        $this->assertGreaterThan($baseline, PriceEngine::priceFor($def, $epoch), '买盘压倒卖盘时价格必须上行');
    }

    // 全服卖出远多于买入 → imbalance < 0 → 目标价下移
    public function test_excess_supply_drives_price_down(): void
    {
        $def = $this->def('iron');
        $epoch = PriceEngine::currentEpoch();
        $baseline = PriceEngine::priceFor($def, $epoch);

        $this->pushOrder('iron', TradeService::SIDE_SELL, MarketDefinition::effectiveLiquidity($def) * 5, $epoch - 1);

        $this->assertLessThan($baseline, PriceEngine::priceFor($def, $epoch), '卖盘压倒买盘时价格必须下行');
    }

    // 移动平均**不含当前窗**:本窗刚成交的单子不能改本窗的价。
    // 这是「同一 epoch 内价格恒定」的实现前提,也堵死「先小单探价、再大单套利」
    public function test_current_window_trades_do_not_move_current_window_price(): void
    {
        $def = $this->def('iron');
        $epoch = PriceEngine::currentEpoch();
        $before = PriceEngine::priceFor($def, $epoch);

        $this->pushOrder('iron', TradeService::SIDE_BUY, MarketDefinition::effectiveLiquidity($def) * 50, $epoch);

        $this->assertSame($before, PriceEngine::priceFor($def, $epoch), '当前窗的成交量绝不能影响当前窗的价格');
    }

    // 移动平均窗口数 N 是后台可调的:把 N 调成 1 后,只有紧邻的上一窗算数
    public function test_moving_average_window_count_is_configurable(): void
    {
        $def = $this->def('iron');
        $epoch = PriceEngine::currentEpoch();

        // 塞在 5 窗之前:N=10 时算得进来,N=1 时算不进来
        $this->pushOrder('iron', TradeService::SIDE_BUY, MarketDefinition::effectiveLiquidity($def) * 5, $epoch - 5);

        $withTenWindows = PriceEngine::priceFor($def, $epoch);

        $this->setSetting(GameSetting::MARKET_MA_WINDOWS, 1);
        $withOneWindow = PriceEngine::priceFor($def, $epoch);

        $this->assertGreaterThan($withOneWindow, $withTenWindows, 'N 调小后,5 窗前的买盘就不该再抬价');
    }

    // 供需底噪(9.C3):空服时 demand == supply,imbalance 恒 0 → 价格只受 noise 影响,
    // 绝不会因为 0/0 而跳到夹取边界
    public function test_noise_floor_keeps_empty_server_balanced(): void
    {
        $def = $this->def('iron');
        [$demand, $supply] = PriceEngine::movingAverage($def, PriceEngine::currentEpoch());

        $this->assertGreaterThan(0, $demand, '空服也必须有底噪,否则 imbalance 会被单笔小单打到 ±1');
        $this->assertSame($demand, $supply, '零成交时买卖底噪必须相等');
    }

    // ---- 夹取边界 ----

    // 供需极端失衡也不能把价格顶穿 §8 的 max_price
    public function test_price_is_clamped_to_upper_bound(): void
    {
        // 把弹性拉到 10(§8 原值 0.75):imbalance 接近 1 时目标价会冲到基础价的 11 倍
        DB::table('market_definition')->where('resource_id', 'iron')->update(['elasticity' => 10.0]);
        MarketDefinition::flush();

        $def = $this->def('iron');
        $epoch = PriceEngine::currentEpoch();
        $this->pushOrder('iron', TradeService::SIDE_BUY, MarketDefinition::effectiveLiquidity($def) * 500, $epoch - 1);

        [, $high] = MarketDefinition::priceBounds($def);
        $this->assertSame(round($high, 4), PriceEngine::priceFor($def, $epoch), '价格必须被夹在上限');
        $this->assertLessThanOrEqual(70.4, PriceEngine::priceFor($def, $epoch), '§8 给 iron 的 max_price 是 70.4');
    }

    public function test_price_is_clamped_to_lower_bound(): void
    {
        DB::table('market_definition')->where('resource_id', 'iron')->update(['elasticity' => 10.0]);
        MarketDefinition::flush();

        $def = $this->def('iron');
        $epoch = PriceEngine::currentEpoch();
        $this->pushOrder('iron', TradeService::SIDE_SELL, MarketDefinition::effectiveLiquidity($def) * 500, $epoch - 1);

        [$low] = MarketDefinition::priceBounds($def);
        $this->assertSame(round($low, 4), PriceEngine::priceFor($def, $epoch), '价格必须被夹在下限');
        $this->assertGreaterThanOrEqual(9.9, PriceEngine::priceFor($def, $epoch), '§8 给 iron 的 min_price 是 9.9');
    }

    // 全局夹取倍率默认取 §8 全表最宽档 → 逐行的 §8 min/max 说了算(等价于「以 §8 为准」)
    public function test_default_global_multiples_defer_to_section_8_rows(): void
    {
        $def = $this->def('iron');
        [$low, $high] = MarketDefinition::priceBounds($def);

        $this->assertEqualsWithDelta(9.9, $low, 0.0001);
        $this->assertEqualsWithDelta(70.4, $high, 0.0001);
    }

    // 运营把全局倍率收紧时,立刻对全市场生效(不必逐行改 26 行定义)
    public function test_global_multiples_can_tighten_the_band(): void
    {
        $this->setSetting(GameSetting::MARKET_PRICE_MIN_MULTIPLE, 0.9);
        $this->setSetting(GameSetting::MARKET_PRICE_MAX_MULTIPLE, 1.1);

        [$low, $high] = MarketDefinition::priceBounds($this->def('iron'));

        $this->assertEqualsWithDelta(19.8, $low, 0.0001, '22 × 0.9');
        $this->assertEqualsWithDelta(24.2, $high, 0.0001, '22 × 1.1');
    }

    // 不可交易资源不参与定价模型:knowledge 恒 0、money 恒 1(它本身就是计价单位)
    public function test_non_tradeable_rows_return_base_price(): void
    {
        $epoch = PriceEngine::currentEpoch();

        $this->assertSame(0.0, PriceEngine::priceFor($this->def('knowledge'), $epoch));
        $this->assertSame(1.0, PriceEngine::priceFor($this->def('money'), $epoch));
    }

    // 未登记资源 → null(Fail Closed:定价失败绝不猜一个数出来)
    public function test_unknown_resource_has_no_price(): void
    {
        $this->assertNull(PriceEngine::price('unobtanium'));
    }

    // 全表价目一次算完,26 行都在 §8 的夹取区间内
    public function test_price_table_covers_all_rows_within_bounds(): void
    {
        $table = PriceEngine::priceTable();
        $this->assertCount(26, $table);

        foreach (MarketDefinition::all() as $resourceId => $def) {
            [$low, $high] = MarketDefinition::priceBounds($def);
            $this->assertGreaterThanOrEqual(round($low, 4), $table[$resourceId], $resourceId);
            $this->assertLessThanOrEqual(round($high, 4), $table[$resourceId], $resourceId);
        }
    }

    // 直接写库 + 清缓存(正常路径是后台 POST /api/admin/settings,这里只是夹具)
    private function setSetting(string $key, mixed $value): void
    {
        DB::table('game_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['value_json' => json_encode($value), 'description' => GameSetting::DEFINITIONS[$key]['description'], 'updated_at' => now()]
        );
        GameSetting::flush();
    }
}
