<?php

namespace Tests\Feature\Definition;

use App\Game\City\CityFactory;
use App\Game\Resource\ResourceCode;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

// 资源 ID 英文化迁移本身的验证:
// 用 down() 把已 seed 的英文数据打回中文(等价于一个"迁移前的老存档库"),
// 再跑 up() 看能不能一字不差地转回来 —— 存量数值、JSON 结构、rs_code 都要守恒。
class ResourceIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_10_200002_migrate_resource_ids_to_english.php');
    }

    public function test_round_trip_preserves_every_value(): void
    {
        $u = User::create(['username' => 'migcity', 'name' => 'migcity', 'email' => 'mc@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        $resourcesBefore = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->all();
        $this->assertNotEmpty($resourcesBefore);
        $levelBefore = DB::table('building_level_definition')->where('building_id', 'P01')->where('level', 1)->first();
        $definitionBefore = DB::table('resource_definition')->orderBy('resource_id')->pluck('rs_code', 'resource_id')->all();

        $migration = $this->migration();

        // ---- 打回中文(模拟迁移前的老库)----
        $migration->down();

        $this->assertTrue(DB::table('resource_definition')->where('resource_id', '粮食')->exists(), 'down() 应把主键改回中文');
        $this->assertTrue(DB::table('city_resources')->where('resource_id', '木材')->exists());
        $chineseLevel = DB::table('building_level_definition')->where('building_id', 'P01')->where('level', 1)->first();
        $this->assertArrayHasKey('木材', json_decode($chineseLevel->cost_json, true));
        $this->assertSame('粮食', json_decode($chineseLevel->input_json, true)[0]['resource']);

        // ---- 再跑正向迁移 ----
        $migration->up();

        // 存档存量逐条守恒
        $resourcesAfter = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->all();
        ksort($resourcesBefore);
        ksort($resourcesAfter);
        $this->assertSame($resourcesBefore, $resourcesAfter, 'city_resources 往返后数值/键必须完全一致');

        // 定义 JSON 逐字段守恒
        $levelAfter = DB::table('building_level_definition')->where('building_id', 'P01')->where('level', 1)->first();
        $this->assertSame(
            json_decode($levelBefore->cost_json, true),
            json_decode($levelAfter->cost_json, true)
        );
        $this->assertSame(
            json_decode($levelBefore->input_json, true),
            json_decode($levelAfter->input_json, true)
        );
        $this->assertSame(
            json_decode($levelBefore->output_json, true),
            json_decode($levelAfter->output_json, true)
        );

        // rs_code 回填
        $definitionAfter = DB::table('resource_definition')->orderBy('resource_id')->pluck('rs_code', 'resource_id')->all();
        $this->assertSame($definitionBefore, $definitionAfter, 'rs_code 应由 up() 按 resources.json 回填');
    }

    // 重复执行不出错、不改值(迁移被中断后可以安全重跑)
    public function test_up_is_idempotent(): void
    {
        $u = User::create(['username' => 'migidem', 'name' => 'migidem', 'email' => 'mi@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $before = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->all();

        $this->migration()->up();
        $this->migration()->up();

        $after = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->all();
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
    }

    // 映射未覆盖的资源 ID 必须让迁移直接失败,不允许静默漏转
    public function test_unmapped_resource_id_aborts_migration(): void
    {
        $u = User::create(['username' => 'migbad', 'name' => 'migbad', 'email' => 'mb@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->insert(['city_id' => $city->id, 'resource_id' => '不存在的资源', 'amount' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/不存在的资源/');
        $this->migration()->up();
    }

    // 本迁移早期版本用过 4 个不符合 v3.2 §0.2.1 权威表的 code,重跑时必须一并纠正
    public function test_legacy_codes_are_corrected(): void
    {
        $u = User::create(['username' => 'miglegacy', 'name' => 'miglegacy', 'email' => 'ml@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        // 模拟旧版迁移留下的键
        DB::table('city_resources')->insert(['city_id' => $city->id, 'resource_id' => 'berry', 'amount' => 42]);
        DB::table('resource_definition')->where('resource_id', ResourceCode::BERRIES)->update(['resource_id' => 'berry']);
        DB::table('building_level_definition')->where('building_id', 'F01')->where('level', 1)
            ->update(['output_json' => json_encode([['resource' => 'berry', 'rate_per_min' => 8]], JSON_UNESCAPED_UNICODE)]);

        $this->migration()->up();

        $this->assertSame(42.0, (float) DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', ResourceCode::BERRIES)->value('amount'));
        $this->assertFalse(DB::table('city_resources')->where('resource_id', 'berry')->exists());
        $this->assertTrue(DB::table('resource_definition')->where('resource_id', ResourceCode::BERRIES)->exists());
        $this->assertSame('RS002', DB::table('resource_definition')->where('resource_id', ResourceCode::BERRIES)->value('rs_code'));

        $output = json_decode(DB::table('building_level_definition')->where('building_id', 'F01')->where('level', 1)->value('output_json'), true);
        $this->assertSame(ResourceCode::BERRIES, $output[0]['resource']);
    }

    // 目标 code 行已存在(迁移中断后重跑 / 新代码抢先写入):合并存量后移除旧中文行,不得报主键冲突
    public function test_target_key_collision_merges_amounts(): void
    {
        $u = User::create(['username' => 'migclash', 'name' => 'migclash', 'email' => 'mcl@x.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);

        // 制造一座「中英文键并存」的城:english food 行保留,另插一条中文 粮食 行
        $food = (float) DB::table('city_resources')->where('city_id', $city->id)
            ->where('resource_id', ResourceCode::FOOD)->value('amount');
        DB::table('city_resources')->insert(['city_id' => $city->id, 'resource_id' => '粮食', 'amount' => 25]);

        $this->migration()->up();

        $rows = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->all();
        $this->assertArrayNotHasKey('粮食', $rows, '旧中文行必须被清掉');
        $this->assertEqualsWithDelta($food + 25, (float) $rows[ResourceCode::FOOD], 0.0001, '存量应合并进目标行,一分不丢');
        $this->assertSame(1, DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', ResourceCode::FOOD)->count());
    }
}
