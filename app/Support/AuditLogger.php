<?php

namespace App\Support;

use App\Game\Definition\GameDataVersion;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 审计写入(append-only)。自动补全时间/请求ID/IP/UA哈希;绝不记录密码或凭证明文。
//
// M3-M.9 起每条审计还会挂上 Hash Chain(previous_hash / event_hash,按 city_id 分域),
// 用来事后检测「历史审计行被改过 / 被删过」。链的形状、并发依据、跨库注意事项见 AuditChain。
final class AuditLogger
{
    public static function record(string $action, string $status, array $attrs = []): void
    {
        $request = request();
        $ua = $request?->userAgent();

        $row = [
            'occurred_at'          => now()->format('Y-m-d H:i:s.u'),
            'request_id'           => Context::get('request_id') ?? (string) Str::uuid(),
            'trace_id'             => $attrs['trace_id'] ?? null,
            'idempotency_key'      => $attrs['idempotency_key'] ?? null,
            'actor_type'           => $attrs['actor_type'] ?? 'player',
            'actor_id'             => $attrs['actor_id'] ?? null,
            'user_id'              => $attrs['user_id'] ?? null,
            'city_id'              => $attrs['city_id'] ?? null,
            'action'               => $action,
            'entity_type'          => $attrs['entity_type'] ?? null,
            'entity_id'            => $attrs['entity_id'] ?? null,
            'city_revision_before' => $attrs['city_revision_before'] ?? null,
            'city_revision_after'  => $attrs['city_revision_after'] ?? null,
            'status'               => $status,
            'reason_code'          => $attrs['reason_code'] ?? null,
            'ip_address'           => $request?->ip(),
            'user_agent_hash'      => $ua ? hash('sha256', $ua) : null,
            // 数值版本(§65):半年后靠它回答「这条记录发生时用的是哪一版数值」。
            // current() 带每请求缓存,一次请求写 N 条审计也只查一次 game_data_versions
            'game_data_version'    => $attrs['game_data_version'] ?? GameDataVersion::current(),
            'before_json'          => isset($attrs['before_json']) ? json_encode($attrs['before_json'], JSON_UNESCAPED_UNICODE) : null,
            'after_json'           => isset($attrs['after_json']) ? json_encode($attrs['after_json'], JSON_UNESCAPED_UNICODE) : null,
            'delta_json'           => isset($attrs['delta_json']) ? json_encode($attrs['delta_json'], JSON_UNESCAPED_UNICODE) : null,
            'metadata_json'        => isset($attrs['metadata_json']) ? json_encode($attrs['metadata_json'], JSON_UNESCAPED_UNICODE) : null,
            'created_at'           => now(),
        ];

        $secret = AuditChain::secret();

        // 没 secret(两个密钥都缺)→ 不挂链,但审计照写:审计是安全底线,不能被链的配置问题挡住
        if ($secret === null) {
            DB::table('audit_logs')->insert($row);

            return;
        }

        $cityId = $row['city_id'] === null ? null : (int) $row['city_id'];

        // 「锁链头 + 插入 + 推进链头」必须在同一个事务里,锁才拉得住(见 AuditChain 顶部的锁形状说明)。
        // 绝大多数调用点已经在城市行锁事务内(此时这里只是复用外层事务,不新开);
        // 事务外的登录 / 限流 / 后台配置 / 快照里的科技懒解锁等,则在这里开一个短事务。
        $write = function () use ($row, $cityId, $secret): void {
            $previousHash = AuditChain::lockHead($cityId) ?? AuditChain::GENESIS;

            $row['previous_hash'] = $previousHash;
            $row['event_hash'] = AuditChain::eventHash(
                AuditChain::canonicalPayload($row),
                $previousHash,
                $secret
            );

            DB::table('audit_logs')->insert($row);
            AuditChain::advanceHead($cityId, $row['event_hash']);
        };

        DB::transactionLevel() > 0 ? $write() : DB::transaction($write);
    }
}
