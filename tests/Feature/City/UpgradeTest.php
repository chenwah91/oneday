<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    // 每个用例结束都复位 Carbon 假时间,避免污染后续用例
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function makeUserWithFarm(string $un): array
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 100000]);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;
        return [$u, $city, $id];
    }

    // 把时间推到该实例的完工时刻之后,再跑一次只读快照触发懒完工
    private function finishWork(User $u, int $instanceId): void
    {
        $finishedAt = DB::table('city_building_instances')->where('id', $instanceId)->value('construction_finished_at');
        Carbon::setTestNow(Carbon::parse($finishedAt)->addSecond());
        $this->actingAs($u)->getJson('/api/city')->assertOk();
    }

    // 升级下单只是开工:等级不变、状态转 upgrading、带服务器算出的完工时刻;到点才真正 +1
    public function test_upgrade_is_timed_not_instant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeUserWithFarm('upgradetimer');

        $res = $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id]);
        $res->assertOk();
        $res->assertJson(['data' => ['building' => ['level' => 1, 'target_level' => 2, 'status' => 'upgrading']]]);

        $inst = DB::table('city_building_instances')->where('id', $id)->first();
        $this->assertSame(1, (int) $inst->level, '升级期间等级不动,完工才 +1');
        $this->assertSame('upgrading', $inst->status);
        // 完工时刻 = 下单时刻 + 该级 duration_seconds(数值以定义表为准,不在测试里写死)
        $duration = (int) DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 2)->value('duration_seconds');
        $this->assertSame(
            Carbon::parse('2026-01-01 00:00:00')->addSeconds($duration)->format('Y-m-d H:i:s'),
            Carbon::parse($inst->construction_finished_at)->format('Y-m-d H:i:s')
        );

        // 还没到点:再取快照也不会翻正
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds($duration - 1));
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        $this->assertSame(1, (int) DB::table('city_building_instances')->where('id', $id)->value('level'));
        $this->assertSame('upgrading', DB::table('city_building_instances')->where('id', $id)->value('status'));

        // 到点:懒完工把它翻成 active 且 level + 1
        $this->finishWork($u, $id);
        $inst = DB::table('city_building_instances')->where('id', $id)->first();
        $this->assertSame(2, (int) $inst->level);
        $this->assertSame('active', $inst->status);
    }

    public function test_upgrade_l1_to_l2_to_l3(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeUserWithFarm('upgrader');

        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();
        $this->finishWork($u, $id);
        $this->assertSame(2, (int) CityBuildingInstance::find($id)->level);

        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();
        $this->finishWork($u, $id);
        $this->assertSame(3, (int) CityBuildingInstance::find($id)->level);

        // 满级被拒 —— 口径是数据驱动的(W13-2):种子只定义到 L3,查不到 L4 定义即满级,
        // 不是代码里写死 3;补上 L4 定义后同一栋楼就能继续升(见 test_upgrade_follows_defined_levels_beyond_three)
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])
            ->assertStatus(422)->assertJson(['error' => 'BUILDING_LIMIT_REACHED']);
    }

    // 等级无上限(W13-2):上限完全由 building_level_definition 决定 ——
    // 没有 L4 定义时 3 级封顶;补上 L4 定义后 3→4 立刻可升;没有 L5 时 L4 就是新的满级
    public function test_upgrade_follows_defined_levels_beyond_three(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeUserWithFarm('unlimited');
        // 等级推进的过程已由 test_upgrade_l1_to_l2_to_l3 覆盖,这里直接把实例推到 L3
        DB::table('city_building_instances')->where('id', $id)->update(['level' => 3]);

        // 没有 L4 定义:3 级即满级
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])
            ->assertStatus(422)->assertJson(['error' => 'BUILDING_LIMIT_REACHED']);

        // 补上 L4 定义(以 L3 行为模板,补数据不改数值口径):同一栋楼立刻能再升一级
        $l3 = (array) DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 3)->first();
        DB::table('building_level_definition')->insert(array_merge($l3, ['level' => 4]));

        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk()
            ->assertJson(['data' => ['building' => ['level' => 3, 'target_level' => 4, 'status' => 'upgrading']]]);
        $this->finishWork($u, $id);
        $this->assertSame(4, (int) CityBuildingInstance::find($id)->level);

        // 没有 L5 定义:L4 就是新的满级(错误码同一条 —— 前端译成「已达最高等级」)
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])
            ->assertStatus(422)->assertJson(['error' => 'BUILDING_LIMIT_REACHED']);
    }

    // 施工/升级中的建筑不能再下升级单(一栋楼同时只有一项工程)
    public function test_cannot_upgrade_while_already_upgrading(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeUserWithFarm('upgradebusy');
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();

        $wood = fn () => (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $before = $wood();

        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);
        $this->assertSame($before, $wood(), '被拒的第二单不得扣费');
    }

    public function test_upgrade_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeUserWithFarm('upgrader2');
        $wood = fn () => (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $before = $wood();

        // F02 L1→L2 花费:木材12/石料3/资金8
        $body = ['instance_id' => $id, 'idempotency_key' => 'upgrade-fixed-key-1'];
        $this->actingAs($u)->postJson('/api/city/upgrade', $body)->assertOk();
        $this->actingAs($u)->postJson('/api/city/upgrade', $body)->assertOk(); // 重复请求:同一 key,不再扣费/不再开工

        $this->assertSame($before - 12, $wood()); // 只扣了一次木材
        $this->finishWork($u, $id);
        $this->assertSame(2, (int) CityBuildingInstance::find($id)->level); // 停在 L2,未被重复升到 L3
    }

    // 缺料明细(W12):升级的 INSUFFICIENT_RESOURCE 同样带 details.missing,契约与建造一致
    // (F02 L1→L2:木材12/石料3/资金8)
    public function test_upgrade_insufficient_resource_lists_every_shortfall(): void
    {
        [$u, $city, $id] = $this->makeUserWithFarm('upgradepoor');
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->update(['amount' => 2]);
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'stone')->update(['amount' => 1]);
        DB::table('cities')->where('id', $city->id)->update(['money' => 3]);

        $res = $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id]);
        $res->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);

        $missing = collect($res->json('details.missing'))->keyBy('resource_id');
        $this->assertCount(3, $missing);
        foreach ([
            'wood'  => ['required' => 12.0, 'have' => 2.0, 'missing' => 10.0],
            'stone' => ['required' => 3.0,  'have' => 1.0, 'missing' => 2.0],
            'money' => ['required' => 8.0,  'have' => 3.0, 'missing' => 5.0],
        ] as $rid => $want) {
            $row = $missing[$rid];
            $this->assertSame($want['required'], (float) $row['required'], "$rid required");
            $this->assertSame($want['have'], (float) $row['have'], "$rid have");
            $this->assertSame($want['missing'], (float) $row['missing'], "$rid missing");
        }

        // 拒绝即整体回滚:等级/状态不动、资源没扣
        $inst = DB::table('city_building_instances')->where('id', $id)->first();
        $this->assertSame(1, (int) $inst->level);
        $this->assertSame('active', $inst->status);
        $this->assertSame(2.0, (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount'));
    }

    public function test_cannot_upgrade_another_players_building(): void
    {
        [$ua, $ca, $ida] = $this->makeUserWithFarm('ownerA');
        $ub = User::create(['username' => 'attackerB', 'name' => 'attackerB', 'email' => 'atb@x.com', 'password' => 'password123']);
        CityFactory::createForUser($ub);

        $this->actingAs($ub)->postJson('/api/city/upgrade', ['instance_id' => $ida])
            ->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
        // A 的建筑未被改动
        $this->assertSame(1, (int) CityBuildingInstance::find($ida)->level);
        $this->assertSame('active', CityBuildingInstance::find($ida)->status);
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', DB::table('audit_logs')->latest('id')->first()->action);
    }

    // ---- 取消升级(M2-C5,v3.2 §3.2「返还 70%,资金不返还」) ----

    public function test_cancel_upgrade_refunds_70_percent_materials_and_no_money(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeUserWithFarm('canceler');
        // 仓储上限默认 1000(无仓库),库存 100000 会让退款全被夹掉 —— 先压到有余量的水位
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 100]);
        DB::table('cities')->where('id', $city->id)->update(['money' => 1000]);

        $wood = fn () => (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $stone = fn () => (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'stone')->value('amount');
        $money = fn () => (float) DB::table('cities')->where('id', $city->id)->value('money');
        [$w0, $s0, $m0] = [$wood(), $stone(), $money()];

        // F02 L1→L2:木材 12 / 石料 3 / 资金 8
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();
        $this->assertSame($w0 - 12, $wood());
        $this->assertSame($m0 - 8, $money());

        $res = $this->actingAs($u)->postJson('/api/city/upgrade/cancel', ['instance_id' => $id]);
        $res->assertOk()->assertJson(['data' => ['building' => ['id' => $id, 'level' => 1, 'status' => 'active']]]);

        // 返还 floor(12 × 0.7) = 8 木材、floor(3 × 0.7) = 2 石料;资金一分不退(v3.2 §3.2)
        $this->assertSame($w0 - 12 + 8, $wood());
        $this->assertSame($s0 - 3 + 2, $stone());
        $this->assertSame($m0 - 8, $money(), '资金不返还');

        // 状态回 active、完工戳清空
        $inst = DB::table('city_building_instances')->where('id', $id)->first();
        $this->assertSame('active', $inst->status);
        $this->assertSame(1, (int) $inst->level);
        $this->assertNull($inst->construction_finished_at);

        // 审计:delta 记实际退到手的量
        $audit = DB::table('audit_logs')->where('action', 'BUILDING.UPGRADE_CANCEL')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(['wood' => 8, 'stone' => 2], json_decode($audit->delta_json, true));
        $this->assertSame((string) $id, $audit->entity_id);
    }

    // 非 upgrading 状态不能取消(active / constructing 都不行)
    public function test_cancel_rejects_non_upgrading_instance(): void
    {
        [$u, $city, $id] = $this->makeUserWithFarm('cancelbad');

        $this->actingAs($u)->postJson('/api/city/upgrade/cancel', ['instance_id' => $id])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'BUILDING.UPGRADE_CANCEL')->count());
    }

    // 已完工再取消:懒完工先把它翻成 active,取消随即被拒 —— 不存在"完工了还能退款"
    public function test_cancel_after_finished_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeUserWithFarm('cancellate');
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();

        $finishedAt = DB::table('city_building_instances')->where('id', $id)->value('construction_finished_at');
        Carbon::setTestNow(Carbon::parse($finishedAt)->addSecond());

        $this->actingAs($u)->postJson('/api/city/upgrade/cancel', ['instance_id' => $id])
            ->assertStatus(422)->assertJson(['error' => 'VALIDATION_ERROR']);

        // 被拒时整个事务回滚(锁内那次结算与懒完工一并回滚,与建造失败同一口径),
        // 所以要再走一次只读快照才把完工落库 —— 落库后等级确实已经是 L2,取消无从谈起
        $this->actingAs($u)->getJson('/api/city')->assertOk();
        $this->assertSame(2, (int) DB::table('city_building_instances')->where('id', $id)->value('level'));
        $this->assertSame('active', DB::table('city_building_instances')->where('id', $id)->value('status'));
    }

    public function test_cancel_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeUserWithFarm('cancelidem');
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 100]);
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();

        $wood = fn () => (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $body = ['instance_id' => $id, 'idempotency_key' => 'cancel-fixed-key-1'];

        $this->actingAs($u)->postJson('/api/city/upgrade/cancel', $body)->assertOk();
        $afterFirst = $wood();
        $revAfterFirst = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->actingAs($u)->postJson('/api/city/upgrade/cancel', $body)->assertOk(); // 重放:不再退第二次
        $this->assertSame($afterFirst, $wood());
        $this->assertSame($revAfterFirst, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'BUILDING.UPGRADE_CANCEL')->count());
    }

    public function test_cannot_cancel_another_players_upgrade(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$ua, $ca, $ida] = $this->makeUserWithFarm('cancelOwnerA');
        $this->actingAs($ua)->postJson('/api/city/upgrade', ['instance_id' => $ida])->assertOk();

        $ub = User::create(['username' => 'cancelAttacker', 'name' => 'cancelAttacker', 'email' => 'ca@x.com', 'password' => 'password123']);
        CityFactory::createForUser($ub);

        $this->actingAs($ub)->postJson('/api/city/upgrade/cancel', ['instance_id' => $ida])
            ->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
        $this->assertSame('upgrading', DB::table('city_building_instances')->where('id', $ida)->value('status'));
    }

    public function test_cancel_rejects_stale_expected_revision(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeUserWithFarm('cancelrev');
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();

        $this->actingAs($u)->postJson('/api/city/upgrade/cancel', ['instance_id' => $id, 'expected_revision' => 999])
            ->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);
        $this->assertSame('upgrading', DB::table('city_building_instances')->where('id', $id)->value('status'));
    }
}
