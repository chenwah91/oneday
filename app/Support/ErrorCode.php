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

    // 劳动力不足:本次分配会让全城已分配工人超过 floor(population × 0.60)(CLAUDE §32 预留码)
    public const WORKER_NOT_AVAILABLE = 'WORKER_NOT_AVAILABLE';

    // 仓储已满:本次增量会让资源超过当前仓储上限。
    // 管理员补偿走「超出即拒绝」而不是静默截断 —— 悄悄少发的补偿比发不出去更难追查
    public const STORAGE_FULL = 'STORAGE_FULL';

    // 科技研究(M2-B1,CLAUDE §32 预留码)
    // TECH_NOT_UNLOCKED:前置科技尚未解锁(在研不算解锁)
    // ERA_REQUIRED:该科技所属时代还没到(过渡期按「已解锁科技的最高时代 + 1」判定,见 TechService)
    public const TECH_NOT_UNLOCKED = 'TECH_NOT_UNLOCKED';
    public const ERA_REQUIRED = 'ERA_REQUIRED';

    // 已有项目在研:同时只允许 1 项(重复提交同一项、或第二项并行下单,都是这个码)
    public const RESEARCH_IN_PROGRESS = 'RESEARCH_IN_PROGRESS';

    // 幂等:同一 key 被复用到不同操作或不同参数上(409)
    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';
}
