<?php

use App\Support\GameSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 补写 M3-D5 国防联动的规则参数行(game_settings)。
//
// 为什么要单独一支迁移:2026_08_10_500001 建表时按**当时**的 GameSetting::DEFINITIONS 灌行,
// 已经跑过那支迁移的库(开发 apg / 线上)不会自动补上后来新增的 key ——
// 功能上不影响(GameSetting::get 缺行会回退登记默认值),但后台设置页的「最后修改时间」会一直空着,
// 运营也看不出「这一项到底有没有被人动过」。与 400003(NPC)/ 600003(工具)/ 700002(事件)同一做法。
//
// 幂等:逐 key 先查后插,已存在的行完全不动 —— 被运营改过值的库绝不能被迁移覆盖回默认值。
//
// 注意 event_defense_ok_max_threat_rank 也在本批:它属于事件权重的第七条修正,
// 但判定口径(读威胁档)是 D5 才有的,所以随 D5 一起补行。
// 被它取代的 event_defense_ok_security_min **不删行**(删配置数据要用户批准),
// 后台会照常显示,只是不再被任何代码读取 —— 说明已写进 GameSetting 的 description 里。
return new class extends Migration
{
    // 本支迁移负责的 key(两个前缀,不能按前缀一把抓,所以显式列出)
    private function keys(): array
    {
        return [
            GameSetting::DEFENSE_THREAT_COVERAGE_SAFE,
            GameSetting::DEFENSE_THREAT_COVERAGE_TENSE,
            GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER,
            GameSetting::DEFENSE_RAID_LOSS_BASE_MULTIPLIER,
            GameSetting::DEFENSE_RAID_LOSS_MAX_PCT,
            GameSetting::DEFENSE_RAID_LOSS_MULT_MEDIUM,
            GameSetting::DEFENSE_RAID_LOSS_MULT_HIGH,
            GameSetting::EVENT_DEFENSE_OK_MAX_THREAT_RANK,
        ];
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
