<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M3-M.9 审计 Hash Chain(CLAUDE §58 / SECURITY「Audit HMAC Hash Chain」/ v3.2 §12.2):
// 补 previous_hash / event_hash 两列 + 链读写用的复合索引。
//
// 历史行一律不回填(append-only 纪律):两列保持 NULL,链从部署时刻开始;
// audit:verify-chain 见到 previous_hash IS NULL 的行按「历史行」跳过并计数。
//
// 索引 idx_audit_chain (city_id, id) 是链的命脉:
//   1) 写入时 O(1) 取同域最后一条(WHERE city_id <=> ? ORDER BY id DESC LIMIT 1 FOR UPDATE);
//   2) 该 SELECT ... FOR UPDATE 借这条索引把「同域链尾 gap」锁住,同域并发插入被挡、跨域互不阻塞
//      (并发依据见 app/Support/AuditChain.php 顶部注释);
//   3) verify 命令按域顺序走链也靠它。
return new class extends Migration {
    public function up(): void
    {
        // 列可能已存在(CLAUDE §54 的建表 SQL 里带这两列,不同环境的建表来源可能不同),存在就不重复加
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'previous_hash')) {
                $table->char('previous_hash', 64)->nullable()->after('metadata_json');
            }
            if (! Schema::hasColumn('audit_logs', 'event_hash')) {
                $table->char('event_hash', 64)->nullable()->after('previous_hash');
            }
        });

        if (! self::hasIndex('idx_audit_chain')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['city_id', 'id'], 'idx_audit_chain');
            });
        }
    }

    public function down(): void
    {
        if (self::hasIndex('idx_audit_chain')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex('idx_audit_chain');
            });
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            foreach (['previous_hash', 'event_hash'] as $column) {
                if (Schema::hasColumn('audit_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    // 索引存在性:Schema::getIndexes 是 Laravel 11+ 的跨库 API(MySQL / MariaDB 都返回小写名)
    private static function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('audit_logs') as $index) {
            if (strtolower((string) $index['name']) === strtolower($name)) {
                return true;
            }
        }

        return false;
    }
};
