<?php

use App\Game\Definition\GameDataVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// M3-D2 工具定义层 + RS027/RS028 上市之后递增数据版本 → V3.4.0。
//
// 为什么是**次版本**而不是补丁位:本批次同时做了两件会改变既有数值的事 ——
//   ① 新增 item_definition(24 行)并把它加进 checksum 表清单(指纹变了);
//   ② **market_definition 从 26 行变成 28 行**(水泥 / 药品上市),
//      这是「数据形状变化」而不是「多一张新表」:同一张既有定义表的行集变了,
//      任何依赖「§8 是 26 行」的对账都要重新校准。按 §18.3 的口径,这一条足以吃掉次版本位。
//
// 排在 600004(上市)之后:checksum 是对**已落库**的定义表算的,顺序反了指纹里就没有那两行。
//
// 单独成一支迁移:600001~600004 可能已在部分库上跑过,放这里保证「跑过 / 没跑过」的库
// 走同一条递增路径;全新库由 GameDataVersionSeeder 直接写入 V3.4.0,此处 exists 守卫跳过。
return new class extends Migration
{
    private const VERSION = 'V3.4.0';

    private const NOTES = 'M3-D2 工具定义层:item_definition 24 行(v3.2 §7)+ RS027 水泥 / RS028 药品上市(资源来源映射草案 §7)';

    public function up(): void
    {
        // 「是不是全新库」必须用一张**只有 DatabaseSeeder 才会填**的定义表来判断,
        // 不能用 item_definition —— 它已经被本批次的 600001 迁移灌满了,拿它判断永远为真。
        //
        // 那样会在全新库上出事:migrate 阶段这里先写下 V3.4.0,随后 db:seed 才补写更早的版本行,
        // game_data_versions 的 id 顺序就成了 …→V3.4.0→V3.3.x,而 current() 取 id 最大的一行 →
        // 「当前数值版本」直接回退。
        // 改用 resource_definition(与 200001 / 500003 同一套路):
        // 全新库在 migrate 阶段它是空的 → 跳过,版本号全部交给 Seeder 按升序写;
        // 已有数据的库它必然非空 → 正常递增。
        if (! DB::table('resource_definition')->exists()) {
            return; // 全新库:定义表还没 seed,由 Seeder 按升序写入版本
        }

        if (DB::table('game_data_versions')->where('version', self::VERSION)->exists()) {
            return; // Seeder 已写入同版本,不重复插入
        }

        GameDataVersion::bump(self::NOTES, 'migration', self::VERSION);
    }

    public function down(): void
    {
        // 版本历史是追加式记录,不随表回滚删除(历史就是历史,与 audit 同口径)
    }
};
