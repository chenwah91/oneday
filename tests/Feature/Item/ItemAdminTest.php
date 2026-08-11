<?php

namespace Tests\Feature\Item;

use App\Game\Definition\GameDataVersion;
use App\Game\Item\ItemDefinition;
use App\Game\Modifier\ModifierTarget;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 后台:工具定义编辑(改数值 → bump game_data_version)+ 6 条工具规则参数。
class ItemAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private ?User $adminUser = null;

    // 同一个用例里可能要连发好几次请求,管理员只建一次(users.email 有唯一索引)
    private function admin(): User
    {
        if ($this->adminUser === null) {
            $user = User::create(['username' => 'itemadmin', 'name' => 'itemadmin', 'email' => 'itemadmin@a.com', 'password' => 'password123']);
            // role 已不可批量赋值,测试里用 forceFill 显式提权
            $user->forceFill(['role' => 'admin'])->save();
            $this->adminUser = $user;
        }

        return $this->adminUser;
    }

    public function test_item_list_returns_rows_and_editable_fields(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/definitions/items');

        $res->assertOk();
        $this->assertCount(24, $res->json('data.items'));
        $this->assertContains('effect_value', $res->json('data.editable'));
        // 结构列只读下发,供后台显示,不出现在 editable 里
        $this->assertNotContains('category', $res->json('data.editable'));
        $this->assertNotContains('durability_tier', $res->json('data.editable'));
    }

    public function test_edit_item_field_audits_and_bumps_version(): void
    {
        $countBefore = DB::table('game_data_versions')->count();

        $res = $this->actingAs($this->admin())->postJson('/api/admin/definitions/item', [
            'item_id' => 'IT001', 'field' => 'durability', 'value' => 90, 'reason' => '工具耐久平衡',
        ]);

        $res->assertOk();
        $this->assertSame(90, (int) DB::table('item_definition')->where('item_id', 'IT001')->value('durability'));

        // 改数值必须 bump:定义表内容变了,指纹与版本号都要跟着变(§64 / §65)
        $this->assertSame($countBefore + 1, DB::table('game_data_versions')->count());

        $audit = DB::table('audit_logs')->where('entity_type', 'item_definition')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('工具耐久平衡', $audit->reason_code);
        $this->assertSame(60, (int) json_decode($audit->before_json, true)['durability']);
        $this->assertSame(90, (int) json_decode($audit->after_json, true)['durability']);
    }

    // 关键一条:effect_value 与 effect_json.specs 是同一个数的两种写法。
    // 只改前者就会变成「后台改了没反应」(= M.3 的 governance_bonus 双口径),必须同步
    public function test_editing_effect_value_rewrites_the_specs(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/item', [
            'item_id' => 'IT001', 'field' => 'effect_value', 'value' => 20, 'reason' => '木材工具增强',
        ])->assertOk();

        ItemDefinition::flush();
        $specs = ItemDefinition::find('IT001')['specs'];

        $this->assertCount(1, $specs);
        $this->assertEqualsWithDelta(0.20, $specs[0]->value, 0.0001, 'specs 没跟着 effect_value 一起改 = 后台改了没反应');
        $this->assertSame(ModifierTarget::SLOT_TOOL, $specs[0]->target);
        $this->assertSame('wood', $specs[0]->scopeKey);
    }

    // 减免类效果的 spec 是负值(维护成本 -8%),改数值时**符号必须保留**,
    // 否则「降低维护」会变成「提高维护」
    public function test_editing_effect_value_preserves_negative_specs(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/item', [
            'item_id' => 'IT016', 'field' => 'effect_value', 'value' => 12, 'reason' => '维护减免增强',
        ])->assertOk();

        ItemDefinition::flush();
        $specs = ItemDefinition::find('IT016')['specs'];

        $this->assertEqualsWithDelta(-0.12, $specs[0]->value, 0.0001);
        $this->assertSame(ModifierTarget::MAINTENANCE_COST_PCT, $specs[0]->target);
    }

    public function test_edit_item_rejects_non_editable_field(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/item', [
            'item_id' => 'IT001', 'field' => 'category', 'value' => 1, 'reason' => '越权改结构',
        ])->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);

        $this->assertSame('gathering_tool', DB::table('item_definition')->where('item_id', 'IT001')->value('category'));
    }

    public function test_edit_item_rejects_out_of_range_and_fractional_durability(): void
    {
        // 超上限
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/item', [
            'item_id' => 'IT001', 'field' => 'effect_value', 'value' => 99999, 'reason' => '超范围',
        ])->assertStatus(422);

        // 耐久必须是 ≥1 的整数
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/item', [
            'item_id' => 'IT001', 'field' => 'durability', 'value' => 2.5, 'reason' => '小数耐久',
        ])->assertStatus(422);

        $this->assertSame(60, (int) DB::table('item_definition')->where('item_id', 'IT001')->value('durability'));
    }

    public function test_edit_item_rejects_unknown_item(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/item', [
            'item_id' => 'IT999', 'field' => 'durability', 'value' => 10, 'reason' => '不存在的工具',
        ])->assertStatus(404);
    }

    // 普通玩家不能碰后台(CLAUDE §83)
    public function test_player_cannot_edit_item_definition(): void
    {
        $player = User::create(['username' => 'itemplayer', 'name' => 'p', 'email' => 'itemplayer@a.com', 'password' => 'password123']);

        $this->actingAs($player)->postJson('/api/admin/definitions/item', [
            'item_id' => 'IT001', 'field' => 'durability', 'value' => 10, 'reason' => '越权',
        ])->assertStatus(403);
    }

    // ---- 6 条工具规则参数(TYPE_NUMBER / TYPE_BOOL)----

    public function test_item_settings_are_listed_with_range_metadata(): void
    {
        $rows = collect($this->actingAs($this->admin())->getJson('/api/admin/settings')->json('data.settings'))
            ->keyBy('setting_key');

        foreach ([GameSetting::ITEM_SLOTS_PER_BUILDING, GameSetting::ITEM_DURABILITY_MINUTES_NORMAL,
            GameSetting::ITEM_DURABILITY_MINUTES_INDUSTRIAL, GameSetting::ITEM_DURABILITY_WARNING_PCT] as $key) {
            $this->assertArrayHasKey($key, $rows, $key . ' 没出现在后台设置页');
            $this->assertSame(GameSetting::TYPE_NUMBER, $rows[$key]['type']);
            // 数值型必须带闭区间,后台据此渲染带范围校验的数字输入
            $this->assertNotNull($rows[$key]['min_value']);
            $this->assertNotNull($rows[$key]['max_value']);
        }

        foreach ([GameSetting::ITEM_CRAFT_ENABLED, GameSetting::ITEM_DURABILITY_ENABLED] as $key) {
            $this->assertSame(GameSetting::TYPE_BOOL, $rows[$key]['type']);
        }
    }

    public function test_item_setting_write_is_range_checked_and_audited(): void
    {
        // 超出登记区间 → 拒绝
        $this->actingAs($this->admin())->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::ITEM_SLOTS_PER_BUILDING, 'value' => 999, 'reason' => '超范围',
        ])->assertStatus(422);

        $this->actingAs($this->admin())->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::ITEM_SLOTS_PER_BUILDING, 'value' => 4, 'reason' => '扩槽',
        ])->assertOk();

        GameSetting::flush();
        $this->assertSame(4, GameSetting::get(GameSetting::ITEM_SLOTS_PER_BUILDING));

        // 规则开关改动不动数值版本(它不是 Definition)
        $version = GameDataVersion::current();
        GameSetting::flush();
        $this->assertSame($version, GameDataVersion::current());
    }
}
