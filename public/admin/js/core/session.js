// 当前管理员身份与权限。
//
// 前端按权限显隐只是体验优化(免得点了必然 403),真正的拦截始终在服务器端
// EnsureAdmin 中间件 —— 前端隐藏不等于安全(CLAUDE §44 / §63)。

import { api } from './api.js';

// 角色 -> 中文标签(CLAUDE §63 五级角色)
export const ROLE_LABELS = {
    player: '玩家',
    support: '客服',
    game_master: '游戏管理员',
    admin: '管理员',
    super_admin: '超级管理员',
};

const session = {
    role: null,
    permissions: [],
};

export function hasPermission(permission) {
    if (!permission) return true;
    return session.permissions.indexOf(permission) !== -1;
}

export function currentRole() {
    return session.role;
}

export function currentPermissions() {
    return session.permissions.slice();
}

export async function loadMe() {
    const data = await api.get('/api/admin/me');
    session.role = data.role || null;
    session.permissions = data.permissions || [];
    return data;
}

export function clearSession() {
    session.role = null;
    session.permissions = [];
}
