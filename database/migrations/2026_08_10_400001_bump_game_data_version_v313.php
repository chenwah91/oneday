<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 波次收尾:定义表枚举值英文化(2026_08_10_200003)后统一递增数据版本 → V3.1.3。
// 单独成一支迁移:200003 已在部分库上跑过(不含 bump),放这里保证「跑过/没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.1.3,此处 exists 守卫跳过。
// C1 的 300001 只动运行时表(cities/instances),不影响定义指纹,无需另记版本。
return new class extends Migration {
    public function up(): void
    {
        if (DB::table('resource_definition')->exists()) {
            GameDataVersion::bump('枚举值英文化:building category/series/cost_type/resource category/tech branch → 英文 code(v3.2 §0.2 第二批)', 'migration');
        }
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随枚举回滚删除(历史就是历史,与 audit 同口径)
    }
};
