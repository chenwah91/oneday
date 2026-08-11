<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// NPC 池 30 → 150 + EVT_BRAIN_DRAIN 复活后递增数据版本 → V3.6.0。
//
// 为什么是**次版本位**(而不是补丁位):定义数据的**行集**变了,而且同一批存档在
// V3.5.1 与 V3.6.0 下会跑出不同结果:
//   · npc_definition 30 → 150 行:招募掷点的候选池整整多了 120 个原型,
//     「时代 I 也能招募」这件事在 V3.5.1 之前不成立(原表最早的可招募原型是时代 II);
//   · 其中 10 行军事 NPC 的国防特性由 unmapped 提升为 defense_score_flat / _pct spec
//     → 有效国防值、威胁覆盖率、EVT_RAID 的损失比例都会跟着变;
//   · npc_definition 多了 name_zh 一列(进 checksum 的列集变了);
//   · event_definition 的 EVT_BRAIN_DRAIN 由停用转启用,自动效果换成可执行的 npc_leave
//     → 高技能城市从此会真的掉人。
// 半年后回查「他那天到底为什么少了一个 NPC / 为什么招到了 N087」必须能一眼看出
// 「那时 150 池与人才流失已经上线」(§64 / §65)。
//
// ⚠️ 严格升序:GameDataVersion::current() 取 id 最大的一行,插反了「当前版本」会回退。
// 前一版是 W4 的 V3.5.1(M3-D5 国防联动),本版紧随其后。
//
// 单独成一支迁移:100001~100003 可能已在部分库上跑过,放这里保证「跑过 / 没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.6.0,此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.6.0';

    public function up(): void
    {
        // 「是不是全新库」用一张**只有 DatabaseSeeder 才会填**的定义表来判断(与前几支 bump 迁移同套路):
        // 全新库在 migrate 阶段 resource_definition 是空的 → 跳过,版本号全部交给 Seeder 按升序写
        if (! DB::table('resource_definition')->exists()) {
            return;
        }

        if (DB::table('game_data_versions')->where('version', self::VERSION)->exists()) {
            return; // Seeder 已写入同版本,不重复插入
        }

        GameDataVersion::bump(
            'NPC 原型池 30 → 150(新增 N031~N150 + name_zh 中文名)+ 10 行军事 NPC 国防特性提升为 spec + EVT_BRAIN_DRAIN 人才流失复活',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
