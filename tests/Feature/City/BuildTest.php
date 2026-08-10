<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function actingUser(): User
    {
        $u = User::create(['username' => 'builder', 'name' => 'builder', 'email' => 'b@b.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        // 给足资源以便建造 F02(木材20/石料5/资金12)
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'wood'], ['amount' => 1000]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => 'stone'], ['amount' => 1000]);
        // 本组用例的主力建筑 F02 属于时代 II,而新城默认时代 I(M2-B6 加的闸门)。
        // 这些用例验的是资源/占地/上限/幂等/Revision,不该被时代闸门顺带挡住 → 直接把城市置于时代 II。
        // 时代闸门本身的正反用例单独写在下方
        $this->setEra($city->id, 'II', 2);
        // 同理,M2-B4 起建造还有科技闸门(F02 需 TECH_II_SUST):先铺好前置科技,
        // 科技闸门本身的正反用例同样单独写在下方
        $this->unlockTechFor($city->id, 'F02');
        return $u;
    }

    // 直接设置城市时代(测试夹具:正常路径只能走 POST /api/city/era/upgrade)
    private function setEra(int $cityId, string $eraKey, int $eraOrder): void
    {
        DB::table('cities')->where('id', $cityId)->update(['era_key' => $eraKey, 'era_order' => $eraOrder]);
    }

    public function test_build_succeeds_and_deducts_and_increments_revision(): void
    {
        $u = $this->actingUser();
        $res = $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2]);

        $res->assertOk();
        $res->assertJson(['success' => true, 'data' => ['revision' => 1]]);
        $city = City::where('user_id', $u->id)->first();
        $this->assertSame(1, (int) $city->revision);
        $this->assertDatabaseHas('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F02', 'x' => 2, 'y' => 2]);
        $wood = (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $this->assertSame(980.0, $wood); // 1000 - 20
        $this->assertSame('BUILDING.BUILD', DB::table('audit_logs')->latest('id')->first()->action);

        // M2-C5:落地即 constructing,带服务器算出的完工时刻(施工计时的细则在 ConstructionTest)
        $inst = DB::table('city_building_instances')->where('city_id', $city->id)->first();
        $this->assertSame('constructing', $inst->status);
        $this->assertNotNull($inst->construction_finished_at);
        // 响应带回真实实例 id 与完工时刻,前端不必等一轮快照才画得出倒计时
        $this->assertSame((int) $inst->id, $res->json('data.building.id'));
        $this->assertSame('constructing', $res->json('data.building.status'));
        $this->assertNotNull($res->json('data.building.construction_finished_at'));
    }

    public function test_build_rejects_insufficient_resources(): void
    {
        $u = User::create(['username' => 'poor', 'name' => 'poor', 'email' => 'p@p.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);
        $this->setEra($city->id, 'II', 2); // 时代闸门排在材料校验之前,先把时代垫够才验得到 INSUFFICIENT_RESOURCE
        $this->unlockTechFor($city->id, 'F02'); // 科技闸门同样排在材料之前(v3.2 §4)

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 1, 'y' => 1])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);
        $this->assertDatabaseMissing('city_building_instances', ['city_id' => $city->id]);
    }

    // ---- 时代闸门(M2-B6,v3.2 §4「时代 → 科技 → …→ 土地 → 材料」) ----

    // 新城默认时代 I:时代 II 的 F02 一律拒绝,且不扣资源、不涨 revision
    public function test_build_rejects_building_above_city_era(): void
    {
        $u = User::create(['username' => 'eralow', 'name' => 'eralow', 'email' => 'eralow@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 1000]);
        $this->unlockTechFor($city->id, 'F02'); // 科技铺满,证明挡下来的确实是时代而不是科技
        $woodBefore = (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2])
            ->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        $this->assertDatabaseMissing('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F02']);
        $this->assertSame($woodBefore, (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount'));
        $this->assertSame(0, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
    }

    // 同一座时代 I 的城:时代 I 的 F01 采集营地照常放行(闸门只挡"超时代",不影响当代建筑)
    public function test_build_allows_building_of_current_era(): void
    {
        $u = User::create(['username' => 'eraok', 'name' => 'eraok', 'email' => 'eraok@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $this->unlockTechFor($city->id, 'F01');

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F01', 'x' => 2, 'y' => 2])->assertOk();

        $this->assertDatabaseHas('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F01']);
    }

    // 检查顺序:时代闸门必须排在占地之前 —— 时代不够时报的是 ERA_REQUIRED,而不是 LAND_OCCUPIED
    public function test_era_gate_is_checked_before_land(): void
    {
        $u = $this->actingUser();                  // 时代 II
        $city = City::where('user_id', $u->id)->first();
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2])->assertOk();

        // 退回时代 I 后,往已被占用的同一块地建 F02:先撞时代闸门
        $this->setEra($city->id, 'I', 1);
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 3, 'y' => 3])
            ->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);
    }

    // ---- 科技闸门(M2-B4,v3.2 §4.1「if (!city.researchedTechIds.has(def.techId)) return fail(TECH_LOCKED)」) ----

    // 前置科技未解锁:拒绝,且不扣资源、不涨 revision、不落实例
    public function test_build_rejects_when_prerequisite_tech_not_unlocked(): void
    {
        $u = User::create(['username' => 'techlow', 'name' => 'techlow', 'email' => 'techlow@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 1000]);
        $woodBefore = (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');

        // F01 是时代 I 建筑(时代闸门放行),但需要 TECH_I_SUST,新城一项科技都没有
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F01', 'x' => 2, 'y' => 2])
            ->assertStatus(422)->assertJson(['error' => 'TECH_NOT_UNLOCKED']);

        $this->assertDatabaseMissing('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F01']);
        $this->assertSame($woodBefore, (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount'));
        $this->assertSame(0, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
    }

    // 在研不算解锁:researching 状态的前置科技照样挡下
    public function test_build_rejects_when_prerequisite_tech_is_only_researching(): void
    {
        $u = User::create(['username' => 'techwip', 'name' => 'techwip', 'email' => 'techwip@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 1000]);
        DB::table('city_technologies')->insert([
            'city_id' => $city->id, 'tech_id' => 'TECH_I_SUST', 'status' => 'researching',
            'started_at' => now(), 'finished_at' => now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F01', 'x' => 2, 'y' => 2])
            ->assertStatus(422)->assertJson(['error' => 'TECH_NOT_UNLOCKED']);
    }

    // 解锁后放行:同一座城、同一次请求,只是把科技翻成 unlocked
    public function test_build_allows_when_prerequisite_tech_unlocked(): void
    {
        $u = User::create(['username' => 'techok', 'name' => 'techok', 'email' => 'techok@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 1000]);

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F01', 'x' => 2, 'y' => 2])
            ->assertStatus(422)->assertJson(['error' => 'TECH_NOT_UNLOCKED']);

        $this->unlockTech($city->id, 'TECH_I_SUST');

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F01', 'x' => 2, 'y' => 2])->assertOk();
        $this->assertDatabaseHas('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F01']);
    }

    // 检查顺序:时代排在科技之前 —— 两个闸门都不满足时报 ERA_REQUIRED(v3.2 §4「时代 → 科技」)
    public function test_era_gate_is_checked_before_tech(): void
    {
        $u = User::create(['username' => 'eratech', 'name' => 'eratech', 'email' => 'eratech@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 1000]);

        // 新城:时代 I + 无科技,建时代 II 的 F02 → 先撞时代闸门
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2])
            ->assertStatus(422)->assertJson(['error' => 'ERA_REQUIRED']);

        // 垫到时代 II 后,同一请求改报科技
        $this->setEra($city->id, 'II', 2);
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2])
            ->assertStatus(422)->assertJson(['error' => 'TECH_NOT_UNLOCKED']);
    }

    // 检查顺序:科技排在材料之前 —— 科技没解锁时报的是 TECH_NOT_UNLOCKED,而不是 INSUFFICIENT_RESOURCE
    public function test_tech_gate_is_checked_before_resources(): void
    {
        $u = User::create(['username' => 'techpoor', 'name' => 'techpoor', 'email' => 'techpoor@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);
        DB::table('cities')->where('id', $city->id)->update(['money' => 0]);

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F01', 'x' => 2, 'y' => 2])
            ->assertStatus(422)->assertJson(['error' => 'TECH_NOT_UNLOCKED']);
    }

    public function test_build_rejects_occupied_land(): void
    {
        $u = $this->actingUser();
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2])->assertOk();
        // 与已建重叠(F02 占 3x3,在 2,2);施工中的建筑同样占地,不能拿"还没建好"当借口重叠
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 3, 'y' => 3])
            ->assertStatus(422)->assertJson(['error' => 'LAND_OCCUPIED']);
    }

    public function test_build_is_idempotent(): void
    {
        $u = $this->actingUser();
        $city = City::where('user_id', $u->id)->first();
        $wood = fn () => (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $before = $wood();

        $body = ['building_id' => 'F02', 'x' => 5, 'y' => 5, 'idempotency_key' => 'fixed-key-1'];
        $this->actingAs($u)->postJson('/api/city/build', $body)->assertOk();
        $this->actingAs($u)->postJson('/api/city/build', $body)->assertOk(); // 重复不再扣/不再建
        $count = DB::table('city_building_instances')->where('city_id', $city->id)->where('x', 5)->where('y', 5)->count();
        $this->assertSame(1, $count);
        $this->assertSame($before - 20, $wood()); // 木材只扣一次(F02 建造花费 20)

        // 幂等键落库:city_id / request_hash / expires_at 都要写入
        $row = DB::table('idempotency_keys')->where('user_id', $u->id)->where('key', 'fixed-key-1')->first();
        $this->assertSame((int) $city->id, (int) $row->city_id);
        $this->assertNotNull($row->request_hash);
        $this->assertSame(64, strlen($row->request_hash));
        $this->assertNotNull($row->expires_at);
    }

    public function test_same_key_reused_for_another_action_is_rejected(): void
    {
        $u = $this->actingUser();
        $city = City::where('user_id', $u->id)->first();
        $key = 'cross-action-key-1';

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2, 'idempotency_key' => $key])->assertOk();
        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');
        $wood = fn () => (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $money = fn () => (float) DB::table('cities')->where('id', $city->id)->value('money');
        [$woodBefore, $moneyBefore] = [$wood(), $money()];

        // 同一 key 换成 upgrade:必须 409,不能静默返回"成功"而什么都没做
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $instanceId, 'idempotency_key' => $key])
            ->assertStatus(409)->assertJson(['error' => 'IDEMPOTENCY_KEY_REUSED']);

        $this->assertSame(1, (int) DB::table('city_building_instances')->where('id', $instanceId)->value('level')); // 没升级
        $this->assertSame($woodBefore, $wood()); // 没扣资源
        $this->assertSame($moneyBefore, $money());
    }

    public function test_same_key_with_different_params_is_rejected(): void
    {
        $u = $this->actingUser();
        $city = City::where('user_id', $u->id)->first();
        $key = 'same-key-diff-params-1';

        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 2, 'y' => 2, 'idempotency_key' => $key])->assertOk();
        // 同 key 同 action,但坐标不同 → 请求指纹不一致,拒绝
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 8, 'y' => 8, 'idempotency_key' => $key])
            ->assertStatus(409)->assertJson(['error' => 'IDEMPOTENCY_KEY_REUSED']);

        $this->assertSame(1, DB::table('city_building_instances')->where('city_id', $city->id)->count());
        $this->assertDatabaseMissing('city_building_instances', ['city_id' => $city->id, 'x' => 8, 'y' => 8]);
    }

    public function test_build_revision_conflict(): void
    {
        $u = $this->actingUser();
        $this->actingAs($u)->postJson('/api/city/build', ['building_id' => 'F02', 'x' => 8, 'y' => 8, 'expected_revision' => 999])
            ->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);
    }
}
