<?php

namespace Tests\Feature\Item;

use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 「只跑迁移、不跑 Seeder」的库也必须能直接做工具。
//
// 这一组用例**刻意不调用 $this->seed()** —— RefreshDatabase 只跑 migrate,不跑 Seeder,
// 正好复现「已有数据的库(开发 apg / 线上)执行 php artisan migrate 之后」的状态。
//
// 守的是一个真实踩过的坑:定义数据如果只放在 Seeder 里,已有数据的库跑完迁移后 item_definition
// 是空表 —— 迁移全绿、制作却每次都 404,而版本 bump 又因为「表是空的」被跳过,
// 连「这库到底升没升级」都查不出来。是最难排查的一类半上线状态。
class ItemMigrationTest extends TestCase
{
    use RefreshDatabase;

    // 6 条工具设定随迁移落库,否则后台设置页看不到工具参数,出事时无从调起
    public function test_migration_alone_registers_all_item_settings(): void
    {
        $keys = DB::table('game_settings')->where('setting_key', 'like', 'item%')->pluck('setting_key')->all();

        $expected = [
            GameSetting::ITEM_CRAFT_ENABLED,
            GameSetting::ITEM_DURABILITY_ENABLED,
            GameSetting::ITEM_SLOTS_PER_BUILDING,
            GameSetting::ITEM_DURABILITY_MINUTES_NORMAL,
            GameSetting::ITEM_DURABILITY_MINUTES_INDUSTRIAL,
            GameSetting::ITEM_DURABILITY_WARNING_PCT,
        ];

        sort($keys);
        sort($expected);
        $this->assertSame($expected, $keys);
    }

    // 每条设定的默认值必须与 GameSetting::DEFINITIONS 登记的一致(灌行时抄错就等于换了一套规则)
    public function test_seeded_setting_values_match_the_registered_defaults(): void
    {
        foreach (DB::table('game_settings')->where('setting_key', 'like', 'item%')->get() as $row) {
            $key = (string) $row->setting_key;
            $this->assertSame(
                GameSetting::DEFINITIONS[$key]['default'],
                GameSetting::get($key),
                $key . ' 的落库值与登记默认值不一致'
            );
        }
    }

    // 运行时表与耐久时钟随迁移建好(时钟缺一列,耐久就永远从 last_simulated_at 起算)
    public function test_migration_creates_runtime_table_and_clock(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('city_items'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('cities', 'item_settled_at'));
    }
}
