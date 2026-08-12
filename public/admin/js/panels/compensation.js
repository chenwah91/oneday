// 玩家补偿 / 扣减(CLAUDE §80)。权限 adjust_resource(game_master 及以上)。
//
// 幂等键:提交时生成、成功后清空。网络超时后管理员再点一次提交,带的还是同一个 key,
// 服务器不会重复入账(CLAUDE §49)。
//
// 下方嵌该玩家最近 20 条 ADMIN.COMPENSATION —— 补偿前先看「他这个月已经被补过几次」,
// 是这一页最常被问到的问题;复用审计筛选接口(action + user_id),不需要新端点。

import { api, errorMessage, query } from '../core/api.js';
import { escapeHtml, formatAmount, toast } from '../core/dom.js';
import { hasPermission } from '../core/session.js';
import { navigate } from '../core/router.js';

const state = { nodes: null, idempotencyKey: null, userId: null };

// 审计行的 delta 列(?with_delta=1 才下发):{"wood": 5} → 「wood +5」。
// 正负分色 —— 补偿与扣减在这张表里必须一眼分得开
function deltaLabel(delta) {
    if (!delta || typeof delta !== 'object' || Array.isArray(delta)) return '<span class="muted">-</span>';
    const keys = Object.keys(delta);
    if (!keys.length) return '<span class="muted">-</span>';

    return keys.map((code) => {
        const amount = Number(delta[code]);
        if (!Number.isFinite(amount)) return escapeHtml(`${code} ${delta[code]}`);
        const sign = amount > 0 ? '+' : '';   // 负数自带减号
        const cls = amount < 0 ? 'status-failed' : 'status-success';
        return `<span class="${cls}">${escapeHtml(code)} ${sign}${escapeHtml(formatAmount(amount))}</span>`;
    }).join(' / ');
}

function newIdempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return 'comp-' + Date.now() + '-' + Math.random().toString(16).slice(2);
}

function targetPayload() {
    const cityId = state.nodes.cityId.value.trim();
    if (cityId) return { city_id: Number(cityId) };
    return { username: state.nodes.username.value.trim() };
}

export const compensationPanel = {
    id: 'compensation',
    label: '补偿',
    permission: 'adjust_resource',

    async load(container) {
        container.innerHTML = `
            <div class="panel-header"><h2>玩家补偿 / 扣减</h2></div>
            <div class="panel-hint muted">
                所有人工补偿都必须走这里(不要直接改库):强制 reason、进审计、带幂等键、锁内校验余额与仓储上限。
            </div>
            <form class="def-form c-form">
                <div class="def-row">
                    <div class="auth-field">
                        <label class="auth-label">用户名</label>
                        <input class="c-username" type="text" placeholder="玩家用户名" maxlength="190">
                    </div>
                    <div class="auth-field">
                        <label class="auth-label">city_id(可选,优先)</label>
                        <input class="c-city-id" type="number" min="1" placeholder="填了优先按 city_id 定位">
                    </div>
                    <div class="auth-field">
                        <label class="auth-label">定位</label>
                        <button type="button" class="btn btn-ghost c-lookup">查询城市与余额</button>
                    </div>
                </div>
                <div class="status muted c-city"></div>
                <div class="def-row">
                    <div class="auth-field">
                        <label class="auth-label">资源</label>
                        <select class="c-resource" required><option value="">先查询城市</option></select>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label">delta(可为负 = 扣减)</label>
                        <input class="c-delta" type="number" step="any" required>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label">工单号(可选)</label>
                        <input class="c-ticket" type="text" maxlength="64" placeholder="如 TKT-1001">
                    </div>
                </div>
                <div class="auth-field">
                    <label class="auth-label">补偿原因(必填,至少 5 字)</label>
                    <input class="c-reason" type="text" required minlength="5" maxlength="80" placeholder="说明本次补偿的来源与依据">
                </div>
                <div class="auth-error hidden c-error"></div>
                <div class="def-result hidden c-ok"></div>
                <div class="def-actions">
                    <button type="submit" class="btn btn-primary c-submit">提交补偿</button>
                </div>
            </form>

            <div class="panel-subtitle">该玩家最近 20 条 ADMIN.COMPENSATION</div>
            <div class="status muted c-history-status">先在上方查询一名玩家</div>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th>时间</th><th>变动(资源 / delta)</th><th>原因</th><th>city</th><th>request_id</th></tr></thead>
                <tbody class="c-history"></tbody>
            </table></div>
            <div class="detail-actions">
                <button type="button" class="btn btn-ghost btn-sm c-jump-audit hidden">在审计面板里看该玩家全部记录</button>
            </div>
        `;

        state.nodes = {
            form: container.querySelector('.c-form'),
            username: container.querySelector('.c-username'),
            cityId: container.querySelector('.c-city-id'),
            lookup: container.querySelector('.c-lookup'),
            city: container.querySelector('.c-city'),
            resource: container.querySelector('.c-resource'),
            delta: container.querySelector('.c-delta'),
            ticket: container.querySelector('.c-ticket'),
            reason: container.querySelector('.c-reason'),
            error: container.querySelector('.c-error'),
            result: container.querySelector('.c-ok'),
            submit: container.querySelector('.c-submit'),
            historyStatus: container.querySelector('.c-history-status'),
            history: container.querySelector('.c-history'),
            jumpAudit: container.querySelector('.c-jump-audit'),
        };

        state.nodes.lookup.addEventListener('click', () => lookup());
        state.nodes.form.addEventListener('submit', onSubmit);
        state.nodes.jumpAudit.addEventListener('click', () => {
            if (state.userId) navigate('audit', { user_id: state.userId, action: 'ADMIN.COMPENSATION' });
        });

        if (!hasPermission('read_audit')) {
            state.nodes.historyStatus.textContent = '当前账号没有 read_audit 权限,补偿历史不可见';
        }
    },

    // 跨面板跳转:#compensation?username=xxx
    apply(params) {
        if (params.username) {
            state.nodes.username.value = params.username;
            state.nodes.cityId.value = '';
            lookup();
        }
    },
};

function setError(message) {
    state.nodes.error.textContent = message;
    state.nodes.error.classList.remove('hidden');
}

// keepResult=true:补偿成功后的余额刷新走这条路 ——
// 不能把刚刚那句「补偿成功:wood 305 → 310」擦掉,那是这次操作唯一的落地凭证
async function lookup(keepResult) {
    state.nodes.error.classList.add('hidden');
    if (!keepResult) state.nodes.result.classList.add('hidden');

    const target = targetPayload();
    if (!target.city_id && !target.username) {
        setError('请填写用户名或 city_id');
        return;
    }

    const qs = query(target.city_id ? { city_id: target.city_id } : { username: target.username });
    state.nodes.city.textContent = '查询中…';

    try {
        const data = await api.get('/api/admin/compensation/lookup' + qs);
        state.nodes.cityId.value = data.city.id;
        state.nodes.username.value = data.user.username;
        state.userId = data.user.id;
        state.nodes.city.textContent =
            `玩家 ${data.user.username}(id=${data.user.id})· 城市 ${data.city.name}(id=${data.city.id})`
            + ` · revision ${data.city.revision} · 人口 ${data.city.population}`
            + ` · 最后结算 ${data.city.last_simulated_at}`;

        const selected = state.nodes.resource.value;
        state.nodes.resource.innerHTML = (data.resources || []).map((r) => `
            <option value="${escapeHtml(r.code)}">${escapeHtml(r.name)}(${escapeHtml(r.code)})· 现有 ${escapeHtml(formatAmount(r.amount))}</option>
        `).join('');
        if (selected) state.nodes.resource.value = selected;

        state.nodes.jumpAudit.classList.remove('hidden');
        await loadHistory();
    } catch (err) {
        state.nodes.city.textContent = '';
        state.nodes.resource.innerHTML = '<option value="">先查询城市</option>';
        setError(errorMessage(err));
    }
}

async function loadHistory() {
    if (!state.userId || !hasPermission('read_audit')) return;
    state.nodes.historyStatus.textContent = '加载中…';
    try {
        // with_delta=1:让审计列表按行带上 delta,补了多少一眼看得到,
        // 不必逐条点开详情(列表默认不带这一列,是因为一次 200 条会很重)
        const data = await api.get('/api/admin/audit' + query({
            action: 'ADMIN.COMPENSATION',
            user_id: state.userId,
            limit: 20,
            with_delta: 1,
        }));
        const rows = data.audit || [];
        state.nodes.history.innerHTML = rows.map((r) => `
            <tr>
                <td>${escapeHtml(r.occurredAt)}</td>
                <td>${deltaLabel(r.delta)}</td>
                <td class="cell-wrap">${escapeHtml(r.reasonCode ?? '-')}</td>
                <td>${r.cityId ?? '-'}</td>
                <td class="cell-id">${escapeHtml(r.requestId ?? '-')}</td>
            </tr>
        `).join('');
        state.nodes.historyStatus.textContent = rows.length
            ? `最近 ${rows.length} 条补偿记录`
            : '该玩家没有补偿记录';
    } catch (err) {
        state.nodes.history.innerHTML = '';
        state.nodes.historyStatus.textContent = errorMessage(err);
    }
}

async function onSubmit(e) {
    e.preventDefault();
    state.nodes.error.classList.add('hidden');
    state.nodes.result.classList.add('hidden');

    if (!state.nodes.resource.value) {
        setError('请先查询城市并选择资源');
        return;
    }
    if (state.nodes.reason.value.trim().length < 5) {
        setError('补偿原因至少 5 字');
        return;
    }

    if (state.idempotencyKey === null) state.idempotencyKey = newIdempotencyKey();

    state.nodes.submit.disabled = true;
    try {
        const payload = Object.assign(targetPayload(), {
            resource: state.nodes.resource.value,
            delta: Number(state.nodes.delta.value),
            reason: state.nodes.reason.value.trim(),
            ticket: state.nodes.ticket.value.trim(),
            idempotency_key: state.idempotencyKey,
        });
        const data = await api.post('/api/admin/compensation', payload);

        const message = data.replayed
            ? `该补偿此前已入账(幂等重放,未重复发放):${data.resource} 当前 ${formatAmount(data.after)},revision ${data.revision}`
            : `补偿成功:${data.resource} ${formatAmount(data.before)} → ${formatAmount(data.after)}`
              + `(delta ${formatAmount(data.delta)})· 资金 ${formatAmount(data.money)} · 新 revision ${data.revision}`;
        state.nodes.result.textContent = message;
        state.nodes.result.classList.remove('hidden');
        toast(message, 'ok');

        // 一次操作对应一个幂等键:成功后清空,下一次补偿重新生成
        state.idempotencyKey = null;
        state.nodes.delta.value = '';
        state.nodes.reason.value = '';
        state.nodes.ticket.value = '';

        await lookup(true);
    } catch (err) {
        setError(errorMessage(err));
    } finally {
        state.nodes.submit.disabled = false;
    }
}
