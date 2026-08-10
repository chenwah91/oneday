<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// 幂等统一入口(CLAUDE §49):请求指纹计算 / 命中判定 / 落键
final class Idempotency
{
    // 请求指纹:同一 key 必须对应同一 action 与同一组业务参数。
    // payload 只放业务参数(build: 建筑 ID 与坐标,upgrade/demolish: 实例 ID),
    // 绝不包含 expected_revision 与 idempotency_key 本身 ——
    // 注意:payload 的键名是「内部指纹标签」,不是 HTTP 契约字段,契约改名时不要跟着动,
    // 否则历史 idempotency_keys 行的指纹会对不上,正常重放会被误判成 KEY_REUSED。
    // 客户端重试同一操作时 revision 可能已经变了,算进指纹会把正常重放误判成冲突。
    public static function hash(string $action, array $payload): string
    {
        self::ksortRecursive($payload);

        return hash('sha256', $action.'|'.json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    // 递归排序:键顺序不同的同一组参数必须得到同一指纹
    private static function ksortRecursive(array &$payload): void
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }

    // 命中判定:
    // 不存在        → 返回 null(首次请求,继续往下执行)
    // 存在且完全一致 → 返回该行(重放,调用方直接回旧结果,不重复扣资源)
    // 存在但不一致   → 同一 key 被复用于别的操作/参数,拒绝(409)
    public static function check(int $userId, string $key, string $action, string $requestHash): ?object
    {
        $row = DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->first();
        if (! $row) {
            return null;
        }

        if ((string) $row->action !== $action) {
            throw new GameRuleException(ErrorCode::IDEMPOTENCY_KEY_REUSED, 409);
        }

        // 兼容旧数据:补列之前写入的历史行 request_hash 为 NULL,只比对 action
        if ($row->request_hash !== null && (string) $row->request_hash !== $requestHash) {
            throw new GameRuleException(ErrorCode::IDEMPOTENCY_KEY_REUSED, 409);
        }

        return $row;
    }

    // 落键:业务成功后在同一事务内写入。expires_at 供后续清理使用(清理命令不在本次范围)
    public static function store(int $userId, ?int $cityId, string $key, string $action, string $requestHash): void
    {
        DB::table('idempotency_keys')->insert([
            'user_id'         => $userId,
            'city_id'         => $cityId,
            'key'             => $key,
            'action'          => $action,
            'request_hash'    => $requestHash,
            'response_status' => 200,
            'created_at'      => now(),
            'expires_at'      => now()->addDay(),
        ]);
    }
}
