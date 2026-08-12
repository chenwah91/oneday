<?php

use App\Game\City\EraService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 时代升级门槛搬表(W11-B 任务3):EraService::REQUIREMENTS 的九档矩阵落成定义表。
//
// 为什么要搬:门槛数值是「游戏数值」——运营调一次升代难度,现在得改代码、发版、重跑测试。
// 与市场基准价 / 工具效果值 / 事件权重同一性质,它们早就在定义表里由后台改,
// 只有时代门槛还留在 PHP 常量里,是这一批定义数据里唯一的例外。
//
// 一张表管两处口径(EraService 顶部注释已点明这条复用关系):
//   ① 升代门槛(EraService::evaluate 逐维校验);
//   ② 国防威胁需求(DefenseService 经 EraService::defenseRequirement 取「国防最低」列)。
// 搬表之后两处仍然只有这一个来源 —— 这正是当初把 defenseRequirement() 开成访问器的理由,
// 绝不能变成「表一份、常量一份」的双口径(M2 governance_bonus 就是这么裂开的)。
//
// 数据来源 = EraService::REQUIREMENTS 常量本身(反射读取,不在这里誊写第二遍数字)。
// 誊写一遍就是抄错的机会,而这九档数字是 v3.2 §5.1 用户定稿的权威值。
// 常量在搬表后**保留**,唯一消费方就是本迁移(见 EraService 常量顶部的注释)。
//
// 幂等:表已存在则不重建,表内已有行则不灌入 —— 迁移可以在任何一种库状态上重复跑。
//
// MySQL 5.7 兼容:JSON 列无默认值(buildings_json 每行显式给值,空清单写 `{}`)、
// 数值列一律整数(§5.1 的八个门槛全是整数,没有一个是小数)。
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('era_upgrade_requirement')) {
            Schema::create('era_upgrade_requirement', function (Blueprint $table) {
                // 主键 = **目标**时代的 era_order(2 表示「I→II 这一档的门槛」),与常量的键逐字同义。
                // 没有 era_key 外键:门槛描述的是「升到第 N 档要什么」,不是某一档时代的属性,
                // 而且 era 表最高只到 10,tinyint 足够
                $table->unsignedTinyInteger('era_order')->primary();

                // ---- §5.1 的七个数值门槛(全部整数,全部「最低 / 储备」语义,不是费用)----
                $table->unsignedInteger('population');
                $table->unsignedInteger('knowledge');
                $table->unsignedInteger('food');
                $table->unsignedInteger('money');
                $table->unsignedInteger('governance');
                // 幸福度是 0~100 的百分制,与其余六项不同量纲(后台编辑器对它另有 0~100 的上限)
                $table->unsignedInteger('happiness');
                // 国防最低:**同时**是 M3-D5 威胁需求的来源(见文件顶部说明)
                $table->unsignedInteger('defense');

                // 必须建筑清单 {building_id: 数量}。空清单写 `{}` 而不是 null ——
                // 「这一档不要求建筑」是一个明确的事实,不是「还没填」
                $table->json('buildings_json');
            });
        }

        // 幂等:表非空跳过。已经跑过的库(哪怕后台已经改过几行数值)不会被常量覆盖回去 ——
        // 迁移只负责「第一次把数据搬进来」,搬进来之后表才是唯一真相
        if (DB::table('era_upgrade_requirement')->exists()) {
            return;
        }

        // 反射读私有常量:数字只存在于 EraService::REQUIREMENTS 一处
        $matrix = (new ReflectionClass(EraService::class))->getConstant('REQUIREMENTS');

        $rows = [];
        foreach ($matrix as $eraOrder => $need) {
            $rows[] = [
                'era_order'      => (int) $eraOrder,
                'population'     => (int) $need['population'],
                'knowledge'      => (int) $need['knowledge'],
                'food'           => (int) $need['food'],
                'money'          => (int) $need['money'],
                'governance'     => (int) $need['governance'],
                'happiness'      => (int) $need['happiness'],
                'defense'        => (int) $need['defense'],
                // 强制编成对象:空数组被 json_encode 编成 `[]`(列表),而这一列语义上恒为
                // 「建筑 → 数量」的映射。两种形状混在同一列里,读的人迟早写出一个形状判断分支
                'buildings_json' => json_encode((object) $need['buildings'], JSON_UNESCAPED_UNICODE),
            ];
        }

        DB::table('era_upgrade_requirement')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('era_upgrade_requirement');
    }
};
