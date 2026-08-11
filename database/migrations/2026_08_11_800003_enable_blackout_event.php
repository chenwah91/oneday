<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Database\Seeders\EventDefinitionSeeder;

// M.1 电力落地后复活 EVT_BLACKOUT(v3.2 §9.2「大停电」)。
//
// M3-D4 交付时这一条是 Fail Closed 停用的:
//   disabled_reason = 「整条依赖电力系统(M.1,W4-A):power 乘区至今恒 1.0,「电力使用率」也还没有口径」
// 现在两个依赖都到位了:
//   · 条件「电力使用率>85%」→ 新增 metric power_usage_rate(EventCode / EventCondition / 内核 $sim 三处已通);
//   · 自动效果「全城电力可用量-40%」→ target=power 的持续型 modifier,由 PowerMultiplierProvider 读回发电侧;
//   · 选项 A「减益降为-10%」→ modifier_set_value 点名 target=power;
//   · 选项 B「工业限电」→ 解除电力减益 + processing 类停机。
//
// **只动 EVT_BLACKOUT 这一行**(backlog §10.2 并行纪律:同批次另一个 agent 在改国防那两条)。
// 数据源仍是 database/data/events.json —— 这里从 Seeder 的同一份构造里取出该行再写库,
// 保证「跑迁移」与「跑 seed」两条路径落到库里的内容一字不差,也自动过一遍 Seeder 的全部守门。
//
// 幂等:updateOrInsert,重复跑就是把这一行刷回 events.json 的样子。
return new class extends Migration
{
    private const EVENT_ID = 'EVT_BLACKOUT';

    public function up(): void
    {
        // 表还不存在(全新库跑到这里时 700001 尚未建表)→ 交给 Seeder / 700001 自己灌
        if (! Schema::hasTable('event_definition')) {
            return;
        }

        $row = collect(EventDefinitionSeeder::rows())->firstWhere('event_id', self::EVENT_ID);
        if ($row === null) {
            return; // events.json 里没有这一条:不猜,不写
        }

        // 主键单独拎出去,其余列整体覆盖
        $eventId = $row['event_id'];
        unset($row['event_id']);
        // effect_multiplier 是**运营调过的值**(后台逐事件可调),迁移不许把它刷回 1
        unset($row['effect_multiplier']);

        DB::table('event_definition')->updateOrInsert(['event_id' => $eventId], $row);
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_definition')) {
            return;
        }

        // 回滚 = 退回 Fail Closed 停用状态(与 M3-D4 交付时一致),不删定义行
        DB::table('event_definition')->where('event_id', self::EVENT_ID)->update([
            'enabled'         => false,
            'disabled_reason' => '整条依赖电力系统(M.1,W4-A):power 乘区至今恒 1.0,「电力使用率」也还没有口径。→ Fail Closed 停用,电力落地后开启即可',
        ]);
    }
};
