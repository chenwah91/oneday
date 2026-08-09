<?php

namespace App\Support;

// 稳定错误码:进入生产后保持不变(CLAUDE §32)。游戏业务码由后续子计划追加。
final class ErrorCode
{
    // 基础设施 / 通用
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const NOT_FOUND = 'NOT_FOUND';
    public const TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';
}
