<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\City\EraService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 时代升级 POST /api/city/era/upgrade(M2-B6)
// 覆盖:成功链(era_order+1 / 审计 / revision / 不扣费)、逐维条件各自不满足、
//       幂等重放、Revision 冲突、越权、快照 era 区块、条件矩阵自洽性。
//
// I→II 的条件(v3.2 §5.1):人口 50 / 知识 0 / 粮食 300 / 资金 100 /
//       住宅 H01≥3、储藏坑 S01≥1、采集营地 F01≥1 / 治理 40 / 幸福 50 / 国防 20
class EraUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // 一座「刚好全部达标」的时代 I 城市。时间冻结在建城时刻 → 结算 elapsed 恒为 0,断言不受产量干扰。
    // 建筑全部直接落库(不走建造端点):本组用例验的是升级闸门,不是建造流程
    private function makeQualifiedCity(string $un): array
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        // 建筑:住宅 ×3(人口容量 54,养得住 50 人)、储藏坑 ×1、采集营地 ×1、
        //       议事火堆 A01(治理容量 80 ≥ 40)、木栅栏 D01(国防 25 ≥ 20)
        $this->place($city, 'H01', 0, 0);
        $this->place($city, 'H01', 2, 0);
        $this->place($city, 'H01', 4, 0);
        $this->place($city, 'S01', 6, 0);
        $this->place($city, 'F01', 0, 3);
        $this->place($city, 'A01', 4, 3);
        $this->place($city, 'D01', 8, 0);

        // 人口 / 资金 / 幸福直接置到达标值;粮食 300、知识 0(§5.1 I→II 知识门槛为 0)
        DB::table('cities')->where('id', $city->id)->update([
            'population' => 60, 'money' => 500, 'happiness' => 60,
        ]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => 400]);
        // 知识显式清零:建城初始资源改由后台 game_settings.initial_resources 决定(默认送 100),
        // 本夹具要的是「知识维未达标」的城,不能跟着开局配置漂
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'knowledge'],
            ['amount' => 0]
        );

        return [$u, $city->fresh()];
    }

    private function place(City $city, string $buildingId, int $x, int $y): void
    {
        CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => $buildingId, 'level' => 1,
            'x' => $x, 'y' => $y, 'status' => 'active', 'assigned_workers' => 0,
        ]);
    }

    private function eraOf(City $city): array
    {
        $row = DB::table('cities')->where('id', $city->id)->first(['era_key', 'era_order']);

        return [$row->era_key, (int) $row->era_order];
    }

    // 从响应 details 里取某一维(building 维度用 building_id 区分)
    private function req(array $details, string $dimension, ?string $buildingId = null): array
    {
        foreach ($details['requirements'] as $r) {
            if ($r['dimension'] === $dimension && $r['building_id'] === $buildingId) {
                return $r;
            }
        }

        $this->fail("响应里没有 {$dimension} 维度");
    }

    // ---- 成功链 ----

    public function test_upgrade_succeeds_and_writes_audit(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraA');
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');
        $foodBefore = (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->value('amount');
        $moneyBefore = (float) DB::table('cities')->where('id', $city->id)->value('money');

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');

        $res->assertOk()->assertJson(['success' => true, 'data' => [
            // 费用:§5.1 八维全是「最低/储备」门槛,没有一项标注消耗 → 不扣任何资源,delta 为空
            'delta' => [],
            'era'   => ['era_key' => 'II', 'era_order' => 2],
        ]]);

        $this->assertSame(['II', 2], $this->eraOf($city));
        $this->assertSame($revisionBefore + 1, (int) DB::table('cities')->where('id', $city->id)->value('revision'));

        // 门槛不是费用:粮食与资金一分不少
        $this->assertEqualsWithDelta($foodBefore, (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->value('amount'), 0.0001);
        $this->assertEqualsWithDelta($moneyBefore, (float) DB::table('cities')->where('id', $city->id)->value('money'), 0.0001);

        $audit = DB::table('audit_logs')->where('action', 'ERA.UPGRADE')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('success', $audit->status);
        $this->assertSame('city', $audit->entity_type);
        $this->assertSame(['era_key' => 'I', 'era_order' => 1], json_decode($audit->before_json, true));
        $this->assertSame(['era_key' => 'II', 'era_order' => 2], json_decode($audit->after_json, true));
        $this->assertSame([], json_decode($audit->delta_json, true));
        $this->assertSame($revisionBefore, (int) $audit->city_revision_before);
        $this->assertSame($revisionBefore + 1, (int) $audit->city_revision_after);
        // 达标当时的逐维实测值进 metadata,便于事后回查
        $this->assertStringContainsString('governance', (string) $audit->metadata_json);
    }

    // 升级后:时代 II 的建筑可以建、时代 II 的科技可以研究(闸门统一读 cities.era_order)
    public function test_upgrade_opens_next_era_build_and_research(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraB');
        // 建造闸门是「时代 → 科技」两道(v3.2 §4);本用例验的是时代那一道,
        // 所以先把 F02 的前置科技 TECH_II_SUST 铺好,免得升级后被科技闸门顶替着挡下
        $this->unlockTechFor($city->id, 'F02');

        // 升级前:时代 II 的 F02 被拒
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 12, 'y' => 12])
            ->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $this->actingAs($u)->postJson('/api/city/era/upgrade')->assertOk();

        // 升级后:同一次建造放行
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 12, 'y' => 12])->assertOk();

        // 研究闸门同样跟着放开(TECH_II_CIV 需前置 TECH_I_CIV,先铺上)
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'knowledge'], ['amount' => 500]
        );
        $this->unlockTech($city->id, 'TECH_I_CIV');
        $this->actingAs($u)->postJson('/api/city/research', ['tech_id' => 'TECH_II_CIV'])->assertOk();
    }

    // ---- 逐维条件不满足 ----

    public function test_population_below_threshold_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraPop');
        DB::table('cities')->where('id', $city->id)->update(['population' => 49]);

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
        $res->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $details = $res->json('details');
        $this->assertSame('II', $details['era_key']);
        $row = $this->req($details, 'population');
        $this->assertFalse($row['met']);
        $this->assertEqualsWithDelta(50.0, $row['required'], 0.0001);
        $this->assertEqualsWithDelta(49.0, $row['current'], 0.0001);
        // 其他维度仍然出现在清单里且已满足 —— 前端要显示完整清单,不是只显示缺口
        $this->assertTrue($this->req($details, 'happiness')['met']);

        $this->assertSame(['I', 1], $this->eraOf($city));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ERA.UPGRADE')->count());
    }

    public function test_knowledge_below_threshold_is_rejected(): void
    {
        // I→II 的知识门槛是 0(§5.1),用 II→III 这一档验知识维:门槛 100
        [$u, $city] = $this->makeQualifiedCity('eraKnow');
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'II', 'era_order' => 2]);

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
        $res->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $row = $this->req($res->json('details'), 'knowledge');
        $this->assertFalse($row['met']);
        $this->assertEqualsWithDelta(100.0, $row['required'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $row['current'], 0.0001);
    }

    public function test_food_below_threshold_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraFood');
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'food')->update(['amount' => 299]);

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
        $res->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $row = $this->req($res->json('details'), 'food');
        $this->assertFalse($row['met']);
        $this->assertEqualsWithDelta(300.0, $row['required'], 0.0001);
    }

    public function test_money_below_threshold_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraMoney');
        DB::table('cities')->where('id', $city->id)->update(['money' => 99]);

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
        $res->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $row = $this->req($res->json('details'), 'money');
        $this->assertFalse($row['met']);
        $this->assertEqualsWithDelta(100.0, $row['required'], 0.0001);
        $this->assertEqualsWithDelta(99.0, $row['current'], 0.0001);
    }

    public function test_missing_required_building_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraBldg');
        // 拆掉一座住宅 → H01 只剩 2 座,不足 3
        $one = DB::table('city_building_instances')->where('city_id', $city->id)->where('building_id', 'H01')->first();
        DB::table('city_building_instances')->where('id', $one->id)->delete();

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
        $res->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $row = $this->req($res->json('details'), 'building', 'H01');
        $this->assertFalse($row['met']);
        $this->assertEqualsWithDelta(3.0, $row['required'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $row['current'], 0.0001);
        // 另外两栋必需建筑仍然满足
        $this->assertTrue($this->req($res->json('details'), 'building', 'S01')['met']);
        $this->assertTrue($this->req($res->json('details'), 'building', 'F01')['met']);
    }

    public function test_governance_below_threshold_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraGov');
        // 拆掉议事火堆 A01(唯一的治理容量来源,80 → 0)
        DB::table('city_building_instances')->where('city_id', $city->id)->where('building_id', 'A01')->delete();

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
        $res->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $row = $this->req($res->json('details'), 'governance');
        $this->assertFalse($row['met']);
        $this->assertEqualsWithDelta(40.0, $row['required'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $row['current'], 0.0001);
    }

    public function test_happiness_below_threshold_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraHappy');
        DB::table('cities')->where('id', $city->id)->update(['happiness' => 49]);

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
        $res->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $row = $this->req($res->json('details'), 'happiness');
        $this->assertFalse($row['met']);
        $this->assertEqualsWithDelta(50.0, $row['required'], 0.0001);
    }

    public function test_defense_below_threshold_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraDef');
        // 拆掉木栅栏 D01(唯一的国防值来源,25 → 0)
        DB::table('city_building_instances')->where('city_id', $city->id)->where('building_id', 'D01')->delete();

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
        $res->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $row = $this->req($res->json('details'), 'defense');
        $this->assertFalse($row['met']);
        $this->assertEqualsWithDelta(20.0, $row['required'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $row['current'], 0.0001);
    }

    // 已是最高时代:没有下一档可升
    public function test_upgrade_at_max_era_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraMax');
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'X', 'era_order' => 10]);

        $res = $this->actingAs($u)->postJson('/api/city/era/upgrade');
        $res->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);
        $this->assertSame('max_era_reached', $res->json('details.reason'));

        $this->assertSame(['X', 10], $this->eraOf($city));
    }

    // ---- 并发与幂等 ----

    public function test_upgrade_is_idempotent(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraIdem');
        $revisionBefore = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $body = ['idempotency_key' => 'era-fixed-key-1'];
        $this->actingAs($u)->postJson('/api/city/era/upgrade', $body)->assertOk();
        // 重放:不得再升一代(否则一个键就能连跳两代)
        $this->actingAs($u)->postJson('/api/city/era/upgrade', $body)->assertOk();

        $this->assertSame(['II', 2], $this->eraOf($city));
        $this->assertSame($revisionBefore + 1, (int) DB::table('cities')->where('id', $city->id)->value('revision'), 'revision 只涨一次');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ERA.UPGRADE')->count(), '审计只写一条');
    }

    // 同一个 key 换成建造:必须 409,不能静默当成重放
    public function test_same_key_reused_for_another_action_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraKeyReuse');
        $key = 'era-cross-action-key';

        $this->actingAs($u)->postJson('/api/city/era/upgrade', ['idempotency_key' => $key])->assertOk();

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 12, 'y' => 12, 'idempotency_key' => $key])
            ->assertStatus(409)->assertJson(['error' => 'IDEMPOTENCY_KEY_REUSED']);

        $this->assertDatabaseMissing('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F02']);
    }

    public function test_stale_revision_is_rejected(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraRev');
        $current = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->actingAs($u)->postJson('/api/city/era/upgrade', ['expected_revision' => $current + 99])
            ->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);

        $this->assertSame(['I', 1], $this->eraOf($city));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ERA.UPGRADE')->count());
    }

    // ---- 越权 ----

    // 端点不接受 city_id:玩家 B 升级影响的只能是自己的城
    public function test_upgrade_never_touches_another_players_city(): void
    {
        [$ua, $ca] = $this->makeQualifiedCity('eraOwner');
        [$ub, $cb] = $this->makeQualifiedCity('eraOther');
        $revisionA = (int) DB::table('cities')->where('id', $ca->id)->value('revision');

        $this->actingAs($ub)->postJson('/api/city/era/upgrade')->assertOk();

        $this->assertSame(['I', 1], $this->eraOf($ca), '受害者的城不得被升级');
        $this->assertSame(['II', 2], $this->eraOf($cb));
        $this->assertSame($revisionA, (int) DB::table('cities')->where('id', $ca->id)->value('revision'));
    }

    public function test_upgrade_requires_auth(): void
    {
        $this->postJson('/api/city/era/upgrade')->assertStatus(401);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'ERA.UPGRADE')->count());
    }

    // ---- 快照契约 ----

    public function test_snapshot_exposes_era_block(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraSnap');

        $res = $this->actingAs($u)->getJson('/api/city');
        $res->assertOk()
            ->assertJsonPath('data.city.era.era_key', 'I')
            ->assertJsonPath('data.city.era.era_order', 1)
            ->assertJsonPath('data.city.era.next.era_key', 'II')
            ->assertJsonPath('data.city.era.next.era_order', 2);

        $reqs = $res->json('data.city.era.next.requirements');
        // 八个维度 + 三栋必须建筑各一行 = 10 行(人口/知识/粮食/资金/H01/S01/F01/治理/幸福/国防)
        $this->assertCount(10, $reqs);
        foreach ($reqs as $r) {
            $this->assertArrayHasKey('dimension', $r);
            $this->assertArrayHasKey('required', $r);
            $this->assertArrayHasKey('current', $r);
            $this->assertArrayHasKey('met', $r);
            $this->assertTrue($r['met'], '这座城是按全部达标造的');
        }
    }

    // 最高时代:next 为 null,前端据此隐藏升级按钮
    public function test_snapshot_next_is_null_at_max_era(): void
    {
        [$u, $city] = $this->makeQualifiedCity('eraSnapMax');
        DB::table('cities')->where('id', $city->id)->update(['era_key' => 'X', 'era_order' => 10]);

        $this->actingAs($u)->getJson('/api/city')
            ->assertOk()
            ->assertJsonPath('data.city.era.era_key', 'X')
            ->assertJsonPath('data.city.era.next', null);
    }

    // ---- 条件矩阵自洽 ----

    // 条件矩阵引用的建筑必须真实存在,且属于「升级前就能建出来的时代」——
    // 引用一栋目标时代的建筑会造成死锁(要升级先建它,要建它先升级)
    public function test_requirement_buildings_are_buildable_before_upgrade(): void
    {
        $reflection = new \ReflectionClass(EraService::class);
        $matrix = $reflection->getConstant('REQUIREMENTS');
        $orders = EraService::orders();

        foreach ($matrix as $targetOrder => $need) {
            foreach ($need['buildings'] as $buildingId => $count) {
                $def = DB::table('building_definition')->where('building_id', $buildingId)->first();
                $this->assertNotNull($def, "条件矩阵引用了不存在的建筑 {$buildingId}");
                $this->assertLessThanOrEqual(
                    $targetOrder - 1,
                    (int) $orders[$def->era_key],
                    "升到时代 {$targetOrder} 的条件引用了 {$buildingId}(时代 {$def->era_key}),升级前建不出来"
                );
                $this->assertGreaterThan(0, $count);
            }
        }
    }

    // 矩阵覆盖 era 表的每一次升级(2..10),不多不少
    public function test_requirement_matrix_covers_every_era_step(): void
    {
        $reflection = new \ReflectionClass(EraService::class);
        $matrix = $reflection->getConstant('REQUIREMENTS');
        $maxOrder = (int) DB::table('era')->max('era_order');

        $this->assertSame(range(2, $maxOrder), array_keys($matrix));
    }
}
