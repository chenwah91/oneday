<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

// 安全事件日志(CLAUDE §60):独立 security 通道,用于异常检测,与审计日志并行、不互相替代。
//
// 写入纪律(CLAUDE §61):
// 1. 只允许写 ALLOWED_KEYS 里的字段(allowlist),未列入的键直接丢弃;
// 2. 绝不 dump 整个 Request / Header / Cookie;
// 3. 禁止字段:密码、密码哈希、Session ID、CSRF Token、Access/Refresh Token、
//    Authorization 头、数据库密码、APP_KEY、加密密钥、审计 HMAC Secret、支付凭证。
final class SecurityLogger
{
    // 允许写入 context 的字段白名单:全部为非敏感的定位/分类信息
    private const ALLOWED_KEYS = [
        'user_id',      // 玩家 ID
        'actor_id',     // 操作者 ID(管理员操作时 actor 与 user 不是同一个人,两者都要记)
        'city_id',      // 城市 ID
        'route',        // 路由名或路径
        'action',       // 审计 Action 码
        'reason',       // 拒绝原因(如 NOT_OWNER)
        'error_code',   // 稳定错误码
        'entity_type',  // 实体类型
        'entity_id',    // 实体 ID
        'limiter',      // 限流器名称
        'method',       // HTTP 方法
        'count',        // 次数/计数
    ];

    public static function log(string $event, array $context = []): void
    {
        $request = request();

        // request_id 取法与 AuditLogger 一致,保证同一请求的审计/安全/应用日志可串联(CLAUDE §59)
        $base = [
            'request_id' => Context::get('request_id'),
            'ip'         => $request?->ip(),
        ];

        // user_id:调用方显式传入优先,否则取当前登录用户(未登录则不带)。
        //
        // ⚠️ 用 array_key_exists 而不是 ??:登录失败这类事件会显式传 `'user_id' => $user?->id`,
        // 账号不存在时那就是 null,意思是「这次尝试归不到任何账号」。用 ?? 的话 null 会退回
        // $request->user() —— 而后台登录口没挂 auth,默认 guard 仍是 web,退回来的正是同一个浏览器里
        // 登着游戏的那个**无关玩家**(后台与玩家会话共存是常态,不是边角情况)。
        // 结果:security.log 指认 bob 正在被爆破,而 bob 什么都没做。宁可不带,不能栽赃。
        $userId = array_key_exists('user_id', $context) ? $context['user_id'] : $request?->user()?->id;
        if ($userId !== null) {
            $base['user_id'] = (int) $userId;
        }

        // user_id 的归属已由上面那段统一裁决,不能让 filter 再从 context 里带一份进来 ——
        // 显式传 null 时会漏成 "user_id": null 的噪音,把「归不到账号」写成一个看起来像字段缺失的值
        $filtered = self::filter($context);
        unset($filtered['user_id']);

        Log::channel('security')->warning($event, $base + $filtered);
    }

    // allowlist 过滤:只保留白名单字段,其余一律丢弃(而不是「记录一切再脱敏」)
    private static function filter(array $context): array
    {
        return array_intersect_key($context, array_flip(self::ALLOWED_KEYS));
    }
}
