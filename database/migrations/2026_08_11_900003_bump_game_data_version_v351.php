<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// M3-D5 国防联动落地后递增数据版本 → V3.5.1。
//
// 为什么是**补丁位**:没有新增定义表,也没有一栋建筑的产出 / 成本 / 升级链被改动 ——
// 变的是 6 行定义数据的**行内容**:
//   · event_definition 的 EVT_RAID / EVT_BORDER_TENSION 两行从停用变启用,
//     条件与自动效果从 unmapped 换成可执行 DSL(threat_level / threat_loss_pct / threat_demand_pct);
//   · item_definition 的 IT008 effect_json:国防 flat(+8)由 unmapped 提升为 spec;
//   · npc_definition 的 N010(+12 flat)/ N016(+15% pct)/ N027(+20% pct)trait_json 同上。
// 这 6 行都进 GameDataVersion::CHECKSUM_TABLES,指纹随之改变;而且**同一批定义数据在
// V3.5.0 与 V3.5.1 下会算出不同结果**(V3.5.1 起城市会挨劫掠、军事 NPC 与防御装备开始加国防值),
// 半年后回查「他那天到底为什么损失了 30% 库存」必须能一眼看出「那时国防联动已经上线」(§64 / §65)。
//
// 版本号紧接 V3.5.0(同波次并行落地的 M.1 电力系统)之后 —— backlog §10.2 的 W4 波次两个任务
// 同时进行,版本号按落地顺序分配,不互相等待。
// ⚠️ 严格升序:GameDataVersion::current() 取 id 最大的一行,插反了「当前版本」会回退。
//
// 单独成一支迁移:900001 / 900002 可能已在部分库上跑过,放这里保证「跑过 / 没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.5.1,此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.5.1';

    public function up(): void
    {
        // 「是不是全新库」用一张**只有 DatabaseSeeder 才会填**的定义表来判断(与前几支 bump 迁移同套路):
        // 全新库在 migrate 阶段 resource_definition 是空的 → 跳过,版本号全部交给 Seeder 按升序写;
        // 已有数据的库它必然非空 → 正常递增
        if (! DB::table('resource_definition')->exists()) {
            return;
        }

        if (DB::table('game_data_versions')->where('version', self::VERSION)->exists()) {
            return; // Seeder 已写入同版本,不重复插入
        }

        GameDataVersion::bump(
            'M3-D5 国防联动:EVT_RAID / EVT_BORDER_TENSION 复活 + IT008 国防 flat 与 N010/N016/N027 国防特性由 unmapped 提升为可执行',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
