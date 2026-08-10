<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 波次收尾:building_level_definition 三列双口径物理删除(2026_08_11_150001)后递增数据版本 → V3.2.1。
//
// 走**补丁位**而不是次版本:被删的三列自始至终没有参与任何结算(单一来源一直是 output_json),
// 删掉它们不改变任何一座城的产出、容量或升级链 —— 只是把「会骗人的冗余列」清出定义表。
// 但定义表的列形状确实变了(checksum 随之改变),所以仍要留一个版本号,
// 半年后才回答得了「那时的定义表长什么样」(§64/§65)。
//
// 单独成一支迁移:150001 可能已在部分库上跑过(不含 bump),放这里保证「跑过/没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.2.1,此处 exists 守卫跳过。
return new class extends Migration {
    private const VERSION = 'V3.2.1';

    public function up(): void
    {
        if (! DB::table('building_level_definition')->exists()) {
            return; // 全新库:定义表还没 seed,由 Seeder 写入版本
        }

        if (DB::table('game_data_versions')->where('version', self::VERSION)->exists()) {
            return; // Seeder 已写入同版本,不重复插入
        }

        GameDataVersion::bump(
            '删除 building_level_definition 的 happiness_bonus / governance_bonus / defense_score 三列双口径(单一来源 output_json)',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随列回滚删除(历史就是历史,与 audit 同口径)
    }
};
