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
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => '木材'], ['amount' => 1000]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => '石料'], ['amount' => 1000]);
        return $u;
    }

    public function test_build_succeeds_and_deducts_and_increments_revision(): void
    {
        $u = $this->actingUser();
        $res = $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 2, 'y' => 2]);

        $res->assertOk();
        $res->assertJson(['success' => true, 'data' => ['revision' => 1]]);
        $city = City::where('user_id', $u->id)->first();
        $this->assertSame(1, (int) $city->revision);
        $this->assertDatabaseHas('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F02', 'x' => 2, 'y' => 2]);
        $wood = (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', '木材')->value('amount');
        $this->assertSame(980.0, $wood); // 1000 - 20
        $this->assertSame('BUILDING.BUILD', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_build_rejects_insufficient_resources(): void
    {
        $u = User::create(['username' => 'poor', 'name' => 'poor', 'email' => 'p@p.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);

        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 1, 'y' => 1])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);
        $this->assertDatabaseMissing('city_building_instances', ['city_id' => $city->id]);
    }

    public function test_build_rejects_occupied_land(): void
    {
        $u = $this->actingUser();
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 2, 'y' => 2])->assertOk();
        // 与已建重叠(F02 占 3x3,在 2,2)
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 3, 'y' => 3])
            ->assertStatus(422)->assertJson(['error' => 'LAND_OCCUPIED']);
    }

    public function test_build_is_idempotent(): void
    {
        $u = $this->actingUser();
        $body = ['buildingId' => 'F02', 'x' => 5, 'y' => 5, 'idempotencyKey' => 'fixed-key-1'];
        $this->actingAs($u)->postJson('/api/city/build', $body)->assertOk();
        $this->actingAs($u)->postJson('/api/city/build', $body)->assertOk(); // 重复不再扣/不再建
        $city = City::where('user_id', $u->id)->first();
        $count = DB::table('city_building_instances')->where('city_id', $city->id)->where('x', 5)->where('y', 5)->count();
        $this->assertSame(1, $count);
    }

    public function test_build_revision_conflict(): void
    {
        $u = $this->actingUser();
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 8, 'y' => 8, 'expectedRevision' => 999])
            ->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);
    }
}
