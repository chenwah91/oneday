<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Technology\TechService;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 科技研究 POST /api/city/research(M2-B1)
// 覆盖:成功链(扣费/审计/revision)/ 前置 / 时代 / 重复 / 并行 / 余额不足回滚 /
//       幂等重放 / Revision 冲突 / 越权 / 懒完成解锁 / 定义与快照契约
class TechResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // 一座城 + 指定知识库存。时间冻结在建城时刻 → 结算 elapsed 恒为 0,断言不受产量干扰
    private function makeCity(string $un, float $knowledge = 1000.0): array
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        // 知识不在建城初始资源里(SimConstants::START_RESOURCES 只给木/石/粮),测试自行铺一行
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'knowledge'],
            ['amount' => $knowledge]
        );

        return [$u, $city];
    }

    // 直接把某项科技置成已解锁(绕过研究流程,用来铺前置条件)
    private function forceUnlock(City $city, string $techId): void
    {
        DB::table('city_technologies')->insert([
            'city_id' => $city->id, 'tech_id' => $techId, 'status' => TechService::STATUS_UNLOCKED,
            'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function knowledgeOf(City $city): float
    {
        return (float) DB::table('city_resources')
            ->where('city_id', $city->id)->where('resource_id', 'knowledge')->value('amount');
    }

    // ---- 成功链 ----

    public function test_research_start_deducts_knowledge_and_writes_audit(): void
    {
        [$u, $city] = $this->makeCity('techA');
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $res = $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST']);

        $res->assertOk()->assertJson(['success' => true, 'data' => [
            'delta'        => ['knowledge' => -20],
            'technologies' => [
                'unlocked'    => [],
                'researching' => ['tech_id' => 'TECH_I_SUST'],
            ],
        ]]);

        // 扣费:TECH_I_SUST knowledge_cost = 20(technologies.json 权威)
        $this->assertEqualsWithDelta(980.0, $this->knowledgeOf($city), 0.0001);

        // 运行时行:researching + finished_at = started_at + research_minutes(1 分钟)
        $row = DB::table('city_technologies')->where('city_id', $city->id)->first();
        $this->assertSame('researching', $row->status);
        $this->assertSame('TECH_I_SUST', $row->tech_id);
        $this->assertSame('2026-01-01 00:00:00', Carbon::parse($row->started_at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01 00:01:00', Carbon::parse($row->finished_at)->format('Y-m-d H:i:s'));

        $this->assertSame($revisionBefore + 1, (int) DB::table('cities')->where('id', $city->id)->value('revision'));

        $audit = DB::table('audit_logs')->where('action', 'TECH.RESEARCH_START')->latest('id')->first();
        $this->assertSame('success', $audit->status);
        $this->assertSame('technology', $audit->entity_type);
        $this->assertSame('TECH_I_SUST', $audit->entity_id);
        $this->assertSame(['knowledge' => -20], json_decode($audit->delta_json, true));
        $this->assertSame($revisionBefore, (int) $audit->city_revision_before);
        $this->assertSame($revisionBefore + 1, (int) $audit->city_revision_after);
    }

    // ---- 规则闸门 ----

    // 前置科技未解锁:TECH_II_SUST 要求 TECH_I_SUST,这里只解锁了同时代的另一分支
    public function test_missing_prerequisite_is_rejected(): void
    {
        [$u, $city] = $this->makeCity('techB');
        $this->forceUnlock($city, 'TECH_I_IND'); // 时代 I:把时代闸门抬到可研究时代 II

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_SUST'])
            ->assertStatus(422)->assertJson(['error' => 'TECH_NOT_UNLOCKED']);

        $this->assertSame(0, DB::table('city_technologies')->where('status', 'researching')->count());
        $this->assertEqualsWithDelta(1000.0, $this->knowledgeOf($city), 0.0001, '被拒绝不得扣费');
    }

    // 时代不满足:新城一项科技都没解锁 → 只能研究时代 I,时代 III 一律拒绝
    public function test_era_requirement_is_rejected(): void
    {
        [$u, $city] = $this->makeCity('techC');

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_III_SUST'])
            ->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $this->assertSame(0, DB::table('city_technologies')->count());
    }

    // 时代闸门会随解锁推进:解锁时代 I 之后,时代 II 就能研究了
    public function test_era_gate_opens_after_unlocking_previous_era(): void
    {
        [$u, $city] = $this->makeCity('techD');
        $this->forceUnlock($city, 'TECH_I_SUST');

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_SUST'])->assertOk();

        // TECH_II_SUST knowledge_cost = 60
        $this->assertEqualsWithDelta(940.0, $this->knowledgeOf($city), 0.0001);
    }

    // 重复研究:已解锁的项目再提交 → VALIDATION_ERROR(客户端状态过期)
    public function test_researching_an_unlocked_tech_is_rejected(): void
    {
        [$u, $city] = $this->makeCity('techE');
        $this->forceUnlock($city, 'TECH_I_SUST');

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);

        $this->assertEqualsWithDelta(1000.0, $this->knowledgeOf($city), 0.0001);
    }

    // 重复研究:同一项正在研究中再提交 → RESEARCH_IN_PROGRESS,不得二次扣费
    public function test_researching_the_same_tech_twice_is_rejected(): void
    {
        [$u, $city] = $this->makeCity('techF');
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])->assertOk();

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])
            ->assertStatus(422)->assertJson(['error' => 'RESEARCH_IN_PROGRESS']);

        $this->assertEqualsWithDelta(980.0, $this->knowledgeOf($city), 0.0001, '只扣一次');
        $this->assertSame(1, DB::table('city_technologies')->where('city_id', $city->id)->count());
    }

    // 并行:同时只允许 1 项在研,第二项(哪怕是别的分支)必须被拒
    public function test_second_parallel_research_is_rejected(): void
    {
        [$u, $city] = $this->makeCity('techG');
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])->assertOk();

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_IND'])
            ->assertStatus(422)->assertJson(['error' => 'RESEARCH_IN_PROGRESS']);

        $this->assertEqualsWithDelta(980.0, $this->knowledgeOf($city), 0.0001);
        $this->assertSame(1, DB::table('city_technologies')->where('city_id', $city->id)->count());
    }

    // 余额不足:整笔回滚 —— 不留在研行、不扣费、revision 不涨
    public function test_insufficient_knowledge_rolls_back(): void
    {
        [$u, $city] = $this->makeCity('techH', 5.0);
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);

        $this->assertSame(0, DB::table('city_technologies')->count());
        $this->assertEqualsWithDelta(5.0, $this->knowledgeOf($city), 0.0001);
        $this->assertSame($revisionBefore, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'TECH.RESEARCH_START')->count());
    }

    public function test_invalid_tech_id_is_rejected(): void
    {
        [$u, $city] = $this->makeCity('techI');

        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_NOT_A_REAL_ONE'])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);

        $this->assertSame(0, DB::table('city_technologies')->count());
    }

    // ---- 并发与幂等 ----

    public function test_research_is_idempotent(): void
    {
        [$u, $city] = $this->makeCity('techJ');
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $body = ['tech_id' => 'TECH_I_SUST', 'idempotency_key' => 'tech-fixed-key-1'];
        $this->actingAs($u)->postJson('/api/city/research', $body)->assertOk();
        // 重放:必须直接回旧结果,不再扣一次知识,也不因"已在研"而报错
        $this->actingAs($u)->postJson('/api/city/research', $body)->assertOk();

        $this->assertEqualsWithDelta(980.0, $this->knowledgeOf($city), 0.0001, '知识只扣一次');
        $this->assertSame($revisionBefore + 1, (int) DB::table('cities')->where('id', $city->id)->value('revision'), 'revision 只涨一次');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'TECH.RESEARCH_START')->count(), '审计只写一条');
        $this->assertSame(1, DB::table('city_technologies')->where('city_id', $city->id)->count());
    }

    public function test_stale_revision_is_rejected(): void
    {
        [$u, $city] = $this->makeCity('techK');
        $current = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->actingAs($u)->postJson('/api/city/research', [
            'tech_id' => 'TECH_I_SUST', 'expected_revision' => $current + 99,
        ])->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);

        $this->assertSame(0, DB::table('city_technologies')->count());
        $this->assertEqualsWithDelta(1000.0, $this->knowledgeOf($city), 0.0001);
    }

    // ---- 越权 ----

    // 端点不接受 city_id:玩家 B 无论怎么发请求,受影响的只能是自己的城
    public function test_research_never_touches_another_players_city(): void
    {
        [$ua, $ca] = $this->makeCity('techOwner');
        [$ub, $cb] = $this->makeCity('techOther');
        $revisionA = (int) DB::table('cities')->where('id', $ca->id)->value('revision');

        $this->actingAs($ub)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])->assertOk();

        $this->assertSame(0, DB::table('city_technologies')->where('city_id', $ca->id)->count(), '受害者的城不得多出研究');
        $this->assertSame(1, DB::table('city_technologies')->where('city_id', $cb->id)->count());
        $this->assertEqualsWithDelta(1000.0, $this->knowledgeOf($ca), 0.0001, '受害者的知识不得被扣');
        $this->assertSame($revisionA, (int) DB::table('cities')->where('id', $ca->id)->value('revision'));
    }

    public function test_research_requires_auth(): void
    {
        $this->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])->assertStatus(401);
        $this->assertSame(0, DB::table('city_technologies')->count());
    }

    // ---- 懒完成 ----

    // 时间推进过 finished_at 后,下一次快照把在研项翻成 unlocked,TECH.UNLOCK 只记一次
    public function test_finished_research_unlocks_lazily_on_snapshot(): void
    {
        [$u, $city] = $this->makeCity('techL');
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])->assertOk();

        // 未到点:快照仍然是在研
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:30'));
        $this->actingAs($u)->getJson('/api/city')
            ->assertOk()
            ->assertJsonPath('data.city.technologies.researching.tech_id', 'TECH_I_SUST')
            ->assertJsonPath('data.city.technologies.unlocked', []);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'TECH.UNLOCK')->count());

        // 到点后:翻成已解锁
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:02:00'));
        $this->actingAs($u)->getJson('/api/city')
            ->assertOk()
            ->assertJsonPath('data.city.technologies.researching', null)
            ->assertJsonPath('data.city.technologies.unlocked', ['TECH_I_SUST'])
            // 派生时代:解锁了时代 I 的科技 → 可研究到时代 II
            ->assertJsonPath('data.city.technologies.current_era_order', 1)
            ->assertJsonPath('data.city.technologies.max_research_era_order', 2);

        $this->assertSame('unlocked', DB::table('city_technologies')->where('city_id', $city->id)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'TECH.UNLOCK')->count());

        // 再刷两次快照:审计不得重复写(条件更新保证恰好一条)
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'TECH.UNLOCK')->count());
    }

    // 到点但还没刷快照时直接下一单:研究端点锁内也会先翻牌,不该被"上一项还在研"卡住
    public function test_research_endpoint_settles_finished_research_first(): void
    {
        [$u, $city] = $this->makeCity('techM');
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_I_SUST'])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:02:00'));
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_SUST'])->assertOk();

        $this->assertSame('unlocked', DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', 'TECH_I_SUST')->value('status'));
        $this->assertSame('researching', DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', 'TECH_II_SUST')->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'TECH.UNLOCK')->count());
    }

    // ---- 契约 ----

    public function test_technology_definitions_endpoint(): void
    {
        [$u] = $this->makeCity('techN');

        $res = $this->actingAs($u)->getJson('/api/definitions/technologies');
        $res->assertOk()->assertJsonStructure(['data' => ['technologies' => [[
            'tech_id', 'name', 'branch', 'era', 'era_order', 'cost', 'duration_minutes', 'prerequisites', 'unlock_building_ids',
        ]]]]);
        $this->assertCount(50, $res->json('data.technologies'));

        // 排序按 era_order → tech_id:时代 I 的五项排在最前
        $this->assertSame('I', $res->json('data.technologies.0.era'));

        $all = collect($res->json('data.technologies'))->keyBy('tech_id');
        $sust = $all['TECH_I_SUST'];
        $this->assertSame('生存采集', $sust['name']);
        $this->assertSame('survival_agriculture', $sust['branch']);
        $this->assertSame('I', $sust['era']);
        $this->assertSame(1, $sust['era_order']);
        $this->assertSame(['knowledge' => 20], $sust['cost']);
        // duration_minutes 是 DECIMAL 分钟;整数分钟会被 json_encode 编成 1(不是 1.0),前端按数字读
        $this->assertEqualsWithDelta(1.0, $sust['duration_minutes'], 0.0001);
        $this->assertEqualsWithDelta(1.2, $all['TECH_III_SUST']['duration_minutes'], 0.0001);
        $this->assertSame([], $sust['prerequisites']);
        $this->assertSame(['F01'], $sust['unlock_building_ids']);

        // 有前置的节点:前置原样透传定义表
        $this->assertSame(['TECH_I_SUST'], $all['TECH_II_SUST']['prerequisites']);
        $this->assertSame(10, $all['TECH_X_DEF']['era_order']);
    }

    public function test_definitions_endpoint_requires_auth(): void
    {
        $this->getJson('/api/definitions/technologies')->assertStatus(401);
    }

    public function test_snapshot_exposes_empty_technology_block_for_new_city(): void
    {
        [$u] = $this->makeCity('techO');

        $this->actingAs($u)->getJson('/api/city')
            ->assertOk()
            ->assertJsonPath('data.city.technologies.unlocked', [])
            ->assertJsonPath('data.city.technologies.researching', null)
            ->assertJsonPath('data.city.technologies.current_era_order', 0)
            ->assertJsonPath('data.city.technologies.max_research_era_order', 1);
    }
}
