<?php

use App\Support\GameSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 新增 M3-D3 市场的 12 条后台可调设定(1 条开关 + 11 条数值)。
//
// 为什么要单独一支迁移:2026_08_10_500001 建表时按当时的 GameSetting::DEFINITIONS 灌行,
// 已经跑过那支迁移的库(开发 apg / 线上)不会自动补上后来新增的 key,
// 后台设置页就看不到市场参数,运营出事时无从调起(GameSetting::get 仍有默认值兜底,不影响玩法)。
//
// 幂等:逐 key 先查后插,已存在就完全不动 —— 全新库在 500001 里已按新的 DEFINITIONS 插过,
// 而已被运营改过值的库更不能被迁移覆盖回默认值。
return new class extends Migration
{
    // 本迁移负责的 key 清单(写死而不是扫 DEFINITIONS 前缀:
    // 将来别的系统再加 market_* 开头的 key,不该被这支历史迁移追认)
    private const KEYS = [
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

    public function up(): void
    {
        foreach (self::KEYS as $key) {
            if (DB::table('game_settings')->where('setting_key', $key)->exists()) {
                continue;
            }

            $meta = GameSetting::DEFINITIONS[$key];
            DB::table('game_settings')->insert([
                'setting_key' => $key,
                // JSON_PRESERVE_ZERO_FRACTION:没有它 json_encode(1.0) 会写成 "1",
                // 读回来就变成 int 1 —— 数值型设定的类型会在「落库再读出」的往返里悄悄从 float 变 int。
                // PHP 里算术结果一样,但后台显示、类型断言与将来的严格比较都会踩到
                'value_json'  => json_encode($meta['default'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'description' => $meta['description'],
                'updated_by'  => null,
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 只删自己这批 key,不动其他开关
        DB::table('game_settings')->whereIn('setting_key', self::KEYS)->delete();
    }
};
