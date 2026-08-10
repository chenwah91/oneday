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

class DemolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    // 每个用例结束都复位 Carbon 假时间,避免污染后续用例
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    // 一座城 + 一栋 active 的 F02;资源压到 100(仓储上限默认 1000,留足返还余量)
    private function makeCityWithFarm(string $un): array
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 100]);
        DB::table('cities')->where('id', $city->id)->update(['money' => 1000]);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;

        return [$u, $city, $id];
    }

    private function amount(City $city, string $resourceId): float
    {
        return (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $resourceId)->value('amount');
    }

    public function test_demolish_own_building(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('razer');

        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id])->assertOk();
        $this->assertDatabaseMissing('city_building_instances', ['id' => $id]);
        $this->assertSame('BUILDING.DEMOLISH', DB::table('audit_logs')->latest('id')->first()->action);
    }

    // ---- 返还(M2-C5,v3.2 §10.9 拆除 50% / §3.2 取消 70%,资金一律不返还) ----

    // L1 的 active 建筑:退 L1 建造材料的 50%(F02 L1 = 木材 20 / 石料 5 / 资金 12)
    public function test_demolish_active_l1_refunds_50_percent_materials(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('refund1');
        [$w0, $s0, $m0] = [$this->amount($city, 'wood'), $this->amount($city, 'stone'), (float) DB::table('cities')->where('id', $city->id)->value('money')];

        $res = $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id]);
        $res->assertOk();

        // floor(20 × 0.5) = 10 木材、floor(5 × 0.5) = 2 石料;资金一分不退
        $this->assertSame($w0 + 10, $this->amount($city, 'wood'));
        $this->assertSame($s0 + 2, $this->amount($city, 'stone'));
        $this->assertSame($m0, (float) DB::table('cities')->where('id', $city->id)->value('money'), '资金不返还');
        $this->assertSame(['wood' => 10, 'stone' => 2], $res->json('data.delta'));

        $audit = DB::table('audit_logs')->where('action', 'BUILDING.DEMOLISH')->latest('id')->first();
        $this->assertSame(['wood' => 10, 'stone' => 2], json_decode($audit->delta_json, true));
    }

    // L3 的 active 建筑:退「L1 + L2 + L3」累计建造材料的 50%
    public function test_demolish_active_l3_refunds_cumulative_materials(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('refund3');
        DB::table('city_building_instances')->where('id', $id)->update(['level' => 3]);
        $w0 = $this->amount($city, 'wood');

        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id])->assertOk();

        // F02 木材:L1 20 + L2 12 + L3 20 = 52 → floor(52 × 0.5) = 26
        $cumulativeWood = 0;
        foreach ([1, 2, 3] as $lv) {
            $cost = json_decode((string) DB::table('building_level_definition')->where('building_id', 'F02')->where('level', $lv)->value('cost_json'), true);
            $cumulativeWood += (int) ($cost['wood'] ?? 0);
        }
        $this->assertSame($w0 + floor($cumulativeWood * 0.5), $this->amount($city, 'wood'));
    }

    // constructing:拆除 = 取消建造,退 L1 材料的 70%(比拆除的 50% 高,§10.9「防止拆建套利」)
    public function test_demolish_constructing_refunds_70_percent(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('refundwip');
        DB::table('city_building_instances')->where('id', $id)
            ->update(['status' => 'constructing', 'construction_finished_at' => now()->addHour()]);
        [$w0, $s0] = [$this->amount($city, 'wood'), $this->amount($city, 'stone')];

        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id])->assertOk();

        // floor(20 × 0.7) = 14 木材、floor(5 × 0.7) = 3 石料
        $this->assertSame($w0 + 14, $this->amount($city, 'wood'));
        $this->assertSame($s0 + 3, $this->amount($city, 'stone'));
    }

    // upgrading:先按取消退该次升级材料 70%,再按拆除退已完工等级材料 50%
    public function test_demolish_upgrading_refunds_cancel_plus_demolish(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$u, $city, $id] = $this->makeCityWithFarm('refundup');
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();
        $this->assertSame('upgrading', DB::table('city_building_instances')->where('id', $id)->value('status'));

        [$w0, $s0] = [$this->amount($city, 'wood'), $this->amount($city, 'stone')];

        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id])->assertOk();

        // 已完工等级 L1:floor(20 × 0.5) = 10 木材 + floor(5 × 0.5) = 2 石料
        // 在建的 L2:floor(12 × 0.7) = 8 木材 + floor(3 × 0.7) = 2 石料
        $this->assertSame($w0 + 10 + 8, $this->amount($city, 'wood'));
        $this->assertSame($s0 + 2 + 2, $this->amount($city, 'stone'));
    }

    // 仓储上限截断:超出上限的返还按内核口径截掉,截断量进审计 metadata,资源不越过上限
    public function test_demolish_refund_is_clamped_by_storage_cap(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('refundfull');
        // 仓储上限 1000(无仓库):木材顶到 995,只剩 5 的空间,应退 10 → 只到手 5
        DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->update(['amount' => 995]);

        $res = $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id]);
        $res->assertOk();

        $this->assertSame(1000.0, $this->amount($city, 'wood'), '资源被夹在仓储上限');
        $this->assertSame(5.0, (float) $res->json('data.delta.wood'));
        $this->assertSame(5.0, (float) $res->json('data.truncated.wood'));

        $audit = DB::table('audit_logs')->where('action', 'BUILDING.DEMOLISH')->latest('id')->first();
        $meta = json_decode($audit->metadata_json, true);
        $this->assertSame(5, (int) $meta['truncated']['wood']);
    }

    public function test_double_demolish_does_not_phantom(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('razer2');

        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id])->assertOk();
        $revisionAfterFirst = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        // 同一实例再次拆除:应返回 404,且不产生"假成功"的 revision 空涨
        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id])
            ->assertStatus(404)->assertJson(['error' => 'NOT_FOUND']);
        $revisionAfterSecond = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->assertSame($revisionAfterFirst, $revisionAfterSecond);
    }

    public function test_demolish_is_idempotent(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('razer3');
        $body = ['instance_id' => $id, 'idempotency_key' => 'demolish-fixed-key-1'];

        $first = $this->actingAs($u)->postJson('/api/city/demolish', $body);
        $first->assertOk();
        $revisionAfterFirst = (int) DB::table('cities')->where('id', $city->id)->value('revision');
        $this->assertSame(1, $revisionAfterFirst);
        $woodAfterFirst = $this->amount($city, 'wood');

        // 同一 key 重放:返回相同 demolished_id,不再删第二次,不再退第二次,revision 不再涨
        $second = $this->actingAs($u)->postJson('/api/city/demolish', $body);
        $second->assertOk()->assertJson(['success' => true, 'data' => ['demolished_id' => $id]]);
        $this->assertSame($first->json('data.demolished_id'), $second->json('data.demolished_id'));
        $this->assertSame($revisionAfterFirst, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
        $this->assertSame($woodAfterFirst, $this->amount($city, 'wood'), '重放不得重复返还材料');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'BUILDING.DEMOLISH')->count());
    }

    public function test_demolish_rejects_stale_expected_revision(): void
    {
        [$u, $city, $id] = $this->makeCityWithFarm('razer4');
        $woodBefore = $this->amount($city, 'wood');

        $this->actingAs($u)->postJson('/api/city/demolish', ['instance_id' => $id, 'expected_revision' => 999])
            ->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);

        // 建筑还在,revision 没变,也没有退款
        $this->assertDatabaseHas('city_building_instances', ['id' => $id]);
        $this->assertSame(0, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
        $this->assertSame($woodBefore, $this->amount($city, 'wood'));
    }

    public function test_cannot_demolish_others_building(): void
    {
        [$ua, $ca, $id] = $this->makeCityWithFarm('da');
        $ub = User::create(['username' => 'db', 'name' => 'db', 'email' => 'db@x.com', 'password' => 'password123']);
        $cb = CityFactory::createForUser($ub);
        $woodBefore = $this->amount($cb, 'wood');

        $this->actingAs($ub)->postJson('/api/city/demolish', ['instance_id' => $id])->assertStatus(403);
        $this->assertDatabaseHas('city_building_instances', ['id' => $id]);
        $this->assertSame($woodBefore, $this->amount($cb, 'wood'), '越权拆除不得给攻击者退款');
    }
}
