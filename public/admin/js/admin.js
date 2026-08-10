// 管理后台:纯原生 DOM + fetch,不依赖任何框架
// API 封装思路与 /game/js/core/api.js 一致:同源 Session + CSRF,客户端只提交意图

const BASE = '';

function cookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
}

let csrfReady = false;
async function ensureCsrf() {
    if (csrfReady) return;
    await fetch(BASE + '/api/csrf-cookie', { credentials: 'include' });
    csrfReady = true;
}

async function request(method, path, body) {
    if (method !== 'GET') await ensureCsrf();
    const headers = { Accept: 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    const token = cookie('XSRF-TOKEN');
    if (method !== 'GET' && token) headers['X-XSRF-TOKEN'] = token;

    const res = await fetch(BASE + path, {
        method,
        headers,
        credentials: 'include',
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.success === false) {
        throw { status: res.status, error: json.error || 'REQUEST_FAILED', body: json };
    }
    return json.data;
}

const api = {
    get: (p) => request('GET', p),
    post: (p, b) => request('POST', p, b),
};

// 错误码 -> 中文提示
const ERROR_MESSAGES = {
    BAD_CREDENTIALS: '用户名或密码错误',
    TOO_MANY_REQUESTS: '尝试次数过多,请稍后再试',
    VALIDATION_ERROR: '提交的数据不符合要求',
    NOT_FOUND: '未找到对应记录',
    AUTH_REQUIRED: '请先登录',
    FORBIDDEN: '无权限执行此操作',
    INSUFFICIENT_RESOURCE: '扣减后余额会变成负数,已拒绝',
    STORAGE_FULL: '补偿后会超过该城市的仓储上限,已拒绝(请拆分或先扩仓)',
    IDEMPOTENCY_KEY_REUSED: '同一幂等键被用于不同的补偿内容,已拒绝',
    REVISION_CONFLICT: '城市状态已变化,请重新查询后再提交',
};

function errorMessage(err) {
    if (err && err.error && ERROR_MESSAGES[err.error]) return ERROR_MESSAGES[err.error];
    if (err && err.status === 0) return '网络错误,请检查连接';
    return '操作失败,请稍后再试';
}

// ---------- DOM 引用 ----------
const el = (id) => document.getElementById(id);

const topbar = el('topbar');
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

const playersStatus = el('players-status');
const playersTable = el('players-table');
const playersRefresh = el('players-refresh');

const auditStatus = el('audit-status');
const auditTable = el('audit-table');
const auditActionInput = el('audit-action-input');
const auditRefresh = el('audit-refresh');

const defForm = el('def-form');
const defBuildingId = el('def-building-id');
const defLevel = el('def-level');
const defField = el('def-field');
const defValue = el('def-value');
const defReason = el('def-reason');
const defCurrent = el('def-current');
const defError = el('def-error');
const defResult = el('def-result');
const defViewBtn = el('def-view-btn');
const defSubmit = el('def-submit');

// 玩家补偿(权限 adjust_resource)
const compPanel = el('comp-panel');
const compForm = el('comp-form');
const compUsername = el('comp-username');
const compCityId = el('comp-city-id');
const compLookupBtn = el('comp-lookup-btn');
const compCity = el('comp-city');
const compResource = el('comp-resource');
const compDelta = el('comp-delta');
const compTicket = el('comp-ticket');
const compReason = el('comp-reason');
const compError = el('comp-error');
const compResult = el('comp-result');
const compSubmit = el('comp-submit');

// 规则开关(权限 edit_definition)
const settingPanel = el('setting-panel');
const settingReason = el('setting-reason');
const settingRefresh = el('setting-refresh');
const settingStatus = el('setting-status');
const settingTable = el('setting-table');
const settingError = el('setting-error');
const settingResult = el('setting-result');

// ---------- 视图切换 ----------
function showView(name) {
    loginView.classList.toggle('hidden', name !== 'login');
    deniedView.classList.toggle('hidden', name !== 'denied');
    dashboardView.classList.toggle('hidden', name !== 'dashboard');
    topbar.classList.toggle('hidden', name === 'login');
}

// ---------- 当前管理员身份 ----------
// 角色 -> 中文标签(CLAUDE §63 五级角色)
const ROLE_LABELS = {
    player: '玩家',
    support: '客服',
    game_master: '游戏管理员',
    admin: '管理员',
    super_admin: '超级管理员',
};

// 当前登录管理员的角色与权限。权限清单先存起来,按权限显隐按钮留待下一波后台 UI 使用;
// 注意:前端显隐只是体验优化,真正的拦截始终在服务器端 EnsureAdmin 中间件
let currentRole = null;
let currentPermissions = [];

async function loadMe() {
    const data = await api.get('/api/admin/me');
    currentRole = data.role || null;
    currentPermissions = data.permissions || [];
    currentRoleEl.textContent = currentRole
        ? `当前角色:${ROLE_LABELS[currentRole] || currentRole}`
        : '';
    // 鼠标悬停可看到本账号实际拥有的权限,便于自查为何某操作被拒
    currentRoleEl.title = currentPermissions.length ? ('权限:' + currentPermissions.join(', ')) : '';
    applyPermissionVisibility();
}

function hasPermission(permission) {
    return currentPermissions.indexOf(permission) !== -1;
}

// 按权限显隐面板。注意这只是体验优化(免得点了必然 403),
// 真正的拦截始终在服务器端 EnsureAdmin 中间件 —— 前端隐藏不等于安全
function applyPermissionVisibility() {
    compPanel.classList.toggle('hidden', !hasPermission('adjust_resource'));
    settingPanel.classList.toggle('hidden', !hasPermission('edit_definition'));
}

// ---------- 玩家列表 ----------
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

async function loadPlayers() {
    playersStatus.textContent = '加载中…';
    try {
        const data = await api.get('/api/admin/players');
        const rows = data.players || [];
        playersTable.innerHTML = rows.map((p) => `
            <tr>
                <td>${p.id}</td>
                <td>${escapeHtml(p.username)}</td>
                <td>${escapeHtml(p.email)}</td>
                <td class="${p.role && p.role !== 'player' ? 'role-admin' : ''}" title="${escapeHtml(ROLE_LABELS[p.role] || '')}">${escapeHtml(p.role)}</td>
                <td>${p.cityId ?? '-'}</td>
            </tr>
        `).join('');
        playersStatus.textContent = `共 ${rows.length} 名玩家`;
    } catch (err) {
        if (err.status === 403) throw err;
        playersTable.innerHTML = '';
        playersStatus.textContent = errorMessage(err);
        throw err;
    }
}

// ---------- 审计日志 ----------
function statusClass(status) {
    if (status === 'success') return 'status-success';
    if (status === 'failed') return 'status-failed';
    if (status === 'rejected') return 'status-rejected';
    return '';
}

async function loadAudit() {
    auditStatus.textContent = '加载中…';
    const action = auditActionInput.value.trim();
    const qs = action ? ('?action=' + encodeURIComponent(action)) : '';
    try {
        const data = await api.get('/api/admin/audit' + qs);
        const rows = data.audit || [];
        auditTable.innerHTML = rows.map((r) => `
            <tr>
                <td>${escapeHtml(r.occurredAt)}</td>
                <td>${escapeHtml(r.action)}</td>
                <td>${escapeHtml(r.actorType)}</td>
                <td>${r.userId ?? '-'}</td>
                <td class="${statusClass(r.status)}">${escapeHtml(r.status)}</td>
                <td>${escapeHtml(r.reasonCode ?? '-')}</td>
                <td>${escapeHtml(r.requestId ?? '-')}</td>
            </tr>
        `).join('');
        auditStatus.textContent = `共 ${rows.length} 条记录`;
    } catch (err) {
        if (err.status === 403) throw err;
        auditTable.innerHTML = '';
        auditStatus.textContent = errorMessage(err);
        throw err;
    }
}

auditRefresh.addEventListener('click', () => { loadAudit().catch(() => {}); });
auditActionInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); loadAudit().catch(() => {}); }
});

// ---------- Definition 调整 ----------
defViewBtn.addEventListener('click', async () => {
    defError.classList.add('hidden');
    const buildingId = defBuildingId.value.trim();
    if (!buildingId) {
        defError.textContent = '请先填写 buildingId';
        defError.classList.remove('hidden');
        return;
    }
    defCurrent.textContent = '查询中…';
    try {
        const data = await api.get('/api/admin/definitions/building-levels?buildingId=' + encodeURIComponent(buildingId));
        const levels = data.levels || [];
        const level = Number(defLevel.value);
        const field = defField.value;
        const row = levels.find((r) => Number(r.level) === level);
        if (!row) {
            defCurrent.textContent = `未找到 ${buildingId} L${level} 的定义`;
            return;
        }
        defValue.value = row[field];
        defCurrent.textContent = `当前 ${buildingId} L${level} 各字段:` + levels
            .filter((r) => Number(r.level) === level)
            .map((r) => Object.keys(r).filter((k) => k !== 'building_id' && k !== 'level')
                .map((k) => `${k}=${r[k]}`).join(', '))
            .join('');
    } catch (err) {
        defCurrent.textContent = '';
        defError.textContent = errorMessage(err);
        defError.classList.remove('hidden');
    }
});

defForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    defError.classList.add('hidden');
    defResult.classList.add('hidden');
    defSubmit.disabled = true;
    try {
        const payload = {
            buildingId: defBuildingId.value.trim(),
            level: Number(defLevel.value),
            field: defField.value,
            value: Number(defValue.value),
            reason: defReason.value.trim(),
        };
        const data = await api.post('/api/admin/definitions/building-level', payload);
        defResult.textContent = `调整成功:${payload.field} ${data.before} → ${data.after}(新版本号 ${data.version})`;
        defResult.classList.remove('hidden');
        // 清空 action 筛选,刷新审计日志,确保新的 ADMIN.CONFIG_CHANGE 记录可见
        auditActionInput.value = '';
        await loadAudit().catch(() => {});
    } catch (err) {
        defError.textContent = errorMessage(err);
        defError.classList.remove('hidden');
    } finally {
        defSubmit.disabled = false;
    }
});

// ---------- 玩家补偿(CLAUDE §80)----------

// 数字显示:去掉浮点尾巴(1000.0000 → 1000),但保留真正的小数
function formatAmount(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return String(value ?? '-');
    return String(Math.round(n * 10000) / 10000);
}

// 本次补偿的幂等键:提交时生成、成功后清空。
// 网络超时后管理员再点一次提交,带的还是同一个 key,服务器不会重复入账(CLAUDE §49)
let compIdempotencyKey = null;

function newIdempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }
    return 'comp-' + Date.now() + '-' + Math.random().toString(16).slice(2);
}

// 定位目标:city_id 优先(与服务器 resolveCity 同口径)
function compTargetPayload() {
    const cityId = compCityId.value.trim();
    if (cityId) return { city_id: Number(cityId) };
    return { username: compUsername.value.trim() };
}

async function lookupCompensationTarget() {
    compError.classList.add('hidden');
    compResult.classList.add('hidden');

    const target = compTargetPayload();
    if (!target.city_id && !target.username) {
        compError.textContent = '请填写用户名或 city_id';
        compError.classList.remove('hidden');
        return;
    }

    const qs = target.city_id
        ? 'city_id=' + encodeURIComponent(target.city_id)
        : 'username=' + encodeURIComponent(target.username);

    compCity.textContent = '查询中…';
    try {
        const data = await api.get('/api/admin/compensation/lookup?' + qs);
        compCityId.value = data.city.id;
        compUsername.value = data.user.username;
        compCity.textContent =
            `玩家 ${data.user.username}(id=${data.user.id})· 城市 ${data.city.name}(id=${data.city.id})`
            + ` · revision ${data.city.revision} · 人口 ${data.city.population}`
            + ` · 最后结算 ${data.city.last_simulated_at}`;

        // 下拉带中文显示名与当前余额,选完就知道补前是多少
        const selected = compResource.value;
        compResource.innerHTML = (data.resources || []).map((r) => `
            <option value="${escapeHtml(r.code)}">${escapeHtml(r.name)}(${escapeHtml(r.code)})· 现有 ${formatAmount(r.amount)}</option>
        `).join('');
        if (selected) compResource.value = selected;
    } catch (err) {
        compCity.textContent = '';
        compResource.innerHTML = '<option value="">先查询城市</option>';
        compError.textContent = errorMessage(err);
        compError.classList.remove('hidden');
    }
}

compLookupBtn.addEventListener('click', () => { lookupCompensationTarget(); });

compForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    compError.classList.add('hidden');
    compResult.classList.add('hidden');

    if (!compResource.value) {
        compError.textContent = '请先查询城市并选择资源';
        compError.classList.remove('hidden');
        return;
    }

    if (compIdempotencyKey === null) compIdempotencyKey = newIdempotencyKey();

    compSubmit.disabled = true;
    try {
        const payload = Object.assign(compTargetPayload(), {
            resource: compResource.value,
            delta: Number(compDelta.value),
            reason: compReason.value.trim(),
            ticket: compTicket.value.trim(),
            idempotency_key: compIdempotencyKey,
        });
        const data = await api.post('/api/admin/compensation', payload);

        compResult.textContent = data.replayed
            ? `该补偿此前已入账(幂等重放,未重复发放):${data.resource} 当前 ${formatAmount(data.after)},revision ${data.revision}`
            : `补偿成功:${data.resource} ${formatAmount(data.before)} → ${formatAmount(data.after)}`
              + `(delta ${formatAmount(data.delta)})· 资金 ${formatAmount(data.money)} · 新 revision ${data.revision}`;
        compResult.classList.remove('hidden');

        // 一次操作对应一个幂等键:成功后清空,下一次补偿重新生成
        compIdempotencyKey = null;
        compDelta.value = '';
        compReason.value = '';
        compTicket.value = '';

        // 刷新余额下拉与审计,让管理员立刻看到新的 ADMIN.COMPENSATION 记录
        await lookupCompensationTarget();
        auditActionInput.value = '';
        await loadAudit().catch(() => {});
    } catch (err) {
        compError.textContent = errorMessage(err);
        compError.classList.remove('hidden');
    } finally {
        compSubmit.disabled = false;
    }
});

// ---------- 规则开关(game_settings)----------

function settingValueLabel(value) {
    if (value === true) return '<span class="setting-on">开启(true)</span>';
    if (value === false) return '<span class="setting-off">关闭(false)</span>';
    return escapeHtml(JSON.stringify(value));
}

async function loadSettings() {
    settingStatus.textContent = '加载中…';
    settingError.classList.add('hidden');
    try {
        const data = await api.get('/api/admin/settings');
        const rows = data.settings || [];
        settingTable.innerHTML = rows.map((s) => {
            // 目前只有布尔开关可一键切换;将来出现非布尔类型时按只读展示,不猜它该变成什么
            const toggle = s.registered && s.type === 'bool'
                ? `<button type="button" class="btn btn-ghost" data-setting-key="${escapeHtml(s.setting_key)}" data-setting-next="${s.value === true ? 'false' : 'true'}">切换为 ${s.value === true ? '关闭' : '开启'}</button>`
                : '<span class="muted">不可编辑</span>';
            return `
                <tr>
                    <td>${escapeHtml(s.setting_key)}</td>
                    <td class="setting-desc">${escapeHtml(s.description)}</td>
                    <td>${settingValueLabel(s.value)}</td>
                    <td>${s.default === null || s.default === undefined ? '-' : escapeHtml(JSON.stringify(s.default))}</td>
                    <td>${s.updated_at ? escapeHtml(s.updated_at) : '-'}${s.updated_by ? ' · by #' + s.updated_by : ''}</td>
                    <td>${toggle}</td>
                </tr>
            `;
        }).join('');
        settingStatus.textContent = `共 ${rows.length} 项设定`;
    } catch (err) {
        if (err.status === 403) throw err;
        settingTable.innerHTML = '';
        settingStatus.textContent = errorMessage(err);
        throw err;
    }
}

// 事件委托:表格内容每次刷新都会重建,不能给每行按钮单独绑事件
settingTable.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-setting-key]');
    if (!btn) return;

    settingError.classList.add('hidden');
    settingResult.classList.add('hidden');

    const reason = settingReason.value.trim();
    if (reason.length < 2) {
        settingError.textContent = '请先填写修改原因(至少 2 字)';
        settingError.classList.remove('hidden');
        return;
    }

    btn.disabled = true;
    try {
        const data = await api.post('/api/admin/settings', {
            setting_key: btn.dataset.settingKey,
            value: btn.dataset.settingNext === 'true',
            reason,
        });
        settingResult.textContent = `已修改 ${data.setting_key}:${JSON.stringify(data.before)} → ${JSON.stringify(data.after)}`;
        settingResult.classList.remove('hidden');
        settingReason.value = '';
        await loadSettings().catch(() => {});
        auditActionInput.value = '';
        await loadAudit().catch(() => {});
    } catch (err) {
        settingError.textContent = errorMessage(err);
        settingError.classList.remove('hidden');
    } finally {
        btn.disabled = false;
    }
});

settingRefresh.addEventListener('click', () => { loadSettings().catch(() => {}); });

// ---------- 登录 / 登出 ----------
async function loadDashboard() {
    // 先取当前管理员身份:403 表示该账号根本不是后台人员,直接给无权限视图
    try {
        await loadMe();
    } catch (err) {
        if (err.status === 403) {
            showView('denied');
            return;
        }
        // 非权限问题(网络/服务器错误)不阻塞看板,仅角色徽标留空
    }
    try {
        await loadPlayers();
    } catch (err) {
        if (err.status === 403) {
            showView('denied');
            return;
        }
        // 玩家列表加载失败(非权限问题)仍尝试展示看板,让管理员能看到其他区块 / 重试
        showView('dashboard');
        return;
    }
    showView('dashboard');
    loadAudit().catch(() => {});
    if (hasPermission('edit_definition')) loadSettings().catch(() => {});
}

async function afterLogin(user) {
    currentUserEl.textContent = `${user.username}(${user.email})`;
    topbar.classList.remove('hidden');
    await loadDashboard();
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
    topbar.classList.add('hidden');
    currentUserEl.textContent = '';
    currentRoleEl.textContent = '';
    currentRoleEl.title = '';
    currentRole = null;
    currentPermissions = [];
    applyPermissionVisibility();
    loginUsername.value = '';
    loginPassword.value = '';
    showView('login');
});

playersRefresh.addEventListener('click', () => { loadPlayers().catch(() => {}); });

// ---------- 初始化:若已存在有效 Session,直接尝试进入看板 ----------
(async function init() {
    showView('login');
    try {
        const data = await api.get('/api/me');
        await afterLogin(data.user);
    } catch (err) {
        // 未登录,停留在登录视图
    }
})();
