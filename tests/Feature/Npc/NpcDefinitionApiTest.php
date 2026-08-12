<?php

namespace Tests\Feature\Npc;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M3-W7 契约缺口 ①②:GET /api/definitions/npcs(招募池预览 + NPC 等级曲线)。
//
// 这个端点补的是前端的两个硬缺口:
//   ① 招募面板只能显示「你抽到了谁」,没法预览「这一版数值里都有些什么人」;
//   ② NPC 经验条没有分母 —— xp_to_next 是全局曲线,前端只能硬编码一份,后台一改就两套真相。
//
// 用例分四层:
//   ① 内容层:150 个原型 / 12 条技能 / 10 级曲线全在,字段与定义表逐字对得上;
//   ② 泄露层(假失败):trait_json 的 specs 结构**一个字都不许出现在响应里**;
//   ③ 安全层:未登录 401;两个玩家拿到的响应必须逐字节相同(定义端点不许夹带玩家数据);
//   ④ 限流层:与其它 definitions 端点同挂 throttle:api。
class NpcDefinitionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function login(string $un): User
    {
        return User::create([
            'username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123',
        ]);
    }

    // ---------- ① 内容层 ----------

    public function test_lists_every_npc_prototype_with_display_fields(): void
    {
        $user = $this->login('npcdefapi');

        $res = $this->actingAs($user)->getJson('/api/definitions/npcs');
        $res->assertOk();

        $npcs = $res->json('data.npcs');
        // §6.3 的 150 个原型(W5 从 30 扩到 150),一个都不能少
        $this->assertCount(150, $npcs);
        $this->assertSame(DB::table('npc_definition')->count(), count($npcs));

        $byId = collect($npcs)->keyBy('npc_id');

        // N001 初始领袖:name_zh 仍为 null(拟名待用户拍板)—— 服务端绝不编占位名
        $n001 = $byId['N001'];
        $this->assertNull($n001['name_zh'], 'N001~N030 没有中文名时必须给 null,由前端回落 name_key');
        $this->assertSame('npc.N001.name', $n001['name_key']);
        $this->assertSame('leadership', $n001['category']);
        $this->assertSame('rare', $n001['rarity']);
        $this->assertSame('I', $n001['min_era']);
        $this->assertSame(1, $n001['min_era_order']);
        $this->assertSame('SKILL_ADMIN', $n001['primary_skill_id']);
        $this->assertSame(5, $n001['initial_skill_level']);
        $this->assertSame(70, $n001['initial_skill_value']);
        $this->assertSame(10, $n001['max_level']);
        // 浮点字段一律用 delta 比:JSON 会把 0.0 序列化成 `0`,回来就成了 int(与 assertSame 不合)
        $this->assertEqualsWithDelta(0.0, $n001['wage_per_min'], 1e-9);
        $this->assertEqualsWithDelta(1.2, $n001['food_per_min'], 1e-9);
        $this->assertSame('initial', $n001['recruit_source']);
        $this->assertNotSame('', $n001['recruit_desc_zh']);
        $this->assertStringContainsString('治理', $n001['trait_desc_zh']);

        // N041:W5 扩池带进来的中文名,拿它验「有名字时确实下发」
        $this->assertSame('岳川', $byId['N041']['name_zh']);
        $this->assertSame(2, $byId['N041']['min_era_order'], 'min_era II → era_order 2');
    }

    // 等级曲线(契约缺口 ②):照 npcs.json 的 level_curve 原样下发,10 行一行不少。
    // xp_to_next 是**增量**不是累计,10 级为 0 = 满级 —— 前端的经验条分母就取它
    public function test_level_curve_is_exposed_verbatim(): void
    {
        $user = $this->login('npccurve');

        $curve = $this->actingAs($user)->getJson('/api/definitions/npcs')->json('data.level_curve');

        $this->assertCount(10, $curve);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], array_column($curve, 'level'));
        // §6.2 原表的 10 个 xp_to_next(黄金样本:抄错一个数这里就红)
        $this->assertSame(
            [100, 303, 580, 919, 1313, 1758, 2250, 2786, 3363, 0],
            array_column($curve, 'xp_to_next')
        );
        $this->assertEqualsWithDelta(0.0, $curve[0]['primary_bonus'], 1e-9);
        $this->assertEqualsWithDelta(0.315, $curve[9]['primary_bonus'], 1e-9, '10 级主技能加成 +31.5%');
        $this->assertEqualsWithDelta(0.18, $curve[9]['maintenance_reduction_cap'], 1e-9);

        // 与库里的曲线表逐行一致(下发的是定义表,不是代码里的第二份常量)
        $rows = DB::table('npc_skill_level_curve')->orderBy('level')->pluck('xp_to_next', 'level')->all();
        foreach ($curve as $entry) {
            $this->assertSame((int) $rows[$entry['level']], $entry['xp_to_next']);
        }
    }

    // 技能表:primary_skill_id 是 code,中文含义只存在 npc_skill_definition 这一处
    public function test_skill_block_translates_primary_skill_id(): void
    {
        $user = $this->login('npcskill');

        $data = $this->actingAs($user)->getJson('/api/definitions/npcs')->json('data');

        $this->assertCount(12, $data['skills'], '§6.1 的 12 条通用技能');

        $skillIds = array_column($data['skills'], 'skill_id');
        // 每个 NPC 的主技能都要能在技能表里查到(查不到 = 前端只能显示一个裸 code)
        foreach ($data['npcs'] as $npc) {
            $this->assertContains($npc['primary_skill_id'], $skillIds, "{$npc['npc_id']} 的主技能不在技能表里");
        }

        $commerce = collect($data['skills'])->firstWhere('skill_id', 'SKILL_COMMERCE');
        $this->assertStringContainsString('手续费', $commerce['effect_desc_zh']);
    }

    // ---------- ② 泄露层(假失败)----------

    // trait_json 的 specs 是**内核内部表达**(target / scope / op / value),下发有两害:
    //   ① 客户端不可信(§31 / §66):前端拿 specs 自己算加成,永远算不出与服务端一致的数;
    //   ② 它是内部结构,target 名单随波次增删(W7 就动了两条),下发等于把它变成对外契约。
    // 这条用例直接扫**原始响应文本**:任何一个 target 名字漏出去就红
    public function test_internal_trait_specs_are_never_exposed(): void
    {
        $user = $this->login('npcleak');

        $body = $this->actingAs($user)->getJson('/api/definitions/npcs')->getContent();

        foreach (['trait_json', '"specs"', 'unmapped_zh', 'scope_key',
            'governance_capacity_pct', 'market_fee_pct', 'research_speed_pct', 'npc_bonus'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "响应里不该出现内部结构 {$needle}");
        }

        // 但**中文描述必须在**:展示信息全靠它(不下发 specs 的前提是这一条给足)
        $this->assertStringContainsString('trait_desc_zh', $body);
    }

    // ---------- ③ 安全层 ----------

    public function test_requires_auth(): void
    {
        $this->getJson('/api/definitions/npcs')->assertStatus(401);
    }

    // 越权面:definitions 是**全服共享的静态定义**,不该带任何玩家数据。
    // 两个不同玩家拿到的响应必须逐字节相同 —— 一旦有人往里塞「我招过谁 / 我买得起谁」,
    // 这条就会红,提醒他那属于快照或专门的玩家端点
    public function test_two_players_receive_byte_identical_definitions(): void
    {
        $a = $this->login('npcdefa');
        $b = $this->login('npcdefb');

        // 给 A 招一个人 + 一堆钱,制造「两人状态完全不同」的前提
        $this->actingAs($a)->postJson('/api/city/npc/recruit')->assertOk();

        $bodyA = $this->actingAs($a)->getJson('/api/definitions/npcs')->getContent();
        $bodyB = $this->actingAs($b)->getJson('/api/definitions/npcs')->getContent();

        $this->assertSame($bodyA, $bodyB, '定义端点不许夹带玩家数据');
    }

    // ---------- ④ 限流层 ----------

    // 与 buildings / resources / technologies 同一档(auth:web + throttle:api)。
    // §48「不同操作使用不同限制」的落地方式就是这份逐路由的 middleware 名单
    public function test_route_carries_the_same_limiter_as_other_definition_endpoints(): void
    {
        $routes = collect(app('router')->getRoutes())->keyBy(fn ($r) => $r->uri());

        $this->assertArrayHasKey('api/definitions/npcs', $routes->all());

        $mine = $routes['api/definitions/npcs']->gatherMiddleware();
        $peer = $routes['api/definitions/technologies']->gatherMiddleware();

        $this->assertContains('throttle:api', $mine);
        $this->assertContains('auth:web', $mine);
        $this->assertSame(array_values($peer), array_values($mine), '与其它 definitions 端点的中间件必须完全一致');
    }
}
