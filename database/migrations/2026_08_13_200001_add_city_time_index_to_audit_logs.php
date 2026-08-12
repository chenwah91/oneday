<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// W11-C1 任务3:audit_logs 补 (city_id, occurred_at) 复合索引。
//
// 为什么需要:建表时只有 idx_audit_user_time (user_id, occurred_at) 与
// idx_audit_action_time (action, occurred_at),**按城市查审计没有任何可用索引** ——
// 后台「查某座城市这段时间发生了什么」会退化成全表扫 + filesort,
// 而 audit_logs 是全项目增长最快的表(每次建造 / 交易 / 触发都写行)。
//
// 列顺序 (city_id, occurred_at) 而不是反过来:等值列在前、范围列在后,
// 才能同时吃到「= city_id」的定位与「occurred_at BETWEEN」的区间扫描 + 天然有序(免 filesort)。
//
// 与 idx_audit_chain (city_id, id) 不重复:那条是 Hash Chain 按 id 取链尾用的,
// 前缀虽同,但排序列不同 —— 按时间区间查照样会 filesort,两条各司其职。
//
// 纯 DDL,MySQL 5.7 语法(不用 CREATE INDEX IF NOT EXISTS —— 5.7 不支持该语法),
// 存在性由 information_schema 自查兜底,重复跑不报错。
return new class extends Migration
{
    private const INDEX = 'idx_audit_city_time';

    public function up(): void
    {
        if (! Schema::hasTable('audit_logs') || $this->indexExists()) {
            return;
        }

        DB::statement('CREATE INDEX '.self::INDEX.' ON audit_logs (city_id, occurred_at)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs') || ! $this->indexExists()) {
            return;
        }

        DB::statement('DROP INDEX '.self::INDEX.' ON audit_logs');
    }

    // 索引是否已存在:按当前连接的库名查 information_schema(5.7 / MariaDB 通用)
    private function indexExists(): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['audit_logs', self::INDEX]
        );

        return $rows !== [];
    }
};
