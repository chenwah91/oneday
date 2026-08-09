<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_demolish_own_building(): void
    {
        $u = User::create(['username' => 'razer', 'name' => 'razer', 'email' => 'r@z.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;

        $this->actingAs($u)->postJson('/api/city/demolish', ['instanceId' => $id])->assertOk();
        $this->assertDatabaseMissing('city_building_instances', ['id' => $id]);
        $this->assertSame('BUILDING.DEMOLISH', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_double_demolish_does_not_phantom(): void
    {
        $u = User::create(['username' => 'razer2', 'name' => 'razer2', 'email' => 'r2@z.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;

        $this->actingAs($u)->postJson('/api/city/demolish', ['instanceId' => $id])->assertOk();
        $revisionAfterFirst = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        // 同一实例再次拆除:应返回 404,且不产生"假成功"的 revision 空涨
        $this->actingAs($u)->postJson('/api/city/demolish', ['instanceId' => $id])
            ->assertStatus(404)->assertJson(['error' => 'NOT_FOUND']);
        $revisionAfterSecond = (int) DB::table('cities')->where('id', $city->id)->value('revision');

        $this->assertSame($revisionAfterFirst, $revisionAfterSecond);
    }

    public function test_demolish_is_idempotent(): void
    {
        $u = User::create(['username' => 'razer3', 'name' => 'razer3', 'email' => 'r3@z.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;

        $body = ['instanceId' => $id, 'idempotencyKey' => 'demolish-fixed-key-1'];
        $first = $this->actingAs($u)->postJson('/api/city/demolish', $body);
        $first->assertOk();
        $revisionAfterFirst = (int) DB::table('cities')->where('id', $city->id)->value('revision');
        $this->assertSame(1, $revisionAfterFirst);

        // 同一 key 重放:返回相同 demolishedId,不再删第二次,revision 不再涨
        $second = $this->actingAs($u)->postJson('/api/city/demolish', $body);
        $second->assertOk()->assertJson(['success' => true, 'data' => ['demolishedId' => $id]]);
        $this->assertSame($first->json('data.demolishedId'), $second->json('data.demolishedId'));
        $this->assertSame($revisionAfterFirst, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
    }

    public function test_demolish_rejects_stale_expected_revision(): void
    {
        $u = User::create(['username' => 'razer4', 'name' => 'razer4', 'email' => 'r4@z.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;

        $this->actingAs($u)->postJson('/api/city/demolish', ['instanceId' => $id, 'expectedRevision' => 999])
            ->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);

        // 建筑还在,revision 没变
        $this->assertDatabaseHas('city_building_instances', ['id' => $id]);
        $this->assertSame(0, (int) DB::table('cities')->where('id', $city->id)->value('revision'));
    }

    public function test_cannot_demolish_others_building(): void
    {
        $ua = User::create(['username' => 'da', 'name' => 'da', 'email' => 'da@x.com', 'password' => 'password123']);
        $ca = CityFactory::createForUser($ua);
        $id = CityBuildingInstance::create(['city_id' => $ca->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;
        $ub = User::create(['username' => 'db', 'name' => 'db', 'email' => 'db@x.com', 'password' => 'password123']);
        CityFactory::createForUser($ub);

        $this->actingAs($ub)->postJson('/api/city/demolish', ['instanceId' => $id])->assertStatus(403);
        $this->assertDatabaseHas('city_building_instances', ['id' => $id]);
    }
}
