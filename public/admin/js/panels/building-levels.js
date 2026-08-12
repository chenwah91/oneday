// 建筑等级定义(building_level_definition):先选建筑,再整表改它的各级。
//
// 三块内容:
//   ① **外围七列**(工期 / 工人 / 三项维护 / 耗电 / 容量)—— 与其它定义同一套整表编辑器;
//   ② **三个 JSON 列的条目级编辑**(产出 / 投入 / 建造成本)—— 点行尾「条目」展开,
//      每组一张逐资源小表格,逐格改。这三列才是「这栋楼强不强」的真正数值。
//   ③ **Excel 导出 / 导入**(W13-2)—— 等级上限已数据驱动(升级只看下一级定义行存不存在),
//      批量调数值、给任意建筑补 L4/L5… 新等级行都走「导出 → 改 → 导回」;
//      导入不删行(文件里没有的现有行一律不动),每栋的等级必须从 1 连续。
//
// 数据来源:GET /definitions/building-levels 现在把 editable 数组与三个 JSON 列的**现值**
// 一起下发(已 decode:output/input 是 [{resource, rate_per_min}],cost 是 {资源: 数量},空列为 null / [])。
// 所以可编辑列直接用 editable,条目表格直接用现值渲染 —— 不再借游戏侧端点、也不再靠提交后的 before 反推。
//
// 仍然只允许改**已存在条目**的数值:增删条目会让 §16.1 的资源来源链断链(某个资源变成无源),
// 属结构性变更,走 Seed + 迁移。所以这里不给「新增一条」的入口,空列就明说它是空的。

import { createDefinitionPanel } from '../ui/definition-table.js';
import { api, errorMessage } from '../core/api.js';
import { escapeHtml, formatAmount } from '../core/dom.js';

const JSON_GROUPS = [
    { column: 'output_json', label: '产出 output_json(rate_per_min)' },
    { column: 'input_json', label: '投入 input_json(rate_per_min)' },
    { column: 'cost_json', label: '建造成本 cost_json' },
];

// 资源 code → 中文名(游戏侧只读定义端点,后台没有等价端点;整个会话只取一次)
let resourceNames = null;
async function loadResourceNames() {
    if (resourceNames) return resourceNames;
    const data = await api.get('/api/definitions/resources');
    resourceNames = {};
    (data.resources || []).forEach((r) => { resourceNames[r.code] = r.name; });
    return resourceNames;
}

function resourceLabel(code) {
    const name = resourceNames && resourceNames[code];
    return name ? `${name}(${code})` : code;
}

// ---- Excel 导出 / 导入(W13-2)----
// 走原生 fetch 而不是 api.post:core/api.js 只发 JSON,下载要拿 blob、上传要发 multipart(FormData)。
// CSRF 与 core/api.js 同一套口径:先确保 XSRF-TOKEN cookie 已下发,写请求带 X-XSRF-TOKEN 头

function cookieValue(name) {
    const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
}

async function downloadExport() {
    const res = await fetch('/api/admin/definitions/building-levels/export', { credentials: 'include' });
    if (!res.ok) {
        const json = await res.json().catch(() => ({}));
        throw { status: res.status, error: json.error || 'REQUEST_FAILED', body: json };
    }
    const blob = await res.blob();
    // 文件名跟服务器的 Content-Disposition 走(带导出时间戳),取不到再用兜底名
    const dispo = res.headers.get('Content-Disposition') || '';
    const match = dispo.match(/filename="?([^";]+)"?/);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = match ? match[1] : 'building_levels.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(a.href);
}

async function uploadImport(file, reason) {
    await fetch('/api/csrf-cookie', { credentials: 'include' });
    const token = cookieValue('XSRF-TOKEN');

    const form = new FormData();
    form.append('file', file);
    form.append('reason', reason);

    const headers = { Accept: 'application/json' };
    if (token) headers['X-XSRF-TOKEN'] = token;

    const res = await fetch('/api/admin/definitions/building-levels/import', {
        method: 'POST',
        credentials: 'include',
        headers,
        body: form,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.success === false) {
        throw { status: res.status, error: json.error || 'REQUEST_FAILED', body: json };
    }
    return json.data;
}

// 导入失败时把逐行错误列出来(服务器最多回 50 条,总数写在第一句里)
function importErrorText(err) {
    const rows = err && err.body && Array.isArray(err.body.row_errors) ? err.body.row_errors : null;
    if (!rows || !rows.length) return errorMessage(err);
    const lines = rows.map((r) => `第${r.row}行 ${r.column}:${r.reason}`);
    return `${errorMessage(err)} —— ${lines.join(';')}`;
}

// 把一列的现值摊平成 [{code, current}]。两种形状各自处理:
//   output_json / input_json = [{resource, rate_per_min}, …]
//   cost_json                = {资源: 数量}
// 空列在契约里可能是 null 也可能是 [] / {},一律当成「没有条目」
function entriesOf(row, column) {
    const value = row[column];
    if (!value) return [];

    if (column === 'cost_json') {
        if (Array.isArray(value)) return [];
        return Object.keys(value).map((code) => ({ code, current: value[code] }));
    }

    if (!Array.isArray(value)) return [];
    return value
        .filter((e) => e && e.resource)
        .map((e) => ({ code: e.resource, current: e.rate_per_min }));
}

// 提交成功后就地更新内存里的行:折叠再展开时要显示新值,不能还是旧的
function writeEntry(row, column, code, value) {
    if (column === 'cost_json') {
        if (row.cost_json && !Array.isArray(row.cost_json)) row.cost_json[code] = value;
        return;
    }
    const list = row[column];
    if (!Array.isArray(list)) return;
    const hit = list.find((e) => e && e.resource === code);
    if (hit) hit.rate_per_min = value;
}

// 一组条目表格:该列的现值逐行列出,每行一个「新值 + 提交」。
// 空列不给输入框 —— 那不是「还没加载出来」,而是这一级本来就没有这一列
function groupHtml(group, row) {
    const entries = entriesOf(row, group.column);

    const rows = entries.map((e) => `
        <tr data-entry="${escapeHtml(e.code)}">
            <td>${escapeHtml(resourceLabel(e.code))}</td>
            <td class="json-current">${escapeHtml(formatAmount(e.current))}</td>
            <td><input type="number" class="json-value" step="any" min="0" placeholder="新值"></td>
            <td><button type="button" class="btn btn-ghost btn-sm"
                        data-json-save="1" data-column="${escapeHtml(group.column)}"
                        data-resource="${escapeHtml(e.code)}">提交</button></td>
        </tr>
    `).join('');

    return `
        <div class="json-group">
            <div class="json-title">${escapeHtml(group.label)}</div>
            ${entries.length ? `
                <table class="map-editor">
                    <thead><tr><th>资源</th><th>当前值</th><th>新值</th><th></th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            ` : '<div class="muted">该级这一列没有条目(新增条目属结构性变更,走迁移)</div>'}
        </div>
    `;
}

async function renderExpand(row, td, ctx) {
    td.innerHTML = '<div class="muted">加载条目…</div>';
    try {
        // 只为把 code 翻成中文名(后台没有资源定义端点,借游戏侧的只读端点,整会话取一次)
        await loadResourceNames();

        td.innerHTML = `
            <div class="json-note muted">
                只能修改<b>已存在条目</b>的数值:增删条目会让资源来源链断链,属结构性变更,走迁移。
            </div>
            <div class="json-groups">${JSON_GROUPS.map((g) => groupHtml(g, row)).join('')}</div>
        `;

        td.addEventListener('click', (e) => onEntrySubmit(e, row, ctx));
    } catch (err) {
        td.innerHTML = `<div class="auth-error">条目加载失败:${escapeHtml(errorMessage(err))}</div>`;
    }
}

async function onEntrySubmit(e, row, ctx) {
    const button = e.target.closest('[data-json-save]');
    if (!button) return;

    ctx.clearError();
    ctx.clearResult();

    const reason = ctx.reason();
    if (reason === null) return;

    const tr = button.closest('tr');
    const resource = button.dataset.resource;
    const input = tr.querySelector('.json-value');
    const text = input.value.trim();
    if (text === '') {
        ctx.setError('请填写新值');
        return;
    }
    const value = Number(text);
    if (!Number.isFinite(value) || value < 0) {
        ctx.setError('新值必须是非负数字');
        return;
    }

    button.disabled = true;
    try {
        const data = await api.post('/api/admin/definitions/building-level-json', {
            building_id: row.building_id,
            level: Number(row.level),
            column: button.dataset.column,
            resource,
            value,
            reason,
        });
        const cell = tr.querySelector('.json-current');
        if (cell) cell.textContent = formatAmount(data.after);
        input.value = '';
        // 内存里的行也要跟着走:折叠再展开是按 row 重绘的,不更新就会显示回旧值
        writeEntry(row, button.dataset.column, resource, data.after);
        ctx.setResult(`${row.building_id} L${row.level} ${button.dataset.column}.${resource}:${formatAmount(data.before)} → ${formatAmount(data.after)}(新版本号 ${data.version})`);
    } catch (err) {
        ctx.setError(errorMessage(err));
    } finally {
        button.disabled = false;
    }
}

export const buildingLevelsPanel = createDefinitionPanel({
    id: 'building-level',
    label: '建筑等级',
    title: '建筑等级定义(等级无上限,定义到几级就能升到几级)',
    hint: '先在下面选一栋建筑,再逐格改它各级的数值。行尾「条目」可展开该级的<b>产出 / 投入 / 建造成本</b>三组逐资源小表格 —— 那才是这栋楼强不强的真正数值。批量改动与<b>补新等级行</b>(L4、L5…)走「导出 Excel → 改 → 导入」:导入不删行,每栋的等级必须从 1 连续。',
    listUrl: (ctx) => (ctx.state.buildingId
        ? '/api/admin/definitions/building-levels?buildingId=' + encodeURIComponent(ctx.state.buildingId)
        : null),
    emptyHint: '请先选择建筑',
    listKey: 'levels',
    editUrl: '/api/admin/definitions/building-level',
    idFields: [
        { row: 'building_id', param: 'buildingId', label: '建筑' },
        { row: 'level', param: 'level', label: '等级', numeric: true },
    ],
    readonlyColumns: [],
    labels: {
        duration_seconds: '建造耗时(秒)',
        worker_required: '所需工人',
        maintenance_money_per_min: '维护-资金/分',
        maintenance_food_per_min: '维护-粮食/分',
        maintenance_fuel_per_min: '维护-燃料/分',
        power_per_min: '耗电/分',
        capacity: '容量',
    },
    fieldMeta: {
        duration_seconds: { integer: true, min: 0, max: 604800 },
        worker_required: { integer: true, min: 0, max: 10000 },
        maintenance_money_per_min: { min: 0, max: 1000000 },
        maintenance_food_per_min: { min: 0, max: 1000000 },
        maintenance_fuel_per_min: { min: 0, max: 1000000 },
        power_per_min: { min: 0, max: 1000000 },
        capacity: { min: 0, max: 1000000000 },
    },
    expand: { label: '条目', render: renderExpand },
    // 建筑选择器:94 行的下拉,选完立刻重新拉这栋楼的各级。
    // 右侧是 Excel 导出 / 导入(W13-2):导出全表 → 线下改 / 补新等级行 → 导回
    toolbar(node, ctx) {
        node.innerHTML = `
            <label class="inline-field">
                <span class="muted">建筑</span>
                <select class="bl-building"><option value="">加载中…</option></select>
            </label>
            <button type="button" class="btn btn-ghost bl-export">导出 Excel</button>
            <label class="inline-field">
                <span class="muted">导入文件</span>
                <input type="file" class="bl-import-file" accept=".xlsx">
            </label>
            <button type="button" class="btn btn-ghost bl-import">导入 Excel</button>
        `;
        const select = node.querySelector('.bl-building');
        api.get('/api/admin/definitions/buildings').then((data) => {
            const list = data.buildings || [];
            select.innerHTML = '<option value="">— 请选择建筑 —</option>' + list.map((b) => `
                <option value="${escapeHtml(b.building_id)}">${escapeHtml(b.building_id)} · ${escapeHtml(b.name)}(${escapeHtml(b.era_key)} / ${escapeHtml(b.category)})</option>
            `).join('');
        }).catch((err) => {
            select.innerHTML = '<option value="">加载失败</option>';
            ctx.setError(errorMessage(err));
        });
        select.addEventListener('change', () => {
            ctx.state.buildingId = select.value;
            ctx.reload();
        });

        // 导出:同源 session 直接下载,失败时把 JSON 错误翻成中文提示
        const exportBtn = node.querySelector('.bl-export');
        exportBtn.addEventListener('click', async () => {
            ctx.clearError();
            ctx.clearResult();
            exportBtn.disabled = true;
            try {
                await downloadExport();
                ctx.setResult('已导出全部建筑等级定义(building_id、名称、等级、七个数值列与三个 JSON 列)');
            } catch (err) {
                ctx.setError(errorMessage(err));
            } finally {
                exportBtn.disabled = false;
            }
        });

        // 导入:reason 必填(右上角同一个输入框,与逐格编辑同一条纪律);
        // 成功显示 {更新/新增/未变/涉及建筑} 摘要,失败把逐行错误列出来
        const importBtn = node.querySelector('.bl-import');
        const fileInput = node.querySelector('.bl-import-file');
        importBtn.addEventListener('click', async () => {
            ctx.clearError();
            ctx.clearResult();

            const reason = ctx.reason();
            if (reason === null) return;

            const file = fileInput.files && fileInput.files[0];
            if (!file) {
                ctx.setError('请先选择要导入的 .xlsx 文件');
                return;
            }

            importBtn.disabled = true;
            try {
                const data = await uploadImport(file, reason);
                if (!data.updated && !data.inserted) {
                    ctx.setResult(`导入完成:文件与当前数据一致,没有任何改动(未变 ${data.unchanged} 行)`);
                } else {
                    const buildings = (data.buildings_affected || []).join('、');
                    ctx.setResult(`导入完成:更新 ${data.updated} 行 / 新增 ${data.inserted} 行 / 未变 ${data.unchanged} 行,涉及建筑:${buildings}(新版本号 ${data.version})`);
                }
                fileInput.value = '';
                // 当前正看着的建筑可能刚被改过:重拉一次,避免表里显示旧值
                if (ctx.state.buildingId) ctx.reload();
            } catch (err) {
                ctx.setError(importErrorText(err));
            } finally {
                importBtn.disabled = false;
            }
        });
    },
});
