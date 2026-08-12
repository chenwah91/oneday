// 事件弹窗(M3 前端波二):活跃事件自动弹出 + 选项结算 + 结算结果。
//
// 职责边界(CLAUDE §5 / §30 / §66):弹窗只提交意图(event_instance_id + choice + 幂等键 +
// expected_revision)。掷点结果、损失比例、奖励数额全部由服务器在触发时就定死并落库,
// 前端一个数都不算 —— 显示的「已造成 / 本次结算」一律读服务器返回的 delta。
//
// 数据来源分两层,刻意不同口径:
//   ① 城市快照 city.events —— **精简 summary**(active_count + 每条的 id / event_id /
//      name_zh / expires_at)。只够打角标与判断「有没有新事件」,不含选项文案;
//   ② GET /api/city/events —— 完整详情(条件 / 自动效果 / 三选项 / 掷点结果 / 规则参数)。
//      这个端点会先跑一次结算(事件是懒结算的),所以只在**打开弹窗**与**结算之后**拉,
//      不跟着 10 秒轮询走(它与 /api/city 同挂 throttle:snapshot,是最贵的 GET)。
//
// 为什么是弹窗而不是底部导航的第四项:事件有到期时间,错过就领不到了(§70 ③),
// 属于「打断玩家一次」才对得起的信息;而工具 / 科技 / NPC / 市场是玩家主动去查的常驻面板。
// 弹窗**非阻塞**:没有全屏遮罩,地图照常拖拽,玩家可以先「收起」,HUD 角标随时点回来。
//
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
import { onChange, setState } from '../core/state.js';
import { errorText } from '../core/error-messages.js';
import { newIdempotencyKey } from '../core/idempotency.js';
import { eventCategoryName, eventTypeName } from '../core/enum-names.js';
import { notifySuccess, notifyError } from './notification.js';
import { updateHud } from './hud.js';
import { resourceName } from '../modules/resources.js';
import { fmt, fmtDec } from '../utils/format.js';

// 结算语境的错误码覆盖
const RESOLVE_ERRORS = {
    NOT_FOUND: '这个事件已经不在了,刷新后重试',
    VALIDATION_ERROR: '选项不合法,请刷新事件后重选',
};

// 秒数 → mm:ss
function clock(seconds) {
    const s = Math.max(0, Math.floor(seconds));
    const m = Math.floor(s / 60);
    const sec = s % 60;
    return m + ':' + (sec < 10 ? '0' + sec : String(sec));
}

// 资源 delta 映射 → 「粮食 +120 · 资金 -300」。0 值不显示(服务器可能回带没变化的键)
function deltaText(delta) {
    const map = delta && typeof delta === 'object' ? delta : {};
    const parts = [];
    Object.keys(map).forEach((code) => {
        const v = Number(map[code]) || 0;
        if (!v) return;
        parts.push(resourceName(code) + ' ' + (v > 0 ? '+' : '') + fmtDec(v, 0));
    });
    return parts.join(' · ');
}

export class EventDialog {
    constructor({ api, state, onOpen, canAutoOpen }) {
        this.api = api;
        this.state = state;
        // onOpen:打开时通知外部(main.js 用它把四个面板收起 —— 窄屏只有一屏的位置,
        // 弹窗与面板同时展开必然互相遮挡)。弹窗自己不认识任何面板
        this.onOpen = onOpen || null;
        // canAutoOpen:外部说了算的「现在能不能自动弹」。玩家正开着某个面板时一律不打断,
        // 只让 HUD 角标亮着等他自己点 —— 打断一次交互换来的信息量,远不如让他把手上的事做完
        this.canAutoOpen = canAutoOpen || null;

        this.rootEl = null;
        this.opened = false;
        this.busy = false;
        this.loading = false;
        this.index = 0;          // 当前显示第几条活跃事件
        this.result = null;      // 结算结果视图 { name, delta, population, happiness, notes }
        this.timer = null;
        // 已经自动弹过的实例 id:玩家收起之后不再自动弹回来(角标仍在,想看随时点)
        this.autoShown = {};
        // 详情拉取的时刻:倒计时用「服务器给的剩余秒数 − 本地经过秒数」推,
        // 不解析 expires_at 与本地时钟比 —— 客户端时间不可信(§16.3),这样连时钟差都不用算
        this.loadedAt = 0;
        // 上一次补拉详情时的快照指纹:同一个快照状态只补拉一次
        this.lastSyncSig = null;
        this.refs = null;
        this.lastSignature = '';
        this.unsubscribed = false;
    }

    // el:挂载容器(#stage,已是 position:relative)
    mount(el) {
        this.rootEl = document.createElement('div');
        this.rootEl.className = 'event-dialog';
        this.rootEl.hidden = true;
        el.appendChild(this.rootEl);

        onChange(() => {
            if (this.unsubscribed) return;
            this.autoOpenIfNew();
            if (!this.opened) return;

            // 开着的时候又触发了新事件(§9.1 允许同时 3 条):快照会先知道,详情要补拉一次。
            // 每个**不同的快照状态**最多补拉一次(lastSyncSig),避免两个端点短暂不一致时
            // 一轮询就拉一次,把 throttle:snapshot 的配额烧光
            if (this.needsReload() && this.summarySig() !== this.lastSyncSig) {
                this.loadEvents().then(() => {
                    if (this.opened) this.render();
                });
                return;
            }

            if (this.signature() === this.lastSignature) return;
            this.render();
        });
    }

    // 快照里出现没弹过的活跃事件 → 自动弹一次(非阻塞,玩家可以立刻收起)
    autoOpenIfNew() {
        const list = this.summaryActive();
        const fresh = list.filter((e) => !this.autoShown[e.event_instance_id]);
        if (!fresh.length || this.opened) return;

        // 有面板开着就不打断:**刻意不标记 autoShown**,等玩家关掉面板后的下一次轮询再弹。
        // 标记了就等于这条事件永远失去自动弹出的机会,只剩角标
        if (this.canAutoOpen && !this.canAutoOpen()) return;

        fresh.forEach((e) => { this.autoShown[e.event_instance_id] = true; });
        this.open();
    }

    async open(instanceId) {
        if (this.onOpen) this.onOpen(this);

        this.opened = true;
        this.result = null;
        this.rootEl.hidden = false;

        // 打开即认为「都弹过了」:收起之后不再自动弹同一批
        this.summaryActive().forEach((e) => { this.autoShown[e.event_instance_id] = true; });

        if (instanceId !== undefined && instanceId !== null) {
            const idx = this.activeEvents().findIndex((e) => String(e.event_instance_id) === String(instanceId));
            this.index = idx >= 0 ? idx : 0;
        }

        this.render();
        this.startTicker();

        // 详情与快照对不上(新事件 / 刚结算过)就重新拉一次
        if (this.needsReload()) await this.loadEvents();
        if (!this.opened) return;
        this.render();
    }

    close() {
        if (!this.opened) return;
        this.opened = false;
        this.stopTicker();
        this.result = null;
        this.refs = null;
        if (this.rootEl) {
            this.rootEl.hidden = true;
            this.rootEl.innerHTML = '';
        }
    }

    destroy() {
        this.unsubscribed = true;
        this.stopTicker();
        if (this.rootEl && this.rootEl.parentNode) this.rootEl.parentNode.removeChild(this.rootEl);
        this.rootEl = null;
    }

    // ---- 数据 ----

    // 快照 summary(打角标 / 判断有没有新事件用)
    summaryActive() {
        const city = this.state.city || {};
        const e = city.events || {};
        return Array.isArray(e.active) ? e.active : [];
    }

    // 详情端点的活跃事件(带选项文案)
    activeEvents() {
        const e = this.state.events || {};
        return Array.isArray(e.active) ? e.active : [];
    }

    current() {
        const list = this.activeEvents();
        if (!list.length) return null;
        const i = Math.max(0, Math.min(this.index, list.length - 1));
        return list[i];
    }

    // 快照 summary 的 id 集合指纹(判断「快照状态有没有变过」用)
    summarySig() {
        return this.summaryActive().map((e) => e.event_instance_id).sort().join(',');
    }

    // 详情里的 id 集合与快照对不上 = 详情过期(新触发 / 已结算 / 已过期)
    needsReload() {
        const detail = this.activeEvents().map((e) => e.event_instance_id).sort().join(',');
        return this.summarySig() !== detail;
    }

    signature() {
        return [
            this.activeEvents().map((e) => e.event_instance_id).join(','),
            this.index,
            this.busy ? 1 : 0,
            this.loading ? 1 : 0,
            this.result ? 'r' : '-',
        ].join('|');
    }

    // ---- 渲染 ----

    render() {
        if (!this.rootEl || !this.opened) return;
        this.lastSignature = this.signature();
        this.refs = {};
        this.rootEl.innerHTML = '';

        const list = this.activeEvents();

        const header = document.createElement('div');
        header.className = 'event-header';

        const title = document.createElement('span');
        title.className = 'event-title';
        title.textContent = '事件';
        header.appendChild(title);

        const counts = document.createElement('span');
        counts.className = 'event-counts';
        counts.textContent = list.length > 1
            ? (Math.min(this.index + 1, list.length)) + ' / ' + list.length
            : '';
        header.appendChild(counts);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'event-close';
        closeBtn.title = '收起';
        closeBtn.setAttribute('aria-label', '收起事件弹窗');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => this.close());
        header.appendChild(closeBtn);

        this.rootEl.appendChild(header);

        // 结算结果优先显示:玩家刚点完选项,先让他看清楚拿到 / 损失了什么
        if (this.result) {
            this.rootEl.appendChild(this.makeResult());
            return;
        }

        if (this.loading && !list.length) {
            this.rootEl.appendChild(this.makeHint('事件加载中...'));
            return;
        }

        if (!list.length) {
            this.rootEl.appendChild(this.makeHint('当前没有生效中的事件。事件由服务器按窗口掷点触发,来了会自动弹出来。'));
            return;
        }

        if (list.length > 1) this.rootEl.appendChild(this.makeNav(list));

        this.rootEl.appendChild(this.makeCard(this.current()));
    }

    makeNav(list) {
        const nav = document.createElement('div');
        nav.className = 'event-nav';

        list.forEach((e, i) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'event-chip' + (i === this.index ? ' active' : '');
            chip.textContent = e.name_zh;
            chip.addEventListener('click', () => {
                this.index = i;
                this.render();
            });
            nav.appendChild(chip);
        });

        return nav;
    }

    makeCard(ev) {
        const card = document.createElement('div');
        card.className = 'event-card is-' + (ev.event_type || 'negative');

        const head = document.createElement('div');
        head.className = 'event-card-head';

        const name = document.createElement('span');
        name.className = 'event-name';
        name.textContent = ev.name_zh;
        head.appendChild(name);

        const type = document.createElement('span');
        type.className = 'event-type is-' + (ev.event_type || 'negative');
        type.textContent = eventTypeName(ev.event_type);
        head.appendChild(type);

        card.appendChild(head);

        const meta = document.createElement('div');
        meta.className = 'event-meta';
        meta.textContent = eventCategoryName(ev.category)
            + (ev.duration_minutes > 0 ? ' · 持续 ' + fmt(ev.duration_minutes) + ' 分钟' : ' · 一次性选择');
        card.appendChild(meta);

        const countdown = document.createElement('div');
        countdown.className = 'event-countdown';
        countdown.textContent = '剩余 --:--';
        this.refs.countdown = countdown;
        card.appendChild(countdown);

        if (ev.auto_effect_desc_zh) {
            card.appendChild(this.makeSection('已经发生', ev.auto_effect_desc_zh));
        }

        // 已造成的实际变化:掷点在触发时就定死并落库(§11.3),这里只是把它读出来给玩家看
        const applied = ev.applied || {};
        const appliedText = deltaText(applied.resources);
        const extra = [];
        if (Number(applied.population)) extra.push('人口 ' + (applied.population > 0 ? '+' : '') + fmt(applied.population));
        if (Number(applied.happiness)) extra.push('幸福 ' + (applied.happiness > 0 ? '+' : '') + fmtDec(applied.happiness, 1));
        const appliedAll = [appliedText, extra.join(' · ')].filter(Boolean).join(' · ');
        if (appliedAll) card.appendChild(this.makeSection('已造成', appliedAll));

        if (ev.condition_desc_zh) {
            card.appendChild(this.makeSection('触发条件', ev.condition_desc_zh));
        }

        // 未生效的文案(服务器诚实下发的 unmapped):不写清楚,玩家会以为是 bug
        const unmapped = Array.isArray(ev.auto_unmapped_zh) ? ev.auto_unmapped_zh : [];
        if (unmapped.length) {
            card.appendChild(this.makeNote('以下描述当前系统尚未承接,不会实际生效:' + unmapped.join(';')));
        }

        card.appendChild(this.makeOptions(ev));

        return card;
    }

    makeSection(label, text) {
        const box = document.createElement('div');
        box.className = 'event-section';

        const key = document.createElement('span');
        key.className = 'event-section-key';
        key.textContent = label;
        box.appendChild(key);

        const val = document.createElement('span');
        val.className = 'event-section-value';
        val.textContent = text;
        box.appendChild(val);

        return box;
    }

    makeNote(text) {
        const note = document.createElement('div');
        note.className = 'event-note';
        note.textContent = text;
        return note;
    }

    makeHint(text) {
        const hint = document.createElement('div');
        hint.className = 'event-hint';
        hint.textContent = text;
        return hint;
    }

    makeOptions(ev) {
        const box = document.createElement('div');
        box.className = 'event-options';

        const options = Array.isArray(ev.options) ? ev.options : [];

        // 没有选项的事件:结算 = 确认知悉(choice 必须不传,传了服务器会拒 —— §70 ④)
        if (!options.length) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'event-btn event-btn-primary';
            btn.textContent = this.busy ? '处理中...' : '知道了';
            btn.disabled = this.busy;
            btn.addEventListener('click', () => this.doResolve(ev, null));
            box.appendChild(btn);
            return box;
        }

        options.forEach((opt) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'event-option';
            btn.disabled = this.busy;

            const label = document.createElement('span');
            label.className = 'event-option-label';
            label.textContent = opt.label_zh;
            btn.appendChild(label);

            // 代价与效果文案:option_x_desc_zh 是 §9.2 的原文(「资金-300,幸福+6」这类),
            // 具体数额一律以服务器结算为准 —— 前端不重算,也不改写
            if (opt.desc_zh) {
                const desc = document.createElement('span');
                desc.className = 'event-option-desc';
                desc.textContent = opt.desc_zh;
                btn.appendChild(desc);
            }

            const unmapped = Array.isArray(opt.unmapped_zh) ? opt.unmapped_zh : [];
            if (unmapped.length) {
                const warn = document.createElement('span');
                warn.className = 'event-option-warn';
                warn.textContent = '部分效果尚未承接:' + unmapped.join(';');
                btn.appendChild(warn);
            }

            btn.addEventListener('click', () => this.doResolve(ev, opt.key));
            box.appendChild(btn);
        });

        return box;
    }

    // 结算结果:资源 delta 一律读服务器返回值;人口 / 幸福服务器回的是**结算后的绝对值**,
    // 所以这里显示「前 → 后」,前值取自提交前的快照(纯显示,不参与任何计算)
    makeResult() {
        const box = document.createElement('div');
        box.className = 'event-result';

        const title = document.createElement('div');
        title.className = 'event-result-title';
        title.textContent = this.result.name + ' · 已结算';
        box.appendChild(title);

        if (this.result.option) {
            box.appendChild(this.makeSection('你的选择', this.result.option));
        }

        const delta = deltaText(this.result.delta);
        box.appendChild(this.makeSection('资源变化', delta || '没有资源变化'));

        if (this.result.population) {
            box.appendChild(this.makeSection('人口', this.result.population));
        }
        if (this.result.happiness) {
            box.appendChild(this.makeSection('幸福', this.result.happiness));
        }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'event-btn event-btn-primary';
        btn.textContent = '知道了';
        btn.addEventListener('click', () => {
            this.result = null;
            this.index = 0;
            // 结算完还有别的事件就接着看,没有就收起
            if (this.activeEvents().length) this.render();
            else this.close();
        });
        box.appendChild(btn);

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

    // 纯视觉倒计时:到点只负责重新拉一次详情(过期与否由服务器判,§70 ③)
    tick() {
        const ev = this.current();
        if (!ev || !this.refs || !this.refs.countdown || this.result) return;

        const elapsed = this.loadedAt ? (Date.now() - this.loadedAt) / 1000 : 0;
        const remain = (Number(ev.remaining_seconds) || 0) - elapsed;

        this.refs.countdown.textContent = remain > 0 ? '剩余 ' + clock(remain) : '已过期,正在刷新...';
        this.refs.countdown.classList.toggle('is-urgent', remain > 0 && remain <= 60);

        if (remain <= 0 && !this.loading) {
            this.loadEvents().then(() => {
                if (this.opened) this.render();
            });
        }
    }

    // ---- 请求 ----

    async loadEvents() {
        if (this.loading) return;
        this.loading = true;

        try {
            const data = await this.api.get('/api/city/events');
            this.loadedAt = Date.now();
            // 记在 setState 之前:setState 会同步触发 onChange,那一轮必须已经看到新指纹
            this.lastSyncSig = this.summarySig();
            // 端点的 data 是 { events: { active_count, active, recent, limits } } —— 比市场价目多包了一层
            // (EventController 返回 ['data' => ['events' => …]]),这里剥掉;万一以后拍平了也认得
            setState({ events: data && data.events ? data.events : data });
            if (this.index >= this.activeEvents().length) this.index = 0;
        } catch (e) {
            notifyError(errorText(e, '事件加载失败,请稍后重试'));
        } finally {
            this.loading = false;
        }
    }

    async refreshCity() {
        try {
            const res = await this.api.get('/api/city');
            setState({ city: res.city });
            updateHud(this.state.city);
        } catch (e) {
            // 刷新失败不打断当前操作,交给 main.js 的定期轮询兜底
        }
    }

    // 结算响应:{ revision, resources, money, population, happiness, delta[, event] }
    applyDiff(diff) {
        const city = this.state.city;
        if (!city || !diff) return;

        setState({
            city: Object.assign({}, city, {
                revision: diff.revision,
                resources: Object.assign({}, city.resources, diff.resources),
                money: diff.money,
                population: diff.population,
                happiness: diff.happiness,
            }),
        });

        updateHud(this.state.city);
    }

    async doResolve(ev, choice) {
        if (this.busy) return;
        this.busy = true;
        this.render();

        // 提交前的人口 / 幸福:结算响应给的是绝对值,要拿它做「前 → 后」的显示
        const before = this.state.city || {};
        const popBefore = Number(before.population) || 0;
        const happyBefore = Number(before.happiness) || 0;
        const option = (Array.isArray(ev.options) ? ev.options : []).filter((o) => o.key === choice)[0];

        try {
            const diff = await this.api.post('/api/city/events/resolve', {
                event_instance_id: Number(ev.event_instance_id),
                choice: choice || undefined,
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);

            const popAfter = Number(diff.population) || 0;
            const happyAfter = Number(diff.happiness) || 0;

            this.result = {
                name: ev.name_zh,
                option: option ? option.label_zh : null,
                delta: diff.delta || {},
                // 比的是**显示出来的**那个数:幸福是浮点数,结算前后差 0.01 也不该在结果里
                // 摆一行「45.4 → 45.4」(那是分段结算的自然漂移,不是这次选项的效果)
                population: fmt(popBefore) !== fmt(popAfter) ? fmt(popBefore) + ' → ' + fmt(popAfter) : null,
                happiness: fmtDec(happyBefore, 1) !== fmtDec(happyAfter, 1)
                    ? fmtDec(happyBefore, 1) + ' → ' + fmtDec(happyAfter, 1)
                    : null,
            };

            const delta = deltaText(diff.delta);
            notifySuccess('事件已结算:' + ev.name_zh + (delta ? ' · ' + delta : ''));

            // 先把详情与快照都拉成权威状态,再让结果视图停在屏幕上等玩家点「知道了」
            await this.loadEvents();
            await this.refreshCity();
        } catch (err) {
            notifyError(errorText(err, '结算失败,请重试', RESOLVE_ERRORS));
            const code = err && err.error;
            if (code === 'EVENT_EXPIRED' || code === 'EVENT_ALREADY_RESOLVED'
                || code === 'REVISION_CONFLICT' || code === 'NOT_FOUND'
                || code === 'EVENT_OPTION_INVALID') {
                await this.loadEvents();
                await this.refreshCity();
            }
        } finally {
            this.busy = false;
            this.render();
        }
    }
}
