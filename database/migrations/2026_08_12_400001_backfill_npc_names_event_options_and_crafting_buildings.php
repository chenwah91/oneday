<?php

use Database\Seeders\EventDefinitionSeeder;
use Database\Seeders\ItemDefinitionSeeder;
use Database\Seeders\NpcDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M3-W10:用户 2026-08-12 拍板的三组定义数据改动一次落地。
//
//   ① npc_definition —— N001~N030 的中文拟名回填(30 行)。
//      这一列在 2026_08_12_100002 落地时只填了 N031~N150,前 30 条留 NULL 等用户拟名,
//      前端一路回落 name_key(玩家看到的是「npc.N001.name」)。名字已在落地前用程序核对过
//      与现行 120 个 name_zh 零重复,合并后全表 150 个中文名互异。
//
//   ② event_definition —— 三条事件的选项由「原文未生效 / 折半落地」改成确定性效果(3 行):
//      · EVT_CORRUPTION 选项 A「调查」:原文的「50% 立即解决」没有概率 kind 可挂,一直只扣钱不办事;
//        改为追加两条 modifier_scale(tax_income_pct / maintenance_cost_pct 各归零)= 100% 确定性解决,
//        代价(资金 -900 / 知识 -50)一分不变。
//      · EVT_CORRUPTION 选项 B「行政改革」:原文是「事件期 -10% + 事后 +5% 持续 30 分钟」,
//        事后补偿那一半没有延迟起效的 kind 可挂;净额折算为当期 -5%(照 EVT_PORT_CONGESTION
//        选项 B 已有的折算先例:好处与代价两侧一起落地,不只落代价)。
//      · EVT_PORT_CONGESTION 选项 A「加班疏港」:追加 trade_capacity_pct / transport_capacity_pct
//        两条归零 = 拥堵立即解除(付了 600 块加班费却不解除拥堵,与选项文案对不上)。
//      · EVT_CRIME 的自动效果只改**补数说明**一句(3%~8% 的区间经用户复核批准),数值一个没动。
//
//   ③ item_definition —— 6 件工具挂上制作建筑(6 行)。
//      §7 点名的 5 个来源建筑(木工作坊 / 石工作坊 / 工坊 / 研究院 / 现代工厂)不在 94 栋内,
//      交付时按「不卡建筑只卡时代」放行。用户拍板改挂现有建筑(照 §16.1「改挂现有不加建筑」先例,
//      映射取自 docs/superpowers/plans/2026-08-10-m3-backlog.md §7 的建议):
//        IT003 / IT005 → P02、IT004 → P04、IT013 → P05、IT016 → K03、IT019 → P08。
//      落地后 ItemService::craft 的既有闸门(crafting_building_id 非 NULL 就要求城内有一栋
//      **active** 的该建筑)对这 6 件开始真的生效 —— 闸门是本次之前就有的代码,这里只喂数据。
//
// 数据源仍是 database/data/*.json —— 这里从各 Seeder 的**同一份构造**里取出这几行再写库
// (照 2026_08_12_300001 的先例),保证「跑迁移」与「跑 seed」两条路径落到库里的内容一字不差,
// 也自动过一遍各 Seeder 的守门(name_zh 长度 / 效果 kind / modifier_scale 系数区间 / 资源 code)。
//
// ⚠️ **不碰任何玩家运行数据**:
//   · city_npcs 不冗余 name_zh(一律联查 npc_definition),回填一列显示名不影响任何在编 NPC;
//   · city_active_modifiers 里已经写下的减益不追溯 —— 选项效果只在玩家**下一次结算事件时**
//     按新定义执行,已经结算过的历史实例维持当时的口径(与审计口径一致:历史就是历史);
//   · city_items 里已经造出来的工具不受影响,建筑闸门只挡**新的制作请求**。
//
// 幂等:定点 update,重复跑就是把这 39 行刷回 json 的样子。
return new class extends Migration
{
    private const NPC_ID_FROM = 'N001';

    private const NPC_ID_TO = 'N030';

    private const EVENT_IDS = ['EVT_CORRUPTION', 'EVT_PORT_CONGESTION', 'EVT_CRIME'];

    private const ITEM_IDS = ['IT003', 'IT004', 'IT005', 'IT013', 'IT016', 'IT019'];

    // 回滚用:6 件工具在本支之前的状态(building_id 空、原文留在 unmapped)
    private const ITEM_ROLLBACK = [
        'IT003' => '木工作坊',
        'IT004' => '石工作坊',
        'IT005' => '木工作坊',
        'IT013' => '工坊',
        'IT016' => '研究院',
        'IT019' => '现代工厂',
    ];

    // 回滚用:三条事件在本支之前的选项 JSON 与选项文案(逐字来自 W5 / W6 交付时的 events.json)
    private const EVENT_ROLLBACK = [
        'EVT_CORRUPTION' => [
            'option_a_desc_zh' => '调查:资金-900,知识-50,50%立即解决',
            'option_b_desc_zh' => '行政改革:治理容量暂时-10%,事件结束后+5% 30分钟',
            'options_json'     => [
                'a' => [
                    'label_zh' => '调查',
                    'effects'  => [
                        ['kind' => 'resource_delta', 'resource' => 'money', 'value' => -900],
                        ['kind' => 'resource_delta', 'resource' => 'knowledge', 'value' => -50],
                    ],
                    'unmapped_zh' => ['50%立即解决:事件系统没有「按概率二选一」的效果 kind(掷点框架有,但要新增一条 kind + 一套「掷不中就什么都不发生」的审计口径),本波次不为一条选项发明它。付了钱却什么都没发生 → 目前选 A 只有成本,平衡上建议后续要么补 kind、要么改文案'],
                ],
                'b' => [
                    'label_zh' => '行政改革',
                    'effects'  => [
                        ['kind' => 'modifier', 'target' => 'governance_capacity_pct', 'scope' => 'city', 'value' => -0.10],
                    ],
                    'unmapped_zh' => ['结束后+5% 持续30分钟:事件系统没有「延迟到实例结束才起效」的 kind(modifier 一律从当下起算),本波次不为一条选项发明它。选 B 目前只承接事件期间的治理容量 -10%,事后补偿那一半待补 kind'],
                ],
                'c' => ['label_zh' => '忽略', 'effects' => [], 'unmapped_zh' => []],
            ],
        ],
        'EVT_PORT_CONGESTION' => [
            'option_a_desc_zh' => '加班疏港:资金-600,维护+10%',
            'option_b_desc_zh' => '转铁路:铁路负载+20%,港口减益取消',
            'options_json'     => [
                'a' => [
                    'label_zh' => '加班疏港',
                    'effects'  => [
                        ['kind' => 'resource_delta', 'resource' => 'money', 'value' => -600],
                        ['kind' => 'modifier', 'target' => 'maintenance_cost_pct', 'scope' => 'city', 'value' => 0.10],
                    ],
                    'unmapped_zh' => [],
                ],
                'b' => [
                    'label_zh' => '转铁路',
                    'effects'  => [
                        ['kind' => 'modifier_scale', 'target' => 'trade_capacity_pct', 'value' => 0],
                        ['kind' => 'modifier_scale', 'target' => 'transport_capacity_pct', 'value' => 0.6667],
                    ],
                    'unmapped_zh' => ['口径折算(非未生效):「港口减益取消」= 贸易减益归零;「铁路负载+20%」的代价按 §10.7 的 load = 需求 / 容量 折算成「容量 ÷ 1.2」—— 运输减益由 -25% 收到 -16.67%(即 ×0.6667)。项目里没有独立的铁路(铁路容量已按语义并入 transport_capacity),也没有负载侧的投稿通道,所以按等价的容量侧落地,好处与代价两侧都落地'],
                ],
                'c' => ['label_zh' => '等待', 'effects' => [], 'unmapped_zh' => []],
            ],
        ],
    ];

    // 回滚用:EVT_CRIME 的自动效果(只有 unmapped_zh 那句话不一样,数值一模一样)
    private const CRIME_AUTO_ROLLBACK = [
        'effects' => [
            ['kind' => 'modifier', 'target' => 'tax_income_pct', 'scope' => 'city', 'value' => -0.10],
            ['kind' => 'resource_pct_of_stock', 'min' => -0.08, 'max' => -0.03],
        ],
        'unmapped_zh' => ['补数说明:「随机库存损失」§9.2 未给比例(§16.5 明令不擅自补数),这里取 3%~8% 的**区间**并随机挑一种当前有库存的非资金资源。取值依据 = 同类「库存损失」事件 EVT_GRANARY_PEST 的 8%~15%(那是针对性的粮食虫害,犯罪偷窃理应更轻)。整体强度可由后台逐事件的 effect_multiplier 调,不必改定义'],
    ];

    public function up(): void
    {
        $this->syncNpcNames();
        $this->syncEvents();
        $this->syncItems();
    }

    // 回滚 = 退回本支之前的状态,不删任何定义行
    public function down(): void
    {
        if (Schema::hasTable('npc_definition') && Schema::hasColumn('npc_definition', 'name_zh')) {
            // 前 30 条的 name_zh 由本支引入,回滚就该回到「拟名待批 = NULL」;
            // N031~N150 由 100002 引入,一列不动
            DB::table('npc_definition')
                ->whereBetween('npc_id', [self::NPC_ID_FROM, self::NPC_ID_TO])
                ->update(['name_zh' => null]);
        }

        if (Schema::hasTable('event_definition')) {
            foreach (self::EVENT_ROLLBACK as $eventId => $old) {
                DB::table('event_definition')->where('event_id', $eventId)->update([
                    'option_a_desc_zh' => $old['option_a_desc_zh'],
                    'option_b_desc_zh' => $old['option_b_desc_zh'],
                    'options_json'     => json_encode($old['options_json'], JSON_UNESCAPED_UNICODE),
                ]);
            }

            DB::table('event_definition')->where('event_id', 'EVT_CRIME')->update([
                'auto_effect_json' => json_encode(self::CRIME_AUTO_ROLLBACK, JSON_UNESCAPED_UNICODE),
            ]);
        }

        if (Schema::hasTable('item_definition')) {
            foreach (self::ITEM_ROLLBACK as $itemId => $sourceZh) {
                DB::table('item_definition')->where('item_id', $itemId)->update([
                    'crafting_building_id' => null,
                    'crafting_unmapped_zh' => $sourceZh,
                ]);
            }
        }
    }

    // ---------- ① NPC 中文名 ----------

    private function syncNpcNames(): void
    {
        // 表还不存在(全新库跑到这里时 400001 建表迁移尚未执行)→ 交给 Seeder 自己灌。
        // name_zh 这一列由 2026_08_12_100001 引入,列不在就更谈不上回填
        if (! Schema::hasTable('npc_definition') || ! Schema::hasColumn('npc_definition', 'name_zh')) {
            return;
        }

        $data = json_decode(file_get_contents(database_path('data/npcs.json')), true);

        foreach (NpcDefinitionSeeder::npcRows($data['npcs'], $data['skills'], withNameZh: true) as $row) {
            if ($row['npc_id'] < self::NPC_ID_FROM || $row['npc_id'] > self::NPC_ID_TO) {
                continue;
            }

            // 只刷 name_zh:工资 / 口粮 / 稀有度 / 特性一列不动
            //(整行覆盖会把运营在后台改过的别的列一起刷回 json)
            DB::table('npc_definition')->where('npc_id', $row['npc_id'])
                ->update(['name_zh' => $row['name_zh']]);
        }
    }

    // ---------- ② 事件选项 ----------

    private function syncEvents(): void
    {
        if (! Schema::hasTable('event_definition')) {
            return;
        }

        foreach (EventDefinitionSeeder::rows() as $row) {
            if (! in_array($row['event_id'], self::EVENT_IDS, true)) {
                continue;
            }

            // 只刷效果 JSON 与三段选项文案:开关 / 权重 / 冷却 / 时长 / 强度倍率都是**运营调过的值**
            //(逐事件后台可设定,W3-B 起),迁移不许把它们刷回 json
            DB::table('event_definition')->where('event_id', $row['event_id'])->update([
                'auto_effect_json' => $row['auto_effect_json'],
                'options_json'     => $row['options_json'],
                'option_a_desc_zh' => $row['option_a_desc_zh'],
                'option_b_desc_zh' => $row['option_b_desc_zh'],
            ]);
        }
    }

    // ---------- ③ 工具制作建筑 ----------

    private function syncItems(): void
    {
        if (! Schema::hasTable('item_definition')) {
            return;
        }

        foreach (ItemDefinitionSeeder::rows() as $row) {
            if (! in_array($row['item_id'], self::ITEM_IDS, true)) {
                continue;
            }

            // 两列一起刷:填了 building_id 就不再是「未映射」,unmapped 必须同时清空
            //(两列互斥是 item_definition 建表注释与 ItemDefinitionTest 都守着的口径)
            DB::table('item_definition')->where('item_id', $row['item_id'])->update([
                'crafting_building_id' => $row['crafting_building_id'],
                'crafting_unmapped_zh' => $row['crafting_unmapped_zh'],
            ]);
        }
    }
};
