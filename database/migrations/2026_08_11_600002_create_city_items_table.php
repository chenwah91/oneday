<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 工具运行时表(M3-D2,v3.2 §7 / §12 Runtime 表)+ cities 的耐久结算时钟。
//
// 一张表而不是 backlog §4.1 草案里的两张(city_item_inventory + city_item_equipped):
// 草案把「未装备的库存」做成 (city_id, item_id, quantity) 的计数行,装备后再拆一行出来。
// 但耐久是**逐件**的状态 —— 一件剩 3 点、一件全新,计数行表达不了,卸下时也无法回答
// 「这两件里退回来的是哪一件」。做成一件一行之后,装备 / 卸下就只是改一列,
// 不存在「计数表与实例表打架」的可能(与 city_npcs 的一 NPC 一行同一条理由)。
//
// 「一件工具同时只能装在一栋楼上」由**表形状**保证:装备关系是本表上的一列
// equipped_instance_id,一行天然只能有一个值。
// 反过来「一栋楼几个槽」不是唯一约束能表达的(槽位数是后台可调的规则参数),
// 由 ItemService 在城市行锁内 count 后判定。
//
// MySQL 5.7 兼容:status 用 varchar 不用 ENUM;耐久用 DECIMAL 不用 float(按分钟扣会有小数);不建 CHECK。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('city_id');
            // 定义 code(item_definition.item_id)。耐久上限 / 效果 / 成本一律回定义表读,不冗余
            $table->string('item_id', 16);

            // 剩余耐久。DECIMAL(10,2):按「工作分钟 / 每点分钟数」扣,天然带小数,
            // 用 float 会让「扣了 300 次之后还剩多少」在不同机器上出现尾差
            $table->decimal('durability_left', 10, 2);

            // stored / equipped / broken(App\Game\Item\ItemCode::STATUS_*)
            $table->string('status', 16)->default('stored');
            // 装备到的建筑实例;NULL = 未装备。不建外键到 city_building_instances:
            // 与 city_npcs.assigned_instance_id 同一约定 —— 外键会让「拆楼」被没卸下的工具卡住
            $table->unsignedBigInteger('equipped_instance_id')->nullable();

            // 获取来源与时刻(ItemCode::SOURCE_*):申诉与反作弊回放要回答「这件东西怎么来的」
            $table->string('acquired_source', 32);
            $table->dateTime('acquired_at');
            $table->timestamps();

            // 快照与乘区准备段都按 (city_id, status) 取数
            $table->index(['city_id', 'status'], 'idx_city_item_status');
            // 按建筑实例归组(乘区准备段 / 槽位占用判定 / 耐久结算)
            $table->index('equipped_instance_id', 'idx_city_item_instance');

            $table->foreign('city_id')->references('id')->on('cities');
        });

        // 耐久的懒结算时钟。
        //
        // 为什么不复用 last_simulated_at:那一列由结算内核推进,而耐久递减要**写** city_items,
        // 走的是 TechService::settleFinished / NpcRuntimeService::settle 同款的「懒结算」路径
        // (快照与工具端点各自触发),两者共用一个时钟会互相吃掉对方的经过时间。
        // 也不复用 npc_settled_at:那是 NPC 的时钟,两个系统共用一列同样会互相吃时间。
        // NULL = 从未结算过,首次按 last_simulated_at 起算。
        Schema::table('cities', function (Blueprint $table) {
            $table->dateTime('item_settled_at')->nullable()->after('npc_settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('item_settled_at');
        });
        Schema::dropIfExists('city_items');
    }
};
