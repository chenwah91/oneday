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

// NPC 定义调整(权限 edit_definition,M3-D1)
const npcDefPanel = el('npc-def-panel');
const npcDefForm = el('npc-def-form');
const npcDefId = el('npc-def-id');
const npcDefOptions = el('npc-def-options');
const npcDefField = el('npc-def-field');
const npcDefValue = el('npc-def-value');
const npcDefReason = el('npc-def-reason');
const npcDefCurrent = el('npc-def-current');
const npcDefError = el('npc-def-error');
const npcDefResult = el('npc-def-result');
const npcDefViewBtn = el('npc-def-view-btn');
const npcDefSubmit = el('npc-def-submit');

// 随机事件定义(权限 edit_definition,M3-D4)
const eventDefPanel = el('event-def-panel');
const eventDefReason = el('event-def-reason');
const eventDefRefresh = el('event-def-refresh');
const eventDefStatus = el('event-def-status');
const eventDefTable = el('event-def-table');
const eventDefError = el('event-def-error');
const eventDefResult = el('event-def-result');

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
    npcDefPanel.classList.toggle('hidden', !hasPermission('edit_definition'));
    eventDefPanel.classList.toggle('hidden', !hasPermission('edit_definition'));
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

// ---------- NPC 定义调整(M3-D1)----------

// 30 行原型一次取回:填 npc_id 时给 datalist 补全,查看当前值时也直接从这份缓存里读,
// 免得每点一次「查看」就打一次接口
let npcDefinitions = [];

async function loadNpcDefinitions() {
    const data = await api.get('/api/admin/definitions/npcs');
    npcDefinitions = data.npcs || [];
    npcDefOptions.innerHTML = npcDefinitions
        .map((n) => `<option value="${escapeHtml(n.npc_id)}">${escapeHtml(n.npc_id)} · ${escapeHtml(n.category)} · ${escapeHtml(n.rarity)}</option>`)
        .join('');
}

npcDefViewBtn.addEventListener('click', async () => {
    npcDefError.classList.add('hidden');
    const npcId = npcDefId.value.trim();
    if (!npcId) {
        npcDefError.textContent = '请先填写 npc_id';
        npcDefError.classList.remove('hidden');
        return;
    }

    npcDefCurrent.textContent = '查询中…';
    try {
        await loadNpcDefinitions();
        const row = npcDefinitions.find((n) => n.npc_id === npcId);
        if (!row) {
            npcDefCurrent.textContent = `未找到 ${npcId}`;
            return;
        }
        npcDefValue.value = row[npcDefField.value];
        npcDefCurrent.textContent =
            `${row.npc_id}(${row.category} · ${row.rarity} · ${row.min_era} · ${row.primary_skill_id} · 来源 ${row.recruit_source})`
            + ` · 特性「${row.trait_desc_zh}」`
            + ` · 工资 ${row.wage_per_min} · 口粮 ${row.food_per_min}`
            + ` · 初始技能 ${row.initial_skill_value} · 初始等级 ${row.initial_skill_level} · 上限 ${row.max_level}`;
    } catch (err) {
        npcDefCurrent.textContent = '';
        npcDefError.textContent = errorMessage(err);
        npcDefError.classList.remove('hidden');
    }
});

npcDefForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    npcDefError.classList.add('hidden');
    npcDefResult.classList.add('hidden');
    npcDefSubmit.disabled = true;
    try {
        const payload = {
            npc_id: npcDefId.value.trim(),
            field: npcDefField.value,
            value: Number(npcDefValue.value),
            reason: npcDefReason.value.trim(),
        };
        const data = await api.post('/api/admin/definitions/npc', payload);
        npcDefResult.textContent = `调整成功:${payload.field} ${data.before} → ${data.after}(新版本号 ${data.version})`;
        npcDefResult.classList.remove('hidden');
        // 缓存已过期,重取一次;并刷新审计让新的 ADMIN.CONFIG_CHANGE 立刻可见
        await loadNpcDefinitions().catch(() => {});
        auditActionInput.value = '';
        await loadAudit().catch(() => {});
    } catch (err) {
        npcDefError.textContent = errorMessage(err);
        npcDefError.classList.remove('hidden');
    } finally {
        npcDefSubmit.disabled = false;
    }
});

// ---------- 随机事件定义(M3-D4)----------
//
// 用户拍板③:「所有事件必须在管理员后台可设定(权重/效果/开关)」。
// 这一块就是那条硬约束的界面落点 —— 30 行事件,每行五个可改项 + 一个保存按钮。
// 前端只负责收集与显示,范围校验的权威始终在服务端(AdminDefinitionController 的 EVENT_FIELD_MAX)。
//
// 全局参数(触发概率 / 并发上限 / 离线补算上限 / 权重三修正系数)不在这里 ——
// 它们是 game_settings 的数值型设定,由下面「规则开关」面板的通用数字控件自动渲染,
// 后端每登记一条新参数,后台自动多出一行,不必再改这份 JS。

const EVENT_TYPE_LABELS = { positive: '正向', negative: '负向' };

function eventNumberCell(eventId, field, value, step) {
    return `<input type="number" step="${step}" min="0" class="event-input"
                   value="${escapeHtml(String(value))}"
                   data-event-field="${escapeHtml(field)}" data-event-row="${escapeHtml(eventId)}">`;
}

async function loadEventDefinitions() {
    eventDefStatus.textContent = '加载中…';
    eventDefError.classList.add('hidden');
    try {
        const data = await api.get('/api/admin/definitions/events');
        const rows = data.events || [];

        eventDefTable.innerHTML = rows.map((e) => {
            const enabled = Number(e.enabled) === 1;
            // 效果落地情况:mapped=0 意味着「开了也不会有任何后果」,必须一眼看得出来
            const mapped = Number(e.mapped_effect_count || 0);
            const unmapped = Number(e.unmapped_effect_count || 0);
            const reason = e.disabled_reason
                ? `<div class="muted" title="${escapeHtml(e.disabled_reason)}">停用原因:${escapeHtml(e.disabled_reason.slice(0, 40))}…</div>`
                : '';

            return `
                <tr class="${enabled ? '' : 'row-disabled'}">
                    <td>${escapeHtml(e.event_id)}</td>
                    <td>${escapeHtml(e.name_zh)}<div class="muted">${escapeHtml(e.category)} · ${escapeHtml(EVENT_TYPE_LABELS[e.event_type] || e.event_type)}</div></td>
                    <td>${escapeHtml(e.min_era)}</td>
                    <td>
                        <button type="button" class="btn btn-ghost"
                                data-event-toggle="${escapeHtml(e.event_id)}"
                                data-event-next="${enabled ? 0 : 1}">${enabled ? '已启用' : '已停用'}</button>
                    </td>
                    <td>${eventNumberCell(e.event_id, 'base_weight', e.base_weight, 'any')}</td>
                    <td>${eventNumberCell(e.event_id, 'cooldown_minutes', e.cooldown_minutes, '1')}</td>
                    <td>${eventNumberCell(e.event_id, 'duration_minutes', e.duration_minutes, '1')}</td>
                    <td>${eventNumberCell(e.event_id, 'effect_multiplier', e.effect_multiplier, 'any')}</td>
                    <td>生效 ${mapped} / 未映射 ${unmapped}${reason}</td>
                    <td><button type="button" class="btn btn-ghost" data-event-save="${escapeHtml(e.event_id)}">保存</button></td>
                </tr>
            `;
        }).join('');

        const enabledCount = rows.filter((e) => Number(e.enabled) === 1).length;
        eventDefStatus.textContent = `共 ${rows.length} 条事件,启用 ${enabledCount} 条`;
    } catch (err) {
        if (err.status === 403) throw err;
        eventDefTable.innerHTML = '';
        eventDefStatus.textContent = errorMessage(err);
        throw err;
    }
}

// 修改原因是所有定义调整的必填项(与 Definition 调整 / 规则开关同口径)
function eventReasonOrNull() {
    const reason = eventDefReason.value.trim();
    if (reason.length < 2) {
        eventDefError.textContent = '请先填写修改原因(至少 2 字)';
        eventDefError.classList.remove('hidden');
        return null;
    }
    return reason;
}

async function submitEventField(btn, eventId, field, value, reason) {
    btn.disabled = true;
    try {
        const data = await api.post('/api/admin/definitions/event', {
            event_id: eventId, field, value, reason,
        });
        eventDefResult.textContent = `已修改 ${eventId} 的 ${field}:${data.before} → ${data.after}(新版本号 ${data.version})`
            + (data.warning ? ` ⚠ ${data.warning}` : '');
        eventDefResult.classList.remove('hidden');
        eventDefReason.value = '';
        await loadEventDefinitions().catch(() => {});
        // 刷新审计,让新的 ADMIN.CONFIG_CHANGE 立刻可见
        auditActionInput.value = '';
        await loadAudit().catch(() => {});
    } catch (err) {
        eventDefError.textContent = errorMessage(err);
        eventDefError.classList.remove('hidden');
    } finally {
        btn.disabled = false;
    }
}

// 事件委托:表格内容每次刷新都会重建,不能给每行按钮单独绑事件
eventDefTable.addEventListener('click', async (e) => {
    const toggleBtn = e.target.closest('[data-event-toggle]');
    const saveBtn = e.target.closest('[data-event-save]');
    if (!toggleBtn && !saveBtn) return;

    eventDefError.classList.add('hidden');
    eventDefResult.classList.add('hidden');

    const reason = eventReasonOrNull();
    if (reason === null) return;

    if (toggleBtn) {
        await submitEventField(toggleBtn, toggleBtn.dataset.eventToggle, 'enabled', Number(toggleBtn.dataset.eventNext), reason);
        return;
    }

    // 保存:逐字段提交(每个字段一条审计 + 一次版本递增)。
    // 不做「一次提交整行」——审计要能回答「是谁把干旱的权重从 8 改成 40 的」,
    // 合并成一条会让 before/after 变成一个大对象,查起来反而困难
    const eventId = saveBtn.dataset.eventSave;
    const inputs = eventDefTable.querySelectorAll(`[data-event-row="${eventId}"]`);
    for (const input of inputs) {
        const value = Number(input.value);
        if (input.value.trim() === '' || !Number.isFinite(value) || value < 0) {
            eventDefError.textContent = '每一项都要填写有效的非负数值';
            eventDefError.classList.remove('hidden');
            return;
        }
        if (Number(input.defaultValue) === value) continue; // 没改的字段不提交,免得刷出一堆空审计
        await submitEventField(saveBtn, eventId, input.dataset.eventField, value, reason);
        return; // 一次保存只提交一个改动:上面的 submit 已经刷新了整张表,继续遍历的是旧 DOM
    }

    eventDefError.textContent = '这一行没有改动';
    eventDefError.classList.remove('hidden');
});

eventDefRefresh.addEventListener('click', () => { loadEventDefinitions().catch(() => {}); });

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

// 对象型设定的「键/值」表格编辑器(不做成裸 JSON 文本框:手写 JSON 迟早写出脏配置)。
// 可选键来自服务器下发的 options(= 服务端 allowlist 的同一份清单),前端不自己编一套资源码表
function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function mapRowHtml(settingKey, code, amount, label, maxValue) {
    return `
        <tr data-map-row="${escapeHtml(code)}">
            <td>${escapeHtml(label)}</td>
            <td><input type="number" step="any" min="0" max="${maxValue}" value="${Number(amount) || 0}"
                       data-map-input="${escapeHtml(settingKey)}" data-map-key="${escapeHtml(code)}"></td>
            <td><button type="button" class="btn btn-ghost" data-map-remove="${escapeHtml(settingKey)}">移除</button></td>
        </tr>
    `;
}

function mapEditorHtml(s) {
    const key = s.setting_key;
    const value = isPlainObject(s.value) ? s.value : {};
    const options = Array.isArray(s.options) ? s.options : [];
    const maxValue = s.max_value === null || s.max_value === undefined ? 1000000 : s.max_value;
    const labelOf = (code) => {
        const hit = options.find((o) => o.code === code);
        return hit ? `${hit.name}(${hit.code})` : code;
    };

    const rows = Object.keys(value)
        .map((code) => mapRowHtml(key, code, value[code], labelOf(code), maxValue))
        .join('');

    return `
        <table class="map-editor" data-map-editor="${escapeHtml(key)}" data-map-max="${maxValue}">
            <thead><tr><th>资源</th><th>数量</th><th></th></tr></thead>
            <tbody data-map-body="${escapeHtml(key)}">${rows}</tbody>
        </table>
        <div class="map-add">
            <select data-map-select="${escapeHtml(key)}">
                ${options.map((o) => `<option value="${escapeHtml(o.code)}">${escapeHtml(o.name)}(${escapeHtml(o.code)})</option>`).join('')}
            </select>
            <button type="button" class="btn btn-ghost" data-map-add="${escapeHtml(key)}">添加资源</button>
        </div>
    `;
}

// 数值型设定的通用数字输入控件(M3 起「系统规则数据后台可调」的载体)。
//
// 完全数据驱动:控件只认服务器下发的 type / min_value / max_value,
// 不在前端内置任何一个具体 key 的规则 —— 后端每登记一条新的数值参数,后台自动多出一行可编辑的输入框,
// 不必再改这份 JS。前端校验只是体验优化(少一次往返),服务端 GameSetting::castValue 才是权威。
function numberEditorHtml(s) {
    const key = s.setting_key;
    const min = s.min_value === null || s.min_value === undefined ? '' : s.min_value;
    const max = s.max_value === null || s.max_value === undefined ? '' : s.max_value;
    const range = (min === '' && max === '') ? '' : `<div class="muted">允许范围:${min} ~ ${max}</div>`;

    return `
        <div class="number-editor">
            <input type="number" step="any" value="${escapeHtml(String(s.value))}"
                   min="${escapeHtml(String(min))}" max="${escapeHtml(String(max))}"
                   data-number-input="${escapeHtml(key)}"
                   data-number-min="${escapeHtml(String(min))}" data-number-max="${escapeHtml(String(max))}">
            ${range}
        </div>
    `;
}

// 取值 + 前端范围校验。返回 null 表示不合法(错误信息已写进 settingError)
function numberEditorValue(key) {
    const input = settingTable.querySelector(`[data-number-input="${key}"]`);
    if (!input) return null;

    if (input.value.trim() === '') {
        settingError.textContent = '请填写数值';
        settingError.classList.remove('hidden');
        return null;
    }

    const value = Number(input.value);
    if (!Number.isFinite(value)) {
        settingError.textContent = '数值必须是有效数字';
        settingError.classList.remove('hidden');
        return null;
    }

    const min = input.dataset.numberMin === '' ? null : Number(input.dataset.numberMin);
    const max = input.dataset.numberMax === '' ? null : Number(input.dataset.numberMax);
    if ((min !== null && value < min) || (max !== null && value > max)) {
        settingError.textContent = `数值必须在 ${min} ~ ${max} 之间`;
        settingError.classList.remove('hidden');
        return null;
    }

    return value;
}

async function loadSettings() {
    settingStatus.textContent = '加载中…';
    settingError.classList.add('hidden');
    try {
        const data = await api.get('/api/admin/settings');
        const rows = data.settings || [];
        settingTable.innerHTML = rows.map((s) => {
            // 布尔开关一键切换;对象型走键/值表格 + 保存;都不是则只读展示,不猜它该变成什么
            let valueCell = settingValueLabel(s.value);
            let actionCell = '<span class="muted">不可编辑</span>';

            if (s.registered && s.type === 'bool') {
                actionCell = `<button type="button" class="btn btn-ghost" data-setting-toggle="${escapeHtml(s.setting_key)}" data-setting-next="${s.value === true ? 'false' : 'true'}">切换为 ${s.value === true ? '关闭' : '开启'}</button>`;
            } else if (s.registered && s.type === 'resource_map') {
                valueCell = mapEditorHtml(s);
                actionCell = `<button type="button" class="btn btn-ghost" data-map-save="${escapeHtml(s.setting_key)}">保存</button>`;
            } else if (s.registered && s.type === 'number') {
                valueCell = numberEditorHtml(s);
                actionCell = `<button type="button" class="btn btn-ghost" data-number-save="${escapeHtml(s.setting_key)}">保存</button>`;
            }

            return `
                <tr>
                    <td>${escapeHtml(s.setting_key)}</td>
                    <td class="setting-desc">${escapeHtml(s.description)}</td>
                    <td>${valueCell}</td>
                    <td>${s.default === null || s.default === undefined ? '-' : escapeHtml(JSON.stringify(s.default))}</td>
                    <td>${s.updated_at ? escapeHtml(s.updated_at) : '-'}${s.updated_by ? ' · by #' + s.updated_by : ''}</td>
                    <td>${actionCell}</td>
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

// 修改原因是所有设定改动的必填项(与 Definition 调整同口径),提交前统一取一次
function settingReasonOrNull() {
    const reason = settingReason.value.trim();
    if (reason.length < 2) {
        settingError.textContent = '请先填写修改原因(至少 2 字)';
        settingError.classList.remove('hidden');
        return null;
    }
    return reason;
}

async function submitSetting(btn, settingKey, value, reason) {
    btn.disabled = true;
    try {
        const data = await api.post('/api/admin/settings', { setting_key: settingKey, value, reason });
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
}

// 事件委托:表格内容每次刷新都会重建,不能给每行按钮单独绑事件
settingTable.addEventListener('click', async (e) => {
    const toggleBtn = e.target.closest('[data-setting-toggle]');
    const saveBtn = e.target.closest('[data-map-save]');
    const addBtn = e.target.closest('[data-map-add]');
    const removeBtn = e.target.closest('[data-map-remove]');
    const numberBtn = e.target.closest('[data-number-save]');
    if (!toggleBtn && !saveBtn && !addBtn && !removeBtn && !numberBtn) return;

    settingError.classList.add('hidden');
    settingResult.classList.add('hidden');

    // 增删行只动 DOM,不发请求:改完整张表再一次性保存,避免中间态被写进配置
    if (removeBtn) {
        const row = removeBtn.closest('[data-map-row]');
        if (row) row.remove();
        return;
    }

    if (addBtn) {
        const key = addBtn.dataset.mapAdd;
        const select = settingTable.querySelector(`[data-map-select="${key}"]`);
        const body = settingTable.querySelector(`[data-map-body="${key}"]`);
        const editor = settingTable.querySelector(`[data-map-editor="${key}"]`);
        if (!select || !body || !select.value) return;
        if (body.querySelector(`[data-map-row="${select.value}"]`)) {
            settingError.textContent = '该资源已在列表中,直接改数量即可';
            settingError.classList.remove('hidden');
            return;
        }
        const label = select.options[select.selectedIndex].textContent.trim();
        body.insertAdjacentHTML('beforeend', mapRowHtml(key, select.value, 0, label, editor ? editor.dataset.mapMax : 1000000));
        return;
    }

    const reason = settingReasonOrNull();
    if (reason === null) return;

    if (toggleBtn) {
        await submitSetting(toggleBtn, toggleBtn.dataset.settingToggle, toggleBtn.dataset.settingNext === 'true', reason);
        return;
    }

    if (numberBtn) {
        const numberKey = numberBtn.dataset.numberSave;
        const value = numberEditorValue(numberKey);
        // null = 前端校验没过,错误信息已经显示,不发请求
        if (value === null) return;
        await submitSetting(numberBtn, numberKey, value, reason);
        return;
    }

    const key = saveBtn.dataset.mapSave;
    const value = {};
    let invalid = false;
    settingTable.querySelectorAll(`[data-map-input="${key}"]`).forEach((input) => {
        const amount = Number(input.value);
        if (input.value === '' || !Number.isFinite(amount)) {
            invalid = true;
            return;
        }
        value[input.dataset.mapKey] = amount;
    });

    if (invalid) {
        settingError.textContent = '每一项都要填写有效数量';
        settingError.classList.remove('hidden');
        return;
    }
    if (Object.keys(value).length === 0) {
        settingError.textContent = '至少保留一项资源';
        settingError.classList.remove('hidden');
        return;
    }

    await submitSetting(saveBtn, key, value, reason);
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
    if (hasPermission('edit_definition')) {
        loadSettings().catch(() => {});
        loadNpcDefinitions().catch(() => {});
        loadEventDefinitions().catch(() => {});
    }
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
