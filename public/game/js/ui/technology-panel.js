// 科技面板(M2-B1):按分支分组的科技树,显示中文名/时代/费用/时长/前置,
// 三态 = 可研究 / 条件未满足(置灰并说明原因) / 已解锁;在研项显示倒计时。
//
// 职责边界(CLAUDE §5):面板只提交意图(tech_id + 幂等键 + expected_revision),
// 能不能研究、扣多少知识、什么时候完成一律由服务器决定 —— 这里的置灰只是显示层的提前提示,
// 玩家改 JS 把按钮点亮也没用,服务端会用同一套规则再判一次。
//
// 倒计时是纯视觉的:到点后拉一次 /api/city 由服务器确认解锁,绝不本地翻牌(CLAUDE §66)。
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
import { onChange, setState } from '../core/state.js';
import { errorText } from '../core/error-messages.js';
import { newIdempotencyKey } from '../core/idempotency.js';
import { techBranchName } from '../core/enum-names.js';
import { resourceName } from '../modules/resources.js';
import { notifySuccess, notifyError } from './notification.js';
import { updateHud } from './hud.js';
import { fmt } from '../utils/format.js';

// 研究语境下的错误码文案覆盖:
// 后端把「该科技已解锁 / tech_id 不在定义表」都归到 VALIDATION_ERROR,
// 泛化文案("输入有误")说不清原因,这里译成"状态已变化"引导玩家刷新
const RESEARCH_ERRORS = {
    VALIDATION_ERROR: '该科技状态已变化,请刷新后重试',
    ERA_REQUIRED: '当前时代还研究不了这项科技,先升级时代',
};

// 时代升级语境下的错误码文案覆盖
const ERA_ERRORS = {
    ERA_REQUIRED: '升级条件还没全部满足',
    VALIDATION_ERROR: '已经是最高时代了',
};

// 升级条件维度 → 中文标签(M2-B6,与 EraService 的 dimension 取值一一对应)。
// building 维度另带 building_id,标签由建筑定义里的中文名拼出
const DIMENSION_LABELS = {
    population: '人口',
    knowledge: '知识',
    food: '粮食储备',
    money: '资金储备',
    building: '建筑',
    governance: '治理容量',
    happiness: '幸福度',
    defense: '国防值',
};

const FILTERS = [
    { key: 'all', label: '全部' },
    { key: 'available', label: '可研究' },
    { key: 'unlocked', label: '已解锁' },
];

// 秒数 → "3分20秒" / "1时05分" / "12秒"
function formatRemain(seconds) {
    const s = Math.max(0, Math.floor(seconds));
    if (s >= 3600) {
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        return h + '时' + String(m).padStart(2, '0') + '分';
    }
    if (s >= 60) {
        return Math.floor(s / 60) + '分' + String(s % 60).padStart(2, '0') + '秒';
    }
    return s + '秒';
}

// 时长展示:定义里是小数分钟(1.2 = 1分12秒)
function formatDuration(minutes) {
    return formatRemain(Math.round((Number(minutes) || 0) * 60));
}

// 成本紧凑展示,如 "知识20"(键是资源 code,显示时翻成中文名)
function formatCost(cost) {
    return Object.keys(cost || {})
        .map((code) => resourceName(code) + fmt(cost[code]))
        .join(' ');
}

export class TechnologyPanel {
    constructor({ api, state, onOpen }) {
        this.api = api;
        this.state = state;
        // onOpen:打开时通知外部(main.js 用它做面板互斥),自己不知道别的面板是谁
        this.onOpen = onOpen || null;

        this.rootEl = null;    // 面板根节点
        this.fabEl = null;     // 打开/关闭入口按钮
        this.listEl = null;    // 列表容器(重绘只动这里,避免整块闪烁)
        this.opened = false;
        this.busy = false;     // 请求进行中:禁用全部研究按钮,防重复提交
        this.filter = 'all';
        this.timer = null;     // 倒计时 ticker
        this.eraReqRefs = null; // 时代条件清单的数值节点(syncEraValues 原地更新用)
        this.pendingRefresh = false; // 到点后只拉一次快照的哨兵
        this.lastSignature = '';
        this.unsubscribed = false;
    }

    // el:挂载容器(#stage,已是 position:relative,入口与面板都绝对定位其中)
    mount(el) {
        this.fabEl = document.createElement('button');
        this.fabEl.type = 'button';
        this.fabEl.className = 'tech-fab';
        this.fabEl.title = '科技';
        this.fabEl.setAttribute('aria-label', '打开科技面板');
        this.fabEl.textContent = '🔬 科技';
        this.fabEl.addEventListener('click', () => (this.opened ? this.close() : this.open()));
        el.appendChild(this.fabEl);

        this.rootEl = document.createElement('div');
        this.rootEl.className = 'tech-panel';
        this.rootEl.hidden = true;
        el.appendChild(this.rootEl);

        // 快照更新(轮询 / 研究成功 / 其他面板)后同步刷新;与面板无关的变化不重建 DOM。
        // 指纹只含「达标与否」这类会改变结构/按钮状态的位,不含条件的具体数值 ——
        // 数值每 10 秒都在动(粮食/幸福/资金),进指纹会让面板每轮询一次就整块重建,
        // 滚动位置和在研进度条都会被打断。所以指纹没变时走 syncEraValues() 原地改文本,
        // 否则条件清单会一直停在上次重建那一刻的数字(M2 收官 E2E 实测到的显示滞后)。
        onChange(() => {
            if (this.unsubscribed || !this.opened) return;
            if (this.signature() === this.lastSignature) {
                this.syncEraValues();
                return;
            }
            this.render();
        });
    }

    async open() {
        if (this.onOpen) this.onOpen(this);

        this.opened = true;
        this.fabEl.classList.add('active');
        this.rootEl.hidden = false;

        // 定义只拉一次,之后常驻 state(50 个节点,不必每次开面板都请求)
        if (!this.state.technologyDefs) {
            try {
                const data = await this.api.get('/api/definitions/technologies');
                setState({ technologyDefs: data.technologies || [] });
            } catch (e) {
                notifyError('科技列表加载失败,请稍后重试');
                setState({ technologyDefs: [] });
            }
        }

        if (!this.opened) return; // 定义还在拉取时玩家已经关掉面板

        this.render();
        this.startTicker();
    }

    close() {
        this.opened = false;
        this.stopTicker();
        if (this.fabEl) this.fabEl.classList.remove('active');
        if (this.rootEl) {
            this.rootEl.hidden = true;
            this.rootEl.innerHTML = '';
        }
        this.listEl = null;
    }

    destroy() {
        this.unsubscribed = true;
        this.stopTicker();
        if (this.rootEl && this.rootEl.parentNode) this.rootEl.parentNode.removeChild(this.rootEl);
        if (this.fabEl && this.fabEl.parentNode) this.fabEl.parentNode.removeChild(this.fabEl);
        this.rootEl = null;
        this.fabEl = null;
        this.listEl = null;
    }

    // ---- 数据 ----

    // 快照里的科技区块;拿不到时退化成空,面板照常显示(全部置灰)
    techState() {
        const city = this.state.city || {};
        const t = city.technologies || {};
        return {
            unlocked: Array.isArray(t.unlocked) ? t.unlocked : [],
            researching: t.researching || null,
            maxEraOrder: Number(t.max_research_era_order) || 0,
        };
    }

    knowledgeBalance() {
        const res = (this.state.city && this.state.city.resources) || {};
        return Number(res.knowledge) || 0;
    }

    // 时代区块(M2-B6):快照的 city.era = { era_key, era_order, era_name, next|null }
    eraState() {
        const city = this.state.city || {};
        return city.era || null;
    }

    // 面板显示用到的数据指纹:只有这些值变了才需要重建 DOM
    signature() {
        const t = this.techState();
        const era = this.eraState();
        // 时代条件的达标情况也进指纹:人口/粮食涨到达标时,升级按钮要跟着亮起来
        const eraMet = era && era.next
            ? era.next.requirements.map((r) => (r.met ? 1 : 0)).join('')
            : '';
        return [
            t.unlocked.length,
            t.researching ? t.researching.tech_id + '@' + t.researching.finished_at : 'none',
            t.maxEraOrder,
            era ? era.era_order : 0,
            eraMet,
            Math.floor(this.knowledgeBalance()),
            this.state.technologyDefs ? this.state.technologyDefs.length : 0,
            this.filter,
            this.busy ? 1 : 0,
        ].join('|');
    }

    // 单个节点的三态与原因。
    // 返回 { status: 'unlocked' | 'researching' | 'available' | 'locked', reasons: [文案] }
    evaluate(def) {
        const t = this.techState();
        const names = this.defNames();

        if (t.unlocked.indexOf(def.tech_id) >= 0) return { status: 'unlocked', reasons: [] };
        if (t.researching && t.researching.tech_id === def.tech_id) return { status: 'researching', reasons: [] };

        const reasons = [];

        // 时代:服务器给的 max_research_era_order 是权威口径,前端不自己推派生规则
        if (Number(def.era_order) > t.maxEraOrder) {
            reasons.push('需先推进到时代 ' + def.era);
        }

        const missing = (def.prerequisites || []).filter((id) => t.unlocked.indexOf(id) < 0);
        if (missing.length) {
            reasons.push('需先解锁:' + missing.map((id) => names[id] || id).join('、'));
        }

        if (t.researching) {
            reasons.push('已有科技在研究中');
        }

        // 费用逐项比对(不写死 knowledge):将来定义表给科技加别的成本资源,这里不用改
        const short = this.shortOf(def.cost);
        if (short.length) {
            reasons.push(short.map((code) => resourceName(code) + '不足(需 ' + fmt(def.cost[code]) + ')').join('、'));
        }

        return { status: reasons.length ? 'locked' : 'available', reasons: reasons };
    }

    // 余额不够的成本资源 code 列表(资金 money 单列在 city.money,与后端口径一致)
    shortOf(cost) {
        const city = this.state.city || {};
        const res = city.resources || {};

        return Object.keys(cost || {}).filter((code) => {
            const have = code === 'money' ? Number(city.money) || 0 : Number(res[code]) || 0;
            return have < (Number(cost[code]) || 0);
        });
    }

    // tech_id → 中文名(前置说明要显示名字而不是 ID)
    defNames() {
        if (this._names) return this._names;
        const map = {};
        (this.state.technologyDefs || []).forEach((d) => { map[d.tech_id] = d.name; });
        this._names = map;
        return map;
    }

    // ---- 渲染 ----

    render() {
        if (!this.rootEl || !this.opened) return;
        this.lastSignature = this.signature();
        this._names = null; // 定义可能刚加载完,名字表重建
        this.progressRefs = null; // 旧节点即将被丢弃,ticker 不能再往上写
        this.eraReqRefs = null;   // 同上:条件清单节点重建前先断开旧引用
        this.rootEl.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'tech-header';

        const title = document.createElement('span');
        title.className = 'tech-title';
        title.textContent = '科技';
        header.appendChild(title);

        const balance = document.createElement('span');
        balance.className = 'tech-balance';
        balance.textContent = resourceName('knowledge') + ' ' + fmt(this.knowledgeBalance());
        header.appendChild(balance);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'tech-close';
        closeBtn.title = '关闭';
        closeBtn.setAttribute('aria-label', '关闭');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => this.close());
        header.appendChild(closeBtn);

        this.rootEl.appendChild(header);

        // 时代区块排在科技列表之前:时代决定能研究哪些科技,玩家先看到"我在哪个时代"才好理解置灰原因
        this.rootEl.appendChild(this.makeEraSection());

        const filters = document.createElement('div');
        filters.className = 'tech-filters';
        FILTERS.forEach((f) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'tech-chip' + (this.filter === f.key ? ' active' : '');
            chip.textContent = f.label;
            chip.addEventListener('click', () => {
                this.filter = f.key;
                this.render();
            });
            filters.appendChild(chip);
        });
        this.rootEl.appendChild(filters);

        this.listEl = document.createElement('div');
        this.listEl.className = 'tech-list';
        this.rootEl.appendChild(this.listEl);

        this.renderList();
    }

    renderList() {
        const defs = this.state.technologyDefs || [];
        if (!defs.length) {
            const empty = document.createElement('div');
            empty.className = 'tech-empty';
            empty.textContent = '科技列表加载中...';
            this.listEl.appendChild(empty);
            return;
        }

        // 分支分组:分支顺序按定义列表里首次出现的顺序(接口已按 era_order → tech_id 排好),
        // 不在前端另立一张"分支排序表"(§13 数据驱动)
        const order = [];
        const groups = {};
        defs.forEach((def) => {
            const evaluated = this.evaluate(def);
            if (this.filter === 'available' && evaluated.status !== 'available' && evaluated.status !== 'researching') return;
            if (this.filter === 'unlocked' && evaluated.status !== 'unlocked') return;

            if (!groups[def.branch]) {
                groups[def.branch] = [];
                order.push(def.branch);
            }
            groups[def.branch].push({ def: def, evaluated: evaluated });
        });

        if (!order.length) {
            const empty = document.createElement('div');
            empty.className = 'tech-empty';
            empty.textContent = this.filter === 'unlocked' ? '还没有解锁任何科技' : '当前没有可研究的科技';
            this.listEl.appendChild(empty);
            return;
        }

        order.forEach((branch) => {
            const section = document.createElement('div');
            section.className = 'tech-branch';

            const heading = document.createElement('div');
            heading.className = 'tech-branch-title';
            heading.textContent = techBranchName(branch);
            section.appendChild(heading);

            groups[branch].forEach((entry) => section.appendChild(this.makeItem(entry.def, entry.evaluated)));
            this.listEl.appendChild(section);
        });
    }

    // ---- 时代区块(M2-B6) ----

    // 顶部:当前时代 + 「升级到 X 时代」按钮 + 逐维条件清单(满足绿 / 未满足橙)。
    // 能不能升同样由服务器判定,这里的置灰只是显示层提示(与科技节点同一纪律,CLAUDE §66)
    makeEraSection() {
        const box = document.createElement('div');
        box.className = 'era-box';
        const era = this.eraState();

        const head = document.createElement('div');
        head.className = 'era-head';

        const cur = document.createElement('span');
        cur.className = 'era-current';
        cur.textContent = era
            ? '时代 ' + era.era_key + (era.era_name ? ' · ' + era.era_name : '')
            : '时代加载中...';
        head.appendChild(cur);
        box.appendChild(head);

        if (!era) return box;

        if (!era.next) {
            const done = document.createElement('div');
            done.className = 'era-note';
            done.textContent = '已达最高时代';
            box.appendChild(done);
            return box;
        }

        const reqs = era.next.requirements || [];
        const allMet = reqs.every((r) => r.met);
        this.eraReqRefs = []; // 逐条条件的数值节点,供 syncEraValues() 原地更新

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'era-btn';
        btn.textContent = '升级到 ' + era.next.era_key + (era.next.era_name ? ' ' + era.next.era_name : '') + ' 时代';
        btn.disabled = this.busy || !allMet;
        btn.addEventListener('click', () => this.doEraUpgrade());
        head.appendChild(btn);

        const list = document.createElement('div');
        list.className = 'era-reqs';
        reqs.forEach((r) => list.appendChild(this.makeRequirement(r)));
        box.appendChild(list);

        return box;
    }

    // 单条条件:标签 + 当前值/需求值,met 绿、未满足橙
    makeRequirement(req) {
        const row = document.createElement('div');
        row.className = 'era-req' + (req.met ? ' is-met' : ' is-missing');

        const label = document.createElement('span');
        label.className = 'era-req-label';
        label.textContent = this.requirementLabel(req);
        row.appendChild(label);

        const value = document.createElement('span');
        value.className = 'era-req-value';
        value.textContent = fmt(req.current) + ' / ' + fmt(req.required);
        row.appendChild(value);

        if (this.eraReqRefs) this.eraReqRefs.push({ row: row, value: value });

        return row;
    }

    // 指纹未变时的轻量同步:只改条件清单里的「当前值」文本与达标配色,不动 DOM 结构。
    // 条件条数/顺序变了(升代)会走 signature 变化 → 整块 render,这里只处理数值漂移
    syncEraValues() {
        const refs = this.eraReqRefs;
        const era = this.eraState();
        const reqs = era && era.next ? era.next.requirements || [] : [];
        if (!refs || refs.length !== reqs.length) return;

        reqs.forEach((req, i) => {
            const text = fmt(req.current) + ' / ' + fmt(req.required);
            if (refs[i].value.textContent !== text) refs[i].value.textContent = text;
            refs[i].row.className = 'era-req' + (req.met ? ' is-met' : ' is-missing');
        });
    }

    // 维度标签:building 维度用建筑定义里的中文名(建造面板启动时已把定义缓存进 state.definitions)
    requirementLabel(req) {
        if (req.dimension === 'building' && req.building_id) {
            const defs = this.state.definitions || [];
            const found = defs.filter((d) => d.building_id === req.building_id)[0];
            return found ? found.name : req.building_id;
        }
        return DIMENSION_LABELS[req.dimension] || req.dimension;
    }

    makeItem(def, evaluated) {
        const item = document.createElement('div');
        item.className = 'tech-item is-' + evaluated.status;

        const head = document.createElement('div');
        head.className = 'tech-item-head';

        const name = document.createElement('span');
        name.className = 'tech-item-name';
        name.textContent = def.name;
        head.appendChild(name);

        const era = document.createElement('span');
        era.className = 'tech-item-era';
        era.textContent = '时代 ' + def.era;
        head.appendChild(era);

        item.appendChild(head);

        const meta = document.createElement('div');
        meta.className = 'tech-item-meta';
        meta.textContent = formatCost(def.cost) + ' · 耗时 ' + formatDuration(def.duration_minutes);
        item.appendChild(meta);

        if ((def.prerequisites || []).length) {
            const names = this.defNames();
            const pre = document.createElement('div');
            pre.className = 'tech-item-meta';
            pre.textContent = '前置:' + def.prerequisites.map((id) => names[id] || id).join('、');
            item.appendChild(pre);
        }

        if (evaluated.status === 'unlocked') {
            const done = document.createElement('div');
            done.className = 'tech-item-state';
            done.textContent = '已解锁';
            item.appendChild(done);
            return item;
        }

        if (evaluated.status === 'researching') {
            item.appendChild(this.makeProgress());
            return item;
        }

        evaluated.reasons.forEach((text) => {
            const reason = document.createElement('div');
            reason.className = 'tech-item-reason';
            reason.textContent = text;
            item.appendChild(reason);
        });

        const actions = document.createElement('div');
        actions.className = 'tech-item-actions';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tech-btn';
        btn.textContent = '研究';
        btn.disabled = this.busy || evaluated.status !== 'available';
        btn.addEventListener('click', () => this.doResearch(def));
        actions.appendChild(btn);

        item.appendChild(actions);
        return item;
    }

    // 在研区块:进度条 + 倒计时。节点单独留引用,ticker 每秒只改这两处文本/宽度
    makeProgress() {
        const box = document.createElement('div');
        box.className = 'tech-progress';

        const bar = document.createElement('div');
        bar.className = 'tech-progress-bar';
        const fill = document.createElement('div');
        fill.className = 'tech-progress-fill';
        bar.appendChild(fill);
        box.appendChild(bar);

        const text = document.createElement('div');
        text.className = 'tech-progress-text';
        box.appendChild(text);

        this.progressRefs = { fill: fill, text: text };
        this.tick();
        return box;
    }

    // ---- 倒计时 ----

    startTicker() {
        this.stopTicker();
        this.timer = setInterval(() => this.tick(), 1000);
    }

    stopTicker() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    // 每秒刷新在研进度。倒计时到点只负责"去拉一次权威快照",绝不本地把状态改成已解锁
    tick() {
        const t = this.techState();
        const refs = this.progressRefs;
        if (!t.researching) {
            this.pendingRefresh = false;
            return;
        }

        const end = Date.parse(t.researching.finished_at);
        const start = Date.parse(t.researching.started_at || t.researching.finished_at);
        const now = Date.now();
        const remainMs = end - now;

        if (refs && refs.text) {
            refs.text.textContent = remainMs > 0 ? '研究中 · 剩余 ' + formatRemain(remainMs / 1000) : '研究完成,正在同步...';
        }
        if (refs && refs.fill) {
            const total = end - start;
            const ratio = total > 0 ? Math.max(0, Math.min(1, (now - start) / total)) : 1;
            refs.fill.style.width = (ratio * 100).toFixed(1) + '%';
        }

        // 到点:拉一次快照让服务器确认解锁(只拉一次,拉完前不重复触发)
        if (remainMs <= 0 && !this.pendingRefresh) {
            this.pendingRefresh = true;
            this.refreshCity().then(() => { this.pendingRefresh = false; });
        }
    }

    // ---- 请求 ----

    async refreshCity() {
        try {
            const res = await this.api.get('/api/city');
            setState({ city: res.city });
            updateHud(this.state.city);
        } catch (e) {
            // 刷新失败不打断当前操作,交给 main.js 的定期轮询兜底
        }
    }

    async doResearch(def) {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/research', {
                tech_id: def.tech_id,
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);
            notifySuccess('开始研究:' + def.name);
        } catch (err) {
            notifyError(errorText(err, '研究失败,请重试', RESEARCH_ERRORS));
            // 旧 revision / 目标失效:先拉权威快照,玩家看到新状态后可直接重试(与建筑面板同一恢复流程)
            const code = err && err.error;
            if (code === 'REVISION_CONFLICT' || code === 'NOT_FOUND' || code === 'RESEARCH_IN_PROGRESS') {
                await this.refreshCity();
            }
        } finally {
            this.busy = false;
            this.render();
        }
    }

    // 时代升级(M2-B6):无业务参数,只带幂等键与 expected_revision。
    // 409(revision 冲突)走与研究/建筑面板完全一致的恢复流程:拉一次权威快照,玩家看到新状态后可重试
    async doEraUpgrade() {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/era/upgrade', {
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyEraDiff(diff);
            notifySuccess('进入新时代:' + (diff.era ? diff.era.era_key : ''));
            // 升级会同时放开科技树与建造清单(两者都按时代闸门置灰),
            // 升级响应里只有时代与资源,所以再拉一次权威快照把科技区块也刷新掉
            await this.refreshCity();
        } catch (err) {
            notifyError(errorText(err, '时代升级失败,请重试', ERA_ERRORS));
            const code = err && err.error;
            if (code === 'REVISION_CONFLICT' || code === 'ERA_REQUIRED') {
                // ERA_REQUIRED 也刷新:本地条件清单已经过期(别的操作花掉了资源),拉一次拿到最新缺口
                await this.refreshCity();
            }
        } finally {
            this.busy = false;
            this.render();
        }
    }

    // 时代升级响应:{ revision, resources, money, delta, era }
    applyEraDiff(diff) {
        const city = this.state.city;
        if (!city || !diff) return;

        setState({
            city: Object.assign({}, city, {
                revision: diff.revision,
                resources: Object.assign({}, city.resources, diff.resources),
                money: diff.money,
                era: diff.era,
            }),
        });

        updateHud(this.state.city);
    }

    // 研究响应:{ revision, resources, money, delta, technologies }
    // 资源/科技状态一律用服务器返回值覆盖,不做本地推算
    applyDiff(diff) {
        const city = this.state.city;
        if (!city || !diff) return;

        setState({
            city: Object.assign({}, city, {
                revision: diff.revision,
                resources: Object.assign({}, city.resources, diff.resources),
                money: diff.money,
                technologies: diff.technologies,
            }),
        });

        updateHud(this.state.city);
    }
}
