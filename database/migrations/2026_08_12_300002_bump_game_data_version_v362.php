<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// M3-W6 治理容量 target 清偿后递增数据版本 → V3.6.2。
//
// 为什么是**补丁位**:没有新增定义表,也没有一栋建筑的产出 / 成本 / 升级链被改动 ——
// 变的是 4 行定义数据的**行内容**:
//   · npc_definition  :N013 / N051 / N111 的 trait_json 由 governance_capacity_pct(op=flat)
//     改成 governance_capacity_flat(op=flat),数值 30 / 20 / 22 一个没动;
//   · event_definition:EVT_CORRUPTION 选项 B 的「治理容量暂时-10%」由 unmapped 提升为可执行 modifier。
// 这些行都进 GameDataVersion::CHECKSUM_TABLES,指纹随之改变;而且**同一批定义数据在
// V3.6.1 与 V3.6.2 下会算出不同结果** —— V3.6.2 起 18 位行政 NPC 与 IT022 的治理加成
// 第一次真的进 governanceLoad,同样的人口 / 同样的行政所会得到不同的治理效率与税收。
// 半年后回查「他那天税收为什么是这个数」必须能一眼看出「那时治理加成已经上线」(§64 / §65)。
//
// ⚠️ 严格升序:GameDataVersion::current() 取 id 最大的一行,插反了「当前版本」会回退。
//
// 单独成一支迁移:300001 可能已在部分库上跑过,放这里保证「跑过 / 没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.6.2,此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.6.2';

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
            'M3-W6 治理容量 target 清偿:拆成 governance_capacity_flat + pct 两条并接进结算内核,'
            . 'N013/N051/N111 的 flat 投稿迁到 flat target,EVT_CORRUPTION 选项 B 的治理容量减益由 unmapped 提升',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
