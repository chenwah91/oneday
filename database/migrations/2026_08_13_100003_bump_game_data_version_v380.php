<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// W11-B 定义层扩容落地后递增数据版本 → V3.8.0。
//
// 为什么吃**次版本位**而不是补丁位:定义数据的**形状**变了两处 ——
//   · 新表 era_upgrade_requirement(9 行)进 GameDataVersion::CHECKSUM_TABLES:
//     时代升级门槛与国防威胁需求从此以表为唯一真相(EraService 的常量降级为迁移的数据源),
//     指纹的构成随之改变;
//   · npc_definition 新增 trait_multiplier 列(150 行默认 1.0000):
//     它进 checksum 的列集,且从此是 NPC 特性强度的乘数 —— 后台调一行,
//     那位 NPC 的全部 specs(治理 / 产量 / 国防 / 维护费减免…)一起变。
//
// 落地当刻的**行为**是零变化:门槛数值逐格搬自同一份常量,倍率全表默认 1.0000。
// 但同一批定义数据在 V3.7.0 与 V3.8.0 下的**可改性**不同(门槛与特性强度第一次可后台调整),
// 半年后回查「他升代时门槛是多少」「那位行政 NPC 当时加多少治理」必须能一眼看出版本分界(§64 / §65)。
//
// ⚠️ 严格升序:GameDataVersion::current() 取 id 最大的一行,插反了「当前版本」会回退。
//
// 单独成一支迁移:100001 / 100002 可能已在部分库上跑过,放这里保证「跑过 / 没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.8.0,此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.8.0';

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
            'W11-B 定义层扩容:时代升级门槛搬表(era_upgrade_requirement 9 行,同时是国防威胁需求的唯一来源)'
            . '+ npc_definition 新增 trait_multiplier 特性强度倍率(150 行默认 1.0000)'
            . '+ 建筑等级三个 JSON 列 / 科技 / 建筑上限 / NPC 等级曲线 / 时代门槛后台可编辑',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
