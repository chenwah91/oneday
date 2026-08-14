// 通用「整表定义编辑器」。
//
// 后台有八张定义表要改(建筑等级 / 建筑上限 / 科技 / NPC / NPC 曲线 / 时代门槛 / 市场 / 工具 / 事件),
// 它们的后端契约是**同一套**:GET 整表 + 一个 editable 数组;POST {主键…, field, value, reason},
// 回 {before, after, version}。所以前端也只写一份渲染器,每个面板退化成一个配置对象。
//
// 三条硬纪律:
//   ① **可编辑列取接口下发的 editable 数组**,前端不再硬编码字段下拉 ——
//      后端 allowlist 增删一个字段,后台自动多/少一列,不必改这份 JS(CLAUDE §13);
//   ② **强制 reason**:所有定义改动都要留原因(§63),提交前统一取一次,保存后**不清空**
//      (运营常常连着改十几格,清空等于每次重打一遍);
//   ③ **提交后就地更新该行**:只改这一格的 original 值与该行的版本号,不重绘整表 ——
//      重绘会把其它行没提交的输入全部抹掉、并把滚动条弹回顶部。
//
// 一次「保存」= 该行所有被改过的格子逐个提交(每格一条审计 + 一次版本递增)。
// 刻意不合并成一条:审计要能回答「是谁把干旱的权重从 8 改成 40 的」。

import { api, errorMessage } from '../core/api.js';
import { escapeHtml, formatAmount } from '../core/dom.js';

// 只读列的默认渲染:JSON 串原样显示(时代门槛的 buildings_json、科技的前置清单都是它)
function readonlyCell(row, col) {
    if (typeof col.format === 'function') return col.format(row);
    const value = row[col.key];
    if (value === null || value === undefined || value === '') return '<span class="muted">-</span>';
    return escapeHtml(String(value));
}

// 可编辑格:数值走 number,带 options 的走 select,meta.text 的走文本框。
// 文本类型是 W14 加的:后端把市场 note 与 NPC 的中文名 / 描述列开放成可编辑后,
// 这些列若仍按数值渲染就是个填不进字的数字框 —— 控件形态必须跟着列的实际类型走
function editorCell(row, field, meta) {
    const raw = row[field];
    const original = raw === null || raw === undefined ? '' : String(raw);

    if (meta && meta.text) {
        const maxLength = meta.maxLength ? ` maxlength="${escapeHtml(String(meta.maxLength))}"` : '';
        return `<input type="text" class="cell-input cell-input-text"${maxLength}
                       data-field="${escapeHtml(field)}" data-original="${escapeHtml(original)}"
                       value="${escapeHtml(original)}">`;
    }

    if (meta && Array.isArray(meta.options)) {
        const options = meta.options.map((o) => {
            const value = String(o.value);
            return `<option value="${escapeHtml(value)}"${value === original ? ' selected' : ''}>${escapeHtml(o.label)}</option>`;
        }).join('');
        const known = meta.options.some((o) => String(o.value) === original);
        // 当前值不在可切换清单里(如市场的 capacity_contract):只读显示,不给一个必然 422 的下拉
        if (!known) {
            return `<span class="cell-locked" title="该取值不在可切换清单内,改动请走迁移">${escapeHtml(original)}</span>`;
        }
        return `<select class="cell-input" data-field="${escapeHtml(field)}" data-original="${escapeHtml(original)}">${options}</select>`;
    }

    const step = meta && meta.integer ? '1' : 'any';
    const min = meta && meta.min !== undefined ? ` min="${escapeHtml(String(meta.min))}"` : ' min="0"';
    const max = meta && meta.max !== undefined ? ` max="${escapeHtml(String(meta.max))}"` : '';
    return `<input type="number" class="cell-input" step="${step}"${min}${max}
                   data-field="${escapeHtml(field)}" data-original="${escapeHtml(original)}"
                   value="${escapeHtml(original)}">`;
}

// 前端校验只是体验优化(少一次往返),服务端 allowlist + 范围校验才是权威
function validateCell(control, meta, label) {
    // 下拉:数值型枚举(事件的 enabled 0/1)转回数字,字符串枚举(市场的 trade_mode)原样提交
    if (control.tagName === 'SELECT') {
        return { ok: true, value: meta && meta.numeric ? Number(control.value) : control.value };
    }

    const text = control.value.trim();

    // 文本列:原样提交(长度由 maxlength 与服务端把关)。允许留空 —— note / 描述这类列
    // 本来就可以是空的,服务端按 NULL 收;不能套用数值列「不能留空」那条
    if (meta && meta.text) {
        if (meta.required && text === '') return { ok: false, message: `${label} 不能留空` };
        return { ok: true, value: text };
    }

    if (text === '') return { ok: false, message: `${label} 不能留空` };

    const value = Number(text);
    if (!Number.isFinite(value)) return { ok: false, message: `${label} 必须是有效数字` };
    if (value < 0) return { ok: false, message: `${label} 不能是负数` };
    if (meta && meta.integer && Math.floor(value) !== value) return { ok: false, message: `${label} 必须是整数` };
    if (meta && meta.min !== undefined && value < meta.min) return { ok: false, message: `${label} 不能小于 ${meta.min}` };
    if (meta && meta.max !== undefined && value > meta.max) return { ok: false, message: `${label} 不能大于 ${meta.max}` };

    return { ok: true, value };
}

// 一个定义面板 = 一个配置对象。字段一览:
//   id / label / permission   路由与导航用(permission 默认 edit_definition)
//   title / hint              面板标题与「这一页能改什么、改坏了会怎样」的说明
//   listUrl / listKey         整表 GET(listUrl 可以是 (ctx) => url,返回 null 表示「先选个对象」)
//   editUrl                   逐格 POST
//   idFields                  [{ row: 行里的键, param: 提交参数名, label, numeric }]
//   readonlyColumns           [{ key, label, wrap, format(row) }]
//   labels                    可编辑列的中文名(列本身由接口下发的 editable 决定)
//   fieldMeta                 { 字段: { min, max, integer, numeric, options } },前端校验与控件形态
//   search                    { placeholder, fields } —— 整表本地过滤
//   expand                    { label, render(row, td, ctx) } —— 行展开(建筑等级的 JSON 条目)
//   extraColumns / rowActions / extraPayload / afterSave / rowClass / toolbar / countSuffix / emptyHint
export function createDefinitionPanel(config) {
    return {
        id: config.id,
        label: config.label,
        permission: config.permission || 'edit_definition',
        load: (container) => mount(container, config),
    };
}

function mount(container, config) {
    const searchPlaceholder = config.search ? config.search.placeholder || '搜索' : null;

    container.innerHTML = `
        <div class="panel-header">
            <h2>${escapeHtml(config.title)}</h2>
            <div class="panel-actions">
                <input class="def-reason" type="text" maxlength="80" placeholder="修改原因(必填,2-80 字)">
                ${searchPlaceholder ? `<input class="def-search" type="search" placeholder="${escapeHtml(searchPlaceholder)}">` : ''}
                <button type="button" class="btn btn-ghost def-refresh">刷新</button>
            </div>
        </div>
        ${config.hint ? `<div class="panel-hint muted">${config.hint}</div>` : ''}
        <div class="def-toolbar"></div>
        <div class="status muted def-status"></div>
        <div class="auth-error hidden def-error"></div>
        <div class="def-result hidden def-ok"></div>
        <div class="table-wrap"><table class="data-table def-table">
            <thead><tr class="def-head"></tr></thead>
            <tbody class="def-body"></tbody>
        </table></div>
    `;

    const nodes = {
        reason: container.querySelector('.def-reason'),
        search: container.querySelector('.def-search'),
        refresh: container.querySelector('.def-refresh'),
        toolbar: container.querySelector('.def-toolbar'),
        status: container.querySelector('.def-status'),
        error: container.querySelector('.def-error'),
        result: container.querySelector('.def-ok'),
        head: container.querySelector('.def-head'),
        body: container.querySelector('.def-body'),
    };

    const ctx = {
        config,
        container,
        nodes,
        state: {},            // 面板私有状态(建筑等级面板的当前 building_id 放这里)
        rows: [],
        editable: [],
        setError(message) { nodes.error.textContent = message; nodes.error.classList.remove('hidden'); },
        clearError() { nodes.error.classList.add('hidden'); },
        setResult(message) { nodes.result.textContent = message; nodes.result.classList.remove('hidden'); },
        clearResult() { nodes.result.classList.add('hidden'); },
        reason() {
            const value = nodes.reason.value.trim();
            if (value.length < 2) {
                ctx.setError('请先在右上角填写修改原因(至少 2 字)');
                return null;
            }
            return value;
        },
        reload: () => load(),
    };

    if (typeof config.toolbar === 'function') {
        config.toolbar(nodes.toolbar, ctx);
    }

    nodes.refresh.addEventListener('click', () => { load(); });

    if (nodes.search) {
        nodes.search.addEventListener('input', () => {
            const keyword = nodes.search.value.trim().toLowerCase();
            let shown = 0;
            Array.from(nodes.body.querySelectorAll('tr[data-search]')).forEach((tr) => {
                const hit = keyword === '' || tr.dataset.search.indexOf(keyword) !== -1;
                tr.classList.toggle('hidden', !hit);
                // 展开行跟随主行一起显隐
                const extra = tr.nextElementSibling;
                if (extra && extra.classList.contains('expand-row')) extra.classList.toggle('hidden', !hit);
                if (hit) shown += 1;
            });
            nodes.status.textContent = `共 ${ctx.rows.length} 行,当前显示 ${shown} 行`;
        });
    }

    nodes.body.addEventListener('click', (e) => onBodyClick(e, ctx));

    return load();

    async function load() {
        ctx.clearError();
        ctx.clearResult();
        nodes.status.textContent = '加载中…';

        const url = typeof config.listUrl === 'function' ? config.listUrl(ctx) : config.listUrl;
        if (!url) {
            nodes.body.innerHTML = '';
            nodes.status.textContent = config.emptyHint || '请先在上方选择要查看的对象';
            return;
        }

        try {
            const data = await api.get(url);
            ctx.rows = data[config.listKey] || [];

            // 可编辑列一律取接口下发的 editable —— 它就是服务端那份 allowlist。
            // 前端不硬编码字段名单,也不从行的键名去猜:后端 allowlist 增删一个字段,
            // 后台自动多 / 少一列,这份 JS 一行都不用改(CLAUDE §13)
            ctx.editable = Array.isArray(data.editable) ? data.editable : [];
            renderDefinitionTable(ctx);
            nodes.status.textContent = `共 ${ctx.rows.length} 行` + (config.countSuffix ? config.countSuffix(ctx.rows) : '');
        } catch (err) {
            nodes.body.innerHTML = '';
            nodes.status.textContent = errorMessage(err);
            if (err.status === 403) ctx.setError('当前账号没有 edit_definition 权限');
        }
    }
}

function renderDefinitionTable(ctx) {
    const { config, nodes } = ctx;
    const idCols = config.idFields || [];
    const readonly = config.readonlyColumns || [];
    const extra = config.extraColumns || [];

    nodes.head.innerHTML = [
        ...idCols.map((c) => `<th>${escapeHtml(c.label)}</th>`),
        ...readonly.map((c) => `<th>${escapeHtml(c.label)}</th>`),
        ...ctx.editable.map((f) => `<th>${escapeHtml((config.labels && config.labels[f]) || f)}</th>`),
        ...extra.map((c) => `<th>${escapeHtml(c.label)}</th>`),
        // 「操作」列被 CSS 钉在右侧,新版本号就挂在这一格里 ——
        // 单独开一列会被钉住的操作列盖住(它正好在它左边)
        '<th>操作 / 新版本</th>',
    ].join('');

    nodes.body.innerHTML = ctx.rows.map((row) => {
        const key = idCols.map((c) => String(row[c.row])).join(':');
        const searchText = (config.search ? config.search.fields : idCols.map((c) => c.row))
            .map((f) => String(row[f] ?? '')).join(' ').toLowerCase();

        const cells = [
            ...idCols.map((c) => `<td class="cell-id">${escapeHtml(String(row[c.row] ?? ''))}</td>`),
            ...readonly.map((c) => `<td class="${c.wrap ? 'cell-wrap' : ''}">${readonlyCell(row, c)}</td>`),
            ...ctx.editable.map((f) => `<td>${editorCell(row, f, (config.fieldMeta || {})[f])}</td>`),
            ...extra.map((c) => `<td>${c.render(row, ctx)}</td>`),
            `<td class="cell-actions">
                <button type="button" class="btn btn-ghost btn-sm" data-save="1">保存</button>
                ${config.expand ? `<button type="button" class="btn btn-ghost btn-sm" data-expand="1">${escapeHtml(config.expand.label)}</button>` : ''}
                ${(config.rowActions || []).map((a) => `<button type="button" class="btn btn-ghost btn-sm" data-action="${escapeHtml(a.key)}">${escapeHtml(a.label)}</button>`).join('')}
                <span class="cell-version muted"></span>
            </td>`,
        ].join('');

        const cls = typeof config.rowClass === 'function' ? config.rowClass(row) : '';

        return `<tr data-row="${escapeHtml(key)}" data-search="${escapeHtml(searchText)}" class="${escapeHtml(cls)}">${cells}</tr>`;
    }).join('');

    // 行对象挂到 DOM 上,后续操作(展开 / 自定义按钮)不必再按 key 反查
    Array.from(nodes.body.children).forEach((tr, index) => { tr._row = ctx.rows[index]; });
}

async function onBodyClick(e, ctx) {
    const saveBtn = e.target.closest('[data-save]');
    const expandBtn = e.target.closest('[data-expand]');
    const actionBtn = e.target.closest('[data-action]');
    if (!saveBtn && !expandBtn && !actionBtn) return;

    ctx.clearError();
    ctx.clearResult();

    const tr = e.target.closest('tr[data-row]');
    if (!tr) return;

    if (expandBtn) {
        toggleExpand(tr, ctx);
        return;
    }

    if (actionBtn) {
        const action = (ctx.config.rowActions || []).find((a) => a.key === actionBtn.dataset.action);
        if (action) await action.run({ ctx, tr, row: tr._row, button: actionBtn });
        return;
    }

    await saveRow(saveBtn, tr, ctx);
}

function toggleExpand(tr, ctx) {
    const next = tr.nextElementSibling;
    if (next && next.classList.contains('expand-row')) {
        next.remove();
        return;
    }
    const holder = document.createElement('tr');
    holder.className = 'expand-row';
    const td = document.createElement('td');
    td.colSpan = tr.children.length;
    holder.appendChild(td);
    tr.after(holder);
    ctx.config.expand.render(tr._row, td, ctx);
}

async function saveRow(button, tr, ctx) {
    const { config } = ctx;
    const reason = ctx.reason();
    if (reason === null) return;

    const controls = Array.from(tr.querySelectorAll('.cell-input'))
        .filter((c) => String(c.value) !== String(c.dataset.original));

    if (!controls.length) {
        ctx.setError('这一行没有改动');
        return;
    }

    const idPayload = {};
    (config.idFields || []).forEach((c) => {
        const raw = tr._row[c.row];
        idPayload[c.param] = c.numeric ? Number(raw) : raw;
    });

    const versionCell = tr.querySelector('.cell-version');
    button.disabled = true;

    try {
        const done = [];
        for (const control of controls) {
            const field = control.dataset.field;
            const label = (config.labels && config.labels[field]) || field;
            const meta = (config.fieldMeta || {})[field];

            const checked = validateCell(control, meta, label);
            if (!checked.ok) {
                ctx.setError(checked.message);
                return;
            }

            let payload = Object.assign({}, idPayload, { field, value: checked.value, reason });
            if (typeof config.extraPayload === 'function') {
                const extra = config.extraPayload(field, checked.value, { ctx, tr, row: tr._row });
                if (extra === null) return; // 缺必填项,extraPayload 已经写好错误信息
                payload = Object.assign(payload, extra);
            }

            const data = await api.post(config.editUrl, payload);

            // 就地更新:只动这一格与这一行的版本号,不重绘整表
            control.dataset.original = String(data.after);
            control.value = data.after;
            tr._row[field] = data.after;
            if (versionCell) versionCell.textContent = data.version ? 'v' + data.version : '已保存';
            done.push(`${label} ${formatAmount(data.before)} → ${formatAmount(data.after)}` + (data.warning ? ` ⚠ ${data.warning}` : ''));
        }

        ctx.setResult(done.join(';'));
        if (typeof config.afterSave === 'function') config.afterSave({ ctx, tr, row: tr._row });
    } catch (err) {
        ctx.setError(errorMessage(err));
    } finally {
        button.disabled = false;
    }
}
