<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// W11-B 定义编辑器扩容的验收面。
//
// 新开五个入口(建筑等级 JSON 条目 / 科技 / 建筑上限 / NPC 等级曲线 / 时代门槛),
// 每一个都必须逐条复用既有的十一步流水线 —— 所以每个编辑器至少验四层:
//   ① 改成功 + game_data_version 递增(§64 / §65:半年后要回答得了「当时是多少」);
//   ② 越权(普通玩家)403 —— 定义数值是全服级改动,权限是第一道门;
//   ③ allowlist 之外的字段 422 —— **每个编辑器锁的都是「结构」列**,理由各不相同,逐个钉死;
//   ④ 上限之外 422 —— 每条上限都对应一种「填错就打穿」的具体后果。
//
// 另外三条补漏(市场 trade_mode 停市 / 复市、事件停用原因、工具只读列)一并在这里守。
class AdminDefinitionExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(string $un = 'w11badmin'): User
    {
        // role 已不可批量赋值,测试里用 forceFill 显式提权
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    // 本文件里 player 的用途只有一个:验「非后台角色一律 403」。
    // 调用点一律写 actingAs($this->player(), 'admin') —— 后台自 2026-08-15 起走独立会话,
    // 只有玩家会话的请求会在 auth:admin 就被挡成 401(那条路径在 AdminAccessTest 单独验),
    // 这里要落到 EnsureAdmin 的角色闸门上才验得到 403
    private function player(string $un = 'w11bplayer'): User
    {
        return User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
    }

    private function versions(): int
    {
        return DB::table('game_data_versions')->count();
    }

    // ==================== 任务1:building_level 三个 JSON 列的条目级编辑 ====================

    // 产出速率:改的是 output_json 里那一条 specs 的 rate_per_min,**其余条目与字段原样**
    public function test_edit_output_json_entry_bumps_version_and_audits_the_exact_cell(): void
    {
        $before = $this->versions();

        $res = $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/building-level-json', [
            'building_id' => 'F01', 'level' => 1, 'column' => 'output_json',
            'resource' => 'berries', 'value' => 12.5, 'reason' => '采集营地产量偏低',
        ])->assertOk();

        $this->assertEqualsWithDelta(8.0, (float) $res->json('data.before'), 1e-6);
        $this->assertEqualsWithDelta(12.5, (float) $res->json('data.after'), 1e-6);
        $this->assertSame($before + 1, $this->versions(), '改 JSON 条目同样要递增 game_data_version');

        // 回写的 JSON 仍是原来的形状:一条 specs、resource 键没变、rate_per_min 换成新值
        $json = json_decode((string) DB::table('building_level_definition')
            ->where('building_id', 'F01')->where('level', 1)->value('output_json'), true);
        $this->assertCount(1, $json);
        $this->assertSame('berries', $json[0]['resource']);
        $this->assertEqualsWithDelta(12.5, (float) $json[0]['rate_per_min'], 1e-6);

        // 审计定位到**具体那一格**:只写 ['output_json' => 整段] 的话,回查得靠人眼 diff 两段 JSON
        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertSame('building_level_definition', $audit->entity_type);
        $this->assertSame('F01:1', $audit->entity_id);
        $this->assertEqualsWithDelta(8.0, (float) json_decode($audit->before_json, true)['output_json.berries'], 1e-6);
        $this->assertEqualsWithDelta(12.5, (float) json_decode($audit->after_json, true)['output_json.berries'], 1e-6);
    }

    // 建造成本是**映射**形状({wood:10, money:6}),与 output/input 的列表形状不同,单独验一遍
    public function test_edit_cost_json_entry_keeps_the_other_keys(): void
    {
        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/building-level-json', [
            'building_id' => 'F01', 'level' => 1, 'column' => 'cost_json',
            'resource' => 'wood', 'value' => 25, 'reason' => '提高采集营地木材成本',
        ])->assertOk();

        $json = json_decode((string) DB::table('building_level_definition')
            ->where('building_id', 'F01')->where('level', 1)->value('cost_json'), true);

        $this->assertEqualsWithDelta(25.0, (float) $json['wood'], 1e-6);
        // 同一列里的其它键一个都不能动
        $this->assertEqualsWithDelta(6.0, (float) $json['money'], 1e-6);
    }

    // 三个护栏:列不在 allowlist / 资源 code 未登记 / 条目不存在(= 新增条目,属结构性变更)
    public function test_json_editor_rejects_column_resource_and_missing_entry(): void
    {
        $admin = $this->admin();

        // ① 只开放三个 JSON 列 —— cost_type 之类的普通列不走这个入口
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level-json', [
            'building_id' => 'F01', 'level' => 1, 'column' => 'cost_type',
            'resource' => 'wood', 'value' => 1, 'reason' => '试图改别的列',
        ])->assertStatus(422);

        // ② 未登记的资源 code:写进去就是一条**永远读不到**的配置(结算按 ResourceCode 查表)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level-json', [
            'building_id' => 'F01', 'level' => 1, 'column' => 'output_json',
            'resource' => 'unobtainium', 'value' => 5, 'reason' => '试图造一个资源',
        ])->assertStatus(422);

        // ③ 条目不存在 = 新增条目 = 改这栋楼产什么,属结构性变更,必须走迁移
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level-json', [
            'building_id' => 'F01', 'level' => 1, 'column' => 'output_json',
            'resource' => 'iron', 'value' => 5, 'reason' => '试图让采集营地产铁',
        ])->assertStatus(422);

        // 三次都不许改到库
        $json = json_decode((string) DB::table('building_level_definition')
            ->where('building_id', 'F01')->where('level', 1)->value('output_json'), true);
        $this->assertCount(1, $json);
        $this->assertSame('berries', $json[0]['resource']);
    }

    public function test_json_editor_enforces_per_column_max(): void
    {
        // 速率上限 1e6:再高会让一栋楼一分钟填满仓库
        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/building-level-json', [
            'building_id' => 'F01', 'level' => 1, 'column' => 'output_json',
            'resource' => 'berries', 'value' => 1000001, 'reason' => '试图爆产量',
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(8.0, (float) json_decode((string) DB::table('building_level_definition')
            ->where('building_id', 'F01')->where('level', 1)->value('output_json'), true)[0]['rate_per_min'], 1e-6);
    }

    public function test_json_editor_requires_edit_definition_permission(): void
    {
        $this->actingAs($this->player(), 'admin')->postJson('/api/admin/definitions/building-level-json', [
            'building_id' => 'F01', 'level' => 1, 'column' => 'output_json',
            'resource' => 'berries', 'value' => 12, 'reason' => '普通玩家试图改定义',
        ])->assertStatus(403);
    }

    // 七个裸奔列补上上限(W11-B):此前只有 min:0
    public function test_building_level_numeric_columns_have_upper_bounds(): void
    {
        $admin = $this->admin();

        // 工期上限 7 天
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level', [
            'buildingId' => 'F02', 'level' => 1, 'field' => 'duration_seconds', 'value' => 604801, 'reason' => '试图无限工期',
        ])->assertStatus(422);

        // 两个 int 列必须收整数:小数写进 int 列会被静默截断
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level', [
            'buildingId' => 'F02', 'level' => 1, 'field' => 'worker_required', 'value' => 3.5, 'reason' => '试图填小数工人',
        ])->assertStatus(422);

        // 上限之内仍然放行(护栏不能把正常调整也拦掉)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level', [
            'buildingId' => 'F02', 'level' => 1, 'field' => 'duration_seconds', 'value' => 600, 'reason' => '缩短工期',
        ])->assertOk();
    }

    // ==================== 任务2a:科技定义 ====================

    public function test_technology_list_and_edit(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/definitions/technologies')->assertOk();
        $this->assertSame(['knowledge_cost', 'research_minutes'], $res->json('data.editable'));
        // 拓扑列只读下发,供后台判断「改贵了会卡住后面哪几条」
        $first = $res->json('data.technologies.0');
        $this->assertArrayHasKey('prerequisite_tech_ids', $first);
        $this->assertArrayHasKey('unlock_building_ids', $first);
        $this->assertNotContains('prerequisite_tech_ids', $res->json('data.editable'));

        $techId = $first['tech_id'];
        $before = $this->versions();

        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology', [
            'tech_id' => $techId, 'field' => 'knowledge_cost', 'value' => 123, 'reason' => '下调早期科技成本',
        ])->assertOk();

        $this->assertSame(123, (int) DB::table('technology_definition')->where('tech_id', $techId)->value('knowledge_cost'));
        $this->assertSame($before + 1, $this->versions());

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('technology_definition', $audit->entity_type);
        $this->assertSame($techId, $audit->entity_id);
    }

    // 科技树拓扑一律不可编辑:改前置会造出环 / 让已解锁科技变成"前置未满足"
    public function test_technology_topology_columns_are_not_editable(): void
    {
        $admin = $this->admin();
        $techId = (string) DB::table('technology_definition')->orderBy('tech_id')->value('tech_id');

        foreach (['prerequisite_tech_ids', 'unlock_building_ids', 'era_key', 'branch', 'name'] as $field) {
            $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology', [
                'tech_id' => $techId, 'field' => $field, 'value' => 1, 'reason' => '试图改科技树拓扑',
            ])->assertStatus(422);
        }
    }

    public function test_technology_bounds_and_permission(): void
    {
        $admin = $this->admin();
        $techId = (string) DB::table('technology_definition')->orderBy('tech_id')->value('tech_id');

        // 研究时长上限一周
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology', [
            'tech_id' => $techId, 'field' => 'research_minutes', 'value' => 10081, 'reason' => '试图无限研究',
        ])->assertStatus(422);

        // 知识成本是 int 列,小数会被静默截断
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology', [
            'tech_id' => $techId, 'field' => 'knowledge_cost', 'value' => 100.5, 'reason' => '试图填小数',
        ])->assertStatus(422);

        // 不存在的科技
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology', [
            'tech_id' => 'TECH_NOPE', 'field' => 'knowledge_cost', 'value' => 10, 'reason' => '不存在的科技',
        ])->assertStatus(404);

        $this->actingAs($this->player(), 'admin')->getJson('/api/admin/definitions/technologies')->assertStatus(403);
    }

    // ==================== 任务2b:建筑定义 ====================

    public function test_building_list_and_max_count_edit(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/definitions/buildings')->assertOk();
        $this->assertSame(['max_count'], $res->json('data.editable'));
        // 占地只读下发:调 max_count 得知道「这栋楼一个占几格」
        $this->assertArrayHasKey('footprint_w', $res->json('data.buildings.0'));

        $before = $this->versions();
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building', [
            'building_id' => 'F01', 'field' => 'max_count', 'value' => 12, 'reason' => '放宽采集营地上限',
        ])->assertOk();

        $this->assertSame(12, (int) DB::table('building_definition')->where('building_id', 'F01')->value('max_count'));
        $this->assertSame($before + 1, $this->versions());
    }

    // max_count = 0 会让**已建成**的实例当场变成非法(而存量不会被重新校验),所以下限是 1 不是 0
    public function test_building_max_count_rejects_zero_and_footprint(): void
    {
        $admin = $this->admin();
        $before = (int) DB::table('building_definition')->where('building_id', 'F01')->value('max_count');

        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building', [
            'building_id' => 'F01', 'field' => 'max_count', 'value' => 0, 'reason' => '试图停售',
        ])->assertStatus(422);

        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building', [
            'building_id' => 'F01', 'field' => 'max_count', 'value' => 10001, 'reason' => '试图取消上限',
        ])->assertStatus(422);

        // 占地绝不开放:改大一格会让所有存量建筑瞬间互相重叠
        foreach (['footprint_w', 'footprint_h'] as $field) {
            $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building', [
                'building_id' => 'F01', 'field' => $field, 'value' => 3, 'reason' => '试图改占地',
            ])->assertStatus(422);
        }

        $this->assertSame($before, (int) DB::table('building_definition')->where('building_id', 'F01')->value('max_count'));
    }

    // 死列裁决落地(用户 2026-08-13「按建议删」):五个真死列已物理删除(2026_08_13_300001),
    // upgrade_to_building_id 保留(跨代升级链的数据地基,有 EnumCodeTest 整套守护)但仍不可编辑。
    // 本用例钉两件事:①五列确实不存在了(防止将来被无意加回);②保留列仍被 allowlist 挡住
    public function test_dead_columns_dropped_and_kept_column_stays_read_only(): void
    {
        foreach ([
            'population_min', 'governance_ratio_min', 'happiness_min',
            'base_workers', 'base_build_seconds',
        ] as $column) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('building_definition', $column),
                "死列 {$column} 应已被 2026_08_13_300001 删除"
            );
        }

        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/building', [
            'building_id' => 'F01', 'field' => 'upgrade_to_building_id', 'value' => 1, 'reason' => '试图改升级链',
        ])->assertStatus(422);
    }

    public function test_building_editor_requires_permission(): void
    {
        $this->actingAs($this->player(), 'admin')->postJson('/api/admin/definitions/building', [
            'building_id' => 'F01', 'field' => 'max_count', 'value' => 5, 'reason' => '普通玩家试图改定义',
        ])->assertStatus(403);
    }

    // ==================== 任务2c:NPC 等级曲线 ====================

    public function test_npc_skill_curve_list_and_edit(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/definitions/npc-skill-curve')->assertOk();
        $this->assertCount(10, $res->json('data.curve'), '§6.2 曲线恒 10 级,整表不分页');
        $this->assertSame(['xp_to_next', 'primary_bonus', 'maintenance_reduction_cap'], $res->json('data.editable'));

        $before = $this->versions();
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc-skill-curve', [
            'level' => 5, 'field' => 'primary_bonus', 'value' => 0.25, 'reason' => '中段曲线偏平',
        ])->assertOk();

        $this->assertEqualsWithDelta(0.25, (float) DB::table('npc_skill_level_curve')->where('level', 5)->value('primary_bonus'), 1e-6);
        $this->assertSame($before + 1, $this->versions());
    }

    public function test_npc_skill_curve_guards(): void
    {
        $admin = $this->admin();

        // 主键 level 绝不开放:改它会让 city_npcs.skill_level 指向的那一级查不到
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc-skill-curve', [
            'level' => 5, 'field' => 'level', 'value' => 6, 'reason' => '试图搬走一级',
        ])->assertStatus(422);

        // 主技能加成上限 0.9:再高会让单个 NPC 顶爆 §6.4 的单人帽,调了也没反应
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc-skill-curve', [
            'level' => 5, 'field' => 'primary_bonus', 'value' => 1.5, 'reason' => '试图爆帽',
        ])->assertStatus(422);

        // 经验是 unsignedInteger 列
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc-skill-curve', [
            'level' => 5, 'field' => 'xp_to_next', 'value' => 100.5, 'reason' => '试图填小数',
        ])->assertStatus(422);

        $this->actingAs($this->player(), 'admin')->getJson('/api/admin/definitions/npc-skill-curve')->assertStatus(403);
    }

    // ==================== 任务3:时代升级门槛编辑器 ====================

    public function test_era_requirement_list_and_edit(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/definitions/era-requirements')->assertOk();
        $this->assertCount(9, $res->json('data.requirements'), '§5.1 恒 9 档(I→II … IX→X)');
        $this->assertSame(
            ['population', 'knowledge', 'food', 'money', 'governance', 'happiness', 'defense'],
            $res->json('data.editable')
        );
        // 必须建筑清单只读下发:只看七个数字会以为门槛就这些
        $this->assertArrayHasKey('buildings_json', $res->json('data.requirements.0'));

        $before = $this->versions();
        $res = $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/era-requirements', [
            'era_order' => 2, 'field' => 'population', 'value' => 80, 'reason' => 'I→II 人口门槛偏低',
        ])->assertOk();

        $this->assertSame(50, (int) $res->json('data.before'));
        $this->assertSame(80, (int) DB::table('era_upgrade_requirement')->where('era_order', 2)->value('population'));
        $this->assertSame($before + 1, $this->versions());
        // 改的不是 defense,不该带 warning
        $this->assertNull($res->json('data.warning'));

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('era_upgrade_requirement', $audit->entity_type);
        $this->assertSame('2', $audit->entity_id);
    }

    // 改 defense 必须回一条 warning:这一列同时是国防威胁需求的来源,
    // 「只是想让升代难一点」会连带改掉全服的威胁等级判定
    public function test_editing_defense_warns_about_threat_requirement(): void
    {
        $res = $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/era-requirements', [
            'era_order' => 4, 'field' => 'defense', 'value' => 200, 'reason' => '提高国防门槛',
        ])->assertOk();

        $this->assertNotNull($res->json('data.warning'));
        $this->assertStringContainsString('威胁需求', (string) $res->json('data.warning'));
    }

    public function test_era_requirement_guards(): void
    {
        $admin = $this->admin();

        // buildings_json 绝不开放:必须建筑清单是升级路径拓扑,填一栋目标时代的建筑就是死锁
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/era-requirements', [
            'era_order' => 8, 'field' => 'buildings_json', 'value' => 1, 'reason' => '试图改升级路径',
        ])->assertStatus(422);

        // 幸福度是 0~100 的百分制:101 等于把升级通道焊死
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/era-requirements', [
            'era_order' => 2, 'field' => 'happiness', 'value' => 101, 'reason' => '试图焊死通道',
        ])->assertStatus(422);

        // 七列都是 unsignedInteger,小数会被静默截断
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/era-requirements', [
            'era_order' => 2, 'field' => 'food', 'value' => 300.5, 'reason' => '试图填小数',
        ])->assertStatus(422);

        // 逐列上限(人口 10 倍余量 = 2e6)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/era-requirements', [
            'era_order' => 2, 'field' => 'population', 'value' => 2000001, 'reason' => '试图锁死升代',
        ])->assertStatus(422);

        // 时代 I 没有「升到 I」这一档
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/era-requirements', [
            'era_order' => 1, 'field' => 'population', 'value' => 10, 'reason' => '不存在的档',
        ])->assertStatus(422);

        $this->assertSame(50, (int) DB::table('era_upgrade_requirement')->where('era_order', 2)->value('population'));

        $this->actingAs($this->player(), 'admin')->getJson('/api/admin/definitions/era-requirements')->assertStatus(403);
    }

    // ==================== 任务4:NPC 特性强度倍率(编辑器侧)====================

    public function test_trait_multiplier_is_editable_within_bounds(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/definitions/npcs')->assertOk();
        $this->assertContains('trait_multiplier', $res->json('data.editable'));
        // 特性**结构**仍然锁着:倍率开放的是强度,不是结构
        $this->assertNotContains('trait_json', $res->json('data.editable'));

        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N001', 'field' => 'trait_multiplier', 'value' => 2.0, 'reason' => 'N001 特性偏弱',
        ])->assertOk();

        $this->assertEqualsWithDelta(2.0, (float) DB::table('npc_definition')->where('npc_id', 'N001')->value('trait_multiplier'), 1e-6);

        // 上限 10:再高会顶爆 §6.4 / §13 的帽,调了也没反应
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N001', 'field' => 'trait_multiplier', 'value' => 10.5, 'reason' => '试图爆帽',
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(2.0, (float) DB::table('npc_definition')->where('npc_id', 'N001')->value('trait_multiplier'), 1e-6);
    }

    // ==================== 任务5:三条补漏 ====================

    // 单资源停市 / 复市:spot ↔ non_tradeable 可逆互切
    public function test_trade_mode_can_be_switched_between_spot_and_non_tradeable(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'trade_mode', 'value' => 'non_tradeable', 'reason' => '铁被刷崩,先停市',
        ])->assertOk();
        $this->assertSame('non_tradeable', DB::table('market_definition')->where('resource_id', 'iron')->value('trade_mode'));

        // 复市:同一条路走回去
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'trade_mode', 'value' => 'spot', 'reason' => '查清后复市',
        ])->assertOk();
        $this->assertSame('spot', DB::table('market_definition')->where('resource_id', 'iron')->value('trade_mode'));
    }

    // 产能合约(电力)一律拒绝:它不是库存资源,现货买卖对它没有语义
    public function test_trade_mode_rejects_capacity_contract_on_either_side(): void
    {
        $admin = $this->admin();

        // 现状是 capacity_contract → 不许切走
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market', [
            'resource_code' => 'electricity', 'field' => 'trade_mode', 'value' => 'spot', 'reason' => '试图让电力上市',
        ])->assertStatus(422);
        $this->assertSame('capacity_contract', DB::table('market_definition')->where('resource_id', 'electricity')->value('trade_mode'));

        // 目标值是 capacity_contract → 不许切进去
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'trade_mode', 'value' => 'capacity_contract', 'reason' => '试图把铁变产能合约',
        ])->assertStatus(422);
        $this->assertSame('spot', DB::table('market_definition')->where('resource_id', 'iron')->value('trade_mode'));
    }

    // 停用事件必须留下原因(后台列表把它直接显示在灰行上);复市 / 启用时自动清空
    public function test_disabling_an_event_requires_a_reason_and_enabling_clears_it(): void
    {
        $admin = $this->admin();

        // 不给原因 → 422,且开关不能被改
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_DROUGHT', 'field' => 'enabled', 'value' => 0, 'reason' => '临时下线',
        ])->assertStatus(422);
        $this->assertSame(1, (int) DB::table('event_definition')->where('event_id', 'EVT_DROUGHT')->value('enabled'));

        // 给了原因 → 同事务写进定义表
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_DROUGHT', 'field' => 'enabled', 'value' => 0,
            'reason' => '临时下线', 'disabled_reason' => '干旱触发过密,等权重重算后再开',
        ])->assertOk();

        $row = DB::table('event_definition')->where('event_id', 'EVT_DROUGHT')->first();
        $this->assertSame(0, (int) $row->enabled);
        $this->assertSame('干旱触发过密,等权重重算后再开', $row->disabled_reason);

        // 审计里两列一起留痕
        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('干旱触发过密,等权重重算后再开', json_decode($audit->after_json, true)['disabled_reason']);

        // 启用 → 原因自动清成 NULL(理由已经不成立,留着只会误导下一个人)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/event', [
            'event_id' => 'EVT_DROUGHT', 'field' => 'enabled', 'value' => 1, 'reason' => '重新上线',
        ])->assertOk();

        $row = DB::table('event_definition')->where('event_id', 'EVT_DROUGHT')->first();
        $this->assertSame(1, (int) $row->enabled);
        $this->assertNull($row->disabled_reason);
    }

    // 工具列表补两列只读:装备对象原文 + 真正进乘区的 specs
    public function test_item_list_exposes_equip_target_and_effect_json_read_only(): void
    {
        $res = $this->actingAs($this->admin(), 'admin')->getJson('/api/admin/definitions/items')->assertOk();

        $row = $res->json('data.items.0');
        $this->assertArrayHasKey('equip_target_desc_zh', $row);
        $this->assertArrayHasKey('effect_json', $row);
        // 只读:手写 specs 要重新过 ModifierSpec 的三重 allowlist,拼错只会静默不生效
        $this->assertNotContains('effect_json', $res->json('data.editable'));
        $this->assertNotContains('equip_target_desc_zh', $res->json('data.editable'));
    }
}
