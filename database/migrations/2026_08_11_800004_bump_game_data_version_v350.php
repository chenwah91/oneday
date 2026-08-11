<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// M.1 电力系统落地后递增数据版本 → V3.5.0。
//
// 为什么是**次版本位**而不是补丁位:定义数据的**含义**变了,不只是多了一张表 ——
//   · building_level_definition 的 `power_per_min`(57 行非零)从「seed 了但零读取」变成
//     全城耗电需求的唯一来源,直接决定时代 VIII~X 建筑的实际产出;
//   · output_json 里的 `electricity`(E03/E04/E05 共 9 行)从「普通库存产出」变成「装机容量」,
//     不再入 city_resources;
//   · input_json 里的 `electricity`(36 行)从「按库存扣料」变成不再读取(与 power_per_min 双计);
//   · event_definition 的 EVT_BLACKOUT 一行从停用变启用,条件 / 自动效果 / 两个选项全部换成可执行 DSL。
// 也就是说:**同一批定义数据,在 V3.4.1 与 V3.5.0 下会算出不同的产量**。
// 这正是 §64 / §65 要求留版本号的场景 —— 半年后回查「他那座城当时为什么只出了一半货」,
// 必须能一眼看出「那时电力已经生效了」。
//
// 版本号紧接 V3.4.1(M3-D4 事件定义层)之后。
// ⚠️ 同波次并行落地的 D5 国防若也要 bump,应排在本行**之后**取下一个号(严格升序,
//    GameDataVersion::current() 取 id 最大的一行,插反了「当前版本」会回退)。
//
// 单独成一支迁移:800001~800003 可能已在部分库上跑过,放这里保证「跑过 / 没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.5.0,此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.5.0';

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
            'M.1 电力系统:power_per_min(57 行)由零读取转为耗电需求 + electricity 由库存资源转为产能合约(§8 RS017 / 9.F4)+ EVT_BLACKOUT 复活',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
