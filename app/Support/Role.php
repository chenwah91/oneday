<?php

namespace App\Support;

// 管理员角色分级(CLAUDE §63):PLAYER / SUPPORT / GAME_MASTER / ADMIN / SUPER_ADMIN。
//
// 设计要点:
// 1. 角色是「有序梯度」,高角色自动继承低角色的全部权限,靠序数比较实现,
//    不维护「角色 × 权限」的全量表格,新增角色/权限时不会漏配;
// 2. PERMISSIONS 记录的是「该权限所需的最低角色」,加权限只需加一行;
// 3. Fail Closed(CLAUDE §41):未知角色、未知权限一律 false,绝不因为「认不出来」而放行;
// 4. 角色字符串直接存 users.role(VARCHAR(16)),这里的取值即数据库允许的全部合法值。
final class Role
{
    // ---------- 角色 ----------
    public const PLAYER = 'player';
    public const SUPPORT = 'support';
    public const GAME_MASTER = 'game_master';
    public const ADMIN = 'admin';
    public const SUPER_ADMIN = 'super_admin';

    // 角色序数:数值越大权限越高。进入生产后保持稳定,新角色只在两端/中间明确评估后调整,
    // 不要随手改动已有数值(会静默改变所有权限判定结果)。
    private const RANKS = [
        self::PLAYER      => 0,
        self::SUPPORT     => 1,
        self::GAME_MASTER => 2,
        self::ADMIN       => 3,
        self::SUPER_ADMIN => 4,
    ];

    // ---------- 权限 ----------
    public const READ_PLAYER = 'read_player';
    public const READ_AUDIT = 'read_audit';
    public const ADJUST_RESOURCE = 'adjust_resource';
    public const EDIT_DEFINITION = 'edit_definition';
    public const BAN_PLAYER = 'ban_player';
    public const MANAGE_ADMIN = 'manage_admin';

    // 权限 => 所需最低角色(CLAUDE §63 最小权限:Support 只能看,不能动经济/数值/封禁)
    private const PERMISSIONS = [
        self::READ_PLAYER     => self::SUPPORT,      // 查看玩家 / 城市
        self::READ_AUDIT      => self::SUPPORT,      // 查看审计日志
        self::ADJUST_RESOURCE => self::GAME_MASTER,  // 补偿 / 调整玩家资源(功能后续实现)
        self::EDIT_DEFINITION => self::ADMIN,        // 修改游戏 Definition 数值
        self::BAN_PLAYER      => self::ADMIN,        // 封禁玩家(功能后续实现)
        self::MANAGE_ADMIN    => self::SUPER_ADMIN,  // 管理其他管理员(功能后续实现)
    ];

    // 「后台人员」的最低角色:support 及以上算管理角色,player 一律不算
    private const STAFF_MIN = self::SUPPORT;

    // 全部合法角色(供 admin:promote 白名单校验、错误提示)
    public static function all(): array
    {
        return array_keys(self::RANKS);
    }

    // 全部已定义权限
    public static function permissions(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    // 是否为合法角色。null / 未知字符串 => false
    public static function isValid(?string $role): bool
    {
        return $role !== null && isset(self::RANKS[$role]);
    }

    // 角色序数;非法角色返回 null(调用方必须按「拒绝」处理)
    public static function rank(?string $role): ?int
    {
        return self::isValid($role) ? self::RANKS[$role] : null;
    }

    // 是否为后台人员(support 及以上)。middleware('admin') 不带参数时的兜底门槛
    public static function isStaff(?string $role): bool
    {
        $rank = self::rank($role);

        return $rank !== null && $rank >= self::RANKS[self::STAFF_MIN];
    }

    // 角色是否具备某权限:未知角色、未知权限一律 false(Fail Closed)
    public static function allows(?string $role, string $permission): bool
    {
        $rank = self::rank($role);
        if ($rank === null || ! isset(self::PERMISSIONS[$permission])) {
            return false;
        }

        return $rank >= self::RANKS[self::PERMISSIONS[$permission]];
    }

    // 该角色拥有的全部权限,供后台按权限显隐 UI(顺序与 PERMISSIONS 定义一致)
    public static function permissionsFor(?string $role): array
    {
        $list = [];
        foreach (array_keys(self::PERMISSIONS) as $permission) {
            if (self::allows($role, $permission)) {
                $list[] = $permission;
            }
        }

        return $list;
    }
}
