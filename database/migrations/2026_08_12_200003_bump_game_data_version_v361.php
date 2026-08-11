<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// M3-W5 容量 / 税收 / 市场价格三组 target 接线后递增数据版本 → V3.6.1。
//
// 为什么是**补丁位**:没有新增定义表,也没有一栋建筑的产出 / 成本 / 升级链被改动 ——
// 变的是 19 行定义数据的**行内容**:
//   · event_definition:EVT_ROUTE_BREAK / EVT_PORT_CONGESTION / EVT_CRIME / EVT_CORRUPTION
//     / EVT_SPECULATION / EVT_OIL_SHOCK 六行由停用转启用(条件与效果换成可执行 DSL),
//     EVT_TRADE_BOOM 两个选项的「成交量 ±X%」由 unmapped 提升为 trade_capacity_pct,
//     EVT_TAX_PROTEST 只刷未生效说明(**维持停用**:税率固定不可调,条件恒不成立);
//   · item_definition:IT018 的「运输容量+15%」由 unmapped 提升为 spec;
//   · npc_definition:N013(税收+8%)与 10 位物流 NPC(运输 / 铁路容量)同上;
//   · npc_skill_definition:SKILL_LOGISTICS 的 effect_target 由 NULL 改为 transport_capacity_pct。
// 这些行都进 GameDataVersion::CHECKSUM_TABLES,指纹随之改变;而且**同一批定义数据在
// V3.6.0 与 V3.6.1 下会算出不同结果**(V3.6.1 起运输容量会被事件与物流 NPC 改动、税收会被事件打折、
// 市场买入价会被石油冲击抬高、单城成交量上限开始受贸易容量约束),
// 半年后回查「他那天为什么只买得到这么点、为什么这么贵」必须能一眼看出「那时这三组已经上线」(§64 / §65)。
//
// 版本号紧接 V3.6.0(同波次并行落地的 NPC 150 条扩充)之后 —— backlog §10.2 的并行波次
// 两个任务同时进行,版本号按分配顺序落位,不互相等待。
// ⚠️ 严格升序:GameDataVersion::current() 取 id 最大的一行,插反了「当前版本」会回退。
//
// 单独成一支迁移:200001 / 200002 可能已在部分库上跑过,放这里保证「跑过 / 没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.6.1,此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.6.1';

    public function up(): void
    {
        // 「是不是全新库」用一张**只有 DatabaseSeeder 才会填**的定义表来判断(与前几支 bump 迁移同套路)
        if (! DB::table('resource_definition')->exists()) {
            return;
        }

        if (DB::table('game_data_versions')->where('version', self::VERSION)->exists()) {
            return; // Seeder 已写入同版本,不重复插入
        }

        GameDataVersion::bump(
            'M3-W5 容量/税收/价格三组 target 接线:EVT_ROUTE_BREAK / EVT_PORT_CONGESTION / EVT_CRIME / EVT_CORRUPTION / EVT_SPECULATION / EVT_OIL_SHOCK 复活 + IT018 与 11 位 NPC 的运输容量/税收特性由 unmapped 提升 + 贸易容量接市场额度',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
