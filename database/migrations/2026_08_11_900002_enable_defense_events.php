<?php

use Database\Seeders\EventDefinitionSeeder;
use Database\Seeders\ItemDefinitionSeeder;
use Database\Seeders\NpcDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M3-D5 国防联动落地后复活两条国防事件,并把国防类效果从 unmapped 提升为可执行(v3.2 §9.2 / §7 / §6.3)。
//
// M3-D4 / D2 / D1 交付时这几行都是「原样保留、明确未生效」的状态:
//   · EVT_RAID            条件「威胁等级≥中」+ 损失公式都没有落点 → Fail Closed 停用;
//   · EVT_BORDER_TENSION  「国防需求+30%」没有落点            → Fail Closed 停用;
//   · IT008「国防值 flat(+8)」/ N010「国防值+12」            → ModifierTarget 尚无 defense_score_flat;
//   · N016「区域国防+15%」/ N027「国防+20%」                  → 同上,尚无 defense_score_pct。
// W4-B 把三条 target 登记到 ModifierTarget、消费点落在 DefenseService 之后,以上全部有了落点。
//
// **只动这 6 行**(backlog §10.2 并行纪律:同批次另一个 agent 在改电力与 EVT_BLACKOUT):
//   event_definition:EVT_RAID / EVT_BORDER_TENSION
//   item_definition :IT008
//   npc_definition  :N010 / N016 / N027
// 数据源仍是 database/data/*.json —— 这里从各 Seeder 的**同一份构造**里取出这几行再写库,
// 保证「跑迁移」与「跑 seed」两条路径落到库里的内容一字不差,也自动过一遍各 Seeder 的守门。
//
// 幂等:updateOrInsert,重复跑就是把这几行刷回 json 的样子。
return new class extends Migration
{
    private const EVENT_IDS = ['EVT_RAID', 'EVT_BORDER_TENSION'];

    private const ITEM_IDS = ['IT008'];

    private const NPC_IDS = ['N010', 'N016', 'N027'];

    public function up(): void
    {
        $this->syncEvents();
        $this->syncItems();
        $this->syncNpcs();
    }

    // 回滚 = 退回各自交付时的「未生效」状态,不删任何定义行
    public function down(): void
    {
        if (Schema::hasTable('event_definition')) {
            DB::table('event_definition')->where('event_id', 'EVT_RAID')->update([
                'enabled'         => false,
                'disabled_reason' => '条件「威胁等级≥中」与损失公式都依赖 D5 国防联动(cities.threat_level + 威胁需求表,W4-B 才落地)。→ Fail Closed 停用',
            ]);
            DB::table('event_definition')->where('event_id', 'EVT_BORDER_TENSION')->update([
                'enabled'         => false,
                'disabled_reason' => '「国防需求+30%」的落点是 D5 的威胁需求表(W4-B 才落地);国防值本身是容量类产出,不经乘区。→ Fail Closed 停用',
            ]);
        }

        // 工具 / NPC 的 specs 回滚成空 + 原样保留的 unmapped 文案(与 D2 / D1 交付时一致)
        if (Schema::hasTable('item_definition')) {
            DB::table('item_definition')->where('item_id', 'IT008')->update([
                'effect_json' => json_encode([
                    'specs'       => [],
                    'unmapped_zh' => ['国防值 flat(+8):ModifierTarget 尚无 defense_score_flat 这一 target,消费点由 W4-B(D5 国防联动)登记后再提升为 spec'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        if (Schema::hasTable('npc_definition')) {
            $rollback = [
                'N010' => ['specs' => [], 'unmapped_zh' => ['国防值+12']],
                'N016' => ['specs' => [], 'unmapped_zh' => ['区域国防+15%']],
                'N027' => [
                    'specs' => [
                        ['target' => 'event_loss_reduction_pct', 'scope' => 'city', 'op' => 'pct', 'value' => 0.10],
                    ],
                    'unmapped_zh' => ['国防+20%'],
                ],
            ];

            foreach ($rollback as $npcId => $trait) {
                DB::table('npc_definition')->where('npc_id', $npcId)
                    ->update(['trait_json' => json_encode($trait, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    private function syncEvents(): void
    {
        // 表还不存在(全新库跑到这里时 700001 尚未建表)→ 交给 Seeder / 700001 自己灌
        if (! Schema::hasTable('event_definition')) {
            return;
        }

        foreach (EventDefinitionSeeder::rows() as $row) {
            if (! in_array($row['event_id'], self::EVENT_IDS, true)) {
                continue;
            }

            $eventId = $row['event_id'];
            unset($row['event_id']);
            // effect_multiplier 是**运营调过的值**(后台逐事件可调),迁移不许把它刷回 1
            unset($row['effect_multiplier']);

            DB::table('event_definition')->updateOrInsert(['event_id' => $eventId], $row);
        }
    }

    private function syncItems(): void
    {
        if (! Schema::hasTable('item_definition')) {
            return;
        }

        foreach (ItemDefinitionSeeder::rows() as $row) {
            if (! in_array($row['item_id'], self::ITEM_IDS, true)) {
                continue;
            }

            // 只刷 effect_json 这一列:耐久 / 成本 / 交易值都没变,
            // 整行覆盖会把运营在后台改过的别的列一起刷回 json(那属于「顺手改了没要求改的东西」)
            DB::table('item_definition')->where('item_id', $row['item_id'])
                ->update(['effect_json' => $row['effect_json']]);
        }
    }

    private function syncNpcs(): void
    {
        if (! Schema::hasTable('npc_definition')) {
            return;
        }

        $data = json_decode(file_get_contents(database_path('data/npcs.json')), true);

        foreach (NpcDefinitionSeeder::npcRows($data['npcs'], $data['skills']) as $row) {
            if (! in_array($row['npc_id'], self::NPC_IDS, true)) {
                continue;
            }

            // 同上:只刷 trait_json,工资 / 口粮 / 稀有度一列不动
            DB::table('npc_definition')->where('npc_id', $row['npc_id'])
                ->update(['trait_json' => $row['trait_json']]);
        }
    }
};
