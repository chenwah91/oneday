<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NPC 运行时表(M3-D1,v3.2 §6 / §12 Runtime 表)+ cities 的 NPC 结算时钟。
//
// 「一 NPC 一岗」(CLAUDE §52 / §67 点名的作弊检测项)在这里由**表形状**保证:
// 派驻关系是 city_npcs 上的一列 assigned_instance_id,一行天然只能有一个值 ——
// 比另开一张 city_npc_assignments 再加 UNIQUE(npc_instance_id) 更省一张表,也不存在两表打架的可能。
// 反过来「一栋楼几个槽」不是唯一约束能表达的(槽位数是后台可调的规则参数),
// 由 NpcService 在城市行锁内 count 后判定。
//
// MySQL 5.7 兼容:status 用 varchar 不用 ENUM;morale 用 DECIMAL 不用 float;不建 CHECK。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_npcs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('city_id');
            // 定义 code(npc_definition.npc_id)。工资 / 口粮 / 稀有度 / 特性一律回定义表读,不冗余
            $table->string('npc_id', 16);

            // 总等级(§6.2「NPC 总等级 1~10」)与当前级内累计 XP
            $table->unsignedTinyInteger('skill_level')->default(1);
            $table->unsignedInteger('xp')->default(0);
            // 技能值(§6.3 initial_skill_value 的运行时副本):它是展示值,不参与乘区计算,
            // 升级 / 事件可能改它,所以必须落在运行时行上而不是每次回定义表取
            $table->unsignedSmallInteger('skill_value')->default(0);

            // 士气 0~100(backlog §9 A4:初始 70,阈值与速率全部走 game_settings)
            $table->decimal('morale', 5, 2)->default(70);

            // idle / assigned / left(App\Game\NPC\NpcCode::STATUS_*)
            $table->string('status', 16)->default('idle');
            // 派驻到的建筑实例;NULL = 未派驻。不建外键到 city_building_instances:
            // 拆楼时由 NpcService 主动置空(见 DemolishController 不在本波次改动范围的说明),
            // 外键会让「拆楼」被没撤下的 NPC 卡住
            $table->unsignedBigInteger('assigned_instance_id')->nullable();

            // 获取来源与时刻(NpcCode::SOURCE_*):申诉与反作弊回放要回答「这个人怎么来的」
            $table->string('acquired_source', 32);
            $table->dateTime('acquired_at');
            $table->timestamps();

            // 快照与结算都按 (city_id, status) 取数
            $table->index(['city_id', 'status'], 'idx_city_npc_status');
            // 按建筑实例归组(乘区准备段 / 槽位占用判定)
            $table->index('assigned_instance_id', 'idx_city_npc_instance');

            $table->foreign('city_id')->references('id')->on('cities');
        });

        // NPC 运行时状态(XP / 士气 / 自然增长 / 离职)的懒结算时钟。
        //
        // 为什么不复用 last_simulated_at:那一列由结算内核推进,而 NPC 的运行时结算
        // 走的是 TechService::settleFinished 同款的「懒结算」路径(快照与 NPC 端点各自触发),
        // 两者共用一个时钟会互相吃掉对方的经过时间。NULL = 从未结算过,首次按建城/当前时刻起算。
        Schema::table('cities', function (Blueprint $table) {
            $table->dateTime('npc_settled_at')->nullable()->after('last_simulated_at');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('npc_settled_at');
        });
        Schema::dropIfExists('city_npcs');
    }
};
