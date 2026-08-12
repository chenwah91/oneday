// 管理后台入口:登录 / 登出 + 面板清单装配 + 路由启动。
//
// 面板清单是**唯一**要维护的地方:加一个后台面板 = 写一个 panels/*.js + 在下面加一行。
// 导航、按权限显隐、懒加载全部由 core/router.js 按这份清单驱动(CLAUDE §7 模块化)。

import { api, errorMessage } from './core/api.js';
import { el } from './core/dom.js';
import { loadMe, clearSession, currentRole, currentPermissions, ROLE_LABELS } from './core/session.js';
import { initRouter, renderNav, resetRouter } from './core/router.js';

import { dashboardPanel } from './panels/dashboard.js';
import { playersPanel } from './panels/players.js';
import { auditPanel } from './panels/audit.js';
import { settingsPanel } from './panels/settings.js';
import { buildingLevelsPanel } from './panels/building-levels.js';
import { buildingsPanel } from './panels/buildings.js';
import { technologiesPanel } from './panels/technologies.js';
import { npcsPanel } from './panels/npcs.js';
import { npcCurvePanel } from './panels/npc-curve.js';
import { eraPanel } from './panels/era.js';
import { marketPanel } from './panels/market.js';
import { itemsPanel } from './panels/items.js';
import { eventsPanel } from './panels/events.js';
import { compensationPanel } from './panels/compensation.js';

// id / label / permission / load —— 顺序即导航顺序
const PANELS = [
    dashboardPanel,      // 仪表盘(首屏)
    playersPanel,        // 玩家
    auditPanel,          // 审计
    settingsPanel,       // 规则参数
    buildingLevelsPanel, // 建筑等级
    buildingsPanel,      // 建筑上限
    technologiesPanel,   // 科技
    npcsPanel,           // NPC 定义
    npcCurvePanel,       // NPC 曲线
    eraPanel,            // 时代门槛
    marketPanel,         // 市场
    itemsPanel,          // 工具
    eventsPanel,         // 事件
    compensationPanel,   // 补偿
];

const topbar = el('topbar');
const nav = el('panel-nav');
const panelRoot = el('panel-root');
const currentUserEl = el('current-user');
const currentRoleEl = el('current-role');
const logoutBtn = el('logout-btn');

const loginView = el('login-view');
const loginForm = el('login-form');
const loginUsername = el('login-username');
const loginPassword = el('login-password');
const loginError = el('login-error');
const loginSubmit = el('login-submit');

const deniedView = el('denied-view');
const dashboardView = el('dashboard-view');

function showView(name) {
    loginView.classList.toggle('hidden', name !== 'login');
    deniedView.classList.toggle('hidden', name !== 'denied');
    dashboardView.classList.toggle('hidden', name !== 'dashboard');
    topbar.classList.toggle('hidden', name === 'login');
}

async function enterAdmin() {
    // 先取当前管理员身份:403 表示该账号根本不是后台人员,直接给无权限视图
    try {
        await loadMe();
    } catch (err) {
        if (err.status === 403) {
            showView('denied');
            return;
        }
        // 非权限问题(网络 / 服务器错误)不阻塞进入,仅角色徽标留空
    }

    const role = currentRole();
    currentRoleEl.textContent = role ? `当前角色:${ROLE_LABELS[role] || role}` : '';
    // 鼠标悬停可看到本账号实际拥有的权限,便于自查为何某个面板不显示
    const permissions = currentPermissions();
    currentRoleEl.title = permissions.length ? ('权限:' + permissions.join(', ')) : '';

    showView('dashboard');
    initRouter({ panels: PANELS, nav, root: panelRoot });
    renderNav();
}

async function afterLogin(user) {
    currentUserEl.textContent = `${user.username}(${user.email})`;
    await enterAdmin();
}

loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    loginError.classList.add('hidden');
    loginSubmit.disabled = true;
    try {
        const data = await api.post('/api/auth/login', {
            username: loginUsername.value.trim(),
            password: loginPassword.value,
        });
        loginPassword.value = '';
        await afterLogin(data.user);
    } catch (err) {
        loginError.textContent = errorMessage(err);
        loginError.classList.remove('hidden');
    } finally {
        loginSubmit.disabled = false;
    }
});

logoutBtn.addEventListener('click', async () => {
    try {
        await api.post('/api/auth/logout');
    } catch (err) {
        // 忽略登出失败,直接回到登录视图
    }
    clearSession();
    resetRouter();
    currentUserEl.textContent = '';
    currentRoleEl.textContent = '';
    currentRoleEl.title = '';
    loginUsername.value = '';
    loginPassword.value = '';
    showView('login');
});

// 初始化:若已存在有效 Session,直接尝试进入后台
(async function init() {
    showView('login');
    try {
        const data = await api.get('/api/me');
        await afterLogin(data.user);
    } catch (err) {
        // 未登录,停留在登录视图
    }
})();
