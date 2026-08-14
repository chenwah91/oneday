// 市场定义(v3.2 §8):基础价 / 价格区间 / 波动率 / 弹性 / 费率 / 流动性 / 交易模式。
//
// trade_mode 只在 spot ↔ non_tradeable 之间互切(单资源停市 / 复市);
// 现状是 capacity_contract(电力)的行,渲染成只读 —— 给一个必然 422 的下拉没有意义。
// 全市场级的开关与系数(停市 / 手续费倍率 / 滑点 / 成交量上限)在「规则参数」面板,不在这里。
//
// W14-B:工具栏加「新增市场定义」表单。resource_id 下拉只列**尚未有市场定义的资源**:
// 全量资源清单借游戏侧只读定义端点 /api/definitions/resources(building-levels 面板同款借用,
// 不新加后端端点),减去本面板整表里已有的 resource_id。resource_id 必须已存在于
// resource_definition(不准借道发明新资源)、唯一性、min ≤ base ≤ max —— 都由服务端权威校验。

import { createDefinitionPanel } from '../ui/definition-table.js';
import { api, errorMessage } from '../core/api.js';
import { escapeHtml } from '../core/dom.js';

// 新增表单的数值字段:label 与预检规则一处定义(上限对齐后端 MARKET_FIELD_MAX)
const MARKET_NUM_FIELDS = [
    { key: 'base_price', label: '基础价', meta: { min: 0, max: 1000000 } },
    { key: 'min_price', label: '价格下限', meta: { min: 0, max: 1000000 } },
    { key: 'max_price', label: '价格上限', meta: { min: 0, max: 1000000 } },
    { key: 'volatility', label: '波动率(0~1)', meta: { min: 0, max: 1 } },
    { key: 'elasticity', label: '弹性(0~10)', meta: { min: 0, max: 10 } },
    { key: 'fee_rate', label: '费率(0~0.9)', meta: { min: 0, max: 0.9 } },
    { key: 'base_liquidity', label: '流动性', meta: { min: 0, max: 1000000000 } },
];

// 全量资源清单缓存(游戏侧只读定义端点,定义很少变,会话内取一次)
let resourcesCache = null;
async function loadResources() {
    if (resourcesCache) return resourcesCache;
    const data = await api.get('/api/definitions/resources');
    resourcesCache = data.resources || [];
    return resourcesCache;
}

// 数字输入的体验级预检(服务端才是权威);失败返回 null 并写好错误信息
function marketNumberOf(input, label, meta, ctx) {
    const text = input.value.trim();
    if (text === '') { ctx.setError(`${label} 不能留空`); return null; }
    const value = Number(text);
    if (!Number.isFinite(value)) { ctx.setError(`${label} 必须是有效数字`); return null; }
    if (value < 0) { ctx.setError(`${label} 不能是负数`); return null; }
    if (meta && meta.max !== undefined && value > meta.max) { ctx.setError(`${label} 不能大于 ${meta.max}`); return null; }
    return value;
}

function addFormHtml() {
    const numbers = MARKET_NUM_FIELDS.map((f) => `
        <div class="auth-field">
            <label class="auth-label">${escapeHtml(f.label)}</label>
            <input type="number" class="ma-num" data-field="${escapeHtml(f.key)}" step="any" min="0" max="${f.meta.max}">
        </div>
    `).join('');

    return `
        <button type="button" class="btn btn-ghost market-add-toggle">新增市场定义</button>
        <form class="def-form market-add-form hidden">
            <div class="def-row">
                <div class="auth-field">
                    <label class="auth-label">资源(只列尚未有市场定义的资源)</label>
                    <select class="ma-resource"></select>
                </div>
                <div class="auth-field">
                    <label class="auth-label">交易模式</label>
                    <select class="ma-trade-mode">
                        <option value="spot">spot(现货可交易)</option>
                        <option value="non_tradeable">non_tradeable(停市)</option>
                    </select>
                </div>
                <div class="auth-field">
                    <label class="auth-label">市场类别 market_category</label>
                    <select class="ma-category"></select>
                </div>
            </div>
            <div class="def-row">${numbers}</div>
            <div class="auth-field">
                <label class="auth-label">备注 note(可留空)</label>
                <input class="ma-note" type="text" maxlength="191" placeholder="这一行定义的来历 / 依据">
            </div>
            <div class="auth-field">
                <label class="auth-label">修改原因(必填,2-80 字)</label>
                <input class="ma-reason" type="text" maxlength="80" placeholder="修改原因(必填,2-80 字)">
            </div>
            <div class="def-actions">
                <button type="submit" class="btn btn-primary ma-submit">提交新增</button>
                <button type="button" class="btn btn-ghost ma-cancel">收起</button>
            </div>
        </form>
    `;
}

// 每次展开重算候选资源:全量资源 − 本面板整表里已有的 resource_id。
// market_category 同时按整表 distinct 填 —— 它与 resource_definition.category 语义不同
// (那是 6 个资源类,这是 11 个市场分组),**派生不出来**,只能让运营在库内已有分组里选;
// 想要新分组走迁移(与后端 Fail Closed 校验同一口径)
async function fillAddForm(form, ctx) {
    const catSelect = form.querySelector('.ma-category');
    const categories = [];
    ctx.rows.forEach((r) => {
        if (r.market_category && categories.indexOf(r.market_category) === -1) categories.push(r.market_category);
    });
    categories.sort();
    catSelect.innerHTML = categories.length
        ? categories.map((c) => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('')
        : '<option value="">无可用类别</option>';

    const select = form.querySelector('.ma-resource');
    select.innerHTML = '<option value="">加载资源清单…</option>';
    try {
        const all = await loadResources();
        const taken = {};
        ctx.rows.forEach((r) => { taken[r.resource_id] = true; });
        const candidates = all.filter((r) => !taken[r.code]);
        if (!candidates.length) {
            select.innerHTML = '<option value="">全部资源都已有市场定义,无可新增</option>';
            return;
        }
        select.innerHTML = '<option value="">— 请选择资源 —</option>' + candidates.map((r) =>
            `<option value="${escapeHtml(r.code)}">${escapeHtml(r.name)}(${escapeHtml(r.code)})</option>`).join('');
    } catch (err) {
        select.innerHTML = '<option value="">资源清单加载失败</option>';
        ctx.setError(errorMessage(err));
    }
}

async function onAddSubmit(e, form, ctx) {
    e.preventDefault();
    ctx.clearError();
    ctx.clearResult();

    const resource = form.querySelector('.ma-resource').value;
    const tradeMode = form.querySelector('.ma-trade-mode').value;
    const category = form.querySelector('.ma-category').value;
    const reason = form.querySelector('.ma-reason').value.trim();

    if (!resource) { ctx.setError('请先选择资源'); return; }
    if (!category) { ctx.setError('请先选择市场类别'); return; }
    if (reason.length < 2) { ctx.setError('请填写修改原因(至少 2 字)'); return; }

    const values = {
        resource_id: resource,
        market_category: category,
        trade_mode: tradeMode,
        note: form.querySelector('.ma-note').value.trim(),
    };
    for (const f of MARKET_NUM_FIELDS) {
        const input = form.querySelector(`.ma-num[data-field="${f.key}"]`);
        const value = marketNumberOf(input, f.label, f.meta, ctx);
        if (value === null) return;
        values[f.key] = value;
    }

    // 跨字段预检(与整行自洽的服务端校验同一口径,省一次 422 往返)
    if (!(values.min_price <= values.base_price && values.base_price <= values.max_price)) {
        ctx.setError('价格必须满足:下限 ≤ 基础价 ≤ 上限');
        return;
    }
    if (tradeMode === 'spot' && values.base_price <= 0) {
        ctx.setError('现货(spot)的基础价必须大于 0');
        return;
    }

    const submit = form.querySelector('.ma-submit');
    submit.disabled = true;
    try {
        const data = await api.post('/api/admin/definitions/market/add', { reason, values });
        form.reset();
        form.classList.add('hidden');
        const toggle = form.parentElement.querySelector('.market-add-toggle');
        if (toggle) toggle.textContent = '新增市场定义';
        // 先整表重拉再写结果条:load() 开头会清结果条,顺序反了成功信息会被立刻擦掉
        await ctx.reload();
        ctx.setResult(`已新增市场定义 ${data.resource_id}(新版本号 ${data.version})`);
    } catch (err) {
        ctx.setError(errorMessage(err));
    } finally {
        submit.disabled = false;
    }
}

export const marketPanel = createDefinitionPanel({
    id: 'market',
    label: '市场',
    title: '市场定义(整表)',
    hint: '改一行基础价就是改全服价格。<b>交易模式</b>可在 spot(现货)与 non_tradeable(停市)之间互切,用于单资源止血;电力(capacity_contract)不可切换。改动后整行必须自洽:min_price 不得大于 max_price,现货的 base_price 必须大于 0。「新增市场定义」只对<b>尚未有市场定义的资源</b>开放。',
    listUrl: '/api/admin/definitions/market',
    listKey: 'market',
    editUrl: '/api/admin/definitions/market',
    idFields: [{ row: 'resource_id', param: 'resource_code', label: '资源 code' }],
    // note 自 W14 起可编辑,从只读列挪走(同一列既只读又可编辑会在表里出现两遍)
    readonlyColumns: [
        { key: 'rs_code', label: 'RS' },
        { key: 'market_category', label: '类别' },
        { key: 'first_era', label: '首现时代' },
    ],
    labels: {
        base_price: '基础价',
        min_price: '价格下限',
        max_price: '价格上限',
        volatility: '波动率',
        elasticity: '弹性',
        fee_rate: '费率',
        base_liquidity: '流动性',
        trade_mode: '交易模式',
        note: '备注',
    },
    fieldMeta: {
        // note 是文本列(这一行定义的来历 / 依据),可留空;数字框填不进字
        note: { text: true, maxLength: 191 },
        base_price: { min: 0, max: 1000000 },
        min_price: { min: 0, max: 1000000 },
        max_price: { min: 0, max: 1000000 },
        volatility: { min: 0, max: 1 },
        elasticity: { min: 0, max: 10 },
        fee_rate: { min: 0, max: 0.9 },
        base_liquidity: { min: 0, max: 1000000000 },
        trade_mode: {
            options: [
                { value: 'spot', label: 'spot(现货可交易)' },
                { value: 'non_tradeable', label: 'non_tradeable(停市)' },
            ],
        },
    },
    search: { placeholder: '按资源 code / RS / 类别筛选', fields: ['resource_id', 'rs_code', 'market_category'] },
    toolbar(node, ctx) {
        node.innerHTML = addFormHtml();

        const toggle = node.querySelector('.market-add-toggle');
        const form = node.querySelector('.market-add-form');

        toggle.addEventListener('click', () => {
            const show = form.classList.contains('hidden');
            if (show) fillAddForm(form, ctx);
            form.classList.toggle('hidden', !show);
            toggle.textContent = show ? '收起新增表单' : '新增市场定义';
        });
        form.querySelector('.ma-cancel').addEventListener('click', () => {
            form.classList.add('hidden');
            toggle.textContent = '新增市场定义';
        });
        form.addEventListener('submit', (e) => onAddSubmit(e, form, ctx));
    },
});
