<?php

namespace Tests\Feature\Market;

use App\Game\Market\MarketDefinition;
use App\Game\Market\PriceEngine;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 后台 API(用户 2026-08-11 铁律②「后台强大」):
// 逐资源的**数值**走 /api/admin/definitions/market(定义表,要 bump 数值版本);
// 全市场级的**开关与系数**走既有的 /api/admin/settings(game_settings,不动数值版本)。
// 两套入口互不重叠 —— 同一个数不允许有两个来源。
// 后台 UI 后置(admin.js 归并行 agent),这一组守的是接口本身。
class MarketAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function admin(string $name = 'mktadmin'): User
    {
        // role 已不可批量赋值,测试里用 forceFill 显式提权
        $user = User::create(['username' => $name, 'name' => $name, 'email' => $name . '@example.com', 'password' => 'password123']);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    private function player(): User
    {
        return User::create(['username' => 'mktplayer', 'name' => 'mktplayer', 'email' => 'mp@example.com', 'password' => 'password123']);
    }

    // ---- 读:28 行全量(§8 的 26 行 + RS027 水泥 / RS028 药品)----

    public function test_admin_can_list_all_market_definitions(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/definitions/market');

        $res->assertOk();
        $this->assertCount(28, $res->json('data.market'));

        $iron = collect($res->json('data.market'))->firstWhere('resource_id', 'iron');
        $this->assertSame('RS012', $iron['rs_code']);
        $this->assertEqualsWithDelta(22.0, (float) $iron['base_price'], 0.0001);
        $this->assertEqualsWithDelta(1364.0, (float) $iron['base_liquidity'], 0.0001);

        // 可编辑字段清单随响应下发,后台不必自己维护一份「哪些能改」
        $this->assertSame(
            ['base_price', 'min_price', 'max_price', 'volatility', 'elasticity', 'fee_rate', 'base_liquidity'],
            $res->json('data.editable')
        );
    }

    // ---- 写:基础价 / 波动率可后台改(任务书铁律②)----

    public function test_admin_can_edit_base_price_and_it_changes_the_live_price(): void
    {
        $epoch = PriceEngine::currentEpoch();
        $priceBefore = PriceEngine::priceFor(MarketDefinition::find('iron'), $epoch);

        $this->actingAs($this->admin())->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'base_price', 'value' => 30.0, 'reason' => '铁价偏低,上调基准',
        ])->assertOk();

        $this->assertEqualsWithDelta(30.0, (float) DB::table('market_definition')->where('resource_id', 'iron')->value('base_price'), 0.0001);

        // 改完立刻对定价生效(缓存已失效)
        MarketDefinition::flush();
        PriceEngine::flushVolumes();
        $this->assertNotEqualsWithDelta($priceBefore, PriceEngine::priceFor(MarketDefinition::find('iron'), $epoch), 0.0001);
    }

    public function test_admin_can_edit_volatility(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'volatility', 'value' => 0.2, 'reason' => '提高铁的波动',
        ])->assertOk();

        $this->assertEqualsWithDelta(0.2, (float) DB::table('market_definition')->where('resource_id', 'iron')->value('volatility'), 0.0001);
    }

    // 改市场数值必须 bump 数值版本 + 写审计(§64 / §65:半年后要回答得了「当时为什么是这个价」)
    public function test_editing_market_definition_audits_and_bumps_data_version(): void
    {
        $versionsBefore = DB::table('game_data_versions')->count();

        $this->actingAs($this->admin())->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'fee_rate', 'value' => 0.05, 'reason' => '上调铁的手续费',
        ])->assertOk();

        $this->assertSame($versionsBefore + 1, DB::table('game_data_versions')->count(), '改市场数值必须递增 game_data_version');

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertSame('market_definition', $audit->entity_type);
        $this->assertSame('iron', $audit->entity_id);
        $this->assertSame('上调铁的手续费', $audit->reason_code);
        $this->assertSame(0.03, (float) json_decode($audit->before_json, true)['fee_rate']);
        $this->assertSame(0.05, (float) json_decode($audit->after_json, true)['fee_rate']);
    }

    // ---- 写:护栏 ----

    // 结构列不可改:改 trade_mode 等于「上市 / 退市」一种资源,属结构性变更,必须走 Seed + 迁移
    public function test_structural_columns_are_not_editable(): void
    {
        // 管理员在循环外只建一个:users.email 有唯一索引,循环里反复 create 会撞唯一键
        $admin = $this->admin();

        foreach (['trade_mode', 'rs_code', 'resource_id', 'first_era', 'market_category'] as $field) {
            $this->actingAs($admin)->postJson('/api/admin/definitions/market', [
                'resource_code' => 'knowledge', 'field' => $field, 'value' => 1, 'reason' => '试图改结构列',
            ])->assertStatus(422);
        }

        $this->assertSame('non_tradeable', DB::table('market_definition')->where('resource_id', 'knowledge')->value('trade_mode'));
    }

    public function test_negative_values_are_rejected(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'fee_rate', 'value' => -0.5, 'reason' => '负费率',
        ])->assertStatus(422);

        // 负费率会让同窗往返立刻转正(净额 = −2·P·q·(s+f),f<0 时可能为正)
        $this->assertEqualsWithDelta(0.03, (float) DB::table('market_definition')->where('resource_id', 'iron')->value('fee_rate'), 0.0001);
    }

    // 逐字段上限:费率不能 ≥1(卖出会变成倒贴),波动率不能 >1
    public function test_per_field_upper_bounds_are_enforced(): void
    {
        $admin = $this->admin();

        foreach ([['fee_rate', 1.5], ['volatility', 2.0], ['elasticity', 50], ['base_price', 9999999]] as [$field, $value]) {
            $this->actingAs($admin)->postJson('/api/admin/definitions/market', [
                'resource_code' => 'iron', 'field' => $field, 'value' => $value, 'reason' => '越界测试',
            ])->assertStatus(422);
        }
    }

    // 跨字段自洽:min_price 不能被改到超过 max_price(夹取区间会变空)
    public function test_min_price_cannot_exceed_max_price(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'min_price', 'value' => 999.0, 'reason' => '把下限顶到上限之上',
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(9.9, (float) DB::table('market_definition')->where('resource_id', 'iron')->value('min_price'), 0.0001);
    }

    // 现货资源的 base_price 不能改成 0:成交额恒为 0 = 该资源变成免费无限领
    public function test_spot_base_price_cannot_be_zeroed(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'base_price', 'value' => 0, 'reason' => '归零测试',
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(22.0, (float) DB::table('market_definition')->where('resource_id', 'iron')->value('base_price'), 0.0001);
    }

    public function test_reason_is_required(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'base_price', 'value' => 25.0,
        ])->assertStatus(422);
    }

    public function test_unknown_resource_returns_404(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/market', [
            'resource_code' => 'unobtanium', 'field' => 'base_price', 'value' => 25.0, 'reason' => '不存在的资源',
        ])->assertStatus(404);
    }

    // ---- 权限 ----

    public function test_players_cannot_read_or_edit_market_definitions(): void
    {
        $player = $this->player();

        $this->actingAs($player)->getJson('/api/admin/definitions/market')->assertStatus(403);
        $this->actingAs($player)->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'base_price', 'value' => 1, 'reason' => '越权尝试',
        ])->assertStatus(403);

        $this->assertEqualsWithDelta(22.0, (float) DB::table('market_definition')->where('resource_id', 'iron')->value('base_price'), 0.0001);
    }

    public function test_guests_cannot_reach_the_admin_market_api(): void
    {
        $this->getJson('/api/admin/definitions/market')->assertStatus(401);
        $this->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'base_price', 'value' => 1, 'reason' => 'x',
        ])->assertStatus(401);
    }

    // ---- 全市场参数走既有的 settings 端点(不另起一套)----

    // 12 条市场设定必须出现在后台设置页,且带上后台渲染数字输入框所需的 min/max 元数据
    public function test_market_settings_are_exposed_through_the_settings_endpoint(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/settings');
        $res->assertOk();

        $settings = collect($res->json('data.settings') ?? $res->json('data'))->keyBy('setting_key');

        foreach ([
            GameSetting::MARKET_ENABLED, GameSetting::MARKET_WINDOW_SECONDS, GameSetting::MARKET_MA_WINDOWS,
            GameSetting::MARKET_SLIPPAGE_COEFFICIENT, GameSetting::MARKET_FEE_RATE_MULTIPLIER,
            GameSetting::MARKET_QUOTA_WINDOW_PCT, GameSetting::MARKET_QUOTA_HOURLY_MULTIPLE,
            GameSetting::MARKET_PRICE_MIN_MULTIPLE, GameSetting::MARKET_PRICE_MAX_MULTIPLE,
            GameSetting::MARKET_LIQUIDITY_MULTIPLIER, GameSetting::MARKET_NOISE_FLOOR_PCT,
            GameSetting::MARKET_MAX_ORDER_QUANTITY,
        ] as $key) {
            $this->assertTrue($settings->has($key), '后台设置页缺少市场参数:' . $key);
        }

        // 数值型必须带闭区间,后台才渲染得出带校验的数字输入框(不必让运营手写 JSON)
        $slippage = $settings[GameSetting::MARKET_SLIPPAGE_COEFFICIENT];
        $this->assertSame('number', $slippage['type']);
        $this->assertSame(0, $slippage['min_value']);
        $this->assertSame(5, $slippage['max_value']);
    }

    // 通过既有 settings 端点改滑点系数,必须立刻生效(全市场参数的端到端链路)。
    //
    // 取 2.5 而不是 2.0 是有意的:JSON 没有「整数值的浮点数」这个概念,
    // 2.0 在序列化时会退化成 2,读回来就是 int —— 那验的是 JSON 的行为,不是设定链路的行为。
    // 带小数位的值才能真正验出「float 原样穿过 HTTP → 校验 → 落库 → 读取」这一整条路
    public function test_changing_slippage_through_settings_endpoint_takes_effect(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::MARKET_SLIPPAGE_COEFFICIENT, 'value' => 2.5, 'reason' => '收紧滑点',
        ])->assertOk();

        GameSetting::flush();
        $this->assertSame(2.5, GameSetting::get(GameSetting::MARKET_SLIPPAGE_COEFFICIENT));

        // 超出登记区间 [0, 5] 的值必须被拒(TYPE_NUMBER 的闭区间校验)
        $this->actingAs($this->admin('mktadmin2'))->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::MARKET_SLIPPAGE_COEFFICIENT, 'value' => 99, 'reason' => '越界',
        ])->assertStatus(422);
    }
}
