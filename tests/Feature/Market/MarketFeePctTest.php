<?php

namespace Tests\Feature\Market;

use App\Game\City\CityFactory;
use App\Game\Market\MarketDefinition;
use App\Game\Market\TradeService;
use App\Game\Modifier\ConsumptionPoint;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcCode;
use App\Models\City;
use App\Models\User;
use App\Support\GameSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M3-W7:死 target `market_fee_pct` 的清偿(消费点 = TradeService 的手续费那一行)。
//
// 接线前的形态与 governance 清偿前一模一样 ——「登记了 ≠ 生效」而且**是静默的**:
// §6.3 的 7 位商人类 NPC 早就写好了 specs(N046 −6% / N065 −8% / N086 −10% / N099 −5% /
// N114 −7% / N127 −10% / N146 −10%),但 app/Game/Market 里没有任何一处读它,
// 玩家花钱招了商人、手续费一分没少,而且不会报错。
//
// 用例分四层:
//   ① 消费点层:三个来源(事件 modifier / 在编 NPC / 已装备工具)各验一遍,漏一个 = 那类投稿静默失效;
//   ② 口径层:有效费率 = 基础费率 × max(0, 1 + Σpct),**买卖两侧共用同一个费率**;
//   ③ 黄金样本层:精确到分的成交金额与审计三字段(fee_rate / base_fee_rate / fee_pct);
//   ④ **反套利不变量层**:最大减免(费率夹到 0)下同窗往返仍然净亏,亏损额恰好等于闭式 −2Pq(s+f')。
//
// 时间冻结 = epoch 冻结:同一个用例里的买与卖必须落在同一个价格窗口,
// 否则测的就不是「同窗往返」而是「跨窗投机」。
class MarketFeePctTest extends TestCase
{
    use RefreshDatabase;

    // 与 MarketAntiAbuseTest / MarketPriceContractTest 同一个基准时刻(epoch = 30000000)
    private const FROZEN_TS = 1800000000;

    // 该 epoch 下 iron 的服务器基准价 P(base 22 × HMAC 噪声,已夹取并落到 4 位小数)。
    // 密钥固定在 phpunit.xml,所以这是一个可以写死在断言里的确定值
    private const P = 20.8804;

    // 黄金样本统一用 50 手 iron:
    //   有效流动性 L = 定义表 1364 × 全局倍率 1
    //   滑点率 s = k × q / L = 0.5 × 50 / 1364 = 0.018328445747800587
    //   买 50 + 卖 50 = 100,落在流动性口径的单窗额度 136.4 之内(走的是完全合法的正常成交)
    private const Q = 50;
    private const S = 0.5 * 50 / 1364.0;

    // iron 的基础费率 = 定义表 fee_rate 0.03 × 全局倍率 1
    private const BASE_FEE = 0.03;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::FROZEN_TS));
        $this->seed();

        // 城市侧的贸易额度(backlog §5.4)不是本文件要验的东西:没有市场建筑的城市基础额度只有
        // 200/分钟,会先把 50 手的往返挡下,测的就不再是手续费了。把基础额度调到远高于流动性口径,
        // 城市侧那一层恒不生效 —— 与 MarketAntiAbuseTest 同一条处理
        GameSetting::set(GameSetting::MARKET_TRADE_CAPACITY_BASE_PER_MIN, 1000000, null, 'test');
        GameSetting::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: City} */
    private function makeCity(string $un, array $resources = ['iron' => 500]): array
    {
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($user);

        DB::table('cities')->where('id', $city->id)->update(['money' => 10000000]);
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

    // 直接落一行 city_npcs(招募链路本身在 NPC 用例里验)
    private function addNpc(City $city, string $npcId): void
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        DB::table('city_npcs')->insert([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => NpcCode::STATUS_IDLE, 'assigned_instance_id' => null,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addModifier(City $city, float $value): void
    {
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => ModifierTarget::MARKET_FEE_PCT, 'scope' => ModifierSpec::SCOPE_CITY,
            'scope_key' => null, 'op' => ModifierSpec::OP_PCT, 'value' => $value,
            'starts_at' => now()->copy()->subMinute(),
            'ends_at' => now()->copy()->addMinutes(30),
            'created_at' => now(),
        ]);
    }

    // §7 的 24 件工具里**一件都没有**投稿 market_fee_pct(items.json 里那一条明文写着
    //「市场手续费走 market_fee_pct 属另一件事,不擅自改挂」)。为了验「工具这个来源读不读得到」,
    // 这里把一件现成工具的 effect_json 临时改成减费 —— 验的是**消费点的取数路径**,不是数值。
    // 将来真出现贸易工具时,这条用例保证它一装上就生效
    private function equipFeeItem(City $city, float $value): void
    {
        DB::table('item_definition')->where('item_id', 'IT016')->update([
            'effect_json' => json_encode([
                'specs' => [[
                    'target' => ModifierTarget::MARKET_FEE_PCT,
                    'scope'  => ModifierSpec::SCOPE_CITY,
                    'op'     => ModifierSpec::OP_PCT,
                    'value'  => $value,
                ]],
                'unmapped_zh' => [],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');
        $durability = (float) DB::table('item_definition')->where('item_id', 'IT016')->value('durability');

        DB::table('city_items')->insert([
            'city_id' => $city->id, 'item_id' => 'IT016',
            'durability_left' => $durability, 'status' => 'equipped',
            'equipped_instance_id' => $instanceId,
            'acquired_source' => 'test', 'acquired_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buy(User $user, int $quantity = self::Q): array
    {
        $res = $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'iron', 'quantity' => $quantity]);
        $res->assertOk();

        return $res->json('data.trade');
    }

    private function sell(User $user, int $quantity = self::Q): array
    {
        $res = $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'iron', 'quantity' => $quantity]);
        $res->assertOk();

        return $res->json('data.trade');
    }

    // ---------- ① 消费点层:三个来源各验一遍 ----------

    // 基准:一个投稿都没有 → 费率就是定义表 × 全局倍率,fee_pct 恒 0
    public function test_base_fee_rate_without_any_contribution(): void
    {
        [$user, $city] = $this->makeCity('feebase');

        $this->assertSame(0.0, ConsumptionPoint::pct(ModifierTarget::MARKET_FEE_PCT, (int) $city->id));

        $trade = $this->buy($user);

        $this->assertEqualsWithDelta(self::BASE_FEE, $trade['fee_rate'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $trade['fee_pct'], 1e-9);
    }

    // 来源①:事件写下的持续型 modifier
    public function test_fee_pct_from_event_modifier(): void
    {
        [$user, $city] = $this->makeCity('feeevt');
        $this->addModifier($city, -0.20);

        $trade = $this->buy($user);

        $this->assertEqualsWithDelta(-0.20, $trade['fee_pct'], 1e-9);
        // 0.03 × 0.80 = 0.024
        $this->assertEqualsWithDelta(0.024, $trade['fee_rate'], 1e-9);
    }

    // 来源②:在编 NPC 的特性。N086「治理+20%,市场手续费-10%」—— 同一位 NPC 同时投两条 target,
    // 这里只该吃到手续费那一条(治理那条走别的消费点)
    public function test_fee_pct_from_npc_trait(): void
    {
        [$user, $city] = $this->makeCity('feenpc');
        $this->addNpc($city, 'N086');

        $trade = $this->buy($user);

        $this->assertEqualsWithDelta(-0.10, $trade['fee_pct'], 1e-9);
        $this->assertEqualsWithDelta(0.027, $trade['fee_rate'], 1e-9, '0.03 × 0.90');
    }

    // 来源③:已装备且耐久 > 0 的工具
    public function test_fee_pct_from_equipped_item(): void
    {
        [$user, $city] = $this->makeCity('feeitem');
        $this->equipFeeItem($city, -0.15);

        $trade = $this->buy($user);

        $this->assertEqualsWithDelta(-0.15, $trade['fee_pct'], 1e-9);
        // 0.03 × 0.85 = 0.0255
        $this->assertEqualsWithDelta(0.0255, $trade['fee_rate'], 1e-9);
    }

    // 三个来源同时在场:各数一次,不重不漏
    public function test_all_three_sources_compose_once(): void
    {
        [$user, $city] = $this->makeCity('feeall');
        $this->addModifier($city, -0.05);
        $this->addNpc($city, 'N086');       // −10%
        $this->equipFeeItem($city, -0.15);

        $trade = $this->buy($user);

        $this->assertEqualsWithDelta(-0.30, $trade['fee_pct'], 1e-9);
        // 0.03 × 0.70 = 0.021
        $this->assertEqualsWithDelta(0.021, $trade['fee_rate'], 1e-9);
    }

    // 七位商人全招齐:§6.3 现有数据的**最大合计减免** = −56%(仍够不着 −100%)
    public function test_all_seven_merchant_npcs_sum_to_the_documented_maximum(): void
    {
        [$user, $city] = $this->makeCity('feeseven');
        foreach (['N046', 'N065', 'N086', 'N099', 'N114', 'N127', 'N146'] as $npcId) {
            $this->addNpc($city, $npcId);
        }

        $trade = $this->buy($user);

        // −0.06 −0.08 −0.10 −0.05 −0.07 −0.10 −0.10 = −0.56
        $this->assertEqualsWithDelta(-0.56, $trade['fee_pct'], 1e-9);
        $this->assertEqualsWithDelta(0.03 * 0.44, $trade['fee_rate'], 1e-9);
        // 定义表数据本身也钉一遍:少一位商人或改了数值,这里立刻红
        $this->assertSame(7, DB::table('npc_definition')
            ->where('trait_json', 'like', '%"' . ModifierTarget::MARKET_FEE_PCT . '"%')->count());
    }

    // 未在编的 NPC(已离职)与未装备的工具都不投稿 —— 与其余消费点同口径
    public function test_inactive_npc_does_not_contribute(): void
    {
        [$user, $city] = $this->makeCity('feeleft');
        $this->addNpc($city, 'N086');
        DB::table('city_npcs')->where('city_id', $city->id)->update(['status' => NpcCode::STATUS_LEFT]);

        $this->assertEqualsWithDelta(0.0, $this->buy($user)['fee_pct'], 1e-9);
    }

    // ---------- ② 口径层 ----------

    // **买卖两侧共用同一个费率**:只减买入侧的话「卖出免费」会立刻变成单边套利的方向盘,
    // §6.3 的文案(「市场手续费 −X%」)也没有分侧的语义
    public function test_reduction_applies_to_both_sides(): void
    {
        [$user, $city] = $this->makeCity('feeboth');
        $this->addNpc($city, 'N086');

        $buy = $this->buy($user);
        $sell = $this->sell($user);

        $this->assertEqualsWithDelta(0.027, $buy['fee_rate'], 1e-9);
        $this->assertEqualsWithDelta(0.027, $sell['fee_rate'], 1e-9, '卖出侧必须吃到同一个减免');
        $this->assertEqualsWithDelta(-0.10, $sell['fee_pct'], 1e-9);
    }

    // 夹到 ≥ 0:后台 / 事件把减免填成 −200% 只会让手续费归零,**绝不出现负费率**。
    // 负费率 = 交易所倒贴钱,同窗往返当场转正 —— 那正是 §13 四机制要堵的缝
    public function test_effective_fee_rate_is_clamped_at_zero(): void
    {
        [$user, $city] = $this->makeCity('feeclamp');
        $this->addModifier($city, -2.0);

        $buy = $this->buy($user);
        $sell = $this->sell($user);

        $this->assertEqualsWithDelta(TradeService::MIN_FEE_RATE, $buy['fee_rate'], 1e-12);
        $this->assertEqualsWithDelta(0.0, $buy['fee'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $sell['fee'], 1e-9);
        $this->assertGreaterThanOrEqual(0.0, $buy['fee_rate']);
        $this->assertGreaterThanOrEqual(0.0, $sell['fee_rate']);
    }

    // 加费方向同样生效(负面事件可以把手续费抬上去):口径是对称的,不是「只认减免」
    public function test_positive_pct_raises_the_fee(): void
    {
        [$user, $city] = $this->makeCity('feeup');
        $this->addModifier($city, 0.50);

        // 0.03 × 1.50 = 0.045
        $this->assertEqualsWithDelta(0.045, $this->buy($user)['fee_rate'], 1e-9);
    }

    // 定义表的 0.9 上限仍然是最后一道闸:基础费率先被 effectiveFeeRate 夹在 [0, 0.9],
    // 减免再乘上去 —— 两道夹取各管各的,不互相顶替
    public function test_admin_multiplier_and_pct_compose(): void
    {
        [$user, $city] = $this->makeCity('feemul');
        GameSetting::set(GameSetting::MARKET_FEE_RATE_MULTIPLIER, 2, null, 'test');
        GameSetting::flush();
        MarketDefinition::flush();
        $this->addNpc($city, 'N086'); // −10%

        // 基础 = 0.03 × 2 = 0.06;再 × 0.90 = 0.054
        $this->assertEqualsWithDelta(0.054, $this->buy($user)['fee_rate'], 1e-9);
    }

    // ---------- ③ 黄金样本层 ----------

    // **黄金样本 1** —— N086(−10%)买入 50 手 iron 的精确成交金额与审计三字段。
    //
    //   P = 20.8804(该 epoch 的服务器基准价)  q = 50  s = 0.5×50/1364 = 0.0183284457478006
    //   有效费率 f' = 0.03 × (1 − 0.10) = 0.027
    //   买入单价 = P × (1 + s)              = 21.2631052...
    //   gross   = 单价 × q                  = 1063.155264
    //   fee     = gross × f'                = 28.705192
    //   付出    = round(gross + fee, 2)     = 1091.86        ← 资金变动就是这个数
    //   不接线时(f = 0.03)付出 = round(1063.155264 + 31.894658, 2) = 1095.05
    // 两者相差 3.19 元 —— 清偿之前这一位商人一分钱都省不下来(费率恒 0.03)
    public function test_golden_sample_buy_with_merchant_npc(): void
    {
        [$user, $city] = $this->makeCity('feegold1');
        $this->addNpc($city, 'N086');
        $before = $this->moneyOf($city);

        $trade = $this->buy($user);

        $gross = self::P * (1.0 + self::S) * self::Q;
        $expectedFee = $gross * 0.027;
        $expectedCost = round($gross + $expectedFee, 2);

        $this->assertEqualsWithDelta(1063.155264, $gross, 1e-6, '闭式与实现的 gross 必须同源');
        $this->assertEqualsWithDelta(28.705192, $expectedFee, 1e-6);
        $this->assertEqualsWithDelta(1091.86, $expectedCost, 1e-9);

        $this->assertEqualsWithDelta($expectedFee, $trade['fee'], 0.0001);
        $this->assertEqualsWithDelta(-$expectedCost, $trade['money_delta'], 0.01);
        $this->assertEqualsWithDelta($before - $expectedCost, $this->moneyOf($city->fresh()), 0.01);

        // 不接线时的对照值:差额 = 1095.05 − 1091.86 = 3.19
        $unwiredCost = round($gross + $gross * self::BASE_FEE, 2);
        $this->assertEqualsWithDelta(1095.05, $unwiredCost, 1e-9);
        $this->assertEqualsWithDelta(3.19, $unwiredCost - $expectedCost, 1e-9);
    }

    // 审计与流水必须留下三个数:实际费率 / 基础费率 / 本次减免。
    // 与建造的 durationSeconds + baseDurationSeconds 同一条理由 ——
    // 半年后要能回答「他这笔为什么只收了 28.71 的手续费」
    public function test_audit_and_order_row_record_the_reduction(): void
    {
        [$user, $city] = $this->makeCity('feeaudit');
        $this->addNpc($city, 'N086');

        $this->buy($user);

        $meta = json_decode((string) DB::table('audit_logs')
            ->where('action', 'MARKET.BUY')->latest('id')->value('metadata_json'), true);

        $this->assertEqualsWithDelta(0.027, $meta['fee_rate'], 1e-9);
        $this->assertEqualsWithDelta(self::BASE_FEE, $meta['base_fee_rate'], 1e-9);
        $this->assertEqualsWithDelta(-0.10, $meta['fee_pct'], 1e-9);

        // 成交流水里落的是**实际**收取的手续费(与审计的 fee 是同一个数)
        $order = DB::table('city_market_orders')->where('city_id', $city->id)->latest('id')->first();
        $this->assertEqualsWithDelta($meta['fee'], (float) $order->fee, 0.0001);
    }

    // ---------- ④ 反套利不变量层(最重要的一条)----------

    // **不变量** —— 最大减免下(费率被夹到 0)同窗往返**仍然净亏**。
    //
    // 闭式(TradeService 类顶部的证明,把 f 换成 f' 重走一遍化简):
    //     净额 = P·q·[(1 − s)(1 − f') − (1 + s)(1 + f')] = **−2·P·q·(s + f')**,f' ≥ 0
    // f' = 0 时净额 = −2·P·q·s —— **滑点独自兜底**:
    //     −2 × 20.8804 × 50 × 0.0183284457478006 = −38.270528
    // 实际落库(两笔各被 DECIMAL(16,2) 截过一次):买 1063.16、卖 1024.88 → 净 −38.28。
    //
    // 这条用例就是「减费能让玩家少亏,但永远不可能让他赚」的可执行证明。
    public function test_zero_fee_round_trip_still_loses_money(): void
    {
        [$user, $city] = $this->makeCity('feearb');
        $this->addModifier($city, -2.0); // 费率夹到 0

        $before = $this->moneyOf($city);
        $heldBefore = (float) DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', 'iron')->value('amount');

        $buy = $this->buy($user);
        $sell = $this->sell($user);

        $after = $this->moneyOf($city->fresh());

        // 手续费确实是 0(前提成立,否则下面证的就不是「零费率」)
        $this->assertEqualsWithDelta(0.0, $buy['fee'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $sell['fee'], 1e-9);

        // 货原样回到起点:否则「亏了钱」有可能只是因为货没卖完,不能算证明
        $this->assertEqualsWithDelta($heldBefore, (float) DB::table('city_resources')
            ->where('city_id', $city->id)->where('resource_id', 'iron')->value('amount'), 0.0001);

        // 净亏,且亏损额恰好等于闭式 −2·P·q·(s + 0)
        $this->assertLessThan($before, $after, '零手续费下同窗往返居然没亏钱 —— 滑点这道闸失效了');
        $expectedLoss = 2 * self::P * self::Q * (self::S + 0.0);
        $this->assertEqualsWithDelta(38.270528, $expectedLoss, 1e-6);
        // 容差 0.02:两笔成交各被 cities.money 的 DECIMAL(16,2) 精度截过一次
        $this->assertEqualsWithDelta($expectedLoss, $before - $after, 0.02, '亏损额必须等于 2·P·q·(s+f\')');
    }

    // 同一条不变量的「广度」版:四个波动率档 × 减免到零,任何一组转正都说明四机制被削弱了。
    // 与 MarketAntiAbuseTest 的同名思路一致,区别只在这里是**零手续费**的极端参数
    public function test_zero_fee_round_trip_loses_on_every_volatility_tier(): void
    {
        $cases = [
            ['food', 500],                 // 波动率 0.04,流动性 10000,单窗额度 1000
            ['iron', 60],                  // 0.07,1364,136.4
            ['coal', 120],                 // 0.08,2500,250
            ['electronic_components', 5],  // 0.10,211,21.1
            ['advanced_materials', 2],     // 0.12,41,4.1(最容易出问题的一档)
        ];

        foreach ($cases as $index => [$resource, $quantity]) {
            // 不预置库存:先买再卖,买入那一笔自己会把货备齐。
            // 预置反而会撞上仓储上限(基础仓容 1000,买入撑爆一律拒绝而不是静默截断)
            [$user, $city] = $this->makeCity('feearb' . $index, []);
            $this->addModifier($city, -2.0);

            $before = $this->moneyOf($city);
            $heldBefore = (float) DB::table('city_resources')->where('city_id', $city->id)
                ->where('resource_id', $resource)->value('amount');

            $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => $resource, 'quantity' => $quantity])->assertOk();
            $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => $resource, 'quantity' => $quantity])->assertOk();

            $this->assertLessThan($before, $this->moneyOf($city->fresh()),
                sprintf('%s × %d 在零手续费下同窗往返居然没亏钱', $resource, $quantity));
            $this->assertEqualsWithDelta($heldBefore, (float) DB::table('city_resources')
                ->where('city_id', $city->id)->where('resource_id', $resource)->value('amount'), 0.0001,
                $resource . ' 往返后库存必须回到原点');
        }
    }

    // 永动机检测的零费率版:反复往返,资金必须单调递减,绝不出现回升
    public function test_repeated_zero_fee_round_trips_drain_money_monotonically(): void
    {
        // 同上:先买再卖,不预置库存(基础仓容 1000,预置 + 买入会撞仓储上限)
        [$user, $city] = $this->makeCity('feeperp', []);
        $this->addModifier($city, -2.0);

        $previous = $this->moneyOf($city);
        for ($i = 0; $i < 8; $i++) {
            $this->actingAs($user)->postJson('/api/market/buy', ['resource_code' => 'food', 'quantity' => 20])->assertOk();
            $this->actingAs($user)->postJson('/api/market/sell', ['resource_code' => 'food', 'quantity' => 20])->assertOk();

            $now = $this->moneyOf($city->fresh());
            $this->assertLessThan($previous, $now, '第 ' . ($i + 1) . ' 轮零费率往返之后资金没有减少 = 出现了永动机');
            $previous = $now;
        }
    }

    // ---------- 登记表 ----------

    public function test_target_is_registered_as_wired_to_trade_service(): void
    {
        $entry = ModifierTarget::CONSUMPTION_POINTS[ModifierTarget::MARKET_FEE_PCT];

        $this->assertTrue($entry['wired'], 'market_fee_pct 必须标记为已接线');
        $this->assertSame('App\Game\Market\TradeService', $entry['consumer']);
        $this->assertTrue(class_exists($entry['consumer']), '登记的消费点必须是**真实存在**的类(接线前登记的 MarketService 从未存在过)');
    }
}
