<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 删五个死列(2026_08_13_300001)后递增数据版本 → V3.8.1。
//
// 吃**补丁位**:没有任何数值变化(三列恒 0、两列零读取),只是 building_definition 的
// 列集变了 —— checksum 的构成随之改变,不 bump 的话 audit:verify 类的指纹对账会对不上。
//
// ⚠️ 严格升序:GameDataVersion::current() 取 id 最大的一行,插反了「当前版本」会回退。
return new class extends Migration
{
    private const VERSION = 'V3.8.1';

    public function up(): void
    {
        if (! DB::table('resource_definition')->exists()) {
            return; // 全新库由 GameDataVersionSeeder 直接写入,此处跳过
        }

        if (DB::table('game_data_versions')->where('version', self::VERSION)->exists()) {
            return;
        }

        GameDataVersion::bump(
            '删除 building_definition 五个死列(population_min/governance_ratio_min/happiness_min 恒0无读取,'
            . 'base_workers/base_build_seconds 被 level 表取代;用户 2026-08-13 拍板删5留3)',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除
    }
};
