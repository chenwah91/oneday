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

// ---------- 视图切换 ----------
function showView(name) {
    loginView.classList.toggle('hidden', name !== 'login');
    deniedView.classList.toggle('hidden', name !== 'denied');
    dashboardView.classList.toggle('hidden', name !== 'dashboard');
    topbar.classList.toggle('hidden', name === 'login');
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
                <td class="${p.role === 'admin' ? 'role-admin' : ''}">${escapeHtml(p.role)}</td>
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

// ---------- 登录 / 登出 ----------
async function loadDashboard() {
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
