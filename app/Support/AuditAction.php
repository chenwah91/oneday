<?php

namespace App\Support;

// 稳定审计 Action Code。
// Action 码进入生产后保持稳定(CLAUDE §55),只增不改:历史审计行里的字符串不会跟着改名,
// 改动会让旧记录与新记录对不上,后台查询/统计全部失真。只允许新增,不允许重命名或删除。
// 另:只登记确实有落点的码,纯常量(无人写入)不加。
final class AuditAction
{
    public const AUTH_REGISTER = 'AUTH.REGISTER';
    public const AUTH_LOGIN_SUCCESS = 'AUTH.LOGIN_SUCCESS';
    public const AUTH_LOGIN_FAILED = 'AUTH.LOGIN_FAILED';
    public const AUTH_LOGOUT = 'AUTH.LOGOUT';

    // 建城(CityFactory 首次真正建城时写,兜底重入不重复写)
    public const CITY_CREATE = 'CITY.CREATE';

    // 建筑经济 Mutation
    public const BUILDING_BUILD = 'BUILDING.BUILD';
    public const BUILDING_UPGRADE = 'BUILDING.UPGRADE';
    public const BUILDING_DEMOLISH = 'BUILDING.DEMOLISH';

    // 安全:越权操作被拒
    public const SECURITY_AUTHORIZATION_FAILED = 'SECURITY.AUTHORIZATION_FAILED';

    // 安全:触发限流(AppServiceProvider 各限流器的 response 回调)
    public const SECURITY_RATE_LIMIT = 'SECURITY.RATE_LIMIT';

    // 安全:Revision 冲突。写入点在全局异常 render,不在事务内 ——
    // 冲突是在事务里抛出的,同事务写的审计会被回滚一起抹掉(见 bootstrap/app.php)
    public const SECURITY_REVISION_CONFLICT = 'SECURITY.REVISION_CONFLICT';

    // 安全:可疑行为(当前落点为幂等键复用 409,同样在全局 render 写)
    public const SECURITY_SUSPICIOUS_ACTIVITY = 'SECURITY.SUSPICIOUS_ACTIVITY';

    // 管理后台
    public const ADMIN_LOGIN = 'ADMIN.LOGIN';
    public const ADMIN_CONFIG_CHANGE = 'ADMIN.CONFIG_CHANGE';
}
