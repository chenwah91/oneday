// 审计日志:多维筛选 + 游标翻页 + 行内单条详情。
//
// 后端对 action 只放行「大写字母 / 下划线 / 点 / %」,带 % 时按前缀 LIKE(且把 _ 转义成字面量),
// 所以预置下拉里给的就是 ADMIN.% / MARKET.% / SECURITY.% 这类前缀。
//
// 列表刻意不下发 before/after/delta/metadata 四个 JSON 列(一次 200 条会是几 MB),
// 点开某一行才按 id 取详情。

import { api, errorMessage, query } from '../core/api.js';
import { escapeHtml } from '../core/dom.js';

const ACTION_PRESETS = [
    { value: '', label: '全部 action' },
    { value: 'ADMIN.%', label: 'ADMIN.%(后台操作)' },
    { value: 'ADMIN.CONFIG_CHANGE', label: 'ADMIN.CONFIG_CHANGE(定义 / 设定改动)' },
    { value: 'ADMIN.COMPENSATION', label: 'ADMIN.COMPENSATION(补偿)' },
    { value: 'ADMIN.PLAYER_BAN', label: 'ADMIN.PLAYER_BAN(封禁)' },
    { value: 'ADMIN.PLAYER_UNBAN', label: 'ADMIN.PLAYER_UNBAN(解禁)' },
    { value: 'MARKET.%', label: 'MARKET.%(市场成交)' },
    { value: 'SECURITY.%', label: 'SECURITY.%(安全事件)' },
    { value: 'EVENT.%', label: 'EVENT.%(随机事件)' },
    { value: 'BUILDING.%', label: 'BUILDING.%(建造 / 升级 / 拆除)' },
    { value: 'AUTH.%', label: 'AUTH.%(登录 / 注册)' },
];

const state = { nodes: null, nextBeforeId: null, count: 0 };

function statusClass(status) {
    if (status === 'success') return 'status-success';
    if (status === 'failed' || status === 'rejected') return 'status-failed';
    return '';
}

function rowHtml(r) {
    return `
        <tr data-audit="${r.id}" class="clickable">
            <td>${r.id}</td>
            <td>${escapeHtml(r.occurredAt)}</td>
            <td class="cell-id">${escapeHtml(r.action)}</td>
            <td>${escapeHtml(r.actorType)}</td>
            <td>${r.userId ?? '-'}</td>
            <td>${r.cityId ?? '-'}</td>
            <td class="${statusClass(r.status)}">${escapeHtml(r.status)}</td>
            <td class="cell-wrap">${escapeHtml(r.reasonCode ?? '-')}</td>
            <td class="cell-id">${escapeHtml(r.requestId ?? '-')}</td>
        </tr>
    `;
}

export const auditPanel = {
    id: 'audit',
    label: '审计',
    permission: 'read_audit',

    async load(container) {
        container.innerHTML = `
            <div class="panel-header">
                <h2>审计日志</h2>
                <div class="panel-actions">
                    <button type="button" class="btn btn-primary a-search">查询</button>
                </div>
            </div>
            <div class="panel-hint muted">
                行可点开看单条详情(before / after / delta / metadata 四个 JSON 列)。action 支持 <b>%</b> 前缀通配;
                时间格式如 <code>2026-08-12 00:00</code>。
            </div>
            <div class="filter-bar">
                <select class="a-preset">
                    ${ACTION_PRESETS.map((p) => `<option value="${escapeHtml(p.value)}">${escapeHtml(p.label)}</option>`).join('')}
                </select>
                <input class="a-action" type="text" placeholder="或自定义 action(如 NPC.%)">
                <input class="a-user" type="number" min="1" placeholder="user_id">
                <input class="a-city" type="number" min="1" placeholder="city_id">
                <input class="a-request" type="text" placeholder="request_id">
                <input class="a-from" type="text" placeholder="起 from">
                <input class="a-to" type="text" placeholder="止 to">
                <input class="a-limit" type="number" min="1" max="200" value="50" title="每页条数(1~200)">
            </div>
            <div class="status muted a-status"></div>
            <div class="auth-error hidden a-error"></div>
            <div class="table-wrap"><table class="data-table">
                <thead><tr>
                    <th>id</th><th>时间</th><th>action</th><th>actor</th><th>user</th><th>city</th>
                    <th>status</th><th>reason</th><th>request_id</th>
                </tr></thead>
                <tbody class="a-body"></tbody>
            </table></div>
            <div class="load-more"><button type="button" class="btn btn-ghost a-more hidden">加载更多</button></div>
        `;

        state.nodes = {
            preset: container.querySelector('.a-preset'),
            action: container.querySelector('.a-action'),
            user: container.querySelector('.a-user'),
            city: container.querySelector('.a-city'),
            request: container.querySelector('.a-request'),
            from: container.querySelector('.a-from'),
            to: container.querySelector('.a-to'),
            limit: container.querySelector('.a-limit'),
            search: container.querySelector('.a-search'),
            status: container.querySelector('.a-status'),
            error: container.querySelector('.a-error'),
            body: container.querySelector('.a-body'),
            more: container.querySelector('.a-more'),
        };

        state.nodes.search.addEventListener('click', () => load(false));
        state.nodes.preset.addEventListener('change', () => { state.nodes.action.value = ''; load(false); });
        state.nodes.more.addEventListener('click', () => load(true));
        state.nodes.body.addEventListener('click', onBodyClick);
        [state.nodes.action, state.nodes.user, state.nodes.city, state.nodes.request, state.nodes.from, state.nodes.to]
            .forEach((input) => input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); load(false); }
            }));

        await load(false);
    },

    // 跨面板跳转:#audit?user_id=12 / #audit?action=ADMIN.COMPENSATION
    apply(params) {
        if (params.user_id !== undefined) state.nodes.user.value = params.user_id;
        if (params.city_id !== undefined) state.nodes.city.value = params.city_id;
        if (params.action !== undefined) {
            const preset = Array.from(state.nodes.preset.options).find((o) => o.value === params.action);
            if (preset) state.nodes.preset.value = params.action;
            else state.nodes.action.value = params.action;
        }
        load(false);
    },
};

async function load(append) {
    const n = state.nodes;
    n.error.classList.add('hidden');
    n.status.textContent = '加载中…';
    if (!append) { state.nextBeforeId = null; state.count = 0; }

    const url = '/api/admin/audit' + query({
        action: n.action.value.trim() || n.preset.value,
        user_id: n.user.value.trim(),
        city_id: n.city.value.trim(),
        request_id: n.request.value.trim(),
        from: n.from.value.trim(),
        to: n.to.value.trim(),
        limit: n.limit.value.trim() || 50,
        before_id: append ? state.nextBeforeId : null,
    });

    try {
        const data = await api.get(url);
        const rows = data.audit || [];
        const html = rows.map(rowHtml).join('');
        if (append) n.body.insertAdjacentHTML('beforeend', html);
        else n.body.innerHTML = html;

        state.count += rows.length;
        state.nextBeforeId = data.next_before_id ?? null;
        n.more.classList.toggle('hidden', !state.nextBeforeId);
        n.status.textContent = `已显示 ${state.count} 条` + (state.nextBeforeId ? '(还有更多)' : '(已到底)');
    } catch (err) {
        if (!append) n.body.innerHTML = '';
        n.status.textContent = errorMessage(err);
        n.error.textContent = errorMessage(err);
        n.error.classList.remove('hidden');
    }
}

function jsonBlock(label, value) {
    if (value === null || value === undefined) {
        return `<div class="json-col"><div class="json-title">${escapeHtml(label)}</div><div class="muted">(空)</div></div>`;
    }
    return `<div class="json-col">
        <div class="json-title">${escapeHtml(label)}</div>
        <pre class="json-pre">${escapeHtml(JSON.stringify(value, null, 2))}</pre>
    </div>`;
}

async function onBodyClick(e) {
    const tr = e.target.closest('tr[data-audit]');
    if (!tr) return;

    const next = tr.nextElementSibling;
    if (next && next.classList.contains('expand-row')) { next.remove(); return; }

    const holder = document.createElement('tr');
    holder.className = 'expand-row';
    const td = document.createElement('td');
    td.colSpan = tr.children.length;
    td.innerHTML = '<span class="muted">加载详情…</span>';
    holder.appendChild(td);
    tr.after(holder);

    try {
        const data = await api.get('/api/admin/audit/' + tr.dataset.audit);
        const a = data.audit;
        td.innerHTML = `
            <div class="detail-grid">
                <div><span class="muted">entity</span> ${escapeHtml(String(a.entity_type || '-'))} / ${escapeHtml(String(a.entity_id || '-'))}</div>
                <div><span class="muted">actor</span> ${escapeHtml(String(a.actor_type || '-'))} #${escapeHtml(String(a.actor_id ?? '-'))}</div>
                <div><span class="muted">city revision</span> ${escapeHtml(String(a.city_revision_before ?? '-'))} → ${escapeHtml(String(a.city_revision_after ?? '-'))}</div>
                <div><span class="muted">ip</span> ${escapeHtml(String(a.ip_address || '-'))}</div>
                <div><span class="muted">数值版本</span> ${escapeHtml(String(a.game_data_version || '-'))}</div>
                <div><span class="muted">幂等键</span> ${escapeHtml(String(a.idempotency_key || '-'))}</div>
                <div><span class="muted">trace_id</span> ${escapeHtml(String(a.trace_id || '-'))}</div>
                <div><span class="muted">event_hash</span> ${escapeHtml(String(a.event_hash || '-').slice(0, 16))}…</div>
            </div>
            <div class="json-groups">
                ${jsonBlock('before_json', a.before_json)}
                ${jsonBlock('after_json', a.after_json)}
                ${jsonBlock('delta_json', a.delta_json)}
                ${jsonBlock('metadata_json', a.metadata_json)}
            </div>
        `;
    } catch (err) {
        td.innerHTML = `<span class="auth-error">${escapeHtml(errorMessage(err))}</span>`;
    }
}
