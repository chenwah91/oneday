<?php

use App\Support\GameSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 补写 M3-D1 NPC 的规则参数行(game_settings)。
//
// 为什么要单独一支迁移:2026_08_10_500001 建表时按**当时**的 GameSetting::DEFINITIONS 灌行,
// 已经跑过那支迁移的库(开发 apg / 线上)不会自动补上后来新增的 key ——
// 功能上不影响(GameSetting::get 缺行会回退登记默认值),但后台设置页的「最后修改时间」会一直空着,
// 运营也看不出「这一项到底有没有被人动过」。与 2026_08_11_050001 同一做法。
//
// 幂等:逐 key 先查后插,已存在的行完全不动 —— 被运营改过值的库绝不能被迁移覆盖回默认值。
return new class extends Migration
{
    // 本支迁移负责的 key:GameSetting 里所有 npc_ 前缀的登记项
    private function keys(): array
    {
        return array_values(array_filter(
            array_keys(GameSetting::DEFINITIONS),
            fn ($key) => str_starts_with($key, 'npc_')
        ));
    }

    public function up(): void
    {
        foreach ($this->keys() as $key) {
            if (DB::table('game_settings')->where('setting_key', $key)->exists()) {
                continue;
            }

            $meta = GameSetting::DEFINITIONS[$key];
            DB::table('game_settings')->insert([
                'setting_key' => $key,
                'value_json'  => json_encode($meta['default'], JSON_UNESCAPED_UNICODE),
                'description' => $meta['description'],
                'updated_by'  => null,
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 只删自己这批行,不动其他开关
        DB::table('game_settings')->whereIn('setting_key', $this->keys())->delete();
    }
};
