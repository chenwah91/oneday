<?php

use Database\Seeders\EventDefinitionSeeder;
use Database\Seeders\NpcDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M3-W6:治理容量死 target 清偿 —— 把定义数据里的治理投稿刷成新的两条 target。
//
// 交付前这些行的状态(W5 汇报点名的两个毛病):
//   ① governance_capacity_pct 登记在册却**没有任何消费点**,15 位行政 NPC 的「治理+X%」
//      与 IT022「治理效率+10%」一律静默失效;
//   ② N013 / N051 / N111 三位写的是 op=flat(「治理+30」「治理容量+20」「治理容量+22」),
//      为了有地方写被塞进了 pct 这条 target —— 就算 pct 接了消费点,flat 通道也读不到它们。
// W6 把 target 拆成 governance_capacity_flat + governance_capacity_pct 并把消费点接进结算内核之后,
// 这一支负责把**存量定义行**挪到正确的 target 上。
//
// **只动这 4 行**(backlog §10.2 并行纪律:同批次另一个 agent 在做前端面板):
//   npc_definition  :N013 / N051 / N111 —— trait_json 的 target 由 pct 改 flat(值不变:30 / 20 / 22)
//   event_definition:EVT_CORRUPTION    —— 选项 B「行政改革」的「治理容量暂时-10%」由 unmapped 提升为可执行
//                                          (「事件结束后+5% 30分钟」仍是 unmapped:没有延迟起效的 kind)
// IT022 与其余 15 位 NPC **一行都不用改** —— 它们本来就写的 op=pct + governance_capacity_pct,
// 缺的只是消费点;消费点是代码,不是数据。
//
// 数据源仍是 database/data/*.json —— 这里从各 Seeder 的**同一份构造**里取出这几行再写库,
// 保证「跑迁移」与「跑 seed」两条路径落到库里的内容一字不差,也自动过一遍各 Seeder 的守门。
//
// ⚠️ **不碰 city_active_modifiers(玩家运行数据)**:历史上没有任何代码路径能往
// governance_capacity_pct 写出 op=flat 的行(EventEffect::insertModifier 一律写 OP_PCT,
// 而选项 B 在本波次之前是空效果),所以存量运行数据里不存在需要搬家的行 ——
// 没有需要改的玩家数据,就不去改玩家数据。
//
// 幂等:定点 update,重复跑就是把这 4 行刷回 json 的样子。
return new class extends Migration
{
    private const NPC_IDS = ['N013', 'N051', 'N111'];

    private const EVENT_IDS = ['EVT_CORRUPTION'];

    // 回滚用:三位 NPC 在 W6 之前的 trait_json(target 写的是 pct、op 写的是 flat 的那一版)
    private const NPC_ROLLBACK = [
        'N013' => [
            'specs' => [
                ['target' => 'governance_capacity_pct', 'scope' => 'city', 'op' => 'flat', 'value' => 30],
                ['target' => 'tax_income_pct', 'scope' => 'city', 'op' => 'pct', 'value' => 0.08],
            ],
            'unmapped_zh' => [],
        ],
        'N051' => [
            'specs'       => [['target' => 'governance_capacity_pct', 'scope' => 'city', 'op' => 'flat', 'value' => 20]],
            'unmapped_zh' => [],
        ],
        'N111' => [
            'specs'       => [['target' => 'governance_capacity_pct', 'scope' => 'city', 'op' => 'flat', 'value' => 22]],
            'unmapped_zh' => [],
        ],
    ];

    public function up(): void
    {
        $this->syncNpcs();
        $this->syncEvents();
    }

    // 回滚 = 退回交付时的「未生效」状态,不删任何定义行
    public function down(): void
    {
        if (Schema::hasTable('npc_definition')) {
            foreach (self::NPC_ROLLBACK as $npcId => $trait) {
                DB::table('npc_definition')->where('npc_id', $npcId)
                    ->update(['trait_json' => json_encode($trait, JSON_UNESCAPED_UNICODE)]);
            }
        }

        if (Schema::hasTable('event_definition')) {
            $options = DB::table('event_definition')->where('event_id', 'EVT_CORRUPTION')->value('options_json');
            $decoded = json_decode((string) $options, true);
            if (is_array($decoded) && isset($decoded['b'])) {
                $decoded['b']['effects'] = [];
                $decoded['b']['unmapped_zh'] = ['治理容量暂时-10% / 结束后+5%:governance_capacity_pct 虽已在 ModifierTarget 登记,但内核里**至今没有任何消费点**(W5 核对过全仓,N001/N026/IT022 等的治理加成同样不生效)。接线要连 op=flat 的投稿(N013「治理+30」)一起设计,属独立小波次 —— 在那之前维持未生效'];
                DB::table('event_definition')->where('event_id', 'EVT_CORRUPTION')
                    ->update(['options_json' => json_encode($decoded, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    private function syncNpcs(): void
    {
        // 表还不存在(全新库跑到这里时 400001 尚未建表)→ 交给 Seeder 自己灌
        if (! Schema::hasTable('npc_definition')) {
            return;
        }

        $data = json_decode(file_get_contents(database_path('data/npcs.json')), true);

        foreach (NpcDefinitionSeeder::npcRows($data['npcs'], $data['skills']) as $row) {
            if (! in_array($row['npc_id'], self::NPC_IDS, true)) {
                continue;
            }

            // 只刷 trait_json:工资 / 口粮 / 稀有度 / 中文名一列不动
            //(整行覆盖会把运营在后台改过的别的列一起刷回 json)
            DB::table('npc_definition')->where('npc_id', $row['npc_id'])
                ->update(['trait_json' => $row['trait_json']]);
        }
    }

    private function syncEvents(): void
    {
        if (! Schema::hasTable('event_definition')) {
            return;
        }

        foreach (EventDefinitionSeeder::rows() as $row) {
            if (! in_array($row['event_id'], self::EVENT_IDS, true)) {
                continue;
            }

            // 只刷 options_json:开关 / 权重 / 冷却 / 时长 / 强度倍率都是**运营调过的值**
            //(逐事件后台可设定,W3-B 起),迁移不许把它们刷回 json
            DB::table('event_definition')->where('event_id', $row['event_id'])
                ->update(['options_json' => $row['options_json']]);
        }
    }
};
