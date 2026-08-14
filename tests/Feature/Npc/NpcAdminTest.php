<?php

namespace Tests\Feature\Npc;

use App\Game\Definition\GameDataVersion;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 后台:NPC 定义编辑(改数值 → bump game_data_version)+ 数值型规则参数(TYPE_NUMBER)。
class NpcAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private ?User $adminUser = null;

    // 同一个用例里可能要连发好几次请求,管理员只建一次(users.email 有唯一索引)
    private function admin(): User
    {
        if ($this->adminUser === null) {
            $user = User::create(['username' => 'npcadmin', 'name' => 'npcadmin', 'email' => 'npcadmin@a.com', 'password' => 'password123']);
            // role 已不可批量赋值,测试里用 forceFill 显式提权
            $user->forceFill(['role' => 'admin'])->save();
            $this->adminUser = $user;
        }

        return $this->adminUser;
    }

    // ---- npc_definition 编辑 ----

    public function test_npc_list_returns_rows_and_editable_fields(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/definitions/npcs');

        $res->assertOk();
        $this->assertCount(150, $res->json('data.npcs'));
        $this->assertContains('wage_per_min', $res->json('data.editable'));
        // W14-A 扩列:rarity / name_zh 等已从「只读结构列」转为可编辑
        // (枚举列对 NpcCode 权威来源校验,文本列限长;详见 AdminDefinitionCreateTest)
        $this->assertContains('rarity', $res->json('data.editable'));
        $this->assertContains('name_zh', $res->json('data.editable'));
        // 中文名随响应下发(150 行里靠 code 认不出人);N001~N030 的拟名已由 400001 回填
        $this->assertSame('武岚', collect($res->json('data.npcs'))->firstWhere('npc_id', 'N036')['name_zh']);
        $this->assertSame('伯衡', collect($res->json('data.npcs'))->firstWhere('npc_id', 'N001')['name_zh']);
        // 真正的结构列仍然只读:主键 / 派生键 / 岗位匹配键 / 特性结构
        foreach (['npc_id', 'name_key', 'primary_skill_id', 'trait_json'] as $locked) {
            $this->assertNotContains($locked, $res->json('data.editable'));
        }
    }

    public function test_edit_npc_field_audits_and_bumps_version(): void
    {
        $versionBefore = GameDataVersion::current();
        $countBefore = DB::table('game_data_versions')->count();

        $res = $this->actingAs($this->admin())->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N012', 'field' => 'wage_per_min', 'value' => 9.5, 'reason' => 'NPC 工资平衡',
        ]);

        $res->assertOk();
        $this->assertEqualsWithDelta(9.5, (float) DB::table('npc_definition')->where('npc_id', 'N012')->value('wage_per_min'), 0.001);

        // 改数值必须 bump:定义表内容变了,指纹与版本号都要跟着变(§64 / §65)
        $this->assertSame($countBefore + 1, DB::table('game_data_versions')->count());
        $this->assertNotSame($versionBefore, GameDataVersion::current());

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertSame('npc_definition', $audit->entity_type);
        $this->assertSame('N012', $audit->entity_id);
        $this->assertSame('NPC 工资平衡', $audit->reason_code);
        $this->assertSame(6.0, (float) json_decode($audit->before_json, true)['wage_per_min']);
    }

    // 结构列不给后台入口(W14-A 扩列后仍然锁着的那几个):
    // 改 primary_skill_id 会让岗位匹配整体换一套,改 trait_json 要重新过 ModifierSpec 三重 allowlist ——
    // 两者都属结构性变更,走 Seed + 迁移那条有 diff、可回滚的路。
    // 稀有度改成**非法值**同样被拒(枚举列已开放,但取值 Fail Closed)
    public function test_edit_npc_rejects_non_allowlisted_field(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N012', 'field' => 'primary_skill_id', 'value' => 'SKILL_MINING', 'reason' => '试图改主技能',
        ])->assertStatus(422);
        $this->assertSame('SKILL_PROCESSING', DB::table('npc_definition')->where('npc_id', 'N012')->value('primary_skill_id'));

        $this->actingAs($admin)->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N012', 'field' => 'trait_json', 'value' => '{"specs":[]}', 'reason' => '试图改特性结构',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N012', 'field' => 'rarity', 'value' => 'mythic', 'reason' => '试图填非法稀有度',
        ])->assertStatus(422);

        $this->assertSame('uncommon', DB::table('npc_definition')->where('npc_id', 'N012')->value('rarity'));
    }

    // 等级列必须是 1~10 的整数:填 0 / 3.5 会让曲线查询落空,静默变成「这个 NPC 没有加成」
    public function test_edit_npc_rejects_out_of_range_level(): void
    {
        foreach ([0, 11, 3.5] as $bad) {
            $this->actingAs($this->admin())->postJson('/api/admin/definitions/npc', [
                'npc_id' => 'N012', 'field' => 'initial_skill_level', 'value' => $bad, 'reason' => '越界等级',
            ])->assertStatus(422);
        }

        $this->assertSame(5, (int) DB::table('npc_definition')->where('npc_id', 'N012')->value('initial_skill_level'));
    }

    public function test_edit_npc_rejects_negative_value_and_unknown_npc(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N012', 'field' => 'wage_per_min', 'value' => -1, 'reason' => '负工资',
        ])->assertStatus(422);

        $this->actingAs($this->admin())->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'NXXX', 'field' => 'wage_per_min', 'value' => 1, 'reason' => '不存在的 NPC',
        ])->assertStatus(404);
    }

    public function test_player_cannot_edit_npc_definition(): void
    {
        $player = User::create(['username' => 'plainplayer', 'name' => 'p', 'email' => 'p@p.com', 'password' => 'password123']);

        $this->actingAs($player)->getJson('/api/admin/definitions/npcs')->assertStatus(403);
        $this->actingAs($player)->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N012', 'field' => 'wage_per_min', 'value' => 1, 'reason' => '越权',
        ])->assertStatus(403);
    }

    // ---- 数值型规则参数(TYPE_NUMBER)----

    public function test_settings_list_exposes_number_type_with_range(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/settings');

        $res->assertOk();
        $settings = collect($res->json('data.settings'))->keyBy('setting_key');

        // 31 条 NPC 规则参数全部登记在案(A 区逐行 + 稀有度权重 + 两个救急开关)
        $npcKeys = $settings->keys()->filter(fn ($k) => str_starts_with($k, 'npc_'));
        // 31 条 D1 规则参数 + W11-A 追加的两条 §6.4 合成参数(npc_total_cap / npc_job_mismatch_rate)
        $this->assertCount(33, $npcKeys);

        $row = $settings[GameSetting::NPC_XP_PER_MIN];
        $this->assertSame('number', $row['type']);
        $this->assertSame(10, $row['value']);
        // 前端的通用数字控件靠这两个字段渲染 min/max,服务端才是权威
        $this->assertSame(0, $row['min_value']);
        $this->assertSame(100000, $row['max_value']);
    }

    public function test_number_setting_can_be_updated_within_range(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/admin/settings', [
            'setting_key' => GameSetting::NPC_MORALE_LEAVE_THRESHOLD, 'value' => 45, 'reason' => '提高离职门槛',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.after', 45);
        GameSetting::flush();
        $this->assertSame(45, GameSetting::get(GameSetting::NPC_MORALE_LEAVE_THRESHOLD));

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertSame('game_setting', $audit->entity_type);
    }

    // 闭区间是服务端权威:前端校验只是少一次往返,绕过前端照样会被拒
    public function test_number_setting_rejects_out_of_range_and_non_numeric(): void
    {
        foreach ([-1, 101] as $bad) {
            $this->actingAs($this->admin())->postJson('/api/admin/settings', [
                'setting_key' => GameSetting::NPC_MORALE_LEAVE_THRESHOLD, 'value' => $bad, 'reason' => '越界',
            ])->assertStatus(422);
        }

        // 字符串数字 / 布尔一律拒绝,不做模糊解释(与 resource_map 同一纪律)
        foreach (['30', true, null, [1]] as $bad) {
            $this->actingAs($this->admin())->postJson('/api/admin/settings', [
                'setting_key' => GameSetting::NPC_MORALE_LEAVE_THRESHOLD, 'value' => $bad, 'reason' => '类型不符',
            ])->assertStatus(422);
        }

        GameSetting::flush();
        $this->assertSame(30, GameSetting::get(GameSetting::NPC_MORALE_LEAVE_THRESHOLD));
    }

    // 库里存的是脏值时读取回退登记默认值(Fail Safe:规则参数读不出来时维持默认行为)
    public function test_dirty_number_value_falls_back_to_default(): void
    {
        DB::table('game_settings')->where('setting_key', GameSetting::NPC_XP_PER_MIN)
            ->update(['value_json' => json_encode('not-a-number')]);
        GameSetting::flush();

        $this->assertSame(10, GameSetting::get(GameSetting::NPC_XP_PER_MIN));
    }
}
