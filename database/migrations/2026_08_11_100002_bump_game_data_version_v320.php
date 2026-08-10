<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 波次收尾:两份 V3.2 映射草案落地(2026_08_11_100001)后统一递增数据版本 → V3.2.0。
//
// 走**次版本**而不是补丁位:本次不是数值微调,而是数据形状变化(建筑新增产出/投入条目、
// 升级链拓扑重接、资源首次时代改写),老存档的产线与升级去向都会跟着变,按 §18.3 应当 bump 次版本。
//
// 单独成一支迁移:100001 已在部分库上跑过(不含 bump),放这里保证「跑过/没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.2.0,此处 exists 守卫跳过。
return new class extends Migration {
    private const VERSION = 'V3.2.0';

    public function up(): void
    {
        if (! DB::table('resource_definition')->exists()) {
            return; // 全新库:定义表还没 seed,由 Seeder 写入版本
        }

        if (DB::table('game_data_versions')->where('version', self::VERSION)->exists()) {
            return; // Seeder 已写入同版本,不重复插入
        }

        GameDataVersion::bump(
            'RESOURCE_SOURCE_MAPPING(黏土/砂石/水泥/药品补链)+ BUILDING_UPGRADE_REMAP(6 条跨代升级链重映射)',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随数据回滚删除(历史就是历史,与 audit 同口径)
    }
};
