// 规则参数(game_settings):154 项后台可调的系统规则。
//
// 完全数据驱动:控件只认服务器下发的 type / min_value / max_value / integer / group /
// depends / deprecated / options,前端不内置任何一个具体 key 的规则 ——
// 后端每登记一条新参数,后台自动多出一行,不必改这份 JS(CLAUDE §13)。
//
// 154 行时必须解决的四件事(88 行时还能忍):
//   ① 按 group 分组小标题 + 顶部过滤(键名 / 说明)+「只看已改动」;
//   ② 已改动的行(当前值 ≠ 默认值)加左侧色条 —— 运营最想知道的就是「这台机器被动过哪些旋钮」;
//   ③ 每行「恢复默认」:走同一个 POST(照样留审计),不给一个绕过审计的还原入口;
//   ④ 保存**只更新该行**:旧实现每保存一项就重绘整表,其它行没保存的输入被清空、滚动条弹回顶部。
//   ⑤ 修改原因保存后**不清空**:运营常连着改十几项,清空等于每次重打一遍。

import { api, errorMessage } from '../core/api.js';
import { escapeHtml, isPlainObject, stableJson } from '../core/dom.js';

// 分组中文名(顺序 = 后端 GameSetting::GROUPS 的顺序)
const GROUP_LABELS = {
    core: '内核基础(离线封顶 / 分段 / 建城初始资源)',
    population: '人口 / 劳动力 / 粮食',
    happiness: '幸福度',
    fiscal: '财政 / 税收 / 维护',
    governance: '治理',
    logistics: '物流 / 运输',
    tech: '科技',
    npc: 'NPC',
    building: '建筑(建造 / 升级 / 拆除 / 返还)',
    market: '市场(定价与反刷)',
    item: '工具(制作与耐久)',
    event: '随机事件',
    power: '电力',
    defense: '国防',
};

const GROUP_ORDER = Object.keys(GROUP_LABELS);

const DEPENDS_LABELS = { lte: '须 ≤', gte: '须 ≥' };

const state = {
    settings: [],
    nodes: null,
};

function isChanged(s) {
    if (!s.registered) return false;
    return stableJson(s.value) !== stableJson(s.default);
}

function valueLabel(value) {
    if (value === true) return '<span class="setting-on">开启(true)</span>';
    if (value === false) return '<span class="setting-off">关闭(false)</span>';
    return escapeHtml(JSON.stringify(value));
}

// ---------- 三种编辑器 ----------

function boolEditorHtml(s) {
    const on = s.value === true;
    return `
        <select class="setting-input" data-kind="bool" data-original="${on ? 'true' : 'false'}">
            <option value="true"${on ? ' selected' : ''}>开启(true)</option>
            <option value="false"${on ? '' : ' selected'}>关闭(false)</option>
        </select>
    `;
}

function numberEditorHtml(s) {
    const min = s.min_value === null || s.min_value === undefined ? '' : s.min_value;
    const max = s.max_value === null || s.max_value === undefined ? '' : s.max_value;
    const range = (min === '' && max === '') ? '' : `<div class="muted">允许范围:${escapeHtml(String(min))} ~ ${escapeHtml(String(max))}${s.integer ? ' · 整数' : ''}</div>`;

    return `
        <div class="number-editor">
            <input type="number" class="setting-input" data-kind="number"
                   step="${s.integer ? '1' : 'any'}"
                   value="${escapeHtml(String(s.value))}" data-original="${escapeHtml(String(s.value))}"
                   min="${escapeHtml(String(min))}" max="${escapeHtml(String(max))}"
                   data-min="${escapeHtml(String(min))}" data-max="${escapeHtml(String(max))}"
                   data-integer="${s.integer ? '1' : ''}">
            ${range}
        </div>
    `;
}

// 对象型设定的「键/值」表格编辑器(不做成裸 JSON 文本框:手写 JSON 迟早写出脏配置)。
// 可选键来自服务器下发的 options(= 服务端 allowlist 的同一份清单)
function mapRowHtml(code, amount, label, maxValue) {
    return `
        <tr data-map-row="${escapeHtml(code)}">
            <td>${escapeHtml(label)}</td>
            <td><input type="number" step="any" min="0" max="${escapeHtml(String(maxValue))}"
                       class="map-input" value="${Number(amount) || 0}" data-map-key="${escapeHtml(code)}"></td>
            <td><button type="button" class="btn btn-ghost btn-sm" data-map-remove="1">移除</button></td>
        </tr>
    `;
}

function mapEditorHtml(s) {
    const value = isPlainObject(s.value) ? s.value : {};
    const options = Array.isArray(s.options) ? s.options : [];
    const maxValue = s.max_value === null || s.max_value === undefined ? 1000000 : s.max_value;
    const labelOf = (code) => {
        const hit = options.find((o) => o.code === code);
        return hit ? `${hit.name}(${hit.code})` : code;
    };

    return `
        <table class="map-editor" data-map-max="${escapeHtml(String(maxValue))}">
            <thead><tr><th>资源</th><th>数量</th><th></th></tr></thead>
            <tbody>${Object.keys(value).map((c) => mapRowHtml(c, value[c], labelOf(c), maxValue)).join('')}</tbody>
        </table>
        <div class="map-add">
            <select class="map-select">
                ${options.map((o) => `<option value="${escapeHtml(o.code)}">${escapeHtml(o.name)}(${escapeHtml(o.code)})</option>`).join('')}
            </select>
            <button type="button" class="btn btn-ghost btn-sm" data-map-add="1">添加资源</button>
        </div>
    `;
}

function editorHtml(s) {
    if (!s.registered) return `<span class="muted">未登记,只读</span>${valueLabel(s.value)}`;
    if (s.deprecated) return `<span class="muted">已停用(代码中已无消费点)</span> ${valueLabel(s.value)}`;
    if (s.type === 'bool') return boolEditorHtml(s);
    if (s.type === 'number') return numberEditorHtml(s);
    if (s.type === 'resource_map') return mapEditorHtml(s);
    return valueLabel(s.value);
}

function rowHtml(s) {
    const changed = isChanged(s);
    const editable = s.registered && !s.deprecated;
    const depends = s.depends && typeof s.depends === 'object' ? Object.keys(s.depends)[0] : null;
    const dependHint = depends
        ? `<div class="depend-hint">${escapeHtml(DEPENDS_LABELS[depends] || depends)} ${escapeHtml(String(s.depends[depends]))} 的当前值</div>`
        : '';

    const cls = [
        'setting-row',
        changed ? 'row-changed' : '',
        s.deprecated ? 'row-deprecated' : '',
        !s.registered ? 'row-deprecated' : '',
    ].filter(Boolean).join(' ');

    return `
        <tr class="${cls}" data-setting="${escapeHtml(s.setting_key)}">
            <td class="cell-id">${escapeHtml(s.setting_key)}${changed ? '<span class="badge-changed">已改</span>' : ''}</td>
            <td class="setting-desc">${escapeHtml(s.description)}${dependHint}</td>
            <td>${editorHtml(s)}</td>
            <td>${s.default === null || s.default === undefined ? '-' : escapeHtml(JSON.stringify(s.default))}</td>
            <td class="cell-updated">${s.updated_at ? escapeHtml(String(s.updated_at)) : '-'}${s.updated_by ? ' · by #' + escapeHtml(String(s.updated_by)) : ''}</td>
            <td class="cell-actions">
                ${editable ? '<button type="button" class="btn btn-ghost btn-sm" data-setting-save="1">保存</button>' : ''}
                ${editable && changed ? '<button type="button" class="btn btn-ghost btn-sm" data-setting-reset="1">恢复默认</button>' : ''}
                ${editable && !changed ? '<span class="muted">与默认一致</span>' : ''}
            </td>
        </tr>
    `;
}

// 分组渲染:先按 GROUPS 顺序出登记键,已停用的死键与未登记的残留行统一置底
function renderTable() {
    const keyword = state.nodes.filter.value.trim().toLowerCase();
    const onlyChanged = state.nodes.onlyChanged.checked;

    const match = (s) => {
        if (onlyChanged && !isChanged(s)) return false;
        if (keyword === '') return true;
        return s.setting_key.toLowerCase().indexOf(keyword) !== -1
            || String(s.description || '').toLowerCase().indexOf(keyword) !== -1;
    };

    const buckets = new Map();
    GROUP_ORDER.forEach((g) => buckets.set(g, []));
    buckets.set('__deprecated__', []);
    buckets.set('__unregistered__', []);

    let shown = 0;
    state.settings.filter(match).forEach((s) => {
        shown += 1;
        if (!s.registered) { buckets.get('__unregistered__').push(s); return; }
        if (s.deprecated) { buckets.get('__deprecated__').push(s); return; }
        const group = buckets.has(s.group) ? s.group : '__unregistered__';
        buckets.get(group).push(s);
    });

    const html = [];
    buckets.forEach((list, group) => {
        if (!list.length) return;
        const label = group === '__deprecated__'
            ? '已停用的死键(只读,代码中已无消费点)'
            : group === '__unregistered__'
                ? '未登记(库里的残留行,只读)'
                : (GROUP_LABELS[group] || group);
        html.push(`<tr class="group-row"><td colspan="6">${escapeHtml(label)} · ${list.length} 项</td></tr>`);
        list.forEach((s) => html.push(rowHtml(s)));
    });

    state.nodes.body.innerHTML = html.join('');
    const changedCount = state.settings.filter(isChanged).length;
    state.nodes.status.textContent = `共 ${state.settings.length} 项设定,当前显示 ${shown} 项,已改动 ${changedCount} 项`;
}

function replaceRow(settingKey) {
    const s = state.settings.find((x) => x.setting_key === settingKey);
    const tr = state.nodes.body.querySelector(`tr[data-setting="${CSS.escape(settingKey)}"]`);
    if (!s || !tr) return;
    const holder = document.createElement('tbody');
    holder.innerHTML = rowHtml(s);
    tr.replaceWith(holder.firstElementChild);
}

// ---------- 取值 ----------

function readValue(tr, s) {
    if (s.type === 'bool') {
        return { ok: true, value: tr.querySelector('.setting-input').value === 'true' };
    }

    if (s.type === 'number') {
        const input = tr.querySelector('.setting-input');
        const text = input.value.trim();
        if (text === '') return { ok: false, message: '请填写数值' };
        const value = Number(text);
        if (!Number.isFinite(value)) return { ok: false, message: '数值必须是有效数字' };
        if (input.dataset.integer && Math.floor(value) !== value) {
            return { ok: false, message: '该项是「条数 / 分钟数 / 次数」类参数,必须填整数' };
        }
        const min = input.dataset.min === '' ? null : Number(input.dataset.min);
        const max = input.dataset.max === '' ? null : Number(input.dataset.max);
        if ((min !== null && value < min) || (max !== null && value > max)) {
            return { ok: false, message: `数值必须在 ${min} ~ ${max} 之间` };
        }
        return { ok: true, value };
    }

    if (s.type === 'resource_map') {
        const value = {};
        let invalid = false;
        tr.querySelectorAll('.map-input').forEach((input) => {
            const amount = Number(input.value);
            if (input.value === '' || !Number.isFinite(amount)) { invalid = true; return; }
            value[input.dataset.mapKey] = amount;
        });
        if (invalid) return { ok: false, message: '每一项都要填写有效数量' };
        if (Object.keys(value).length === 0) return { ok: false, message: '至少保留一项资源' };
        return { ok: true, value };
    }

    return { ok: false, message: '该类型暂不支持编辑' };
}

async function submit(settingKey, value, button, ctx) {
    const reason = ctx.reason();
    if (reason === null) return;

    button.disabled = true;
    try {
        const data = await api.post('/api/admin/settings', { setting_key: settingKey, value, reason });
        const s = state.settings.find((x) => x.setting_key === settingKey);
        if (s) {
            s.value = data.after;
            s.updated_at = '刚刚(本次会话)';
            s.updated_by = null;
        }
        replaceRow(settingKey);
        ctx.setResult(`已修改 ${data.setting_key}:${JSON.stringify(data.before)} → ${JSON.stringify(data.after)}`);
        const changedCount = state.settings.filter(isChanged).length;
        state.nodes.status.textContent = `共 ${state.settings.length} 项设定,已改动 ${changedCount} 项`;
    } catch (err) {
        ctx.setError(errorMessage(err));
    } finally {
        button.disabled = false;
    }
}

export const settingsPanel = {
    id: 'settings',
    label: '规则参数',
    permission: 'edit_definition',

    async load(container) {
        container.innerHTML = `
            <div class="panel-header">
                <h2>规则参数(game_settings)</h2>
                <div class="panel-actions">
                    <input class="def-reason" type="text" maxlength="80" placeholder="修改原因(必填,2-80 字)">
                    <button type="button" class="btn btn-ghost s-refresh">刷新</button>
                </div>
            </div>
            <div class="panel-hint muted">
                规则开关决定「某条规则要不要生效 / 强度多少」,与定义表的游戏数值分属两条路径(改这里不 bump 数值版本)。
                <b>已改动</b>的行左侧有色条;<b>已停用的死键</b>只读置底(改了也没有任何消费点)。
            </div>
            <div class="filter-bar">
                <input class="s-filter" type="search" placeholder="过滤:键名或说明">
                <label class="inline-field"><input type="checkbox" class="s-only-changed"> <span>只看已改动</span></label>
            </div>
            <div class="status muted s-status"></div>
            <div class="auth-error hidden s-error"></div>
            <div class="def-result hidden s-ok"></div>
            <div class="table-wrap"><table class="data-table">
                <thead><tr>
                    <th>setting_key</th><th>说明</th><th>当前值</th><th>默认值</th><th>最后修改</th><th>操作</th>
                </tr></thead>
                <tbody class="s-body"></tbody>
            </table></div>
        `;

        state.nodes = {
            reason: container.querySelector('.def-reason'),
            refresh: container.querySelector('.s-refresh'),
            filter: container.querySelector('.s-filter'),
            onlyChanged: container.querySelector('.s-only-changed'),
            status: container.querySelector('.s-status'),
            error: container.querySelector('.s-error'),
            result: container.querySelector('.s-ok'),
            body: container.querySelector('.s-body'),
        };

        const ctx = {
            setError: (m) => { state.nodes.error.textContent = m; state.nodes.error.classList.remove('hidden'); },
            clearError: () => state.nodes.error.classList.add('hidden'),
            setResult: (m) => { state.nodes.result.textContent = m; state.nodes.result.classList.remove('hidden'); },
            clearResult: () => state.nodes.result.classList.add('hidden'),
            reason() {
                const value = state.nodes.reason.value.trim();
                if (value.length < 2) {
                    ctx.setError('请先在右上角填写修改原因(至少 2 字)');
                    return null;
                }
                return value;
            },
        };

        state.nodes.refresh.addEventListener('click', () => reload(ctx));
        state.nodes.filter.addEventListener('input', renderTable);
        state.nodes.onlyChanged.addEventListener('change', renderTable);
        state.nodes.body.addEventListener('click', (e) => onBodyClick(e, ctx));

        await reload(ctx);
    },
};

async function reload(ctx) {
    ctx.clearError();
    ctx.clearResult();
    state.nodes.status.textContent = '加载中…';
    try {
        const data = await api.get('/api/admin/settings');
        state.settings = data.settings || [];
        renderTable();
    } catch (err) {
        state.nodes.body.innerHTML = '';
        state.nodes.status.textContent = errorMessage(err);
    }
}

async function onBodyClick(e, ctx) {
    const saveBtn = e.target.closest('[data-setting-save]');
    const resetBtn = e.target.closest('[data-setting-reset]');
    const addBtn = e.target.closest('[data-map-add]');
    const removeBtn = e.target.closest('[data-map-remove]');
    if (!saveBtn && !resetBtn && !addBtn && !removeBtn) return;

    ctx.clearError();
    ctx.clearResult();

    const tr = e.target.closest('tr[data-setting]');
    if (!tr) return;
    const settingKey = tr.dataset.setting;
    const s = state.settings.find((x) => x.setting_key === settingKey);
    if (!s) return;

    // 增删行只动 DOM,不发请求:改完整张表再一次性保存,避免中间态被写进配置
    if (removeBtn) {
        const row = removeBtn.closest('[data-map-row]');
        if (row) row.remove();
        return;
    }

    if (addBtn) {
        const select = tr.querySelector('.map-select');
        const body = tr.querySelector('.map-editor tbody');
        const editor = tr.querySelector('.map-editor');
        if (!select || !body || !select.value) return;
        if (body.querySelector(`[data-map-row="${CSS.escape(select.value)}"]`)) {
            ctx.setError('该资源已在列表中,直接改数量即可');
            return;
        }
        const label = select.options[select.selectedIndex].textContent.trim();
        body.insertAdjacentHTML('beforeend', mapRowHtml(select.value, 0, label, editor.dataset.mapMax));
        return;
    }

    if (resetBtn) {
        // 恢复默认走**同一个** POST:照样强制 reason、照样进审计,不给一个绕过审计的还原入口
        await submit(settingKey, s.default, resetBtn, ctx);
        return;
    }

    const read = readValue(tr, s);
    if (!read.ok) {
        ctx.setError(read.message);
        return;
    }
    await submit(settingKey, read.value, saveBtn, ctx);
}
