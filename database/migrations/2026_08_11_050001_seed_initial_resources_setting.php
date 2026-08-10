<?php

use App\Support\GameSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 新增后台可配设定 initial_resources(建城初始资源,对象型)。
//
// 为什么要单独一支迁移:2026_08_10_500001 建表时按当时的 GameSetting::DEFINITIONS 灌行,
// 已经跑过那支迁移的库(开发 apg / 线上)不会自动补上后来新增的 key,后台设置页会缺这一项,
// 建城也就一直走硬编码回退(新号拿不到 knowledge = 开局硬锁没解除)。
//
// 幂等:先查后插,已存在就完全不动 —— 全新库在 500001 里已按新的 DEFINITIONS 插过这一行,
// 而已被运营改过值的库更不能被迁移覆盖回默认值。
return new class extends Migration
{
    public function up(): void
    {
        $key = GameSetting::INITIAL_RESOURCES;
        $meta = GameSetting::DEFINITIONS[$key];

        if (DB::table('game_settings')->where('setting_key', $key)->exists()) {
            return;
        }

        DB::table('game_settings')->insert([
            'setting_key' => $key,
            'value_json'  => json_encode($meta['default'], JSON_UNESCAPED_UNICODE),
            'description' => $meta['description'],
            'updated_by'  => null,
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        // 只删自己这一行,不动其他开关
        DB::table('game_settings')->where('setting_key', GameSetting::INITIAL_RESOURCES)->delete();
    }
};
