<?php

use Database\Seeders\EventDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// EVT_BRAIN_DRAIN 人才流失复活(v3.2 §9.2)。
//
// M3-D4 事件系统交付时这一行是 Fail Closed 停用的,理由写在 disabled_reason 里:
// 「随机高级NPC提出离职」要写 city_npcs 的状态位,而那是 app/Game/NPC 的职责边界,
// 事件系统不越界改别的系统的运行时状态。
//
// 本波次(NPC 池 30→150,同一批次由 NPC 模块所有者落地)把入口正式开在 NPC 模块里:
//   NpcRuntimeService::leaveRandom() —— 状态位、岗位解绑、NPC.LEAVE 审计都在那里,
//   与 A4「士气过低自行离职」共用同一个写入点(markLeft);
//   事件侧新增一个窄效果 kind = npc_leave,只负责「什么时候流失」+ 注入可重算的掷点闭包。
// 边界没有被打破,只是有了一扇正门。
//
// **只动 EVT_BRAIN_DRAIN 这一行**(并行纪律:同批次另有 agent 在改 events.json 的别的行)。
// 数据源仍是 database/data/events.json —— 这里从 EventDefinitionSeeder 的**同一份构造**里
// 取出这一行再写库,保证「跑迁移」与「跑 seed」两条路径落库内容一字不差,也自动过一遍守门。
//
// 幂等:updateOrInsert,重复跑就是把这一行刷回 json 的样子。
return new class extends Migration
{
    private const EVENT_ID = 'EVT_BRAIN_DRAIN';

    public function up(): void
    {
        // 表还不存在(全新库跑到这里时 700001 尚未建表)→ 交给 Seeder / 700001 自己灌
        if (! Schema::hasTable('event_definition')) {
            return;
        }

        foreach (EventDefinitionSeeder::rows() as $row) {
            if ($row['event_id'] !== self::EVENT_ID) {
                continue;
            }

            unset($row['event_id']);
            // effect_multiplier 是**运营调过的值**(后台逐事件可调),迁移不许把它刷回 1
            unset($row['effect_multiplier']);

            DB::table('event_definition')->updateOrInsert(['event_id' => self::EVENT_ID], $row);
        }
    }

    // 回滚 = 退回 M3-D4 交付时的停用状态(条件与选项 B 保持原样,自动效果退回空 + 原文 unmapped)
    public function down(): void
    {
        if (! Schema::hasTable('event_definition')) {
            return;
        }

        DB::table('event_definition')->where('event_id', self::EVENT_ID)->update([
            'enabled'         => false,
            'disabled_reason' => '「NPC 提出离职 / 加薪挽留」的写入点在 app/Game/NPC(本波次不碰的目录),事件系统不越界改别的系统的运行时状态。→ Fail Closed 停用,留待与 D1 合并波次接线',
            'auto_effect_json' => json_encode([
                'effects'     => [],
                'unmapped_zh' => ['随机高级NPC提出离职:写 city_npcs 的状态属 D1 的职责边界'],
            ], JSON_UNESCAPED_UNICODE),
            'options_json' => json_encode([
                'a' => [
                    'label_zh'    => '加薪挽留',
                    'effects'     => [],
                    'unmapped_zh' => ['该NPC工资+20%:同上,落点在 D1'],
                ],
                'b' => [
                    'label_zh' => '改善环境',
                    'effects'  => [
                        ['kind' => 'resource_delta', 'resource' => 'money', 'value' => -2000],
                        ['kind' => 'happiness', 'value' => 4],
                    ],
                    'unmapped_zh' => [],
                ],
                'c' => [
                    'label_zh'    => '允许离开',
                    'effects'     => [],
                    'unmapped_zh' => ['离职的实际执行落点在 D1'],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
};
