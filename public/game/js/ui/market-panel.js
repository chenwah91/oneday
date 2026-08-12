// 市场面板(M3 前端波一):28 行价目表 + 买 / 卖表单 + 额度与停市提示。
//
// 职责边界(CLAUDE §5 / §45 / §66):面板只提交意图(resource_code + quantity + 幂等键 +
// expected_revision)。**成交价一律由服务器计算**,请求里根本没有价格字段;这里的「预估」
// 只是给玩家一个数量级的心理预期,任何与实际成交的差异一律以服务器返回的成交明细为准。
//
// W7 起预估把滑点与事件冲击也算进去了(价目端点补下发 slippage_coefficient /
// max_slippage_rate / effective_liquidity / buy_price_pct)。公式与 TradeService 逐项对齐,
// 但它仍然只是预估 —— 服务器成交时会在城市行锁内用最新窗口价与最新事件强度重算。
//
// 数据来源:GET /api/market/prices(全服共享的只读端点,刻意不在城市快照里)。
// 窗口倒计时是纯视觉的:到点只做一件事 —— 重新拉一次价目表,绝不本地推算新价格(§30)。
//
// 面板范式照 ui/technology-panel.js:类模块 + 指纹跳过重绘 + 倒计时 + 409 恢复。
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
import { onChange, setState } from '../core/state.js';
import { errorText } from '../core/error-messages.js';
import { newIdempotencyKey } from '../core/idempotency.js';
import { notifySuccess, notifyError } from './notification.js';
import { updateHud } from './hud.js';
import { resourceName } from '../modules/resources.js';
import { fmt, fmtDec } from '../utils/format.js';

const SIDE_BUY = 'buy';
const SIDE_SELL = 'sell';

// 交易语境的错误码覆盖
const TRADE_ERRORS = {
    RESOURCE_NOT_TRADEABLE: '这种资源不能在现货市场买卖',
    VALIDATION_ERROR: '数量不合法,请填 1 以上的整数',
};

// 秒数 → mm:ss
function clock(seconds) {
    const s = Math.max(0, Math.floor(seconds));
    const m = Math.floor(s / 60);
    const sec = s % 60;
    return m + ':' + (sec < 10 ? '0' + sec : String(sec));
}

// 价格显示:小额资源(粮食 2.06)需要两位小数,大额(先进材料 967.92)也照两位,口径统一好比对
function price(n) {
    return fmtDec(n, 2);
}

export class MarketPanel {
    constructor({ api, state, onOpen }) {
        this.api = api;
        this.state = state;
        // onOpen:打开时通知外部(main.js 用它做面板互斥),自己不知道别的面板是谁
        this.onOpen = onOpen || null;

        this.rootEl = null;
        this.opened = false;
        this.busy = false;
        this.loading = false;
        this.selected = null;   // 选中的 resource_code
        this.side = SIDE_BUY;
        this.qty = 1;
        this.timer = null;
        this.pendingRefresh = false; // 窗口到点后只拉一次的哨兵
        // 服务器与客户端的时钟差:倒计时必须按服务器时间走(客户端时间不可信,§16.3)
        this.skewMs = 0;
        this.quota = null;      // 上一笔成交回带的额度剩余 { resource_code, ... }
        this.limitInfo = null;  // 被额度挡下时的两口径说明 { resource_code, text }
        // 事件价格冲击不再单独存:W7 起价目端点逐行下发 buy_price_pct(只读口径),
        // 横幅与行标记都直接从 prices 读,不必等成交响应回带
        this.refs = null;       // 倒计时 / 余额 / 预估的原地更新引用
        this.lastSignature = '';
        this.unsubscribed = false;
    }

    // el:挂载容器(#stage,已是 position:relative,面板绝对定位其中)。
    // W12 起入口在底部导航(main.js 接线),本面板不再创建自己的 FAB 按钮
    mount(el) {
        this.rootEl = document.createElement('div');
        this.rootEl.className = 'market-panel';
        this.rootEl.hidden = true;
        el.appendChild(this.rootEl);

        // 快照更新后:与面板结构无关的变化(资源 / 资金随轮询漂移)只原地改文本,
        // 免得每 10 秒把数量输入框重建一次、把玩家正在输的数字冲掉
        onChange(() => {
            if (this.unsubscribed || !this.opened) return;
            if (this.signature() === this.lastSignature) {
                this.syncValues();
                return;
            }
            this.render();
        });
    }

    async open() {
        if (this.onOpen) this.onOpen(this);

        this.opened = true;
        this.rootEl.hidden = false;
        this.render();

        await this.loadPrices();
        if (!this.opened) return; // 价目还在路上时玩家已经关掉面板

        this.render();
        this.startTicker();
    }

    close() {
        if (!this.opened) return;
        this.opened = false;
        this.stopTicker();
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

    market() {
        return this.state.market || null;
    }

    prices() {
        const m = this.market();
        return m && Array.isArray(m.prices) ? m.prices : [];
    }

    priceOf(code) {
        const list = this.prices();
        for (let i = 0; i < list.length; i++) {
            if (list[i].resource_code === code) return list[i];
        }
        return null;
    }

    // 持有量:资金单列在 city.money(与后端口径一致),其余走 city.resources
    heldOf(code) {
        const city = this.state.city || {};
        if (code === 'money') return Number(city.money) || 0;
        return Number((city.resources || {})[code]) || 0;
    }

    // 数量:输入框里的值只存在实例上,重绘时不丢
    quantity() {
        const q = Math.floor(Number(this.qty) || 0);
        return q > 0 ? q : 0;
    }

    // 面板结构指纹:资源余额与资金**不进**指纹(它们每轮询都在动,走 syncValues 原地更新)
    signature() {
        const m = this.market();
        return [
            m ? m.window_index : '-',
            m ? (m.market_enabled ? 1 : 0) : '-',
            this.prices().length,
            this.selected || '-',
            this.side,
            this.busy ? 1 : 0,
            this.loading ? 1 : 0,
            this.limitInfo ? this.limitInfo.resource_code : '-',
            // 事件冲击进指纹:它随价目刷新变化,变了要重画横幅与行标记
            this.prices().filter((p) => Number(p.buy_price_pct) > 0).length,
        ].join('|');
    }

    // ---- 渲染 ----

    render() {
        if (!this.rootEl || !this.opened) return;
        this.lastSignature = this.signature();
        this.refs = {};
        this.rootEl.innerHTML = '';

        const m = this.market();

        const header = document.createElement('div');
        header.className = 'market-header';

        const title = document.createElement('span');
        title.className = 'market-title';
        title.textContent = '市场';
        header.appendChild(title);

        const countdown = document.createElement('span');
        countdown.className = 'market-countdown';
        countdown.textContent = m ? '本窗剩余 --:--' : '加载中...';
        header.appendChild(countdown);
        this.refs.countdown = countdown;

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'market-close';
        closeBtn.title = '关闭';
        closeBtn.setAttribute('aria-label', '关闭');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => this.close());
        header.appendChild(closeBtn);

        this.rootEl.appendChild(header);

        const money = document.createElement('div');
        money.className = 'market-money';
        money.textContent = '资金 ' + fmtDec(this.heldOf('money'));
        this.refs.money = money;
        this.rootEl.appendChild(money);

        // 事件价格冲击:W7 起价目端点直接下发本城的 buy_price_pct(只读口径),
        // 不再依赖「买过一次才知道」的成交响应 —— 横幅在下单之前就能给出
        const impacted = this.prices().filter((p) => Number(p.buy_price_pct) > 0);
        if (impacted.length) {
            const banner = document.createElement('div');
            banner.className = 'market-banner';
            banner.textContent = '事件价格冲击生效中:'
                + impacted.map((p) => resourceName(p.resource_code)
                    + ' +' + fmtDec(Number(p.buy_price_pct) * 100, 1) + '%').join(' · ')
                + '(只影响本城买入价,卖出不受影响)';
            this.rootEl.appendChild(banner);
        }

        this.rootEl.appendChild(this.makeTable());

        if (this.selected) this.rootEl.appendChild(this.makeForm());

        // 停市:整面板遮罩(价目仍看得见,只挡买卖 —— 与后端 MARKET_CLOSED 的语义一致)
        if (m && m.market_enabled === false) {
            const mask = document.createElement('div');
            mask.className = 'market-mask';

            const text = document.createElement('div');
            text.className = 'market-mask-text';
            text.textContent = '市场已停市,暂时无法买卖。行情仍在更新,请稍后再来。';
            mask.appendChild(text);

            this.rootEl.appendChild(mask);
        }

        this.tick();
    }

    makeTable() {
        const box = document.createElement('div');
        box.className = 'market-list';

        if (this.loading && !this.prices().length) {
            const empty = document.createElement('div');
            empty.className = 'market-empty';
            empty.textContent = '价目加载中...';
            box.appendChild(empty);
            return box;
        }

        if (!this.prices().length) {
            const empty = document.createElement('div');
            empty.className = 'market-empty';
            empty.textContent = '价目加载失败,关掉面板重开可重试。';
            box.appendChild(empty);
            return box;
        }

        const head = document.createElement('div');
        head.className = 'market-row is-head';
        ['资源', '现价', '涨跌', '波动'].forEach((t, i) => {
            const cell = document.createElement('span');
            cell.className = 'market-cell c' + i;
            cell.textContent = t;
            head.appendChild(cell);
        });
        box.appendChild(head);

        this.prices().forEach((p) => box.appendChild(this.makeRow(p)));

        return box;
    }

    makeRow(p) {
        const base = Number(p.base_price) || 0;
        const now = Number(p.price) || 0;
        // 涨跌相对**基础价**(v3.2 §8 的锚点),不是相对上一窗 —— 玩家要判断的是「现在贵不贵」
        const pct = base > 0 ? (now / base - 1) * 100 : 0;
        const up = pct > 0.05;
        const down = pct < -0.05;

        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'market-row'
            + (p.tradeable ? '' : ' is-locked')
            + (this.selected === p.resource_code ? ' is-selected' : '');
        row.disabled = !p.tradeable;

        const name = document.createElement('span');
        name.className = 'market-cell c0';
        name.textContent = resourceName(p.resource_code);

        // 事件价格冲击(W7 起只读可查):本城买入侧才有,卖出侧口径上恒 0。
        // 标在资源名后面,让玩家在下单**之前**就知道「这一行为什么比别人贵」
        if (Number(p.buy_price_pct)) {
            const evt = document.createElement('span');
            evt.className = 'market-tag is-event';
            evt.textContent = '事件 +' + fmtDec(Number(p.buy_price_pct) * 100, 0) + '%';
            name.appendChild(evt);
        }

        if (!p.tradeable) {
            const tag = document.createElement('span');
            tag.className = 'market-tag';
            // 三种不可交易情形对玩家的结论都是「买卖不了」,但原因不同,提示分开写
            tag.textContent = p.trade_mode === 'capacity_contract' ? '产能合约' : '不可交易';
            name.appendChild(tag);
        }
        row.appendChild(name);

        const cur = document.createElement('span');
        cur.className = 'market-cell c1';
        cur.textContent = price(now);
        row.appendChild(cur);

        // 涨红跌绿(中文行情惯例);买入方视角下涨价本来就是坏消息,配色与 HUD 的告警色系一致
        const change = document.createElement('span');
        change.className = 'market-cell c2' + (up ? ' is-up' : (down ? ' is-down' : ''));
        change.textContent = (up ? '▲ +' : (down ? '▼ ' : '— ')) + fmtDec(pct, 1) + '%';
        row.appendChild(change);

        const vol = document.createElement('span');
        vol.className = 'market-cell c3';
        vol.textContent = '±' + fmtDec((Number(p.volatility) || 0) * 100, 0) + '%';
        row.appendChild(vol);

        row.addEventListener('click', () => {
            this.selected = this.selected === p.resource_code ? null : p.resource_code;
            this.qty = 1;
            this.limitInfo = null;
            this.render();
        });

        return row;
    }

    makeForm() {
        const p = this.priceOf(this.selected);
        const box = document.createElement('div');
        box.className = 'market-form';
        if (!p) return box;

        const head = document.createElement('div');
        head.className = 'market-form-head';

        const name = document.createElement('span');
        name.className = 'market-form-name';
        name.textContent = resourceName(p.resource_code);
        head.appendChild(name);

        const held = document.createElement('span');
        held.className = 'market-form-held';
        held.textContent = '持有 ' + fmtDec(this.heldOf(p.resource_code));
        this.refs.held = held;
        head.appendChild(held);

        box.appendChild(head);

        // 买 / 卖 切换
        const sides = document.createElement('div');
        sides.className = 'market-sides';
        [{ key: SIDE_BUY, label: '买入' }, { key: SIDE_SELL, label: '卖出' }].forEach((s) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'market-chip' + (this.side === s.key ? ' active' : '');
            chip.textContent = s.label;
            chip.addEventListener('click', () => {
                this.side = s.key;
                this.limitInfo = null;
                this.render();
            });
            sides.appendChild(chip);
        });
        box.appendChild(sides);

        // 数量:输入框的值只存在实例上,重绘不丢;改数量只刷新预估文本,不整块重建
        const qtyRow = document.createElement('div');
        qtyRow.className = 'market-qty';

        const minus = document.createElement('button');
        minus.type = 'button';
        minus.className = 'market-step';
        minus.textContent = '−';
        minus.setAttribute('aria-label', '数量减一');
        minus.addEventListener('click', () => this.setQuantity(this.quantity() - 1));
        qtyRow.appendChild(minus);

        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'market-qty-input';
        input.min = '1';
        input.step = '1';
        input.inputMode = 'numeric';
        input.value = String(this.quantity());
        input.setAttribute('aria-label', '交易数量');
        input.addEventListener('input', () => {
            this.qty = Math.max(0, Math.floor(Number(input.value) || 0));
            this.syncValues();
        });
        this.refs.input = input;
        qtyRow.appendChild(input);

        const plus = document.createElement('button');
        plus.type = 'button';
        plus.className = 'market-step';
        plus.textContent = '+';
        plus.setAttribute('aria-label', '数量加一');
        plus.addEventListener('click', () => this.setQuantity(this.quantity() + 1));
        qtyRow.appendChild(plus);

        const max = document.createElement('button');
        max.type = 'button';
        max.className = 'market-step market-step-wide';
        max.textContent = '最大';
        max.addEventListener('click', () => this.setQuantity(this.maxQuantity(p)));
        qtyRow.appendChild(max);

        box.appendChild(qtyRow);

        const estimate = document.createElement('div');
        estimate.className = 'market-estimate';
        this.refs.estimate = estimate;
        box.appendChild(estimate);

        const note = document.createElement('div');
        note.className = 'market-note';
        const maxOrder = Number((this.market() || {}).market_max_order_quantity) || 0;
        note.textContent = (this.side === SIDE_BUY
            ? '滑点已计入预估(数量越大成交价越高)'
            : '滑点已计入预估(数量越大成交价越低)')
            + (maxOrder > 0 ? ' · 单笔上限 ' + fmt(maxOrder) : '')
            + ' · 一切以服务器成交为准';
        box.appendChild(note);

        // 上一笔成交回带的额度剩余:让玩家知道这一窗还能做多少
        if (this.quota && this.quota.resource_code === p.resource_code) {
            const quota = document.createElement('div');
            quota.className = 'market-note';
            quota.textContent = '本窗剩余额度 ' + fmtDec(this.quota.window_remaining)
                + ' · 本小时剩余 ' + fmtDec(this.quota.hourly_remaining);
            box.appendChild(quota);
        }

        // 被额度挡下时的两口径说明(等下一窗 vs 建市场类建筑)
        if (this.limitInfo && this.limitInfo.resource_code === p.resource_code) {
            const warn = document.createElement('div');
            warn.className = 'market-warn';
            warn.textContent = this.limitInfo.text;
            box.appendChild(warn);
        }

        const submit = document.createElement('button');
        submit.type = 'button';
        submit.className = 'market-btn' + (this.side === SIDE_BUY ? ' is-buy' : ' is-sell');
        submit.textContent = this.busy
            ? '提交中...'
            : (this.side === SIDE_BUY ? '买入' : '卖出');
        submit.disabled = this.busy || this.quantity() <= 0;
        submit.addEventListener('click', () => this.doTrade(p));
        this.refs.submit = submit;
        box.appendChild(submit);

        this.syncValues();
        return box;
    }

    setQuantity(n) {
        this.qty = Math.max(0, Math.floor(Number(n) || 0));
        if (this.refs && this.refs.input) this.refs.input.value = String(this.qty);
        this.syncValues();
    }

    // 「最大」只是显示层的便利值(买:资金 ÷ 参考买价;卖:当前持有),
    // 再夹一层服务器下发的单笔硬上限(§69,与流动性额度是两道独立的闸)。
    // 真正能不能成交仍由服务器判(余额 / 库存 / 仓储 / 额度四道闸)
    maxQuantity(p) {
        const hardCap = Number((this.market() || {}).market_max_order_quantity) || 0;
        const clamp = (n) => (hardCap > 0 ? Math.min(n, hardCap) : n);

        if (this.side === SIDE_SELL) return clamp(Math.floor(this.heldOf(p.resource_code)));

        const unit = Number(p.buy_price) || Number(p.price) || 0;
        if (unit <= 0) return 0;
        return clamp(Math.floor(this.heldOf('money') / unit));
    }

    // 滑点率(W7 起可算):min(上限, 系数 × 数量 / 有效流动性)。
    // 三个数全部由服务器下发 —— 系数与上限是全局参数、有效流动性是逐资源的(定义值 × 全局倍率)。
    // 参数缺失(老响应)时退回 0:宁可预估偏低也不自己编一个系数
    slippageRate(p, qty) {
        const m = this.market() || {};
        const k = Number(m.slippage_coefficient) || 0;
        const liquidity = Number(p.effective_liquidity) || 0;
        const cap = Number(m.max_slippage_rate) || 0;
        if (k <= 0 || liquidity <= 0 || qty <= 0) return 0;

        const raw = k * qty / liquidity;
        return cap > 0 ? Math.min(cap, raw) : raw;
    }

    // 前端预估:与 TradeService 的成交公式逐项对齐(W7 契约补齐之后才算得出来)
    //   买:成交单价 = price × (1 + 滑点率) × max(0, 1 + buy_price_pct);应付 = 单价 × 数量 × (1 + 费率)
    //   卖:成交单价 = price × (1 − 滑点率);可得 = 单价 × 数量 × (1 − 费率),夹 ≥ 0
    // **仍然只是预估**:成交时服务器会在城市行锁内用最新的价格窗口与事件强度重算,
    // 任何差异一律以服务器返回的成交明细为准(§45 / §66)。
    // 已知会偏的一处:商人 NPC 的减费(market_fee_pct)不在价目端点的 fee_rate 里,
    // 招了商人的城市实际手续费会比这里低 —— 交付汇报的「待下一步」已记下
    estimate(p) {
        const qty = this.quantity();
        const base = Number(p.price) || 0;
        const feeRate = Number(p.fee_rate) || 0;
        const slip = this.slippageRate(p, qty);
        const eventPct = this.side === SIDE_BUY ? (Number(p.buy_price_pct) || 0) : 0;

        const unit = this.side === SIDE_BUY
            ? base * (1 + slip) * Math.max(0, 1 + eventPct)
            : base * (1 - slip);

        const gross = unit * qty;
        const fee = gross * feeRate;
        const total = this.side === SIDE_BUY ? gross + fee : Math.max(0, gross - fee);

        return { unit, gross, fee, total, slip, eventPct, feeRate };
    }

    estimateText(p) {
        const qty = this.quantity();
        if (!p || qty <= 0) return '填入数量后显示预估';

        const e = this.estimate(p);
        const parts = ['预估单价 ' + price(e.unit) + ' × ' + fmt(qty) + ' = ' + fmtDec(e.gross)];
        parts.push('手续费 ' + fmtDec(e.fee) + '(' + fmtDec(e.feeRate * 100, 1) + '%)');
        parts.push('滑点 ' + (this.side === SIDE_BUY ? '+' : '−') + fmtDec(e.slip * 100, 2) + '%');
        if (e.eventPct) parts.push('事件冲击 +' + fmtDec(e.eventPct * 100, 1) + '%');
        parts.push((this.side === SIDE_BUY ? '约需支付 ' : '约可收到 ') + fmtDec(e.total));

        return parts.join(' · ');
    }

    // 指纹未变时的轻量同步:只改随时间/输入漂移的文本,不动 DOM 结构
    syncValues() {
        if (!this.refs) return;
        if (this.refs.money) this.refs.money.textContent = '资金 ' + fmtDec(this.heldOf('money'));

        const p = this.selected ? this.priceOf(this.selected) : null;
        if (p && this.refs.held) this.refs.held.textContent = '持有 ' + fmtDec(this.heldOf(p.resource_code));
        if (p && this.refs.estimate) this.refs.estimate.textContent = this.estimateText(p);
        if (this.refs.submit) this.refs.submit.disabled = this.busy || this.quantity() <= 0;
    }

    // ---- 窗口倒计时 ----

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

    // 纯视觉倒计时:到点只负责重新拉一次价目表,绝不本地推算新价格(服务器权威,§30)
    tick() {
        const m = this.market();
        if (!m || !this.refs || !this.refs.countdown) return;

        const end = Date.parse(m.next_window_at);
        if (!end) return;

        const remain = (end - (Date.now() + this.skewMs)) / 1000;
        this.refs.countdown.textContent = remain > 0 ? '本窗剩余 ' + clock(remain) : '调价中...';

        if (remain <= 0 && !this.pendingRefresh) {
            this.pendingRefresh = true;
            this.loadPrices(true).then(() => {
                this.pendingRefresh = false;
                if (this.opened) this.render();
            });
        }
    }

    // ---- 请求 ----

    async loadPrices(silent) {
        this.loading = true;
        if (!silent && this.opened) this.render();

        try {
            const data = await this.api.get('/api/market/prices');
            // 时钟差:倒计时按服务器时间走,玩家改本机时间也不会让窗口提前翻
            const serverAt = Date.parse(data.server_time);
            this.skewMs = serverAt ? serverAt - Date.now() : 0;
            setState({ market: data });
        } catch (e) {
            if (!silent) notifyError(errorText(e, '价目加载失败,请稍后重试'));
        } finally {
            this.loading = false;
        }
    }

    // MARKET_LIMIT_REACHED 的两口径文案:到底该等下一窗,还是该去建市场类建筑。
    // 判据来自响应 details —— 城市侧上限 = min(流动性口径, 贸易吞吐口径),谁小谁在卡人
    limitText(details) {
        if (!details) return '成交量已达上限,等下一窗再试';

        const hourlyLeft = Number(details.hourly_remaining);
        if (!isNaN(hourlyLeft) && hourlyLeft <= 0) {
            return '本小时成交额度已用完(上限 ' + fmtDec(details.hourly_quota) + '),要等下一小时';
        }

        const liquidity = Number(details.liquidity_quota) || 0;
        const capacityQuota = Number(details.trade_capacity_quota) || 0;
        const windowQuota = fmtDec(details.window_quota);
        const windowLeft = fmtDec(details.window_remaining);

        if (capacityQuota < liquidity) {
            return '贸易容量不足:本窗最多 ' + windowQuota + '(还剩 ' + windowLeft
                + ')。当前贸易容量 ' + fmtDec(details.trade_capacity)
                + ',建市场 / 商贸类建筑可以提高这条上限。';
        }

        return '本窗额度已满:上限 ' + windowQuota + ',还剩 ' + windowLeft + ',等下一窗再交易。';
    }

    async doTrade(p) {
        if (this.busy) return;
        const qty = this.quantity();
        if (qty <= 0) return;

        this.busy = true;
        this.limitInfo = null;
        this.render();

        const side = this.side;

        try {
            const diff = await this.api.post(side === SIDE_BUY ? '/api/market/buy' : '/api/market/sell', {
                resource_code: p.resource_code,
                quantity: qty,
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);

            const t = diff.trade || null;
            if (t) {
                this.quota = {
                    resource_code: t.resource_id,
                    window_remaining: t.window_remaining,
                    hourly_remaining: t.hourly_remaining,
                };
                notifySuccess((side === SIDE_BUY ? '买入 ' : '卖出 ') + resourceName(t.resource_id)
                    + ' ×' + fmt(t.quantity)
                    + ' · 成交单价 ' + price(t.unit_price)
                    + ' · 手续费 ' + fmtDec(t.fee)
                    + ' · 资金 ' + (t.money_delta >= 0 ? '+' : '') + fmtDec(t.money_delta));
            } else {
                // 幂等重放路径拿不到本次成交明细(服务器没有第二次成交,这是对的)
                notifySuccess('这笔交易已经成交过了,资源与资金已同步');
            }
        } catch (err) {
            const code = err && err.error;
            const details = err && err.body ? err.body.details : null;

            if (code === 'MARKET_LIMIT_REACHED') {
                this.limitInfo = { resource_code: p.resource_code, text: this.limitText(details) };
                notifyError(this.limitInfo.text);
            } else if (code === 'STORAGE_FULL' && details) {
                notifyError('仓储放不下:上限 ' + fmtDec(details.storage_capacity)
                    + ',当前已有 ' + fmtDec(details.current_amount) + ',先扩建仓库或少买一些');
            } else {
                notifyError(errorText(err, '交易失败,请重试', TRADE_ERRORS));
            }

            // 旧 revision / 停市 / 目标失效:拉一次权威状态,玩家看到新数字后可直接重试
            if (code === 'REVISION_CONFLICT' || code === 'INSUFFICIENT_RESOURCE' || code === 'STORAGE_FULL') {
                await this.refreshCity();
            }
            if (code === 'MARKET_CLOSED' || code === 'RESOURCE_NOT_TRADEABLE') {
                await this.loadPrices(true);
            }
        } finally {
            this.busy = false;
            this.render();
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

    // 成交响应:{ revision, resources, money, delta[, trade] }
    // 资源与资金一律用服务器返回值覆盖,不做本地推算
    applyDiff(diff) {
        const city = this.state.city;
        if (!city || !diff) return;

        setState({
            city: Object.assign({}, city, {
                revision: diff.revision,
                resources: Object.assign({}, city.resources, diff.resources),
                money: diff.money,
            }),
        });

        updateHud(this.state.city);
    }
}
