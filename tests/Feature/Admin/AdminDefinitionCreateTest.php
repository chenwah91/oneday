<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// W14-A 定义表「新增」端点的验收面(建筑等级 / 科技 / NPC / 市场)。
//
// 四个端点走与编辑器同一条十一步流水线,所以每个至少验五层:
//   ① 新增成功 → 落库 + game_data_version 递增 + 一条 create 语义的 ADMIN_CONFIG_CHANGE 审计;
//   ② 重复 ID 422 —— 新增绝不能变成隐式覆盖(那会让「改数值」绕开编辑器的全部护栏);
//   ③ 非法枚举 / 外键 422 —— 每一条都 Fail Closed,拼错的 code 在运行时只会静默不生效;
//   ④ 缺 reason 422 —— §63「管理员改动必须强制输入 reason」;
//   ⑤ 普通玩家 403 —— 定义是全服级数据,权限是第一道门。
// 另加两条本波特有的不变量:建筑等级的「服务端算 level = 最高 + 1」与并发防双加、
// 市场的价格三元组 min ≤ base ≤ max。
//
// 编辑器扩列(NPC / 市场)的 FIELD_MAX 越界也在这里守 —— 那几列此前上限裸奔(旧挂账)。
class AdminDefinitionCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(string $un = 'w14aadmin'): User
    {
        // role 已不可批量赋值,测试里用 forceFill 显式提权
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    // 本文件里 player 的用途只有一个:验「非后台角色一律 403」。
    // 调用点一律写 actingAs($this->player(), 'admin') —— 后台自 2026-08-15 起走独立会话,
    // 只有玩家会话的请求会在 auth:admin 就被挡成 401(那条路径在 AdminAccessTest 单独验),
    // 这里要落到 EnsureAdmin 的角色闸门上才验得到 403 + SECURITY.AUTHORIZATION_FAILED
    private function player(string $un = 'w14aplayer'): User
    {
        return User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
    }

    private function versions(): int
    {
        return DB::table('game_data_versions')->count();
    }

    // 一份合法的建筑等级 values(七个数值列 + 三个 JSON 列)
    private function levelValues(array $overrides = []): array
    {
        return array_merge([
            'duration_seconds'          => 600,
            'worker_required'           => 4,
            'maintenance_money_per_min' => 1.5,
            'maintenance_food_per_min'  => 0,
            'maintenance_fuel_per_min'  => 0,
            'power_per_min'             => 0,
            'capacity'                  => 0,
            'output_json'               => [['resource' => 'berries', 'rate_per_min' => 20]],
            'input_json'                => null,
            'cost_json'                 => ['wood' => 120, 'money' => 60],
        ], $overrides);
    }

    // 一份合法的新科技 values
    private function techValues(array $overrides = []): array
    {
        return array_merge([
            'tech_id'               => 'TECH_I_TEST',
            'name'                  => '测试科技',
            'era_key'               => 'I',
            'branch'                => 'survival_agriculture',
            'knowledge_cost'        => 40,
            'research_minutes'      => 2,
            'prerequisite_tech_ids' => ['TECH_I_SUST'],
            'unlock_building_ids'   => ['F01'],
        ], $overrides);
    }

    // 一份合法的新 NPC values
    private function npcValues(array $overrides = []): array
    {
        return array_merge([
            'npc_id'              => 'N901',
            'name_key'            => 'npc.N901.name',
            'name_zh'             => '测试员',
            'category'            => 'agriculture',
            'min_era'             => 'II',
            'primary_skill_id'    => 'SKILL_AGRICULTURE',
            'initial_skill_value' => 55,
            'initial_skill_level' => 3,
            'max_level'           => 10,
            'wage_per_min'        => 4.5,
            'food_per_min'        => 1.0,
            'rarity'              => 'uncommon',
            'recruit_source'      => 'recruit',
            'recruit_desc_zh'     => '学堂招募',
            'trait_desc_zh'       => '农业产量 +8%',
            'trait_json'          => ['specs' => [['target' => 'npc', 'scope' => 'city', 'op' => 'pct', 'value' => 0.08]], 'unmapped_zh' => []],
            'trait_multiplier'    => 1,
        ], $overrides);
    }

    // 一份合法的上市 values。iron_tools 在 resource_definition 里但尚无 market_definition 行
    private function marketValues(array $overrides = []): array
    {
        return array_merge([
            'resource_id'     => 'iron_tools',
            'market_category' => 'metal',
            'trade_mode'      => 'spot',
            'base_price'      => 30,
            'min_price'       => 15,
            'max_price'       => 90,
            'volatility'      => 0.06,
            'elasticity'      => 0.6,
            'fee_rate'        => 0.03,
            'base_liquidity'  => 1000,
            'note'            => '铁制工具上市',
        ], $overrides);
    }

    // ==================== ① 建筑等级加一行 ====================

    public function test_add_building_level_computes_next_level_and_audits_create(): void
    {
        $before = $this->versions();
        $maxBefore = (int) DB::table('building_level_definition')->where('building_id', 'F01')->max('level');

        $res = $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'F01', 'reason' => 'F01 需要第四级', 'values' => $this->levelValues(),
        ])->assertOk();

        // level 由服务端算 = 当前最高级 + 1(客户端根本不传它)
        $this->assertSame($maxBefore + 1, (int) $res->json('data.level'));
        $this->assertSame('F01', $res->json('data.building_id'));

        $row = DB::table('building_level_definition')->where('building_id', 'F01')->where('level', $maxBefore + 1)->first();
        $this->assertNotNull($row, '新等级行必须落库');
        $this->assertSame(600, (int) $row->duration_seconds);
        $this->assertSame(4, (int) $row->worker_required);
        // JSON 列规范化落库,能原样读回来
        $this->assertSame('berries', json_decode((string) $row->output_json, true)[0]['resource']);
        $this->assertSame(120, (int) json_decode((string) $row->cost_json, true)['wood']);

        $this->assertSame($before + 1, $this->versions(), '新增等级必须 bump game_data_version');

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertSame('building_level_definition', $audit->entity_type);
        $this->assertSame('F01:' . ($maxBefore + 1), $audit->entity_id);
        $this->assertSame('F01 需要第四级', $audit->reason_code);
        // create 语义:before 为空(此前没有这一行),after 记完整新行
        $this->assertNull($audit->before_json);
        $this->assertNotNull($audit->after_json);
        $this->assertSame('create', json_decode((string) $audit->metadata_json, true)['operation']);
    }

    // 连发两次:第二次必须拿到再 +1 的等级(行锁 → 重算 max),不会出现两行同级或断档
    public function test_add_building_level_twice_yields_consecutive_levels(): void
    {
        $admin = $this->admin();
        $maxBefore = (int) DB::table('building_level_definition')->where('building_id', 'F01')->max('level');

        $first = $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'F01', 'reason' => '加第一档', 'values' => $this->levelValues(),
        ])->assertOk();
        $second = $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'F01', 'reason' => '加第二档', 'values' => $this->levelValues(),
        ])->assertOk();

        $this->assertSame($maxBefore + 1, (int) $first->json('data.level'));
        $this->assertSame($maxBefore + 2, (int) $second->json('data.level'));

        // 等级连续性:1..N 无断档
        $levels = DB::table('building_level_definition')->where('building_id', 'F01')->orderBy('level')->pluck('level')
            ->map(fn ($l) => (int) $l)->all();
        $this->assertSame(range(1, count($levels)), $levels);
    }

    public function test_add_building_level_guards(): void
    {
        $admin = $this->admin();
        $countBefore = DB::table('building_level_definition')->count();

        // 不存在的建筑:不准借道「加等级」新建建筑
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'NOPE', 'reason' => '试图新建建筑', 'values' => $this->levelValues(),
        ])->assertStatus(422);

        // 缺 reason(§63 强制)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'F01', 'values' => $this->levelValues(),
        ])->assertStatus(422);

        // 数值列上限(工期 7 天)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'F01', 'reason' => '试图填爆工期',
            'values' => $this->levelValues(['duration_seconds' => 604801]),
        ])->assertStatus(422);

        // int 列的小数会被静默截断
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'F01', 'reason' => '试图填小数工人',
            'values' => $this->levelValues(['worker_required' => 3.5]),
        ])->assertStatus(422);

        // JSON 列的资源 code 必须登记在册(拼错 = 一条永远读不到的配置)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'F01', 'reason' => '试图写未登记资源',
            'values' => $this->levelValues(['cost_json' => ['woooood' => 10]]),
        ])->assertStatus(422);

        // 数值列缺项一律拒(新增没有「默认值兜底」这回事)
        $values = $this->levelValues();
        unset($values['capacity']);
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'F01', 'reason' => '缺列', 'values' => $values,
        ])->assertStatus(422);

        $this->assertSame($countBefore, DB::table('building_level_definition')->count(), '任何一条失败都不得留下半行');

        // 普通玩家 403
        $this->actingAs($this->player(), 'admin')->postJson('/api/admin/definitions/building-level/add', [
            'building_id' => 'F01', 'reason' => '越权尝试', 'values' => $this->levelValues(),
        ])->assertStatus(403);
    }

    // ==================== ② 新增科技 ====================

    public function test_add_technology_persists_and_audits_create(): void
    {
        $before = $this->versions();

        $res = $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/technology/add', [
            'reason' => '补一条时代 I 的实验科技', 'values' => $this->techValues(),
        ])->assertOk();

        $this->assertSame('TECH_I_TEST', $res->json('data.tech_id'));

        $row = DB::table('technology_definition')->where('tech_id', 'TECH_I_TEST')->first();
        $this->assertNotNull($row);
        $this->assertSame('survival_agriculture', $row->branch);
        $this->assertSame('I', $row->era_key);
        $this->assertSame(40, (int) $row->knowledge_cost);
        // 两个数组列照现有行的格式:json 列存 JSON 数组
        $this->assertSame(['TECH_I_SUST'], json_decode((string) $row->prerequisite_tech_ids, true));
        $this->assertSame(['F01'], json_decode((string) $row->unlock_building_ids, true));

        $this->assertSame($before + 1, $this->versions());

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertSame('technology_definition', $audit->entity_type);
        $this->assertSame('TECH_I_TEST', $audit->entity_id);
        $this->assertNull($audit->before_json);
        $this->assertSame('create', json_decode((string) $audit->metadata_json, true)['operation']);
    }

    public function test_add_technology_rejects_duplicate_id(): void
    {
        $before = (int) DB::table('technology_definition')->where('tech_id', 'TECH_I_SUST')->value('knowledge_cost');

        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/technology/add', [
            'reason' => '试图覆盖既有科技',
            'values' => $this->techValues(['tech_id' => 'TECH_I_SUST', 'knowledge_cost' => 99999]),
        ])->assertStatus(422);

        // 新增绝不能变成隐式覆盖
        $this->assertSame($before, (int) DB::table('technology_definition')->where('tech_id', 'TECH_I_SUST')->value('knowledge_cost'));
    }

    public function test_add_technology_guards(): void
    {
        $admin = $this->admin();
        $countBefore = DB::table('technology_definition')->count();

        // ID 格式必须与库内 TECH_* 风格一致
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology/add', [
            'reason' => '错误 ID 风格', 'values' => $this->techValues(['tech_id' => 'tech-lowercase']),
        ])->assertStatus(422);

        // branch 必须在 EnumCode::TECH_BRANCHES 登记表内
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology/add', [
            'reason' => '非法分支', 'values' => $this->techValues(['branch' => 'not_a_branch']),
        ])->assertStatus(422);

        // era_key 必须存在于时代表
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology/add', [
            'reason' => '不存在的时代', 'values' => $this->techValues(['era_key' => 'ZZ']),
        ])->assertStatus(422);

        // 前置科技必须已存在
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology/add', [
            'reason' => '悬空前置', 'values' => $this->techValues(['prerequisite_tech_ids' => ['TECH_NOPE']]),
        ])->assertStatus(422);

        // 前置不能引用自己(自环)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology/add', [
            'reason' => '自环', 'values' => $this->techValues(['prerequisite_tech_ids' => ['TECH_I_TEST']]),
        ])->assertStatus(422);

        // 解锁建筑必须存在于 building_definition
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology/add', [
            'reason' => '悬空解锁', 'values' => $this->techValues(['unlock_building_ids' => ['ZZZ']]),
        ])->assertStatus(422);

        // 缺 reason
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/technology/add', [
            'values' => $this->techValues(),
        ])->assertStatus(422);

        $this->assertSame($countBefore, DB::table('technology_definition')->count());

        $this->actingAs($this->player(), 'admin')->postJson('/api/admin/definitions/technology/add', [
            'reason' => '越权尝试', 'values' => $this->techValues(),
        ])->assertStatus(403);
    }

    // ==================== ③ 新增 NPC ====================

    public function test_add_npc_persists_and_audits_create(): void
    {
        $before = $this->versions();

        $res = $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '补一位农业 NPC', 'values' => $this->npcValues(),
        ])->assertOk();

        $this->assertSame('N901', $res->json('data.npc_id'));

        $row = DB::table('npc_definition')->where('npc_id', 'N901')->first();
        $this->assertNotNull($row);
        $this->assertSame('测试员', $row->name_zh);
        $this->assertSame('agriculture', $row->category);
        $this->assertSame('uncommon', $row->rarity);
        $this->assertSame('SKILL_AGRICULTURE', $row->primary_skill_id);
        // trait_json 规范化落库:只保留 specs / unmapped_zh 两个已知键
        $trait = json_decode((string) $row->trait_json, true);
        $this->assertSame(['specs', 'unmapped_zh'], array_keys($trait));
        $this->assertSame('npc', $trait['specs'][0]['target']);

        $this->assertSame($before + 1, $this->versions());

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertSame('npc_definition', $audit->entity_type);
        $this->assertSame('N901', $audit->entity_id);
        $this->assertNull($audit->before_json);
        $this->assertSame('create', json_decode((string) $audit->metadata_json, true)['operation']);
    }

    public function test_add_npc_rejects_duplicate_id(): void
    {
        $before = DB::table('npc_definition')->where('npc_id', 'N001')->value('name_zh');

        $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '试图覆盖 N001',
            'values' => $this->npcValues(['npc_id' => 'N001', 'name_key' => 'npc.N001.name', 'name_zh' => '冒名者']),
        ])->assertStatus(422);

        $this->assertSame($before, DB::table('npc_definition')->where('npc_id', 'N001')->value('name_zh'));
    }

    public function test_add_npc_guards(): void
    {
        $admin = $this->admin();
        $countBefore = DB::table('npc_definition')->count();

        // ID 格式照 N001 风格
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '错误 ID', 'values' => $this->npcValues(['npc_id' => 'NPC_901', 'name_key' => 'npc.NPC_901.name']),
        ])->assertStatus(422);

        // 稀有度必须是 NpcCode::RARITIES 里的 code
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '非法稀有度', 'values' => $this->npcValues(['rarity' => 'mythic']),
        ])->assertStatus(422);

        // 获取来源必须是 NpcCode::SOURCES 里的 code
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '非法来源', 'values' => $this->npcValues(['recruit_source' => 'gacha']),
        ])->assertStatus(422);

        // category 只能用库内已有分类(新分类走迁移)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '发明新分类', 'values' => $this->npcValues(['category' => 'wizardry']),
        ])->assertStatus(422);

        // 主技能必须存在于 npc_skill_definition
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '不存在的技能', 'values' => $this->npcValues(['primary_skill_id' => 'SKILL_MAGIC']),
        ])->assertStatus(422);

        // min_era 必须存在于时代表
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '不存在的时代', 'values' => $this->npcValues(['min_era' => 'ZZ']),
        ])->assertStatus(422);

        // trait_json 的 target 必须过 ModifierSpec 的三重 allowlist(拼错只会静默不生效)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '非法特性 target',
            'values' => $this->npcValues(['trait_json' => ['specs' => [['target' => 'not_a_target', 'scope' => 'city', 'op' => 'pct', 'value' => 0.1]], 'unmapped_zh' => []]]),
        ])->assertStatus(422);

        // 数值列上限:初始技能值是百分制
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '技能值越界', 'values' => $this->npcValues(['initial_skill_value' => 101]),
        ])->assertStatus(422);

        // 等级对必须自洽
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '等级对不自洽', 'values' => $this->npcValues(['initial_skill_level' => 8, 'max_level' => 5]),
        ])->assertStatus(422);

        // 缺 reason
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc/add', [
            'values' => $this->npcValues(),
        ])->assertStatus(422);

        $this->assertSame($countBefore, DB::table('npc_definition')->count());

        $this->actingAs($this->player(), 'admin')->postJson('/api/admin/definitions/npc/add', [
            'reason' => '越权尝试', 'values' => $this->npcValues(),
        ])->assertStatus(403);
    }

    // ==================== ④ 新增市场定义(上市)====================

    public function test_add_market_definition_derives_identity_columns_and_audits_create(): void
    {
        $before = $this->versions();
        $resource = DB::table('resource_definition')->where('resource_id', 'iron_tools')->first();

        $res = $this->actingAs($this->admin(), 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => '铁制工具上市补缺口', 'values' => $this->marketValues(),
        ])->assertOk();

        $this->assertSame('iron_tools', $res->json('data.resource_id'));

        $row = DB::table('market_definition')->where('resource_id', 'iron_tools')->first();
        $this->assertNotNull($row);
        // 身份列由服务端从 resource_definition 派生,客户端传不进来
        $this->assertSame($resource->first_era, $row->first_era);
        $this->assertNotNull($row->rs_code, 'rs_code 必须由服务端派生 / 顺延');
        $this->assertSame('spot', $row->trade_mode);
        $this->assertEqualsWithDelta(30.0, (float) $row->base_price, 1e-6);

        $this->assertSame($before + 1, $this->versions());

        $audit = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('ADMIN.CONFIG_CHANGE', $audit->action);
        $this->assertSame('market_definition', $audit->entity_type);
        $this->assertSame('iron_tools', $audit->entity_id);
        $this->assertNull($audit->before_json);
        $this->assertSame('create', json_decode((string) $audit->metadata_json, true)['operation']);
    }

    public function test_add_market_definition_rejects_duplicate_and_unknown_resource(): void
    {
        $admin = $this->admin();

        // 已有市场行的资源:改数值请走编辑器,新增不得变成覆盖
        $beforePrice = DB::table('market_definition')->where('resource_id', 'iron')->value('base_price');
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => '试图覆盖铁的定价', 'values' => $this->marketValues(['resource_id' => 'iron', 'base_price' => 999]),
        ])->assertStatus(422);
        $this->assertEquals($beforePrice, DB::table('market_definition')->where('resource_id', 'iron')->value('base_price'));

        // 不存在的资源:上市不能发明新资源
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => '试图发明新资源', 'values' => $this->marketValues(['resource_id' => 'unobtainium']),
        ])->assertStatus(422);
        $this->assertNull(DB::table('market_definition')->where('resource_id', 'unobtainium')->first());
    }

    public function test_add_market_definition_enforces_price_triple(): void
    {
        $admin = $this->admin();

        // min > max:夹取区间为空
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => '区间倒置', 'values' => $this->marketValues(['min_price' => 90, 'max_price' => 15]),
        ])->assertStatus(422);

        // base 掉出区间下方
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => 'base 低于下限', 'values' => $this->marketValues(['base_price' => 10]),
        ])->assertStatus(422);

        // base 掉出区间上方
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => 'base 高于上限', 'values' => $this->marketValues(['base_price' => 200]),
        ])->assertStatus(422);

        $this->assertNull(DB::table('market_definition')->where('resource_id', 'iron_tools')->first());
    }

    public function test_add_market_definition_guards(): void
    {
        $admin = $this->admin();

        // 市场分组只能用库内已有的(market_category 与 resource_definition.category 语义不同)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => '发明新分组', 'values' => $this->marketValues(['market_category' => 'luxury']),
        ])->assertStatus(422);

        // trade_mode 只收 spot / non_tradeable:产能合约是电力的特例
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => '试图新建产能合约', 'values' => $this->marketValues(['trade_mode' => 'capacity_contract']),
        ])->assertStatus(422);

        // 费率 ≥1 会让卖出变成倒贴钱
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => '费率越界', 'values' => $this->marketValues(['fee_rate' => 1.5]),
        ])->assertStatus(422);

        // 缺 reason
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market/add', [
            'values' => $this->marketValues(),
        ])->assertStatus(422);

        $this->assertNull(DB::table('market_definition')->where('resource_id', 'iron_tools')->first());

        $this->actingAs($this->player(), 'admin')->postJson('/api/admin/definitions/market/add', [
            'reason' => '越权尝试', 'values' => $this->marketValues(),
        ])->assertStatus(403);
    }

    // ==================== 任务2:编辑器扩列的护栏(旧挂账补上限)====================

    public function test_npc_editor_exposes_expanded_columns_with_field_max(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/definitions/npcs')->assertOk();
        $editable = $res->json('data.editable');
        foreach (['name_zh', 'category', 'min_era', 'rarity', 'recruit_source', 'recruit_desc_zh', 'trait_desc_zh'] as $col) {
            $this->assertContains($col, $editable, "{$col} 应已扩进可编辑列");
        }
        // 结构列仍然锁着:主键 / 派生键 / 岗位匹配键 / 特性结构
        foreach (['npc_id', 'name_key', 'primary_skill_id', 'trait_json'] as $locked) {
            $this->assertNotContains($locked, $editable, "{$locked} 是结构列,不得开放");
        }

        // 枚举列改成合法值:成功
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N001', 'field' => 'rarity', 'value' => 'epic', 'reason' => '提高 N001 稀有度',
        ])->assertOk();
        $this->assertSame('epic', DB::table('npc_definition')->where('npc_id', 'N001')->value('rarity'));

        // 枚举列改成非法值:422(Fail Closed)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N001', 'field' => 'rarity', 'value' => 'mythic', 'reason' => '非法稀有度',
        ])->assertStatus(422);
        $this->assertSame('epic', DB::table('npc_definition')->where('npc_id', 'N001')->value('rarity'));

        // 旧挂账:五个数值列此前上限裸奔,现在逐列有上限
        $wageBefore = DB::table('npc_definition')->where('npc_id', 'N002')->value('wage_per_min');
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N002', 'field' => 'wage_per_min', 'value' => 1000001, 'reason' => '工资越界',
        ])->assertStatus(422);
        $this->assertEquals($wageBefore, DB::table('npc_definition')->where('npc_id', 'N002')->value('wage_per_min'));

        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N002', 'field' => 'initial_skill_value', 'value' => 101, 'reason' => '技能值越界',
        ])->assertStatus(422);

        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N002', 'field' => 'food_per_min', 'value' => 10001, 'reason' => '口粮越界',
        ])->assertStatus(422);

        // 等级两列改单列时也要与另一列现值合并校验
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N001', 'field' => 'max_level', 'value' => 2, 'reason' => '把上限压到初始之下',
        ])->assertStatus(422);
    }

    public function test_market_editor_exposes_note_and_enforces_price_triple(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'admin')->getJson('/api/admin/definitions/market')->assertOk();
        $editable = $res->json('data.editable');
        foreach (['base_price', 'min_price', 'max_price', 'volatility', 'elasticity', 'fee_rate', 'base_liquidity', 'trade_mode', 'note'] as $col) {
            $this->assertContains($col, $editable, "{$col} 应在可编辑列内");
        }
        // 三个身份列仍然只读
        foreach (['rs_code', 'market_category', 'first_era'] as $locked) {
            $this->assertNotContains($locked, $editable, "{$locked} 是身份列,不得开放");
        }

        // note 可改(此前没有任何入口能写它)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'note', 'value' => '钢产线上线后再评估', 'reason' => '补一句备注',
        ])->assertOk();
        $this->assertSame('钢产线上线后再评估', DB::table('market_definition')->where('resource_id', 'iron')->value('note'));

        // 改单列时与另两列现值合并校验:把 base 顶到 max 之上必须 422
        $max = (float) DB::table('market_definition')->where('resource_id', 'iron')->value('max_price');
        $baseBefore = DB::table('market_definition')->where('resource_id', 'iron')->value('base_price');
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'base_price', 'value' => $max + 1, 'reason' => 'base 越过上限',
        ])->assertStatus(422);
        $this->assertEquals($baseBefore, DB::table('market_definition')->where('resource_id', 'iron')->value('base_price'));

        // 把 min 顶到 base 之上同样 422(三元组的另一侧)
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'min_price', 'value' => (float) $baseBefore + 1, 'reason' => 'min 越过 base',
        ])->assertStatus(422);

        // 逐列 FIELD_MAX
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'volatility', 'value' => 1.5, 'reason' => '波动率越界',
        ])->assertStatus(422);
        $this->actingAs($admin, 'admin')->postJson('/api/admin/definitions/market', [
            'resource_code' => 'iron', 'field' => 'base_liquidity', 'value' => 1000000001, 'reason' => '流动性越界',
        ])->assertStatus(422);
    }

    // 后台科技 GET 必须带 branch:前端要按分支分组显示
    public function test_admin_technologies_response_carries_branch(): void
    {
        $res = $this->actingAs($this->admin(), 'admin')->getJson('/api/admin/definitions/technologies')->assertOk();

        $first = $res->json('data.technologies.0');
        $this->assertArrayHasKey('branch', $first);
        $this->assertNotNull($first['branch']);
    }
}
