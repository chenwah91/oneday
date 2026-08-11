<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// M3-D3 市场定义层落地后递增数据版本 → V3.3.1。
//
// 为什么是**补丁位**而不是次版本:新增了一张定义表(market_definition),但既有 5 张定义表
// 一行一列都没动 —— 所有城市的产出 / 成本 / 升级链完全不变,不存在「老城数值被改」的情况。
// 但 checksum 表清单多了一张表(见 GameDataVersion::CHECKSUM_TABLES),指纹随之改变,
// 所以仍要留一个版本号,半年后才回答得了「那时的市场基础价是多少」(§64 / §65)。
//
// 版本号刻意跳过 V3.3.0:那一号留给同波次并行落地的 M3-D1 NPC 定义表
// (backlog §10.2 W2 波次两个任务同时进行,版本号按落地顺序分配,不互相等待)。
//
// 单独成一支迁移:500001 / 500002 可能已在部分库上跑过,放这里保证「跑过 / 没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.3.1,此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.3.1';

    public function up(): void
    {
        // 「是不是全新库」必须用一张**只有 DatabaseSeeder 才会填**的定义表来判断,
        // 不能用 market_definition —— 它已经被本批次的 500001 迁移灌满了,拿它判断永远为真。
        //
        // 那样会在全新库上出事:migrate 阶段这里先写下 V3.3.1,随后 db:seed 才补写 V3.3.0,
        // game_data_versions 的 id 顺序就成了 …→V3.3.1→V3.3.0,而 current() 取 id 最大的一行 →
        // 「当前数值版本」直接回退到 V3.3.0。
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
            'M3-D3 市场定义层:market_definition 26 行(v3.2 §8 全表)+ base_liquidity 模型(9.C1)',
            'migration',
            self::VERSION
        );
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
