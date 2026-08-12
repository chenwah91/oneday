// 玩家列表 + 玩家详情全景页(W13-1):
//   · 列表:搜索(用户名 / 邮箱前缀)+ 角色过滤 + 游标翻页 + 封禁 / 解禁;
//   · 详情:点击列表行 → 同面板内切到全景视图(hash 带 #players?player=12,可直链回来),
//     分区展示账号 / 城市全字段 / 资源 / 建筑 / NPC / 科技 / 工具 / 事件 / 交易 / 审计,
//     并内嵌三个操作(按权限显隐):资源补偿、触发事件、封禁 / 解禁。
//
// 三处与后端契约对齐的地方:
//   ① q 是**前缀**匹配(后端刻意不做 %关键字%:中缀 LIKE 用不上索引);
//   ② 翻页用 before_id 游标(不是 offset):翻页期间有人注册也不会漏行 / 重复行;
//   ③ 封禁**绝不删除任何玩家数据**,只写 users.banned_at / ban_reason 两列;
//      管理角色账号不可在这里封禁(后端 422),按钮对它们不显示。
//
// 详情页的两条纪律:
//   · 全部数值是「最近结算时点」的快照(后端只读原始 DB 值,绝不在读路径跑结算),页面顶部标注;
//   · 操作全部走**既有**写端点(compensation / events/trigger / ban),本面板不发明新协议;
//     封禁调用与列表共用同一个 requestBanToggle,不复制两份逻辑。
//
// 按权限显隐只是体验优化 —— 前端隐藏不等于安全,真正的拦截在服务器端。

import { api, errorMessage, query } from '../core/api.js';
import { escapeHtml, formatAmount, toast } from '../core/dom.js';
import { hasPermission, ROLE_LABELS } from '../core/session.js';
import { navigate } from '../core/router.js';

const ROLE_OPTIONS = ['player', 'support', 'game_master', 'admin', 'super_admin'];

// 各运行时状态 → 中文标签(只作显示,不参与判断;未知值原样显示,不猜)
const BUILDING_STATUS_LABELS = { active: '已建成', constructing: '建造中', upgrading: '升级中' };
const NPC_STATUS_LABELS = { idle: '待岗', assigned: '在岗', left: '已离场' };
const ITEM_STATUS_LABELS = { stored: '库存', equipped: '已装备', broken: '已损坏' };
const TECH_STATUS_LABELS = { researching: '在研', unlocked: '已解锁' };
const EVENT_STATUS_LABELS = { active: '生效中', resolved: '已结算', expired: '已过期' };
const TRADE_ACTION_LABELS = { 'MARKET.BUY': '买入', 'MARKET.SELL': '卖出' };

const state = { nodes: null, nextBeforeId: null, count: 0, detail: null };

// 事件定义下拉的缓存:定义很少变,面板生命周期内拉一次就够
let eventDefsCache = null;

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
        // 两个子视图:列表与详情。切详情只切显隐,列表的搜索词 / 分页位置原样保留
        container.innerHTML = `
            <div class="pl-list-view">
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
                    关键字是<b>前缀</b>匹配(后端刻意不做中缀 LIKE:用不上索引)。封禁只写两列时间戳与原因,<b>绝不删除任何玩家数据</b>,解禁即完整复原。点击行进入玩家详情全景页。
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
            </div>
            <div class="pl-detail-view hidden"></div>
        `;

        state.nodes = {
            listView: container.querySelector('.pl-list-view'),
            detailView: container.querySelector('.pl-detail-view'),
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

    // 跨面板 / 直链跳转:#players?q=xxx 或 #players?player=12(路由 hash 只到面板一级,
    // 详情用查询参数带 —— 不改 core/router.js)
    apply(params) {
        if (params.q !== undefined) {
            state.nodes.q.value = params.q;
            showListView();
            load(false);
        }
        if (params.player !== undefined) {
            const id = Number(params.player);
            if (Number.isInteger(id) && id > 0) openDetail(id);
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

// ---------- 封禁 / 解禁(列表与详情共用同一条调用路径,不复制两份)----------

// 唯一的封禁 / 解禁请求入口:reason 为空则不带(解禁允许留空)
function requestBanToggle(playerId, ban, reason) {
    return api.post(`/api/admin/players/${playerId}/${ban ? 'ban' : 'unban'}`, reason ? { reason } : {});
}

// 封禁结果就地写回列表行(不重拉整页,保住分页位置)。
// 邮箱 / 城市 id 接口不回传,从原单元格取回;行不在当前页时静默跳过
function updateListRowAfterBan(p) {
    const tr = state.nodes.body.querySelector(`tr[data-player="${p.id}"]`);
    if (!tr) return;
    const fresh = {
        id: p.id, username: p.username, role: p.role,
        banned_at: p.banned_at, ban_reason: p.ban_reason,
        email: tr.children[2].textContent, city_id: tr.children[4].textContent.trim(),
    };
    const holderTbody = document.createElement('tbody');
    holderTbody.innerHTML = rowHtml(fresh);
    tr.replaceWith(holderTbody.firstElementChild);
}

async function onBodyClick(e) {
    const detailBtn = e.target.closest('[data-detail]');
    const banBtn = e.target.closest('[data-ban]');
    const unbanBtn = e.target.closest('[data-unban]');
    const confirmBtn = e.target.closest('[data-ban-confirm]');
    const cancelBtn = e.target.closest('[data-ban-cancel]');

    if (!detailBtn && !banBtn && !unbanBtn && !confirmBtn && !cancelBtn) {
        // 没点中任何按钮:整行点击 = 进详情(W13-1)
        const rowTr = e.target.closest('tr[data-player]');
        if (rowTr && !e.target.closest('button')) {
            navigate('players', { player: rowTr.dataset.player });
        }
        return;
    }

    clearError();
    const tr = e.target.closest('tr[data-player]') || e.target.closest('tr.expand-row').previousElementSibling;
    const playerId = Number(tr.dataset.player);

    if (cancelBtn) { closeExtra(tr); return; }

    if (detailBtn) {
        // 详情按钮与行点击同一条路:hash 带 player 参数,可直链 / 前进后退
        navigate('players', { player: playerId });
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
            const data = await requestBanToggle(playerId, ban, reason);
            const p = data.player;
            toast(data.changed
                ? `${ban ? '已封禁' : '已解禁'} ${p.username}(#${p.id})`
                : `${p.username} 已经是${ban ? '封禁' : '正常'}状态,未重复写入`, 'ok');

            holder.remove();
            updateListRowAfterBan(p);
        } catch (err) {
            setError(errorMessage(err));
        } finally {
            confirmBtn.disabled = false;
        }
    }
}

// ---------- 详情全景视图(W13-1)----------

function showListView() {
    state.nodes.detailView.classList.add('hidden');
    state.nodes.listView.classList.remove('hidden');
}

// 返回列表:把 hash 里的 player 参数摘掉,刷新页面时才不会又弹回详情
function backToList() {
    showListView();
    if (location.hash.indexOf('player=') !== -1) location.hash = '#players';
}

function setDetailError(message) {
    const n = state.nodes.detailView.querySelector('.pd-error');
    if (!n) return;
    n.textContent = message;
    n.classList.remove('hidden');
}

function setDetailOk(message) {
    const n = state.nodes.detailView.querySelector('.pd-ok');
    if (!n) return;
    n.textContent = message;
    n.classList.remove('hidden');
}

// 补偿的幂等键(与补偿面板同款):提交时生成、成功后清空,
// 网络超时重试带同一个 key,服务器不会重复入账
function newIdempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return 'pd-comp-' + Date.now() + '-' + Math.random().toString(16).slice(2);
}

// 审计 delta 映射 → 「wood +5 / money -30」,正负分色(与补偿面板同款展示)
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

// 键值分区(值一律由调用方转义好再传进来)
function kvGrid(pairs) {
    return `<div class="detail-grid">${pairs.map(([k, v]) => `<div><span class="muted">${escapeHtml(k)}</span> ${v}</div>`).join('')}</div>`;
}

// 表格分区:rows 是已拼好的 <tr> 数组,空时给占位文案
function tableHtml(headers, rows, emptyText) {
    if (!rows.length) return `<div class="status muted">${escapeHtml(emptyText)}</div>`;
    return `<div class="table-wrap"><table class="data-table">
        <thead><tr>${headers.map((h) => `<th>${escapeHtml(h)}</th>`).join('')}</tr></thead>
        <tbody>${rows.join('')}</tbody>
    </table></div>`;
}

function sub(title) {
    return `<div class="panel-subtitle">${escapeHtml(title)}</div>`;
}

async function openDetail(playerId) {
    const view = state.nodes.detailView;
    state.nodes.listView.classList.add('hidden');
    view.classList.remove('hidden');
    view.innerHTML = '<div class="status muted">加载玩家详情中…</div>';

    try {
        const data = await api.get('/api/admin/players/' + playerId);
        state.detail = { id: playerId, data, compKey: null };
        renderDetail();

        // 操作区的两份下拉按权限懒拉;拉失败只影响对应表单,不影响详情本身
        if (data.city) {
            if (hasPermission('adjust_resource')) loadCompResources();
            if (hasPermission('edit_definition')) loadEventDefs();
        }
    } catch (err) {
        view.innerHTML = `
            <div class="auth-error">${escapeHtml(errorMessage(err))}</div>
            <div class="detail-actions"><button type="button" class="btn btn-ghost btn-sm pd-back">← 返回列表</button></div>
        `;
        view.querySelector('.pd-back').addEventListener('click', backToList);
    }
}

function renderDetail() {
    const { data } = state.detail;
    const p = data.player;
    const c = data.city;
    const view = state.nodes.detailView;

    const canBan = hasPermission('ban_player') && (!p.role || p.role === 'player');
    const banned = !!p.banned_at;

    // ---- 账号分区 ----
    const accountHtml = kvGrid([
        ['玩家 ID', String(p.id)],
        ['用户名', escapeHtml(p.username)],
        ['邮箱', escapeHtml(p.email)],
        ['角色', escapeHtml(ROLE_LABELS[p.role] || p.role || '-')],
        ['注册时间', escapeHtml(String(p.created_at || '-'))],
        ['封禁状态', banned
            ? `<span class="status-failed">已封禁</span> ${escapeHtml(p.banned_at)} · ${escapeHtml(p.ban_reason || '')}`
            : '<span class="status-success">正常</span>'],
    ]);

    // ---- 城市与各分区(没有城市时整段收成一句话)----
    let cityHtml = '<div class="status muted">该玩家还没有城市</div>';
    let sectionsHtml = '';
    if (c) {
        cityHtml = kvGrid([
            ['城市', `${escapeHtml(c.name)}(id=${c.id})`],
            ['时代', `${escapeHtml(String(c.era_key))}(第 ${c.era_order} 档)`],
            ['人口', escapeHtml(formatAmount(c.population))],
            ['资金', escapeHtml(formatAmount(c.money))],
            ['幸福度', escapeHtml(formatAmount(c.happiness))],
            ['revision', String(c.revision)],
            ['建筑数', String(c.buildingCount)],
            ['地图', `${c.map_width} × ${c.map_height}`],
            ['数值版本', escapeHtml(String(c.game_data_version || '-'))],
            ['主结算时钟', escapeHtml(String(c.last_simulated_at || '-'))],
            ['NPC 时钟', escapeHtml(String(c.npc_settled_at || '-'))],
            ['工具时钟', escapeHtml(String(c.item_settled_at || '-'))],
            ['事件时钟', escapeHtml(String(c.event_settled_at || '-'))],
            ['粮食赤字自', escapeHtml(String(c.food_deficit_since || '-'))],
            ['粮食归零自', escapeHtml(String(c.food_zero_since || '-'))],
            ['建城时间', escapeHtml(String(c.created_at || '-'))],
        ]);

        const resourceRows = (data.resources || []).map((r) => `
            <tr><td>${escapeHtml(r.name)}</td><td class="cell-id">${escapeHtml(r.resource_id)}</td>
            <td>${escapeHtml(formatAmount(r.amount))}</td></tr>`);

        const buildingRows = (data.buildings || []).map((b) => `
            <tr><td>${b.id}</td><td>${escapeHtml(b.name)}</td><td class="cell-id">${escapeHtml(b.building_id)}</td>
            <td>Lv.${b.level}</td><td>${escapeHtml(BUILDING_STATUS_LABELS[b.status] || b.status)}</td>
            <td>(${b.x}, ${b.y})</td><td>${b.assigned_workers}</td></tr>`);

        const npcRows = (data.npcs || []).map((n) => `
            <tr><td>${n.id}</td><td>${escapeHtml(n.name)}</td><td class="cell-id">${escapeHtml(n.npc_id)}</td>
            <td>${escapeHtml(n.rarity || '-')}</td><td>Lv.${n.skill_level} / 技能 ${n.skill_value}</td>
            <td>${escapeHtml(formatAmount(n.morale))}</td>
            <td>${escapeHtml(NPC_STATUS_LABELS[n.status] || n.status)}</td>
            <td>${n.assigned_instance_id
                ? `${escapeHtml(n.assigned_building_name || '?')}(#${n.assigned_instance_id})` : '-'}</td></tr>`);

        const techRows = (data.technologies || []).map((t) => `
            <tr><td>${escapeHtml(t.name)}</td><td class="cell-id">${escapeHtml(t.tech_id)}</td>
            <td>${escapeHtml(TECH_STATUS_LABELS[t.status] || t.status)}</td>
            <td>${escapeHtml(t.started_at)}</td><td>${escapeHtml(t.finished_at)}</td></tr>`);

        const itemRows = (data.items || []).map((i) => `
            <tr><td>${i.id}</td><td>${escapeHtml(i.name)}</td><td class="cell-id">${escapeHtml(i.item_id)}</td>
            <td>${escapeHtml(formatAmount(i.durability_left))}${i.durability_max != null ? ' / ' + escapeHtml(formatAmount(i.durability_max)) : ''}</td>
            <td>${escapeHtml(ITEM_STATUS_LABELS[i.status] || i.status)}</td>
            <td>${i.equipped_instance_id
                ? `${escapeHtml(i.equipped_building_name || '?')}(#${i.equipped_instance_id})` : '-'}</td></tr>`);

        const eventRow = (ev) => `
            <tr><td>${ev.id}</td><td>${escapeHtml(ev.name)}</td><td class="cell-id">${escapeHtml(ev.event_id)}</td>
            <td>${escapeHtml(EVENT_STATUS_LABELS[ev.status] || ev.status)}</td>
            <td>${escapeHtml(ev.triggered_at)}</td><td>${escapeHtml(ev.expires_at)}</td>
            <td>${escapeHtml(ev.chosen_option || '-')}</td></tr>`;
        const eventsActiveRows = ((data.events && data.events.active) || []).map(eventRow);
        const eventsSettledRows = ((data.events && data.events.settled) || []).map(eventRow);

        const tradeRows = (data.trades || []).map((t) => `
            <tr><td>${t.id}</td><td>${escapeHtml(TRADE_ACTION_LABELS[t.action] || t.action)}</td>
            <td>${escapeHtml(t.occurred_at)}</td><td>${escapeHtml(t.status)}</td>
            <td>${deltaLabel(t.delta)}</td></tr>`);

        const auditRows = (data.recent_audit || []).map((a) => `
            <tr><td>${a.id}</td><td class="cell-id">${escapeHtml(a.action)}</td>
            <td>${escapeHtml(a.occurred_at)}</td><td>${escapeHtml(a.status)}</td></tr>`);

        sectionsHtml = `
            ${sub(`资源现况(${(data.resources || []).length} 项)`)}
            ${tableHtml(['资源', 'code', '数量'], resourceRows, '没有资源行')}

            ${sub(`建筑(${(data.buildings || []).length} 栋)`)}
            ${tableHtml(['实例', '名称', 'code', '等级', '状态', '坐标', '工人'], buildingRows, '还没有建筑')}

            ${sub(`NPC(${(data.npcs || []).length} 名,含已离场)`)}
            ${tableHtml(['实例', '名字', 'code', '稀有度', '等级', '士气', '状态', '岗位'], npcRows, '还没有 NPC')}

            ${sub(`科技(${(data.technologies || []).length} 项)`)}
            ${tableHtml(['名称', 'code', '状态', '开始', '完成'], techRows, '还没有科技记录')}

            ${sub(`工具(${(data.items || []).length} 件)`)}
            ${tableHtml(['实例', '名称', 'code', '耐久', '状态', '装备于'], itemRows, '还没有工具')}

            ${sub(`事件:生效中(${eventsActiveRows.length})`)}
            <div class="panel-hint muted">事件是懒结算的:已过期但还没被玩家侧翻牌的实例在这里仍显示「生效中」。</div>
            ${tableHtml(['实例', '名称', 'code', '状态', '触发', '到期', '选项'], eventsActiveRows, '当前没有生效中的事件')}

            ${sub(`事件:最近已结算(${eventsSettledRows.length},最多 10 条)`)}
            ${tableHtml(['实例', '名称', 'code', '状态', '触发', '到期', '选项'], eventsSettledRows, '还没有已结算的事件')}

            ${sub(`市场交易(最近 ${tradeRows.length} 笔,最多 20)`)}
            ${tableHtml(['审计 id', '方向', '时间', '状态', '变动'], tradeRows, '还没有市场交易')}

            ${sub(`最近审计(${auditRows.length} 条,最多 20;完整字段请跳审计面板)`)}
            ${tableHtml(['id', 'action', '时间', '状态'], auditRows, '该城还没有审计记录')}
        `;
    }

    // ---- 操作区(按权限显隐;无城市时补偿 / 事件不可用)----
    const opsParts = [];
    if (c && hasPermission('adjust_resource')) {
        opsParts.push(`
            ${sub('操作:资源补偿 / 扣减')}
            <form class="def-form pd-comp-form">
                <div class="def-row">
                    <div class="auth-field">
                        <label class="auth-label">资源</label>
                        <select class="pd-comp-resource" required><option value="">加载中…</option></select>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label">delta(可为负 = 扣减)</label>
                        <input class="pd-comp-delta" type="number" step="any" required>
                    </div>
                </div>
                <div class="auth-field">
                    <label class="auth-label">补偿原因(必填,至少 5 字)</label>
                    <input class="pd-comp-reason" type="text" required minlength="5" maxlength="80" placeholder="说明本次补偿的来源与依据">
                </div>
                <div class="def-actions"><button type="submit" class="btn btn-primary pd-comp-submit">提交补偿</button></div>
            </form>
        `);
    }
    if (c && hasPermission('edit_definition')) {
        opsParts.push(`
            ${sub('操作:触发事件到该城')}
            <form class="def-form pd-event-form">
                <div class="def-row">
                    <div class="auth-field">
                        <label class="auth-label">事件</label>
                        <select class="pd-event-id" required><option value="">加载中…</option></select>
                    </div>
                    <div class="auth-field">
                        <label class="auth-label">原因(必填,至少 5 字)</label>
                        <input class="pd-event-reason" type="text" required minlength="5" maxlength="80" placeholder="会真实改变该玩家的资源">
                    </div>
                </div>
                <div class="def-actions"><button type="submit" class="btn btn-primary pd-event-submit">触发事件</button></div>
            </form>
        `);
    }
    if (canBan) {
        opsParts.push(`
            ${sub(banned ? '操作:解禁' : '操作:封禁')}
            <div class="ban-form">
                <span>${banned ? '解禁' : '封禁'}玩家 #${p.id} —— 原因${banned ? '(可选,填了进审计)' : '(必填,至少 5 字)'}</span>
                <input type="text" class="pd-ban-reason" maxlength="80" placeholder="${banned ? '可留空' : '说明封禁依据'}">
                <button type="button" class="btn btn-primary btn-sm pd-ban-submit">确认${banned ? '解禁' : '封禁'}</button>
            </div>
        `);
    }

    view.innerHTML = `
        <div class="panel-header">
            <h2>玩家详情 #${p.id} · ${escapeHtml(p.username)}</h2>
            <div class="panel-actions">
                <button type="button" class="btn btn-ghost pd-refresh">刷新</button>
                <button type="button" class="btn btn-ghost pd-back">← 返回列表</button>
            </div>
        </div>
        <div class="panel-hint muted">
            所有数值为<b>最近结算时点</b>的快照(各时钟见「城市」分区),不是实时值 ——
            后台读路径绝不代跑结算,玩家下次操作或拉快照时数字才会推进。
            本系统当前没有跨玩家关联系统(无联盟 / 好友),此页即该玩家在系统里的全部关联数据。
        </div>
        <div class="auth-error hidden pd-error"></div>
        <div class="def-result hidden pd-ok"></div>

        ${sub('账号')}
        ${accountHtml}

        ${sub('城市')}
        ${cityHtml}

        ${sectionsHtml}

        ${opsParts.join('')}

        <div class="detail-actions">
            ${hasPermission('read_audit') ? '<button type="button" class="btn btn-ghost btn-sm pd-jump-audit">查看完整审计</button>' : ''}
        </div>
    `;

    // ---- 绑定 ----
    view.querySelector('.pd-back').addEventListener('click', backToList);
    view.querySelector('.pd-refresh').addEventListener('click', () => openDetail(state.detail.id));

    const jumpAudit = view.querySelector('.pd-jump-audit');
    if (jumpAudit) {
        // 审计面板的 apply 支持 city_id / user_id 过滤:有城按城过滤,没城按玩家过滤
        jumpAudit.addEventListener('click', () => {
            navigate('audit', c ? { city_id: c.id } : { user_id: p.id });
        });
    }

    const compForm = view.querySelector('.pd-comp-form');
    if (compForm) compForm.addEventListener('submit', onCompSubmit);

    const eventForm = view.querySelector('.pd-event-form');
    if (eventForm) eventForm.addEventListener('submit', onEventSubmit);

    const banSubmit = view.querySelector('.pd-ban-submit');
    if (banSubmit) banSubmit.addEventListener('click', () => onDetailBanToggle(banSubmit, !banned));
}

// 补偿的资源下拉:复用补偿面板的 lookup 端点(它只回可补偿资源,且带当前余额)。
// 详情分区里的资源表是「全部资源」,两者口径不同,不能互相顶替
async function loadCompResources() {
    const view = state.nodes.detailView;
    const sel = view.querySelector('.pd-comp-resource');
    const c = state.detail.data.city;
    if (!sel || !c) return;

    try {
        const data = await api.get('/api/admin/compensation/lookup' + query({ city_id: c.id }));
        sel.innerHTML = (data.resources || []).map((r) => `
            <option value="${escapeHtml(r.code)}">${escapeHtml(r.name)}(${escapeHtml(r.code)})· 现有 ${escapeHtml(formatAmount(r.amount))}</option>
        `).join('');
    } catch (err) {
        sel.innerHTML = '<option value="">资源清单加载失败</option>';
        setDetailError(errorMessage(err));
    }
}

// 触发事件的定义下拉:复用事件定义列表端点;停用中的事件禁选(后端也会拒,这里省一次 422)
async function loadEventDefs() {
    const view = state.nodes.detailView;
    const sel = view.querySelector('.pd-event-id');
    if (!sel) return;

    try {
        if (!eventDefsCache) {
            const data = await api.get('/api/admin/definitions/events');
            eventDefsCache = data.events || [];
        }
        sel.innerHTML = eventDefsCache.map((ev) => {
            const enabled = Number(ev.enabled) === 1;
            return `<option value="${escapeHtml(ev.event_id)}"${enabled ? '' : ' disabled'}>`
                + `${escapeHtml(ev.name_zh || ev.event_id)}(${escapeHtml(ev.event_id)})${enabled ? '' : ' · 已停用'}</option>`;
        }).join('');
    } catch (err) {
        sel.innerHTML = '<option value="">事件清单加载失败</option>';
        setDetailError(errorMessage(err));
    }
}

async function onCompSubmit(e) {
    e.preventDefault();
    const view = state.nodes.detailView;
    view.querySelector('.pd-error').classList.add('hidden');
    view.querySelector('.pd-ok').classList.add('hidden');

    const resource = view.querySelector('.pd-comp-resource').value;
    const delta = Number(view.querySelector('.pd-comp-delta').value);
    const reason = view.querySelector('.pd-comp-reason').value.trim();
    const submit = view.querySelector('.pd-comp-submit');
    const c = state.detail.data.city;

    if (!resource) { setDetailError('请先选择资源'); return; }
    if (reason.length < 5) { setDetailError('补偿原因至少 5 字'); return; }

    // 一次操作一个幂等键:成功前重试沿用同一个,服务器不会重复入账
    if (state.detail.compKey === null) state.detail.compKey = newIdempotencyKey();

    submit.disabled = true;
    try {
        const data = await api.post('/api/admin/compensation', {
            city_id: c.id,
            resource,
            delta,
            reason,
            idempotency_key: state.detail.compKey,
        });
        state.detail.compKey = null;

        const message = data.replayed
            ? `该补偿此前已入账(幂等重放,未重复发放):${data.resource} 当前 ${formatAmount(data.after)}`
            : `补偿成功:${data.resource} ${formatAmount(data.before)} → ${formatAmount(data.after)}(delta ${formatAmount(data.delta)})· 新 revision ${data.revision}`;
        toast(message, 'ok');

        // 操作成功 → 整页详情重拉(资源 / 审计 / revision 全部要变),成功文案在重绘后补写
        await openDetail(state.detail.id);
        setDetailOk(message);
    } catch (err) {
        setDetailError(errorMessage(err));
        submit.disabled = false;
    }
}

async function onEventSubmit(e) {
    e.preventDefault();
    const view = state.nodes.detailView;
    view.querySelector('.pd-error').classList.add('hidden');
    view.querySelector('.pd-ok').classList.add('hidden');

    const eventId = view.querySelector('.pd-event-id').value;
    const reason = view.querySelector('.pd-event-reason').value.trim();
    const submit = view.querySelector('.pd-event-submit');
    const c = state.detail.data.city;

    if (!eventId) { setDetailError('请先选择事件'); return; }
    if (reason.length < 5) { setDetailError('手动触发会真实改变该玩家的资源,原因至少 5 字'); return; }

    submit.disabled = true;
    try {
        const data = await api.post('/api/admin/events/trigger', {
            city_id: c.id,
            event_id: eventId,
            reason,
        });
        const message = `已触发 ${data.event_id}(${data.name_zh})到城市 ${data.city_id},实例 id ${data.event_instance_id};该城生效事件 ${data.active_count}/${data.max_active}`;
        toast(message, 'ok');

        await openDetail(state.detail.id);
        setDetailOk(message);
    } catch (err) {
        // EVENT_LIMIT_REACHED 的三种原因摊开(与事件面板同款),不然运营只看到「已达上限」然后反复重试
        const d = err && err.body ? err.body.details : null;
        let extra = '';
        if (d && d.limit === 'already_active') extra = `(${d.event_id} 已在该城生效中,等它结束再触发)`;
        else if (d && d.limit === 'max_active') extra = `(该城生效事件 ${d.current}/${d.max},已满)`;
        else if (d && d.limit === 'max_active_disaster') extra = `(该城灾害类事件 ${d.current}/${d.max},已满)`;
        setDetailError(errorMessage(err) + extra);
        submit.disabled = false;
    }
}

// 详情页的封禁 / 解禁:与列表共用 requestBanToggle,成功后同步列表行 + 重拉详情
async function onDetailBanToggle(button, ban) {
    const view = state.nodes.detailView;
    view.querySelector('.pd-error').classList.add('hidden');

    const reason = view.querySelector('.pd-ban-reason').value.trim();
    if (ban && reason.length < 5) { setDetailError('封禁原因至少 5 字'); return; }

    button.disabled = true;
    try {
        const data = await requestBanToggle(state.detail.id, ban, reason);
        const p = data.player;
        toast(data.changed
            ? `${ban ? '已封禁' : '已解禁'} ${p.username}(#${p.id})`
            : `${p.username} 已经是${ban ? '封禁' : '正常'}状态,未重复写入`, 'ok');

        updateListRowAfterBan(p);
        await openDetail(state.detail.id);
    } catch (err) {
        setDetailError(errorMessage(err));
        button.disabled = false;
    }
}
