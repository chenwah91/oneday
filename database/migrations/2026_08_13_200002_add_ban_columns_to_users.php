<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// W11-C1 任务4:users 增加封禁两列(banned_at / ban_reason)。
//
// ⚠️ 结构变更:上线前先备份(配合 backup_sys)。
//
// 设计要点:
// 1. **绝不删除玩家数据**(CLAUDE 红线 + 任务硬约束):封禁只是 users 上的一个时间戳,
//    城市 / 资源 / 审计一行不动,解禁把 banned_at 置 NULL 即可完整恢复;
// 2. banned_at 用 DATETIME NULL 而不是 boolean:「什么时候被封的」是申诉与统计的第一现场,
//    布尔列一旦要回答「封了多久」就得再补一列,不如一次做对;
// 3. ban_reason VARCHAR(190) 与 users 其余字符列同宽口径(utf8mb4 下 190 是安全的索引宽度);
//    接口层再收紧到 5~80 字,与 audit_logs.reason_code(VARCHAR(80))对齐 ——
//    列宽留富余,是为了将来 reason 需要拼上工单号时不必再改结构;
// 4. banned_at 单列索引:后台「被封玩家列表」与仪表盘统计按它过滤;
//    存量行一律 NULL(= 未封禁),迁移不改动任何既有数据。
//
// MySQL 5.7 兼容:不用 ENUM、不建 CHECK、DATETIME 不给默认值。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 列可能已存在(不同环境的建表来源可能不同),存在就不重复加
            if (! Schema::hasColumn('users', 'banned_at')) {
                $table->dateTime('banned_at')->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'ban_reason')) {
                $table->string('ban_reason', 190)->nullable()->after('banned_at');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! $this->indexExists('idx_users_banned_at')) {
                $table->index('banned_at', 'idx_users_banned_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if ($this->indexExists('idx_users_banned_at')) {
                $table->dropIndex('idx_users_banned_at');
            }
            if (Schema::hasColumn('users', 'ban_reason')) {
                $table->dropColumn('ban_reason');
            }
            if (Schema::hasColumn('users', 'banned_at')) {
                $table->dropColumn('banned_at');
            }
        });
    }

    private function indexExists(string $index): bool
    {
        $rows = \Illuminate\Support\Facades\DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['users', $index]
        );

        return $rows !== [];
    }
};
