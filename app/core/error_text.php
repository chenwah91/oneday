<?php
// 错误码 → 用户可读中文文案
final class ErrorText {
    private const MAP = [
        'INVALID_USERNAME'   => '用户名需 3-20 位,只能包含字母、数字、下划线或中文',
        'PASSWORD_TOO_SHORT' => '密码至少 8 位',
        'INVALID_EMAIL'      => '邮箱格式不正确',
        'USERNAME_TAKEN'     => '用户名已被使用',
        'TOO_MANY_ATTEMPTS'  => '尝试次数过多,请 15 分钟后再试',
        'BAD_CREDENTIALS'    => '用户名或密码错误',
        'AUTH_REQUIRED'      => '请先登录',
        'NOT_FOUND'          => '接口不存在',
        'SERVER_ERROR'       => '服务器错误,请稍后再试',
    ];

    public static function of(string $code): string {
        return self::MAP[$code] ?? $code;
    }
}
