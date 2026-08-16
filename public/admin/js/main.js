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

// preloaded:init 已经取过一次后台身份时直接复用,不重复打 /api/admin/me
async function enterAdmin(preloaded) {
    // 先取当前管理员身份:403 表示该账号根本不是后台人员,直接给无权限视图
    try {
        if (!preloaded) await loadMe();
    } catch (err) {
        if (err.status === 403) {
            showView('denied');
            return;
        }
        // 非权限问题(401 会话失效 / 429 限流 / 500 / 网络抖动):**不能**继续往下走。
        // 原先的注释说「仅角色徽标留空」,但这是错的 —— 权限清单同时也空了,而 14 个面板
        // 全部声明了 permission(9 个定义面板默认 edit_definition),visiblePanels() 返回空 →
        // router 直接 return、导航渲染成空,运营看到的是一张顶栏正常、正文全白、
        // 一个 tab 都没有、也没有半个字解释的页面,只能 F5 → 再撞一次 → 退回登录页,
        // 陷入「登录 → 白页 → 又要登录」的死循环。宁可退回登录视图并说明原因。
        loginError.textContent = err && err.status === 429
            ? '请求过于频繁,请稍候再登录'
            : '无法获取管理员身份(' + ((err && err.error) || '网络错误') + '),请重试';
        loginError.classList.remove('hidden');
        showView('login');
        return;
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

async function afterLogin(user, preloaded) {
    currentUserEl.textContent = `${user.username}(${user.email})`;
    await enterAdmin(preloaded);
}

loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    loginError.classList.add('hidden');
    loginSubmit.disabled = true;
    try {
        // 后台专用登录端点:走独立的 admin 会话,不会覆盖同一浏览器里已登录的玩家身份
        const data = await api.post('/api/admin/auth/login', {
            username: loginUsername.value.trim(),
            password: loginPassword.value,
        });
        loginPassword.value = '';
        await afterLogin(data.user);
    } catch (err) {
        // 401 = 用户名或密码错(账号不存在与密码错不可区分,后端刻意同一个响应);
        // 403 = 密码是对的,但这个账号不是后台人员 —— 两种情况的处置完全不同,文案必须分开
        loginError.textContent = err && err.status === 403
            ? '该账号不是管理员,无法登录后台'
            : errorMessage(err);
        loginError.classList.remove('hidden');
    } finally {
        loginSubmit.disabled = false;
    }
});

logoutBtn.addEventListener('click', async () => {
    try {
        // 只退后台身份:同一浏览器里正在玩游戏的玩家会话不受影响(见 AdminAuthController::logout)
        await api.post('/api/admin/auth/logout');
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

// 初始化:若已存在有效的**后台** Session,直接尝试进入后台。
//
// 探针必须打 /api/admin/me 而不是 /api/me:两套会话已彻底分开(admin guard vs web guard),
// /api/me 反映的是游戏侧的登录状态 —— 拿它当探针会同时错两个方向:
// 只登了游戏的人被判成有后台会话(随后 403 变成「无权限」页),
// 而只登了后台的人刷新页面会被判成未登录(退回登录表单)。
(async function init() {
    showView('login');
    try {
        const me = await loadMe();
        await afterLogin(me, me);
    } catch (err) {
        // 403:有后台会话但角色已被降级(登录之后被改了角色)→ 明确给无权限视图;
        // 其余(401 未登录后台 / 网络错误)一律停留在登录视图
        if (err && err.status === 403) showView('denied');
    }
})();
