<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// M3-W10 三组定义数据改动落地后递增数据版本 → V3.7.0。
//
// 为什么吃**次版本位**而不是补丁位:改的不只是数值,而是**玩法条件**——
//   · item_definition:6 件工具的 crafting_building_id 由 NULL 变成实际建筑
//     (IT003/IT005→P02、IT004→P04、IT013→P05、IT016→K03、IT019→P08)。
//     ItemService::craft 的既有闸门从此对这 6 件生效:同样一座城、同样的材料与时代,
//     V3.6.2 下能做出来的工具在 V3.7.0 下会返回 CRAFTING_BUILDING_MISSING。
//     这是获取条件的变化,不是产量的微调;
//   · event_definition:EVT_CORRUPTION 选项 A 由「只扣钱不办事」变成 100% 确定性解除两条减益、
//     选项 B 由当期 -10% 折算为当期 -5%、EVT_PORT_CONGESTION 选项 A 追加拥堵立即解除。
//     同一个事件实例、同一个选项,V3.6.2 与 V3.7.0 下的城市结算结果完全不同;
//   · npc_definition:N001~N030 的 name_zh 由 NULL 回填为中文拟名(显示列,不参与判定,
//     但它进 CHECKSUM_TABLES 的列集,指纹随之改变)。
// 半年后回查「他那天为什么做不出木犁」「选了调查为什么减益没了」必须能一眼看出版本分界(§64 / §65)。
//
// ⚠️ 严格升序:GameDataVersion::current() 取 id 最大的一行,插反了「当前版本」会回退。
//
// 单独成一支迁移:400001 可能已在部分库上跑过,放这里保证「跑过 / 没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.7.0,此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.7.0';

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
            'M3-W10 用户拍板三组数据改动:N001~N030 中文拟名回填(全表 150 名互异)'
            . '+ EVT_CORRUPTION 选项 A 改为确定性解除、选项 B 净额折算为 -5%、EVT_PORT_CONGESTION 选项 A 追加拥堵解除'
            . '+ 6 件工具改挂现有制作建筑(IT003/IT005→P02、IT004→P04、IT013→P05、IT016→K03、IT019→P08)',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
