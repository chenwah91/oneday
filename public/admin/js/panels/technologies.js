// 科技定义(v3.2 §4):存量行只改知识成本与研究时长。
// 前置 / 解锁 / 时代 / 分支四列只读 —— 那是科技树拓扑,存量行改动会造出环或死锁(见后端 TECH_EDITABLE 注释)。
//
// W14-B 两个新增:
//   ① 列表按 branch 分组显示:countSuffix 是渲染器渲染完成后唯一回调进面板的钩子,
//      借它把已渲染的行按分支归堆重排、插入 group-row 标题行(行元素是移动不是重建,
//      _row 绑定与未提交的输入原样保留)—— 不动 ui/definition-table.js 是硬纪律;
//   ② 「新增科技」表单:全字段 + era/branch 下拉 + 前置科技/解锁建筑多选,
//      POST /definitions/technology/add(W14-A 契约),成功后整表刷新。
//      新增行的拓扑(前置/解锁 ID 存在性、tech_id 唯一)由服务端权威校验,前端只做体验级预检。

import { createDefinitionPanel } from '../ui/definition-table.js';
import { api, errorMessage } from '../core/api.js';
import { escapeHtml } from '../core/dom.js';

// branch code → 中文名。后台前端没有公共枚举映射模块(游戏侧 core/enum-names.js 按目录纪律不跨引),
// 按 docs/templates/enum-code-map.md §5 的 branch 表放一份面板内小映射 —— 改动时与该文档同步
const BRANCH_NAMES = {
    survival_agriculture: '生存/农业',
    industry_processing: '工业/加工',
    governance_science_trade: '治理/科研/商贸',
    storage_transport: '仓储/运输',
    defense: '国防',
};
const BRANCH_ORDER = Object.keys(BRANCH_NAMES);

// era_key 是罗马数字,字母序会把 IX 排到 II 前面,固定顺序表排序
const ERA_ORDER = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];

function branchLabel(code) {
    return BRANCH_NAMES[code] ? `${BRANCH_NAMES[code]}(${code})` : (code || '未分组');
}

// JSON 数组列的紧凑显示:["TECH_I_SUST"] → TECH_I_SUST
function jsonList(value) {
    if (!value) return '<span class="muted">-</span>';
    let list = value;
    if (typeof value === 'string') {
        try { list = JSON.parse(value); } catch (e) { return escapeHtml(value); }
    }
    if (!Array.isArray(list) || !list.length) return '<span class="muted">-</span>';
    return escapeHtml(list.join(' / '));
}

// toolbar 捕获的面板 ctx:countSuffix(分组重排)要用。toolbar 在首次 load 前执行,顺序安全
let panelCtx = null;

// 解锁建筑多选的数据源:后台建筑定义端点(building-limit 面板同款),定义很少变,会话内取一次
let buildingsCache = null;
async function loadBuildings() {
    if (buildingsCache) return buildingsCache;
    const data = await api.get('/api/admin/definitions/buildings');
    buildingsCache = data.buildings || [];
    return buildingsCache;
}

// ---------- 分组显示 ----------
// 把 tbody 里已渲染好的行按 branch 归堆:每组前插一行 group-row 标题(规则参数页同款样式)。
// 搜索过滤只认 tr[data-search],标题行不参与显隐 —— 过滤时空组标题会留着,属可接受的简版行为
function regroupByBranch(ctx) {
    const body = ctx.nodes.body;
    const rows = Array.from(body.querySelectorAll('tr[data-row]'));
    if (!rows.length) return 0;

    const byBranch = new Map();
    rows.forEach((tr) => {
        const branch = (tr._row && tr._row.branch) || '';
        if (!byBranch.has(branch)) byBranch.set(branch, []);
        byBranch.get(branch).push(tr);
    });

    // 已知分支按固定顺序;库里冒出的未知分支排最后,绝不吞行
    const order = BRANCH_ORDER.filter((b) => byBranch.has(b))
        .concat(Array.from(byBranch.keys()).filter((b) => BRANCH_ORDER.indexOf(b) === -1));

    // 组内按时代序:后端按 tech_id 字符串排,罗马数字会排成 I/II/III/IV/IX/V…,
    // 而运营读这张表就是顺着时代读的,所以重排时一并按 ERA_ORDER 归位(未知时代排组末,绝不吞行)
    const eraRank = (tr) => {
        const idx = ERA_ORDER.indexOf((tr._row && tr._row.era_key) || '');
        return idx === -1 ? ERA_ORDER.length : idx;
    };

    const colCount = ctx.nodes.head.children.length;
    order.forEach((branch) => {
        const header = document.createElement('tr');
        header.className = 'group-row';
        header.innerHTML = `<td colspan="${colCount}">${escapeHtml(branchLabel(branch))} · ${byBranch.get(branch).length} 条</td>`;
        body.appendChild(header);
        byBranch.get(branch).sort((a, b) => eraRank(a) - eraRank(b)).forEach((tr) => body.appendChild(tr));
    });
    return order.length;
}

// ---------- 新增表单 ----------

// 数字输入的体验级预检(服务端 allowlist + 范围校验才是权威);失败返回 null 并写好错误信息
function numberOf(input, label, meta, ctx) {
    const text = input.value.trim();
    if (text === '') { ctx.setError(`${label} 不能留空`); return null; }
    const value = Number(text);
    if (!Number.isFinite(value)) { ctx.setError(`${label} 必须是有效数字`); return null; }
    if (value < 0) { ctx.setError(`${label} 不能是负数`); return null; }
    if (meta && meta.integer && Math.floor(value) !== value) { ctx.setError(`${label} 必须是整数`); return null; }
    if (meta && meta.max !== undefined && value > meta.max) { ctx.setError(`${label} 不能大于 ${meta.max}`); return null; }
    return value;
}

function selectedValues(select) {
    return Array.from(select.selectedOptions).map((o) => o.value);
}

function addFormHtml() {
    return `
        <button type="button" class="btn btn-ghost tech-add-toggle">新增科技</button>
        <form class="def-form tech-add-form hidden">
            <div class="def-row">
                <div class="auth-field">
                    <label class="auth-label">tech_id</label>
                    <input class="ta-tech-id" type="text" maxlength="32" placeholder="如 TECH_II_IRON">
                </div>
                <div class="auth-field">
                    <label class="auth-label">名称</label>
                    <input class="ta-name" type="text" maxlength="64" placeholder="中文显示名">
                </div>
                <div class="auth-field">
                    <label class="auth-label">时代 era_key</label>
                    <select class="ta-era"></select>
                </div>
                <div class="auth-field">
                    <label class="auth-label">分支 branch</label>
                    <select class="ta-branch"></select>
                </div>
            </div>
            <div class="def-row">
                <div class="auth-field">
                    <label class="auth-label">知识成本(整数)</label>
                    <input class="ta-cost" type="number" step="1" min="0" max="10000000">
                </div>
                <div class="auth-field">
                    <label class="auth-label">研究时长(分)</label>
                    <input class="ta-minutes" type="number" step="any" min="0" max="10080">
                </div>
            </div>
            <div class="def-row">
                <div class="auth-field">
                    <label class="auth-label">前置科技(多选,Ctrl/Cmd 点选;不选 = 无前置)</label>
                    <select class="ta-prereq" multiple size="8"></select>
                </div>
                <div class="auth-field">
                    <label class="auth-label">解锁建筑(多选;不选 = 不解锁建筑)</label>
                    <select class="ta-unlock" multiple size="8"></select>
                </div>
            </div>
            <div class="auth-field">
                <label class="auth-label">修改原因(必填,2-80 字)</label>
                <input class="ta-reason" type="text" maxlength="80" placeholder="修改原因(必填,2-80 字)">
            </div>
            <div class="def-actions">
                <button type="submit" class="btn btn-primary ta-submit">提交新增</button>
                <button type="button" class="btn btn-ghost ta-cancel">收起</button>
            </div>
        </form>
    `;
}

// 每次展开都用最新行数据重填下拉(新增成功后整表已刷新,前置清单要含刚加的那条)
function fillAddForm(form, ctx) {
    const eraSelect = form.querySelector('.ta-era');
    const eras = [];
    ctx.rows.forEach((r) => { if (r.era_key && eras.indexOf(r.era_key) === -1) eras.push(r.era_key); });
    eras.sort((a, b) => ERA_ORDER.indexOf(a) - ERA_ORDER.indexOf(b));
    eraSelect.innerHTML = eras.map((e) => `<option value="${escapeHtml(e)}">${escapeHtml(e)}</option>`).join('');

    // 分支给全量 5 条(新科技可以落在任何分支,不局限于库内 distinct)
    form.querySelector('.ta-branch').innerHTML = BRANCH_ORDER.map((b) =>
        `<option value="${escapeHtml(b)}">${escapeHtml(branchLabel(b))}</option>`).join('');

    form.querySelector('.ta-prereq').innerHTML = ctx.rows.map((r) =>
        `<option value="${escapeHtml(r.tech_id)}">${escapeHtml(r.tech_id)} · ${escapeHtml(r.name)}</option>`).join('');

    const unlockSelect = form.querySelector('.ta-unlock');
    unlockSelect.innerHTML = '<option disabled>加载建筑清单…</option>';
    loadBuildings().then((list) => {
        unlockSelect.innerHTML = list.map((b) =>
            `<option value="${escapeHtml(b.building_id)}">${escapeHtml(b.building_id)} · ${escapeHtml(b.name)}(${escapeHtml(b.era_key)})</option>`).join('');
    }).catch((err) => {
        unlockSelect.innerHTML = '<option disabled>建筑清单加载失败</option>';
        ctx.setError(errorMessage(err));
    });
}

async function onAddSubmit(e, form, ctx) {
    e.preventDefault();
    ctx.clearError();
    ctx.clearResult();

    const techId = form.querySelector('.ta-tech-id').value.trim();
    const name = form.querySelector('.ta-name').value.trim();
    const reason = form.querySelector('.ta-reason').value.trim();

    if (!techId) { ctx.setError('请填写 tech_id'); return; }
    if (!name) { ctx.setError('请填写名称'); return; }
    if (reason.length < 2) { ctx.setError('请填写修改原因(至少 2 字)'); return; }

    const cost = numberOf(form.querySelector('.ta-cost'), '知识成本', { integer: true, max: 10000000 }, ctx);
    if (cost === null) return;
    const minutes = numberOf(form.querySelector('.ta-minutes'), '研究时长(分)', { max: 10080 }, ctx);
    if (minutes === null) return;

    const submit = form.querySelector('.ta-submit');
    submit.disabled = true;
    try {
        const data = await api.post('/api/admin/definitions/technology/add', {
            reason,
            values: {
                tech_id: techId,
                name,
                era_key: form.querySelector('.ta-era').value,
                branch: form.querySelector('.ta-branch').value,
                knowledge_cost: cost,
                research_minutes: minutes,
                prerequisite_tech_ids: selectedValues(form.querySelector('.ta-prereq')),
                unlock_building_ids: selectedValues(form.querySelector('.ta-unlock')),
            },
        });
        form.reset();
        form.classList.add('hidden');
        const toggle = form.parentElement.querySelector('.tech-add-toggle');
        if (toggle) toggle.textContent = '新增科技';
        // 先整表重拉再写结果条:load() 开头会清结果条,顺序反了成功信息会被立刻擦掉
        await ctx.reload();
        ctx.setResult(`已新增科技 ${data.tech_id}(新版本号 ${data.version})`);
    } catch (err) {
        ctx.setError(errorMessage(err));
    } finally {
        submit.disabled = false;
    }
}

export const technologiesPanel = createDefinitionPanel({
    id: 'tech',
    label: '科技',
    title: '科技定义(按分支分组)',
    hint: '存量行只开放<b>知识成本</b>与<b>研究时长</b>两列(前置 / 解锁 / 时代 / 分支是科技树拓扑,改动会造出环或死锁,一律走 Seed + 迁移)。「新增科技」走上方表单:tech_id 唯一、前置与解锁 ID 的存在性由服务端校验。',
    listUrl: '/api/admin/definitions/technologies',
    listKey: 'technologies',
    editUrl: '/api/admin/definitions/technology',
    idFields: [{ row: 'tech_id', param: 'tech_id', label: 'tech_id' }],
    readonlyColumns: [
        { key: 'name', label: '名称' },
        { key: 'era_key', label: '时代' },
        { key: 'branch', label: '分支', format: (r) => escapeHtml(branchLabel(r.branch)) },
        { key: 'prerequisite_tech_ids', label: '前置', format: (r) => jsonList(r.prerequisite_tech_ids), wrap: true },
        { key: 'unlock_building_ids', label: '解锁建筑', format: (r) => jsonList(r.unlock_building_ids), wrap: true },
    ],
    labels: {
        knowledge_cost: '知识成本',
        research_minutes: '研究时长(分)',
    },
    fieldMeta: {
        knowledge_cost: { integer: true, min: 0, max: 10000000 },
        research_minutes: { min: 0, max: 10080 },
    },
    search: { placeholder: '按 tech_id / 名称 / 分支筛选', fields: ['tech_id', 'name', 'branch', 'era_key'] },
    toolbar(node, ctx) {
        panelCtx = ctx;
        node.innerHTML = addFormHtml();

        const toggle = node.querySelector('.tech-add-toggle');
        const form = node.querySelector('.tech-add-form');

        toggle.addEventListener('click', () => {
            const show = form.classList.contains('hidden');
            if (show) fillAddForm(form, ctx);
            form.classList.toggle('hidden', !show);
            toggle.textContent = show ? '收起新增表单' : '新增科技';
        });
        form.querySelector('.ta-cancel').addEventListener('click', () => {
            form.classList.add('hidden');
            toggle.textContent = '新增科技';
        });
        form.addEventListener('submit', (e) => onAddSubmit(e, form, ctx));
    },
    // 渲染完成后的唯一钩子:借它做分组重排(见文件头说明)
    countSuffix() {
        if (!panelCtx) return '';
        const groups = regroupByBranch(panelCtx);
        return groups ? `,按分支分 ${groups} 组` : '';
    },
});
