// NPC 定义(v3.2 §6.3 的 150 行):工资 / 口粮 / 初始技能 / 等级 / 特性强度倍率。
//
// 改成与其它定义同款的**整表**(此前是「填 id → 选字段 → 填值」的单条表单:
// 150 行里横向比较不了「这一档工资是不是偏了」,而那正是调数值时唯一要回答的问题)。
// 150 行整表 + 右上角搜索框按 npc_id / 中文名 / 类别 / 稀有度定位。
//
// 可编辑列一律跟接口下发的 editable 走(本面板没有写死列集):W14-A 把后端 allowlist 扩到
// 更多列时,这里自动多列,零改动跟随。labels / fieldMeta 只是给已知列配中文名与预检,
// 没配到的新列会以原始键名 + 默认数字框出现 —— 能用,只是不够漂亮。
//
// W14-B:工具栏加「新增 NPC」表单(全列)。category / rarity / min_era / recruit_source /
// primary_skill_id 下拉取库内 distinct(150 行覆盖了所有在用取值);npc_id 唯一性、
// trait_json 结构合法性由服务端权威校验,前端只做体验级预检。

import { createDefinitionPanel } from '../ui/definition-table.js';
import { api, errorMessage } from '../core/api.js';
import { escapeHtml } from '../core/dom.js';

// rarity code → 中文名(与游戏侧 core/enum-names.js 的 NPC_RARITY_NAMES 同一份取值,
// 后台前端没有公共枚举映射模块,按目录纪律在面板里放一份小映射,改动时两边同步)
const RARITY_NAMES = {
    common: '普通',
    uncommon: '优秀',
    rare: '稀有',
    epic: '史诗',
    legendary: '传说',
};

// era_key 是罗马数字,字母序会把 IX 排到 II 前面,固定顺序表排序
const ERA_ORDER = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];

// 新增表单的数值字段(全列中的数字部分):label 与预检规则一处定义
const NPC_NUM_FIELDS = [
    { key: 'initial_skill_value', label: '初始技能值', meta: { integer: true, min: 0 } },
    { key: 'initial_skill_level', label: '初始等级(1~10)', meta: { integer: true, min: 1, max: 10 } },
    { key: 'max_level', label: '上限等级(1~10)', meta: { integer: true, min: 1, max: 10 } },
    { key: 'wage_per_min', label: '工资/分', meta: { min: 0 } },
    { key: 'food_per_min', label: '口粮/分', meta: { min: 0 } },
    { key: 'trait_multiplier', label: '特性强度倍率(0~10)', meta: { min: 0, max: 10 } },
];

// 整表逐格编辑用的枚举取值(W14:后端把这四列开放成可编辑后,行内控件必须是下拉而不是数字框)。
// 与库内 150 行的 distinct 一致 —— 服务端按同一口径 Fail Closed 校验,想要新取值走迁移;
// 这里写死是因为 fieldMeta 是静态配置,渲染时拿不到 ctx.rows(新增表单那边才用 distinctOf)
const NPC_CATEGORIES = [
    'administration', 'agriculture', 'commerce', 'construction', 'education', 'engineering',
    'gathering', 'leadership', 'logistics', 'medicine', 'military', 'mining',
    'processing', 'production', 'research',
];
const NPC_SOURCES = ['event', 'initial', 'natural_growth', 'recruit'];

// code 列表 → fieldMeta 的 options(rarity 附中文名)
function optionsOf(key, codes) {
    return codes.map((c) => ({ value: c, label: optionLabel(key, c) }));
}

// 下拉字段:取库内 distinct(rarity 附中文名)
const NPC_SELECT_FIELDS = [
    { key: 'category', label: '类别' },
    { key: 'rarity', label: '稀有度' },
    { key: 'min_era', label: '最低时代' },
    { key: 'recruit_source', label: '招募来源' },
    { key: 'primary_skill_id', label: '主技能' },
];

// 库内 distinct:150 行定义就在面板内存里,不必开新端点
function distinctOf(rows, key) {
    const seen = [];
    rows.forEach((r) => {
        const v = r[key];
        if (v !== null && v !== undefined && v !== '' && seen.indexOf(v) === -1) seen.push(v);
    });
    if (key === 'min_era') {
        seen.sort((a, b) => ERA_ORDER.indexOf(a) - ERA_ORDER.indexOf(b));
    } else {
        seen.sort();
    }
    return seen;
}

function optionLabel(key, value) {
    if (key === 'rarity' && RARITY_NAMES[value]) return `${value}(${RARITY_NAMES[value]})`;
    return String(value);
}

// 数字输入的体验级预检(服务端才是权威);失败返回 null 并写好错误信息
function npcNumberOf(input, label, meta, ctx) {
    const text = input.value.trim();
    if (text === '') { ctx.setError(`${label} 不能留空`); return null; }
    const value = Number(text);
    if (!Number.isFinite(value)) { ctx.setError(`${label} 必须是有效数字`); return null; }
    if (value < 0) { ctx.setError(`${label} 不能是负数`); return null; }
    if (meta && meta.integer && Math.floor(value) !== value) { ctx.setError(`${label} 必须是整数`); return null; }
    if (meta && meta.min !== undefined && value < meta.min) { ctx.setError(`${label} 不能小于 ${meta.min}`); return null; }
    if (meta && meta.max !== undefined && value > meta.max) { ctx.setError(`${label} 不能大于 ${meta.max}`); return null; }
    return value;
}

function addFormHtml() {
    const selects = NPC_SELECT_FIELDS.map((f) => `
        <div class="auth-field">
            <label class="auth-label">${escapeHtml(f.label)} ${escapeHtml(f.key)}</label>
            <select class="na-select" data-field="${escapeHtml(f.key)}"></select>
        </div>
    `).join('');
    const numbers = NPC_NUM_FIELDS.map((f) => `
        <div class="auth-field">
            <label class="auth-label">${escapeHtml(f.label)}</label>
            <input type="number" class="na-num" data-field="${escapeHtml(f.key)}"
                   step="${f.meta.integer ? '1' : 'any'}" min="${f.meta.min !== undefined ? f.meta.min : 0}"${f.meta.max !== undefined ? ` max="${f.meta.max}"` : ''}>
        </div>
    `).join('');

    return `
        <button type="button" class="btn btn-ghost npc-add-toggle">新增 NPC</button>
        <form class="def-form npc-add-form hidden">
            <div class="def-row">
                <div class="auth-field">
                    <label class="auth-label">npc_id</label>
                    <input class="na-npc-id" type="text" maxlength="16" placeholder="如 N151">
                </div>
                <div class="auth-field">
                    <label class="auth-label">中文名 name_zh</label>
                    <input class="na-name-zh" type="text" maxlength="64" placeholder="如 老练铁匠">
                </div>
                <div class="auth-field">
                    <label class="auth-label">name_key</label>
                    <input class="na-name-key" type="text" maxlength="64" placeholder="如 npc.N151.name">
                </div>
            </div>
            <div class="def-row">${selects}</div>
            <div class="def-row">${numbers}</div>
            <div class="def-row">
                <div class="auth-field">
                    <label class="auth-label">招募文案 recruit_desc_zh</label>
                    <input class="na-recruit-desc" type="text" maxlength="191" placeholder="招募来源的自然语言描述">
                </div>
                <div class="auth-field">
                    <label class="auth-label">特性文案 trait_desc_zh</label>
                    <input class="na-trait-desc" type="text" maxlength="191" placeholder="特性的自然语言描述">
                </div>
            </div>
            <div class="auth-field">
                <label class="auth-label">trait_json(可留空;结构 {"specs":[…]},合法性由服务端校验)</label>
                <textarea class="na-trait-json" rows="4" spellcheck="false" placeholder='如 {"specs":[{"target":"...","op":"...","value":0.05}]}'></textarea>
            </div>
            <div class="auth-field">
                <label class="auth-label">修改原因(必填,2-80 字)</label>
                <input class="na-reason" type="text" maxlength="80" placeholder="修改原因(必填,2-80 字)">
            </div>
            <div class="def-actions">
                <button type="submit" class="btn btn-primary na-submit">提交新增</button>
                <button type="button" class="btn btn-ghost na-cancel">收起</button>
            </div>
        </form>
    `;
}

// 每次展开都用最新行数据重填下拉(枚举取值以库内 distinct 为准)
function fillAddForm(form, ctx) {
    form.querySelectorAll('.na-select').forEach((select) => {
        const key = select.dataset.field;
        select.innerHTML = distinctOf(ctx.rows, key).map((v) =>
            `<option value="${escapeHtml(String(v))}">${escapeHtml(optionLabel(key, v))}</option>`).join('');
    });
}

async function onAddSubmit(e, form, ctx) {
    e.preventDefault();
    ctx.clearError();
    ctx.clearResult();

    const npcId = form.querySelector('.na-npc-id').value.trim();
    const nameZh = form.querySelector('.na-name-zh').value.trim();
    const nameKey = form.querySelector('.na-name-key').value.trim();
    const reason = form.querySelector('.na-reason').value.trim();

    if (!npcId) { ctx.setError('请填写 npc_id'); return; }
    if (!nameZh) { ctx.setError('请填写中文名 name_zh'); return; }
    if (!nameKey) { ctx.setError('请填写 name_key'); return; }
    if (reason.length < 2) { ctx.setError('请填写修改原因(至少 2 字)'); return; }

    const values = {
        npc_id: npcId,
        name_zh: nameZh,
        name_key: nameKey,
        recruit_desc_zh: form.querySelector('.na-recruit-desc').value.trim(),
        trait_desc_zh: form.querySelector('.na-trait-desc').value.trim(),
    };

    for (const select of Array.from(form.querySelectorAll('.na-select'))) {
        if (!select.value) { ctx.setError('下拉选项为空:请先刷新整表再新增'); return; }
        values[select.dataset.field] = select.value;
    }
    for (const f of NPC_NUM_FIELDS) {
        const input = form.querySelector(`.na-num[data-field="${f.key}"]`);
        const value = npcNumberOf(input, f.label, f.meta, ctx);
        if (value === null) return;
        values[f.key] = value;
    }

    const traitText = form.querySelector('.na-trait-json').value.trim();
    if (traitText === '') {
        values.trait_json = null; // 无结构化特性:合法(库里存 NULL)
    } else {
        try {
            values.trait_json = JSON.parse(traitText);
        } catch (err) {
            ctx.setError('trait_json 不是合法 JSON,请检查引号与逗号');
            return;
        }
    }

    const submit = form.querySelector('.na-submit');
    submit.disabled = true;
    try {
        const data = await api.post('/api/admin/definitions/npc/add', { reason, values });
        form.reset();
        form.classList.add('hidden');
        const toggle = form.parentElement.querySelector('.npc-add-toggle');
        if (toggle) toggle.textContent = '新增 NPC';
        // 先整表重拉再写结果条:load() 开头会清结果条,顺序反了成功信息会被立刻擦掉
        await ctx.reload();
        ctx.setResult(`已新增 NPC ${data.npc_id}(新版本号 ${data.version})`);
    } catch (err) {
        ctx.setError(errorMessage(err));
    } finally {
        submit.disabled = false;
    }
}

export const npcsPanel = createDefinitionPanel({
    id: 'npc',
    label: 'NPC 定义',
    title: 'NPC 定义(150 行)',
    hint: '只开放接口下发的可编辑列。<b>初始等级 / 上限等级</b>必须是 1~10 的整数(§6.2 曲线只有 10 级,填 0 或 3.5 会让该 NPC 静默失去全部加成)。<b>特性强度倍率</b>上限 10:再高会顶爆 §6.4 单人帽 1.60 与 §13 总帽 2.75,改了不会有任何变化。新增 NPC 走上方表单(npc_id 唯一、trait_json 结构由服务端校验)。',
    listUrl: '/api/admin/definitions/npcs',
    listKey: 'npcs',
    editUrl: '/api/admin/definitions/npc',
    idFields: [{ row: 'npc_id', param: 'npc_id', label: 'npc_id' }],
    // 只读列只留真正锁着的两列:name_key 是派生身份键(npc.{npc_id}.name),
    // primary_skill_id 改了等于换一个人。W14 把中文名 / 类别 / 稀有度 / 时代 / 来源 / 特性描述
    // 开放成可编辑后,它们从这里挪走 —— 同一列既只读又可编辑会在表里出现两遍
    readonlyColumns: [
        { key: 'name_key', label: '键名' },
        { key: 'primary_skill_id', label: '主技能' },
    ],
    labels: {
        name_zh: '中文名',
        category: '类别',
        min_era: '时代',
        rarity: '稀有度',
        recruit_source: '来源',
        recruit_desc_zh: '招募文案',
        trait_desc_zh: '特性描述',
        wage_per_min: '工资/分',
        food_per_min: '口粮/分',
        initial_skill_value: '初始技能值',
        initial_skill_level: '初始等级',
        max_level: '上限等级',
        trait_multiplier: '特性强度倍率',
    },
    fieldMeta: {
        // 文本列:W14 起可编辑,必须按文本渲染(数字框填不进中文)
        name_zh: { text: true, maxLength: 50, required: true },
        recruit_desc_zh: { text: true, maxLength: 191 },
        trait_desc_zh: { text: true, maxLength: 191 },
        // 枚举列:下拉给库内在用取值,填错在服务端也是 422,下拉省一次往返
        category: { options: optionsOf('category', NPC_CATEGORIES) },
        rarity: { options: optionsOf('rarity', ['common', 'uncommon', 'rare', 'epic', 'legendary']) },
        min_era: { options: optionsOf('min_era', ERA_ORDER) },
        recruit_source: { options: optionsOf('recruit_source', NPC_SOURCES) },
        wage_per_min: { min: 0 },
        food_per_min: { min: 0 },
        initial_skill_value: { min: 0 },
        initial_skill_level: { integer: true, min: 1, max: 10 },
        max_level: { integer: true, min: 1, max: 10 },
        trait_multiplier: { min: 0, max: 10 },
    },
    search: {
        placeholder: '按 npc_id / 中文名 / 类别 / 稀有度定位',
        fields: ['npc_id', 'name_zh', 'name_key', 'category', 'rarity', 'primary_skill_id'],
    },
    toolbar(node, ctx) {
        node.innerHTML = addFormHtml();

        const toggle = node.querySelector('.npc-add-toggle');
        const form = node.querySelector('.npc-add-form');

        toggle.addEventListener('click', () => {
            const show = form.classList.contains('hidden');
            if (show) fillAddForm(form, ctx);
            form.classList.toggle('hidden', !show);
            toggle.textContent = show ? '收起新增表单' : '新增 NPC';
        });
        form.querySelector('.na-cancel').addEventListener('click', () => {
            form.classList.add('hidden');
            toggle.textContent = '新增 NPC';
        });
        form.addEventListener('submit', (e) => onAddSubmit(e, form, ctx));
    },
});
