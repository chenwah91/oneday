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
    public const FORBIDDEN = 'FORBIDDEN';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    public const CSRF_TOKEN_MISMATCH = 'CSRF_TOKEN_MISMATCH';
    public const HTTP_ERROR = 'HTTP_ERROR';

    // 登录
    public const BAD_CREDENTIALS = 'BAD_CREDENTIALS';

    // 游戏经济 Mutation(建造/升级/拆除等)
    public const INSUFFICIENT_RESOURCE = 'INSUFFICIENT_RESOURCE';
    public const BUILDING_LIMIT_REACHED = 'BUILDING_LIMIT_REACHED';
    public const INVALID_POSITION = 'INVALID_POSITION';
    public const LAND_OCCUPIED = 'LAND_OCCUPIED';
    public const REVISION_CONFLICT = 'REVISION_CONFLICT';
    public const INVALID_BUILDING = 'INVALID_BUILDING';

    // 幂等:同一 key 被复用到不同操作或不同参数上(409)
    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';
}
