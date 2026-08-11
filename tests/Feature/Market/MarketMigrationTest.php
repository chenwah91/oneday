<?php

namespace Tests\Feature\Market;

use App\Game\Market\MarketDefinition;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 「只跑迁移、不跑 Seeder」的库也必须能直接开市。
//
// 这一组用例**刻意不调用 $this->seed()** —— RefreshDatabase 只跑 migrate,不跑 Seeder,
// 正好复现「已有数据的库(开发 apg / 线上)执行 php artisan migrate 之后」的状态。
//
// 守的是一个真实踩过的坑:定义数据如果只放在 Seeder 里,已有数据的库跑完迁移后 market_definition
// 是空表 —— 迁移全绿、市场却每笔买卖都返回 RESOURCE_NOT_TRADEABLE,而版本 bump 又因为
// 「表是空的」被跳过,连「这库到底升没升级」都查不出来。是最难排查的一类半上线状态。
class MarketMigrationTest extends TestCase
{
    use RefreshDatabase;

    // 26 行定义随建表迁移落库,不依赖 db:seed
    public function test_migration_alone_populates_market_definition(): void
    {
        $this->assertSame(26, DB::table('market_definition')->count(), '只跑迁移的库必须已经有 §8 的 26 行');
        $this->assertTrue(MarketDefinition::isTradeable(MarketDefinition::find('iron')));
    }

    // 12 条市场设定随迁移落库,否则后台设置页看不到市场参数,出事时无从调起
    public function test_migration_alone_registers_all_market_settings(): void
    {
        $keys = DB::table('game_settings')->where('setting_key', 'like', 'market%')->pluck('setting_key')->all();

        $expected = [
            GameSetting::MARKET_ENABLED,
            GameSetting::MARKET_WINDOW_SECONDS,
            GameSetting::MARKET_MA_WINDOWS,
            GameSetting::MARKET_SLIPPAGE_COEFFICIENT,
            GameSetting::MARKET_FEE_RATE_MULTIPLIER,
            GameSetting::MARKET_QUOTA_WINDOW_PCT,
            GameSetting::MARKET_QUOTA_HOURLY_MULTIPLE,
            GameSetting::MARKET_PRICE_MIN_MULTIPLE,
            GameSetting::MARKET_PRICE_MAX_MULTIPLE,
            GameSetting::MARKET_LIQUIDITY_MULTIPLIER,
            GameSetting::MARKET_NOISE_FLOOR_PCT,
            GameSetting::MARKET_MAX_ORDER_QUANTITY,
        ];

        sort($keys);
        sort($expected);
        $this->assertSame($expected, $keys);
    }

    // 每条设定的默认值必须与 GameSetting::DEFINITIONS 登记的一致(灌行时抄错就等于换了一套规则)
    public function test_seeded_setting_values_match_the_registered_defaults(): void
    {
        foreach (DB::table('game_settings')->where('setting_key', 'like', 'market%')->get() as $row) {
            $key = (string) $row->setting_key;
            $this->assertSame(
                GameSetting::DEFINITIONS[$key]['default'],
                json_decode((string) $row->value_json, true),
                $key . ' 的落库默认值与代码登记值不一致'
            );
        }

        // C 区批准口径的抽查:窗口 60 秒、滑点系数 0.5、单窗 10%、每小时 20 倍
        $this->assertSame(60, GameSetting::get(GameSetting::MARKET_WINDOW_SECONDS));
        $this->assertSame(0.5, GameSetting::get(GameSetting::MARKET_SLIPPAGE_COEFFICIENT));
        $this->assertSame(0.1, GameSetting::get(GameSetting::MARKET_QUOTA_WINDOW_PCT));
        $this->assertSame(20, GameSetting::get(GameSetting::MARKET_QUOTA_HOURLY_MULTIPLE));
    }

    // 全新库上,版本号刻意**不**由迁移写入,而是交给 GameDataVersionSeeder 按升序补齐。
    //
    // 守的是一个真实踩过的坑:市场的建表迁移会自己灌 26 行定义,
    // 如果 bump 迁移拿「market_definition 非空」当「不是全新库」的判据,它在全新库上也会成立 →
    // migrate 阶段先写下 V3.3.1,随后 db:seed 才补写 NPC 的 V3.3.0,
    // game_data_versions 的 id 顺序变成 …→V3.3.1→V3.3.0,而 current() 取 id 最大的一行,
    // 「当前数值版本」就直接回退到 V3.3.0 了。
    // 所以判据必须用一张只有 Seeder 才会填的表(resource_definition)。
    // 「Seeder 跑完之后版本号补齐且严格升序」的另一半,在 MarketDefinitionTest 里断言
    // (那一组的 setUp 本来就 seed;这里刻意不 seed,两边合起来覆盖完整链路)。
    public function test_fresh_database_defers_version_recording_to_the_seeder(): void
    {
        // RefreshDatabase 只跑迁移不跑 Seeder,正是「全新库」的状态
        $this->assertFalse(DB::table('resource_definition')->exists(), '前提:本用例不跑 Seeder');
        $this->assertFalse(
            DB::table('game_data_versions')->where('version', 'V3.3.1')->exists(),
            '全新库上迁移不该抢先写版本号,否则会和 Seeder 的补写打乱顺序'
        );
    }
}
