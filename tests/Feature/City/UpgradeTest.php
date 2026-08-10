<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function makeUserWithFarm(string $un): array
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 100000]);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;
        return [$u, $city, $id];
    }

    public function test_upgrade_l1_to_l2_to_l3(): void
    {
        [$u, $city, $id] = $this->makeUserWithFarm('upgrader');
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();
        $this->assertSame(2, (int) CityBuildingInstance::find($id)->level);
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertOk();
        $this->assertSame(3, (int) CityBuildingInstance::find($id)->level);
        // L3 已满级,再升级被拒
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instance_id' => $id])->assertStatus(422);
    }

    public function test_upgrade_is_idempotent(): void
    {
        [$u, $city, $id] = $this->makeUserWithFarm('upgrader2');
        $wood = fn () => (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', 'wood')->value('amount');
        $before = $wood();

        // F02 L1→L2 花费:木材12/石料3/资金8
        $body = ['instance_id' => $id, 'idempotency_key' => 'upgrade-fixed-key-1'];
        $this->actingAs($u)->postJson('/api/city/upgrade', $body)->assertOk();
        $this->actingAs($u)->postJson('/api/city/upgrade', $body)->assertOk(); // 重复请求:同一 key,不再扣费/不再升级

        $this->assertSame(2, (int) CityBuildingInstance::find($id)->level); // 停在 L2,未被重复升到 L3
        $this->assertSame($before - 12, $wood()); // 只扣了一次木材
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
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', DB::table('audit_logs')->latest('id')->first()->action);
    }
}
