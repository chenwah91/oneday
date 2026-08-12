// 玩家列表:搜索(用户名 / 邮箱前缀)+ 角色过滤 + 游标翻页 + 行内详情 + 封禁 / 解禁。
//
// 三处与后端契约对齐的地方:
//   ① q 是**前缀**匹配(后端刻意不做 %关键字%:中缀 LIKE 用不上索引);
//   ② 翻页用 before_id 游标(不是 offset):翻页期间有人注册也不会漏行 / 重复行;
//   ③ 封禁**绝不删除任何玩家数据**,只写 users.banned_at / ban_reason 两列;
//      管理角色账号不可在这里封禁(后端 422),按钮对它们不显示。
//
// 封禁按钮按 ban_player 权限显隐 —— 前端显隐只是体验优化,真正的拦截在服务器端。

import { api, errorMessage, query } from '../core/api.js';
import { escapeHtml, toast } from '../core/dom.js';
import { hasPermission, ROLE_LABELS } from '../core/session.js';
import { navigate } from '../core/router.js';

const ROLE_OPTIONS = ['player', 'support', 'game_master', 'admin', 'super_admin'];

const state = { nodes: null, nextBeforeId: null, count: 0 };

function statusCell(p) {
    if (!p.banned_at) return '<span class="status-success">正常</span>';
    return `<span class="status-failed">已封禁</span><div class="muted">${escapeHtml(p.banned_at)}</div>`
        + (p.ban_reason ? `<div class="muted" title="${escapeHtml(p.ban_reason)}">${escapeHtml(String(p.ban_reason).slice(0, 30))}</div>` : '');
}

function rowHtml(p) {
    const canBan = hasPermission('ban_player') && (!p.role || p.role === 'player');
    const banned = !!p.banned_at;

    return `
        <tr data-player="${p.id}">
            <td>${p.id}</td>
            <td>${escapeHtml(p.username)}</td>
            <td>${escapeHtml(p.email)}</td>
            <td class="${p.role && p.role !== 'player' ? 'role-admin' : ''}">${escapeHtml(ROLE_LABELS[p.role] || p.role || '-')}</td>
            <td>${p.city_id ?? p.cityId ?? '-'}</td>
            <td>${statusCell(p)}</td>
            <td class="cell-actions">
                <button type="button" class="btn btn-ghost btn-sm" data-detail="1">详情</button>
                ${canBan && !banned ? '<button type="button" class="btn btn-ghost btn-sm" data-ban="1">封禁</button>' : ''}
                ${canBan && banned ? '<button type="button" class="btn btn-ghost btn-sm" data-unban="1">解禁</button>' : ''}
            </td>
        </tr>
    `;
}

export const playersPanel = {
    id: 'players',
    label: '玩家',
    permission: 'read_player',

    async load(container) {
        container.innerHTML = `
            <div class="panel-header">
                <h2>玩家</h2>
                <div class="panel-actions">
                    <input class="p-q" type="search" placeholder="用户名 / 邮箱前缀">
                    <select class="p-role">
                        <option value="">全部角色</option>
                        ${ROLE_OPTIONS.map((r) => `<option value="${r}">${escapeHtml(ROLE_LABELS[r])}(${r})</option>`).join('')}
                    </select>
                    <button type="button" class="btn btn-primary p-search">查询</button>
                </div>
            </div>
            <div class="panel-hint muted">
                关键字是<b>前缀</b>匹配(后端刻意不做中缀 LIKE:用不上索引)。封禁只写两列时间戳与原因,<b>绝不删除任何玩家数据</b>,解禁即完整复原。
            </div>
            <div class="status muted p-status"></div>
            <div class="auth-error hidden p-error"></div>
            <div class="table-wrap"><table class="data-table">
                <thead><tr>
                    <th>ID</th><th>用户名</th><th>邮箱</th><th>角色</th><th>城市 ID</th><th>状态</th><th>操作</th>
                </tr></thead>
                <tbody class="p-body"></tbody>
            </table></div>
            <div class="load-more"><button type="button" class="btn btn-ghost p-more hidden">加载更多</button></div>
        `;

        state.nodes = {
            q: container.querySelector('.p-q'),
            role: container.querySelector('.p-role'),
            search: container.querySelector('.p-search'),
            status: container.querySelector('.p-status'),
            error: container.querySelector('.p-error'),
            body: container.querySelector('.p-body'),
            more: container.querySelector('.p-more'),
        };

        state.nodes.search.addEventListener('click', () => load(false));
        state.nodes.q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); load(false); } });
        state.nodes.role.addEventListener('change', () => load(false));
        state.nodes.more.addEventListener('click', () => load(true));
        state.nodes.body.addEventListener('click', onBodyClick);

        await load(false);
    },

    // 跨面板跳转:#players?q=xxx
    apply(params) {
        if (params.q !== undefined) {
            state.nodes.q.value = params.q;
            load(false);
        }
    },
};

function setError(message) {
    state.nodes.error.textContent = message;
    state.nodes.error.classList.remove('hidden');
}

function clearError() {
    state.nodes.error.classList.add('hidden');
}

async function load(append) {
    clearError();
    state.nodes.status.textContent = '加载中…';
    if (!append) { state.nextBeforeId = null; state.count = 0; }

    // limit 一律带上 → 走后端的游标分页模式(不带任何参数是「一次拿全 500 条」的兼容模式)
    const url = '/api/admin/players' + query({
        q: state.nodes.q.value.trim(),
        role: state.nodes.role.value,
        limit: 50,
        before_id: append ? state.nextBeforeId : null,
    });

    try {
        const data = await api.get(url);
        const rows = data.players || [];
        const html = rows.map(rowHtml).join('');
        if (append) state.nodes.body.insertAdjacentHTML('beforeend', html);
        else state.nodes.body.innerHTML = html;

        state.count += rows.length;
        state.nextBeforeId = data.next_before_id ?? null;
        state.nodes.more.classList.toggle('hidden', !state.nextBeforeId);
        state.nodes.status.textContent = `已显示 ${state.count} 名玩家` + (state.nextBeforeId ? '(还有更多)' : '(已到底)');
    } catch (err) {
        if (!append) state.nodes.body.innerHTML = '';
        state.nodes.status.textContent = errorMessage(err);
        setError(errorMessage(err));
    }
}

function closeExtra(tr) {
    const next = tr.nextElementSibling;
    if (next && next.classList.contains('expand-row')) { next.remove(); return true; }
    return false;
}

function openExtra(tr) {
    closeExtra(tr);
    const holder = document.createElement('tr');
    holder.className = 'expand-row';
    const td = document.createElement('td');
    td.colSpan = tr.children.length;
    holder.appendChild(td);
    tr.after(holder);
    return td;
}

async function onBodyClick(e) {
    const detailBtn = e.target.closest('[data-detail]');
    const banBtn = e.target.closest('[data-ban]');
    const unbanBtn = e.target.closest('[data-unban]');
    const confirmBtn = e.target.closest('[data-ban-confirm]');
    const cancelBtn = e.target.closest('[data-ban-cancel]');
    const jumpBtn = e.target.closest('[data-jump]');
    if (!detailBtn && !banBtn && !unbanBtn && !confirmBtn && !cancelBtn && !jumpBtn) return;

    clearError();
    const tr = e.target.closest('tr[data-player]') || e.target.closest('tr.expand-row').previousElementSibling;
    const playerId = Number(tr.dataset.player);

    if (jumpBtn) {
        const target = jumpBtn.dataset.jump;
        if (target === 'audit') navigate('audit', { user_id: playerId });
        if (target === 'comp') navigate('compensation', { username: jumpBtn.dataset.username || '' });
        return;
    }

    if (cancelBtn) { closeExtra(tr); return; }

    if (detailBtn) {
        if (closeExtra(tr)) return;
        const td = openExtra(tr);
        td.innerHTML = '<span class="muted">加载中…</span>';
        try {
            const data = await api.get('/api/admin/players/' + playerId);
            const p = data.player;
            const c = data.city;
            td.innerHTML = `
                <div class="detail-grid">
                    <div><span class="muted">注册时间</span> ${escapeHtml(String(p.created_at || '-'))}</div>
                    <div><span class="muted">角色</span> ${escapeHtml(ROLE_LABELS[p.role] || p.role || '-')}</div>
                    <div><span class="muted">封禁状态</span> ${p.banned_at ? escapeHtml(p.banned_at) + ' · ' + escapeHtml(p.ban_reason || '') : '正常'}</div>
                    ${c ? `
                        <div><span class="muted">城市 id</span> ${c.id}</div>
                        <div><span class="muted">revision</span> ${c.revision}</div>
                        <div><span class="muted">人口</span> ${c.population}</div>
                        <div><span class="muted">资金</span> ${c.money}</div>
                        <div><span class="muted">建筑数</span> ${c.buildingCount}</div>
                    ` : '<div class="muted">该玩家还没有城市</div>'}
                </div>
                <div class="detail-actions">
                    <button type="button" class="btn btn-ghost btn-sm" data-jump="comp" data-username="${escapeHtml(p.username)}">去补偿这名玩家</button>
                    <button type="button" class="btn btn-ghost btn-sm" data-jump="audit">看他的审计记录</button>
                </div>
            `;
        } catch (err) {
            td.innerHTML = `<span class="auth-error">${escapeHtml(errorMessage(err))}</span>`;
        }
        return;
    }

    if (banBtn || unbanBtn) {
        const ban = !!banBtn;
        const td = openExtra(tr);
        td.innerHTML = `
            <div class="ban-form">
                <span>${ban ? '封禁' : '解禁'}玩家 #${playerId} —— 原因${ban ? '(必填,至少 5 字)' : '(可选,填了进审计)'}</span>
                <input type="text" class="ban-reason" maxlength="80" placeholder="${ban ? '如:w11smoke 冒烟测试封禁验证' : '可留空'}">
                <button type="button" class="btn btn-primary btn-sm" data-ban-confirm="${ban ? 'ban' : 'unban'}">确认${ban ? '封禁' : '解禁'}</button>
                <button type="button" class="btn btn-ghost btn-sm" data-ban-cancel="1">取消</button>
            </div>
        `;
        td.querySelector('.ban-reason').focus();
        return;
    }

    if (confirmBtn) {
        const ban = confirmBtn.dataset.banConfirm === 'ban';
        const holder = confirmBtn.closest('tr.expand-row');
        const reason = holder.querySelector('.ban-reason').value.trim();
        if (ban && reason.length < 5) { setError('封禁原因至少 5 字'); return; }

        confirmBtn.disabled = true;
        try {
            const data = await api.post(`/api/admin/players/${playerId}/${ban ? 'ban' : 'unban'}`,
                reason ? { reason } : {});
            const p = data.player;
            toast(data.changed
                ? `${ban ? '已封禁' : '已解禁'} ${p.username}(#${p.id})`
                : `${p.username} 已经是${ban ? '封禁' : '正常'}状态,未重复写入`, 'ok');

            // 就地更新该行:状态列 + 操作列,不重拉整页。
            // 邮箱 / 城市 id 从原来的单元格取回 —— 封禁接口只回账号字段,重拉整页会丢掉当前分页位置
            const fresh = {
                id: p.id, username: p.username, role: p.role,
                banned_at: p.banned_at, ban_reason: p.ban_reason,
                email: tr.children[2].textContent, city_id: tr.children[4].textContent.trim(),
            };
            const holderTbody = document.createElement('tbody');
            holderTbody.innerHTML = rowHtml(fresh);
            holder.remove();
            tr.replaceWith(holderTbody.firstElementChild);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            confirmBtn.disabled = false;
        }
    }
}
