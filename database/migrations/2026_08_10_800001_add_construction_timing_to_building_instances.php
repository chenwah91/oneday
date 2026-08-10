<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M2-C5 建筑生命周期:施工 / 升级计时(v3.2 §16.3「B3 = 做」)
//
// 只补一列 construction_finished_at:服务器权威的完工时刻。
//   NULL          = 该实例没有在进行中的工程(存量 active 建筑全部如此,不受影响)
//   非 NULL       = 施工 / 升级中,或「刚完工但完工点落在本次结算窗口内」(见 SimulationService 的懒完工)
//
// status 语义扩展:现列已是 varchar(16),直接使用新值,不需要改列类型 ——
//   constructing  建造中(还没生产,拆除 = 取消建造)
//   upgrading     升级中(v3.2 §3.2「升级时建筑进入 upgrading 状态:生产建筑默认暂停生产」)
//   active        正常运转(唯一进入生产集合的状态)
//
// MySQL 5.7 每条 ALTER TABLE 都要重建整张表,所以本次只发一条 ALTER。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('city_building_instances', function (Blueprint $table) {
            $table->dateTime('construction_finished_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('city_building_instances', function (Blueprint $table) {
            $table->dropColumn('construction_finished_at');
        });
    }
};
