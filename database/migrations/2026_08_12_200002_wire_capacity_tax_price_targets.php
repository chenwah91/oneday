<?php

use Database\Seeders\EventDefinitionSeeder;
use Database\Seeders\ItemDefinitionSeeder;
use Database\Seeders\NpcDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M3-W5:容量类 / 税收 / 市场价格三组 target 接线后,把对应的定义行从「未生效」提升为可执行。
//
// 交付前这些行的状态:
//   · EVT_ROUTE_BREAK / EVT_PORT_CONGESTION  运输、贸易容量是容量类产出,乘区改不到 → Fail Closed 停用;
//   · EVT_CRIME / EVT_CORRUPTION             税收在内核按 §10.5 现算,没有 target        → Fail Closed 停用;
//   · EVT_SPECULATION / EVT_OIL_SHOCK        价格乘数只有 PriceEngine 的全服口径         → Fail Closed 停用;
//   · EVT_TRADE_BOOM 的两个选项               「成交量 ±X%」没有落点                       → unmapped;
//   · IT018「运输容量+15%」/ 10 位物流 NPC 的「运输(铁路)容量+X%」/ N013「税收+8%」→ unmapped。
// W5 登记 transport / trade / finance_capacity_pct + tax_income_pct + market_price_pct
// 并把消费点接到结算内核与 TradeService 之后,以上全部有了落点。
//
// **只动这 19 行**(backlog §10.2 并行纪律:同批次另一个 agent 在改 NPC 扩充与 EVT_BRAIN_DRAIN):
//   event_definition:EVT_ROUTE_BREAK / EVT_PORT_CONGESTION / EVT_CRIME / EVT_CORRUPTION
//                    / EVT_SPECULATION / EVT_OIL_SHOCK / EVT_TRADE_BOOM / EVT_TAX_PROTEST(仅刷说明,维持停用)
//   item_definition :IT018
//   npc_definition  :N013 + N022 / N069 / N074 / N084 / N089 / N126 / N129 / N134 / N144 / N149
// 数据源仍是 database/data/*.json —— 这里从各 Seeder 的**同一份构造**里取出这几行再写库,
// 保证「跑迁移」与「跑 seed」两条路径落到库里的内容一字不差,也自动过一遍各 Seeder 的守门。
//
// 幂等:updateOrInsert / 定点 update,重复跑就是把这几行刷回 json 的样子。
return new class extends Migration
{
    private const EVENT_IDS = [
        'EVT_ROUTE_BREAK', 'EVT_PORT_CONGESTION', 'EVT_CRIME', 'EVT_CORRUPTION',
        'EVT_SPECULATION', 'EVT_OIL_SHOCK', 'EVT_TRADE_BOOM', 'EVT_TAX_PROTEST',
    ];

    private const ITEM_IDS = ['IT018'];

    private const NPC_IDS = ['N013', 'N022', 'N069', 'N074', 'N084', 'N089', 'N126', 'N129', 'N134', 'N144', 'N149'];

    // 回滚用:各事件交付时的停用理由(与 W5 之前的 events.json 逐字一致)
    private const DISABLED_REASONS = [
        'EVT_ROUTE_BREAK' => '运输容量是容量类产出,结算内核在填七乘区**之前**就把它提取成全城值,乘区改不到它;D0 也未登记 transport_capacity target。自动效果整条无法承接 → Fail Closed 停用',
        'EVT_PORT_CONGESTION' => '贸易容量与运输容量都是容量类产出,内核在填七乘区之前就提取成全城值,乘区改不到。→ Fail Closed 停用',
        'EVT_CRIME' => '自动效果两项都承接不了:税收在结算内核按 §10.5 公式现算(D0 未登记 tax_income_pct target);「随机库存损失」§9.2 未给任何数值,§16.5 明令不擅自补数。→ Fail Closed 停用',
        'EVT_CORRUPTION' => '自动效果两项都承接不了:税收在结算内核按 §10.5 现算(无 target);维护成本的消费点(maintenance_cost_pct)排在 W3-A,本波次没有读取方。→ Fail Closed 停用',
        'EVT_SPECULATION' => '市场价格的事件乘数接线点是 PriceEngine::EVENT_MULTIPLIER_DEFAULT(app/Game/Market,本波次不碰的目录)。自动效果整条无法承接 → Fail Closed 停用,留待与 D3 合并波次接线',
        'EVT_OIL_SHOCK' => '价格冲击的接线点是 PriceEngine::EVENT_MULTIPLIER_DEFAULT(app/Game/Market,本波次不碰的目录)。→ Fail Closed 停用',
    ];

    public function up(): void
    {
        $this->syncEvents();
        $this->syncItems();
        $this->syncNpcs();
    }

    // 回滚 = 退回各自交付时的「未生效」状态,不删任何定义行。
    // 注意 EVT_TRADE_BOOM / EVT_TAX_PROTEST 回滚后仍分别是启用 / 停用(它们的开关本来就没被本迁移改),
    // 只是效果 JSON 退回未提升的样子 —— 这一步由 down() 里的 json 覆盖完成
    public function down(): void
    {
        if (Schema::hasTable('event_definition')) {
            foreach (self::DISABLED_REASONS as $eventId => $reason) {
                DB::table('event_definition')->where('event_id', $eventId)->update([
                    'enabled'         => false,
                    'disabled_reason' => $reason,
                ]);
            }
        }

        if (Schema::hasTable('item_definition')) {
            DB::table('item_definition')->where('item_id', 'IT018')->update([
                'effect_json' => json_encode([
                    'specs'       => [],
                    'unmapped_zh' => ['运输容量(+15%):ModifierTarget 尚无运输容量消费点(§6.1 的 SKILL_LOGISTICS 同样留空),等物流波次登记'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        if (Schema::hasTable('npc_definition')) {
            // 回滚成「specs 清空 + 原样保留的文案」:文案取 trait_desc_zh 的原文,不另编一份
            $rollback = [
                'N013' => ['specs' => [['target' => 'governance_capacity_pct', 'scope' => 'city', 'op' => 'flat', 'value' => 30]], 'unmapped_zh' => ['税收+8%']],
                'N022' => ['specs' => [], 'unmapped_zh' => ['铁路容量+15%']],
                'N069' => ['specs' => [], 'unmapped_zh' => ['运输容量+12%']],
                'N074' => ['specs' => [], 'unmapped_zh' => ['铁路容量+15%']],
                'N084' => ['specs' => [], 'unmapped_zh' => ['运输容量+22%', '拥堵损失降低']],
                'N089' => ['specs' => [], 'unmapped_zh' => ['全球运输容量+30%']],
                'N126' => ['specs' => [], 'unmapped_zh' => ['运输容量+10%']],
                'N129' => ['specs' => [], 'unmapped_zh' => ['运输容量+13%']],
                'N134' => ['specs' => [], 'unmapped_zh' => ['铁路容量+16%']],
                'N144' => ['specs' => [], 'unmapped_zh' => ['运输容量+24%', '拥堵损失降低']],
                'N149' => ['specs' => [], 'unmapped_zh' => ['全球运输容量+32%']],
            ];

            foreach ($rollback as $npcId => $trait) {
                DB::table('npc_definition')->where('npc_id', $npcId)
                    ->update(['trait_json' => json_encode($trait, JSON_UNESCAPED_UNICODE)]);
            }
        }

        if (Schema::hasTable('npc_skill_definition')) {
            DB::table('npc_skill_definition')->where('skill_id', 'SKILL_LOGISTICS')
                ->update(['effect_target' => null]);
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
        $data = json_decode(file_get_contents(database_path('data/npcs.json')), true);

        // §6.1 的 SKILL_LOGISTICS 由 effect_target=null 改为 transport_capacity_pct
        //(与 SKILL_MEDICINE 不同:后者仍然没有消费点,继续留空)
        if (Schema::hasTable('npc_skill_definition')) {
            foreach (NpcDefinitionSeeder::skillRows($data['skills']) as $row) {
                if ($row['skill_id'] !== 'SKILL_LOGISTICS') {
                    continue;
                }
                DB::table('npc_skill_definition')->where('skill_id', $row['skill_id'])
                    ->update(['effect_target' => $row['effect_target']]);
            }
        }

        if (! Schema::hasTable('npc_definition')) {
            return;
        }

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
