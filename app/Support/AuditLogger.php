<?php

namespace App\Support;

use App\Game\Definition\GameDataVersion;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 审计写入(append-only)。自动补全时间/请求ID/IP/UA哈希;绝不记录密码或凭证明文。
final class AuditLogger
{
    public static function record(string $action, string $status, array $attrs = []): void
    {
        $request = request();
        $ua = $request?->userAgent();

        DB::table('audit_logs')->insert([
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
        ]);
    }
}
