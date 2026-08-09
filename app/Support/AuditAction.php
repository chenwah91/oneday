<?php

namespace App\Support;

// 稳定审计 Action Code(进入生产后保持稳定)
final class AuditAction
{
    public const AUTH_REGISTER = 'AUTH.REGISTER';
    public const AUTH_LOGIN_SUCCESS = 'AUTH.LOGIN_SUCCESS';
    public const AUTH_LOGIN_FAILED = 'AUTH.LOGIN_FAILED';
    public const AUTH_LOGOUT = 'AUTH.LOGOUT';

    // 建筑经济 Mutation
    public const BUILDING_BUILD = 'BUILDING.BUILD';
    public const BUILDING_UPGRADE = 'BUILDING.UPGRADE';
    public const BUILDING_DEMOLISH = 'BUILDING.DEMOLISH';

    // 安全:越权操作被拒
    public const SECURITY_AUTHORIZATION_FAILED = 'SECURITY.AUTHORIZATION_FAILED';

    // 管理后台
    public const ADMIN_LOGIN = 'ADMIN.LOGIN';
    public const ADMIN_CONFIG_CHANGE = 'ADMIN.CONFIG_CHANGE';
}
