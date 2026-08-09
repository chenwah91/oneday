<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// 清理已过期的幂等键:php artisan idempotency:prune
// 只删 expires_at 已过期的行;历史行(补列前写入,expires_at 为 NULL)不删,保持保守。
// 上线后建议挂进 cron/Scheduler 每日执行(基础设施属 M2-G2,落地时一并注册)。
class IdempotencyPrune extends Command
{
    protected $signature = 'idempotency:prune';
    protected $description = '删除 idempotency_keys 中已过期的幂等键(expires_at < now)';

    public function handle(): int
    {
        $deleted = DB::table('idempotency_keys')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("已清理过期幂等键 {$deleted} 行");
        return 0;
    }
}
