<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// M3-D1 NPC 定义层落地后递增数据版本 → V3.3.0。
//
// 为什么是**次版本**而不是补丁位:定义数据的形状变了 —— 新增三张定义表
// (npc_skill_definition / npc_skill_level_curve / npc_definition,合计 12 + 10 + 30 行),
// 并且它们从此参与 checksum(见 GameDataVersion::CHECKSUM_TABLES)。
// 既有 5 张定义表一行未动,所有城市的产出 / 成本 / 升级链完全不变,
// 但「有没有 NPC 系统」这件事本身就是一次数值形状的变化,补丁位表达不了(§64 / §65)。
//
// 与并行落地的 M3-D3 市场(V3.3.1,迁移 2026_08_11_500003)的关系:
// 版本号按波次内的落地顺序分配,NPC 在前、市场在后;两支迁移的时间戳顺序(400004 < 500003)
// 保证了 game_data_versions 的插入顺序与版本号升序一致 ——
// GameDataVersion::current() 取的是 id 最大的一行,顺序插反了「当前版本」会回退。
//
// 单独成一支迁移:400001~400003 可能已在部分库上跑过(不含 bump),放这里保证
// 「跑过 / 没跑过」的库走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.3.0,
// 此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.3.0';

    public function up(): void
    {
        // 「是不是全新库」必须用一张**只有 DatabaseSeeder 才会填**的定义表来判断,
        // 不能用 npc_definition —— 它已经被本批次的 400001 迁移灌满了,拿它判断永远为真。
        //
        // 那样会在全新库上出事:migrate 阶段这里先写下 V3.3.0,随后 db:seed 才按升序补写
        // V3.1.0…V3.2.1,game_data_versions 的 id 顺序就乱了,而 current() 取 id 最大的一行 →
        // 「当前数值版本」会回退到一个旧版本号。
        // 改用 resource_definition(与 2026_08_11_200001 用 building_level_definition 同一套路):
        // 全新库在 migrate 阶段它是空的 → 跳过,版本号全部交给 Seeder 按升序写;
        // 已有数据的库它必然非空 → 正常递增。
        if (! DB::table('resource_definition')->exists()) {
            return; // 全新库:定义表还没 seed,由 Seeder 按升序写入版本
        }

        if (DB::table('game_data_versions')->where('version', self::VERSION)->exists()) {
            return; // Seeder 已写入同版本,不重复插入
        }

        GameDataVersion::bump(
            'M3-D1 NPC 定义层:12 条技能 + 10 级曲线 + 30 个 NPC 原型(v3.2 §6.1 / §6.2 / §6.3)',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
