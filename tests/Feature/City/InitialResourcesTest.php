<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimConstants;
use App\Models\City;
use App\Models\User;
use App\Support\GameSetting;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 建城初始资源改为后台可配(game_settings.initial_resources,用户 2026-08-10 拍板)。
//
// 覆盖:
//   - 新城按设定发资源(含 money 与 knowledge)
//   - 改设定只影响此后新建的城,老城一行不动
//   - 缺行 → 回退 SimConstants 硬编码随机区间(接入设定前的历史行为)
//   - 脏值 → 回退登记默认值(Fail Safe,脏配置不得改变开局)
//   - **新号硬锁解除回归**:注册完直接能研究时代 I 科技
//   - 恶意输入(未知资源码 / 容量类 code / 负数 / 巨数 / 嵌套 / 字符串 / 数组 / 空对象)一律 422
class InitialResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function makeUser(string $username): User
    {
        return User::create([
            'username' => $username, 'name' => $username,
            'email' => $username . '@example.com', 'password' => 'password123',
        ]);
    }

    private function admin(string $username): User
    {
        $user = $this->makeUser($username);
        $user->forceFill(['role' => Role::ADMIN])->save();

        return $user;
    }

    private function amountOf(City $city, string $resourceId): ?float
    {
        $value = DB::table('city_resources')
            ->where('city_id', $city->id)->where('resource_id', $resourceId)->value('amount');

        return $value === null ? null : (float) $value;
    }

    // ---------- 设定本身 ----------

    // 迁移已把这一项灌进 game_settings,且默认值含 knowledge 100(测试期解除新号硬锁的关键)
    public function test_setting_is_registered_with_knowledge_in_default(): void
    {
        $this->assertSame(1, DB::table('game_settings')
            ->where('setting_key', GameSetting::INITIAL_RESOURCES)->count());

        $default = GameSetting::get(GameSetting::INITIAL_RESOURCES);
        $this->assertSame(GameSetting::INITIAL_RESOURCES_DEFAULT, $default);
        $this->assertSame(100, $default[ResourceCode::KNOWLEDGE]);
        $this->assertArrayHasKey(ResourceCode::MONEY, $default);
    }

    // ---------- 建城读设定 ----------

    public function test_new_city_uses_configured_resources(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        GameSetting::set(GameSetting::INITIAL_RESOURCES, [
            ResourceCode::MONEY     => 777,
            ResourceCode::WOOD      => 55,
            ResourceCode::KNOWLEDGE => 42,
        ], null, '测试期调整开局资源');

        $city = CityFactory::createForUser($this->makeUser('initcfg1'));

        $this->assertEqualsWithDelta(777.0, (float) $city->money, 0.0001);
        $this->assertEqualsWithDelta(55.0, $this->amountOf($city, ResourceCode::WOOD), 0.0001);
        $this->assertEqualsWithDelta(42.0, $this->amountOf($city, ResourceCode::KNOWLEDGE), 0.0001);
        // 配置里没写的资源不会凭空出现(不与硬编码默认混着发)
        $this->assertNull($this->amountOf($city, ResourceCode::FOOD));
        $this->assertNull($this->amountOf($city, ResourceCode::STONE));
        // money 是 cities 列,不进 city_resources
        $this->assertNull($this->amountOf($city, ResourceCode::MONEY));

        // 审计留下这一局到底按哪套配置发的
        $audit = DB::table('audit_logs')->where('action', 'CITY.CREATE')->latest('id')->first();
        $metadata = json_decode($audit->metadata_json, true);
        $this->assertSame('game_setting', $metadata['initial_resources_source']);
        $this->assertSame(55, $metadata['resources'][ResourceCode::WOOD]);
    }

    // 改设定只影响此后新建的城:老城的存量一行都不许动(改配置 ≠ 全服补发)
    public function test_changing_setting_only_affects_new_cities(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $old = CityFactory::createForUser($this->makeUser('initold'));
        $oldWood = $this->amountOf($old, ResourceCode::WOOD);
        $oldMoney = (float) $old->money;

        GameSetting::set(GameSetting::INITIAL_RESOURCES, [
            ResourceCode::MONEY => 1, ResourceCode::WOOD => 7,
        ], null, '把开局资源调到很低');

        $new = CityFactory::createForUser($this->makeUser('initnew'));

        $this->assertEqualsWithDelta(7.0, $this->amountOf($new, ResourceCode::WOOD), 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $new->money, 0.0001);

        $this->assertEqualsWithDelta($oldWood, $this->amountOf($old->fresh(), ResourceCode::WOOD), 0.0001);
        $this->assertEqualsWithDelta($oldMoney, (float) $old->fresh()->money, 0.0001);
    }

    // 缺行(库比代码旧 / 被人删了)→ 回退硬编码随机区间,即接入设定前的历史行为
    public function test_missing_row_falls_back_to_hardcoded_defaults(): void
    {
        DB::table('game_settings')->where('setting_key', GameSetting::INITIAL_RESOURCES)->delete();
        GameSetting::flush();

        $city = CityFactory::createForUser($this->makeUser('initmissing'));

        foreach (SimConstants::START_RESOURCES as $resourceId => [$lo, $hi]) {
            $amount = $this->amountOf($city, $resourceId);
            $this->assertNotNull($amount, "{$resourceId} 应按硬编码区间发放");
            $this->assertGreaterThanOrEqual($lo, $amount);
            $this->assertLessThanOrEqual($hi, $amount);
        }
        $this->assertGreaterThanOrEqual((float) SimConstants::START_MONEY[0], (float) $city->money);
        $this->assertLessThanOrEqual((float) SimConstants::START_MONEY[1], (float) $city->money);
        // 硬编码回退里没有知识 —— 这正是「新号硬锁」的历史形态
        $this->assertNull($this->amountOf($city, ResourceCode::KNOWLEDGE));

        $audit = DB::table('audit_logs')->where('action', 'CITY.CREATE')->latest('id')->first();
        $this->assertSame('default', json_decode($audit->metadata_json, true)['initial_resources_source']);
    }

    // 脏值(合法 JSON 但形状不对)→ 回退登记默认值,不让脏配置改变开局
    public function test_dirty_value_falls_back_to_registered_default(): void
    {
        foreach ([['unobtanium' => 5], [1, 2, 3], 'hello', 12] as $index => $dirty) {
            DB::table('game_settings')
                ->where('setting_key', GameSetting::INITIAL_RESOURCES)
                ->update(['value_json' => json_encode($dirty)]);
            GameSetting::flush();

            $this->assertSame(GameSetting::INITIAL_RESOURCES_DEFAULT, GameSetting::get(GameSetting::INITIAL_RESOURCES));

            $city = CityFactory::createForUser($this->makeUser('initdirty' . $index));
            $this->assertEqualsWithDelta(100.0, $this->amountOf($city, ResourceCode::KNOWLEDGE), 0.0001);
            $this->assertEqualsWithDelta(300.0, $this->amountOf($city, ResourceCode::WOOD), 0.0001);
        }
    }

    // ---------- 新号硬锁解除(回归)----------

    // STATUS「待用户拍板 §1」:新城没有 knowledge、时代 I 又没有产知识的建筑 →
    // 新注册账号研究不了任何科技、也就建不了任何建筑。默认送 100 知识后,注册完必须能直接研究。
    public function test_fresh_account_can_research_immediately(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $this->postJson('/api/auth/register', [
            'username' => 'lockfree', 'email' => 'lockfree@example.com', 'password' => 'password123',
        ])->assertStatus(201);

        $user = User::where('username', 'lockfree')->firstOrFail();
        $city = City::where('user_id', $user->id)->firstOrFail();
        $this->assertEqualsWithDelta(100.0, $this->amountOf($city, ResourceCode::KNOWLEDGE), 0.0001);

        // TECH_I_SUST「生存采集」:时代 I、无前置、20 知识 → 解锁 F01,新号的第一步
        $this->actingAs($user, 'web')->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])->assertOk();

        $this->assertEqualsWithDelta(80.0, $this->amountOf($city, ResourceCode::KNOWLEDGE), 0.0001);
        $this->assertSame(1, DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', 'TECH_I_SUST')->count());
    }

    // ---------- 后台编辑对象型设定 ----------

    public function test_admin_can_edit_object_setting(): void
    {
        $admin = $this->admin('initadmin');

        // 列表下发编辑器元数据:可选键清单 + 数量上限(后台据此渲染键/值表格,不必手写 JSON)
        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/settings');
        $res->assertOk();
        $row = collect($res->json('data.settings'))->firstWhere('setting_key', GameSetting::INITIAL_RESOURCES);
        $this->assertSame('resource_map', $row['type']);
        $this->assertSame(GameSetting::MAX_RESOURCE_AMOUNT, $row['max_value']);
        $this->assertContains(ResourceCode::KNOWLEDGE, array_column($row['options'], 'code'));
        // 容量类不是库存资源,不能出现在可选键里
        $this->assertNotContains(ResourceCode::GOVERNANCE_CAPACITY, array_column($row['options'], 'code'));

        $payload = [ResourceCode::MONEY => 500, ResourceCode::FOOD => 250, ResourceCode::KNOWLEDGE => 120];
        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::INITIAL_RESOURCES,
            'value'       => $payload,
            'reason'      => '测试期上调开局知识',
        ])->assertOk()->assertJson(['data' => ['after' => $payload]]);

        GameSetting::flush();
        $this->assertSame($payload, GameSetting::get(GameSetting::INITIAL_RESOURCES));

        // 审计:before/after 都记的是整张映射(改配置属 ADMIN.CONFIG_CHANGE)
        $audit = DB::table('audit_logs')->where('action', 'ADMIN.CONFIG_CHANGE')->latest('id')->first();
        $this->assertSame('game_setting', $audit->entity_type);
        $this->assertSame(GameSetting::INITIAL_RESOURCES, $audit->entity_id);
        $this->assertSame($payload, json_decode($audit->after_json, true)[GameSetting::INITIAL_RESOURCES]);
        $this->assertSame(
            GameSetting::INITIAL_RESOURCES_DEFAULT,
            json_decode($audit->before_json, true)[GameSetting::INITIAL_RESOURCES]
        );
    }

    // 恶意 / 填错的输入一律 422,且一个字节都不许落库(CLAUDE §45 allowlist)
    public function test_admin_rejects_malicious_object_values(): void
    {
        $admin = $this->admin('initadmin2');
        $before = DB::table('game_settings')
            ->where('setting_key', GameSetting::INITIAL_RESOURCES)->value('value_json');

        $cases = [
            '未知资源码'   => ['unobtanium' => 5],
            '容量类 code'  => [ResourceCode::GOVERNANCE_CAPACITY => 5],
            '负数'         => [ResourceCode::WOOD => -1],
            '巨数'         => [ResourceCode::WOOD => 1000000000],
            '嵌套对象'     => [ResourceCode::WOOD => ['amount' => 10]],
            '字符串数量'   => [ResourceCode::WOOD => '100'],
            '布尔数量'     => [ResourceCode::WOOD => true],
            '数组不是对象' => [1, 2, 3],
            '空对象'       => [],
            '纯量'         => 'oops',
        ];

        foreach ($cases as $label => $value) {
            $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
                'setting_key' => GameSetting::INITIAL_RESOURCES,
                'value'       => $value,
                'reason'      => '恶意输入:' . $label,
            ])->assertStatus(422);

            $this->assertSame($before, DB::table('game_settings')
                ->where('setting_key', GameSetting::INITIAL_RESOURCES)->value('value_json'), $label . ' 不该落库');
        }
    }

    // 加了对象型设定之后,布尔开关的既有行为一点不变(不回归)
    public function test_boolean_settings_not_regressed(): void
    {
        $admin = $this->admin('initadmin3');

        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => false, 'reason' => '对象型上线后复测布尔开关',
        ])->assertOk()->assertJson(['data' => ['before' => true, 'after' => false]]);

        GameSetting::flush();
        $this->assertFalse(GameSetting::get(GameSetting::WORKER_GATE_ENABLED));

        // 布尔开关仍然只收真正的 true/false
        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::WORKER_GATE_ENABLED, 'value' => ['wood' => 1], 'reason' => '对象值塞进布尔开关',
        ])->assertStatus(422);

        // 对象型设定也不接受布尔值
        $this->actingAs($admin, 'admin')->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::INITIAL_RESOURCES, 'value' => true, 'reason' => '布尔塞进对象型设定',
        ])->assertStatus(422);
    }
}
