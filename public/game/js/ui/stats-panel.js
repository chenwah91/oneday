// 村庄统计面板(W12 前端波):把散在 HUD 各处的读数集中成一页分区文本。
//
// 面板范式照 ui/market-panel.js(constructor({api,state,onOpen}) / mount / open / close /
// opened / destroy),但**不建 FAB 入口按钮**:本波起面板由底部导航开合(框架侧统一接线),
// mount 只建面板 DOM,挂 #stage 内绝对定位。
//
// 数据来源:全部读城市快照(state.city)。打开时先 GET /api/city 刷一次并 setState,
// 已打开期间用 onChange 跟随主轮询刷新。所有数值都是服务端派生字段,
// 前端不做任何二次经济推算(CLAUDE §5:客户端不算经济结果)。
// 建筑分类计数用 /api/definitions/buildings 的 category(building_id → category 映射),
// 定义已由建造面板启动时存进 state.definitions,这里只在缺失时兜底拉一次。
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
import { onChange, setState } from '../core/state.js';
import { errorText } from '../core/error-messages.js';
import { notifyError } from './notification.js';
import { updateHud } from './hud.js';
import { fmt, fmtDec } from '../utils/format.js';
import { categoryName, threatLevelName, BUILDING_CATEGORY_NAMES } from '../core/enum-names.js';

// 财政预警(§10.5)三态 → 提示文字:级别由服务端派生(city.fiscal_warning),
// 前端只负责上色与话术,绝不自己拿资金除维护再判一次阈值(与 hud.js 同一口径)
const FISCAL_TITLES = {
    yellow: '资金可支撑维护不足 10 分钟',
    red: '维护费即将付不出,建筑将半停工',
};

// 百分比显示:factor / rate / efficiency 这类比值统一按整数百分比给(与 hud.js 的 pct 同款)
function pct(n, digits) {
    return fmtDec((Number(n) || 0) * 100, typeof digits === 'number' ? digits : 0) + '%';
}

export class StatsPanel {
    constructor({ api, state, onOpen }) {
        this.api = api;
        this.state = state;
        // onOpen:打开时通知外部(装配处用它做面板互斥),自己不知道别的面板是谁
        this.onOpen = onOpen || null;

        this.rootEl = null;
        this.bodyEl = null;
        this.opened = false;
        this.unsubscribed = false;

        // 快照更新后跟随重绘:面板是纯只读文本,整块重建不会打断任何输入,
        // 只要把滚动位置带过去就不影响玩家正在看的分区
        onChange(() => {
            if (this.unsubscribed || !this.opened) return;
            this.render();
        });
    }

    // el:挂载容器(#stage,已是 position:relative,面板绝对定位其中)
    mount(el) {
        this.rootEl = document.createElement('div');
        this.rootEl.className = 'stats-panel';
        this.rootEl.hidden = true;
        el.appendChild(this.rootEl);
    }

    async open() {
        if (this.onOpen) this.onOpen(this);

        this.opened = true;
        this.rootEl.hidden = false;
        this.render();

        // 打开时刷一次权威快照(轮询间隔里的变化立刻可见);建筑定义缺失时兜底拉一次
        await Promise.all([this.refreshCity(), this.ensureDefinitions()]);
        if (!this.opened) return; // 请求还在路上时玩家已经关掉面板

        this.render();
    }

    close() {
        if (!this.opened) return;
        this.opened = false;
        this.bodyEl = null;
        if (this.rootEl) {
            this.rootEl.hidden = true;
            this.rootEl.innerHTML = '';
        }
    }

    destroy() {
        this.unsubscribed = true;
        if (this.rootEl && this.rootEl.parentNode) this.rootEl.parentNode.removeChild(this.rootEl);
        this.rootEl = null;
        this.bodyEl = null;
    }

    // ---- 请求 ----

    async refreshCity() {
        try {
            const res = await this.api.get('/api/city');
            setState({ city: res.city });
            updateHud(this.state.city);
        } catch (e) {
            notifyError(errorText(e, '统计刷新失败,稍后会随轮询自动更新'));
        }
    }

    // 建筑定义(building_id → category):建造面板启动时已存进 state.definitions,
    // 这里只兜底;拉不到也不报错 —— 分类计数那几行显示不出来,总数/在建仍然可看
    async ensureDefinitions() {
        if (this.state.definitions) return;
        try {
            const data = await this.api.get('/api/definitions/buildings');
            setState({ definitions: data.buildings });
        } catch (e) {
            // 静默:定义是增强信息,不挡面板主体
        }
    }

    // ---- 渲染 ----

    render() {
        if (!this.rootEl || !this.opened) return;
        const prevScroll = this.bodyEl ? this.bodyEl.scrollTop : 0;
        this.rootEl.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'stats-header';

        const title = document.createElement('span');
        title.className = 'stats-title';
        title.textContent = '村庄统计';
        header.appendChild(title);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'stats-close';
        closeBtn.title = '关闭';
        closeBtn.setAttribute('aria-label', '关闭');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => this.close());
        header.appendChild(closeBtn);

        this.rootEl.appendChild(header);

        const body = document.createElement('div');
        body.className = 'stats-body';
        this.bodyEl = body;

        const city = this.state.city;
        if (!city) {
            const empty = document.createElement('div');
            empty.className = 'stats-empty';
            empty.textContent = '快照加载中...';
            body.appendChild(empty);
            this.rootEl.appendChild(body);
            return;
        }

        this.makeSection(body, '概览', [
            ['城市名', city.name || '-'],
            ['时代', city.era ? city.era.era_key : '-'],
            ['数据版本', 'rev ' + fmt(city.revision)],
        ]);

        this.makeSection(body, '人口', [
            ['人口 / 容量', fmt(city.population) + ' / ' + fmt(city.population_capacity)],
            ['劳动力 已用 / 可用', fmt(city.assigned_workers) + ' / ' + fmt(city.available_workers)],
            // 民生三值:服务器给 float(收敛斜率需要小数),显示只取整数(与 HUD 同口径)
            ['幸福度', String(Math.round(Number(city.happiness) || 0))],
            ['健康度', String(Math.round(Number(city.health) || 0))],
            ['治安度', String(Math.round(Number(city.security) || 0))],
        ]);

        this.makeEconomySection(body, city);
        this.makePowerSection(body, city);
        this.makeDefenseSection(body, city);
        this.makeGovernanceSection(body, city);
        this.makeBuildingSection(body, city);

        this.rootEl.appendChild(body);
        body.scrollTop = prevScroll;
    }

    // 经济:资金按财政预警上色(黄 <10 分钟 / 红 <3 分钟,级别由服务端派生,参照 hud.js)
    makeEconomySection(body, city) {
        const section = this.makeSection(body, '经济', []);

        const moneyRow = this.makeRow('资金', fmt(city.money));
        const fiscal = city.fiscal_warning || 'none';
        const moneyVal = moneyRow.querySelector('.stats-row-value');
        moneyVal.classList.toggle('stats-warn', fiscal === 'yellow');
        moneyVal.classList.toggle('stats-alert', fiscal === 'red');
        if (FISCAL_TITLES[fiscal]) moneyRow.title = FISCAL_TITLES[fiscal];
        section.appendChild(moneyRow);

        section.appendChild(this.makeRow('税收', fmtDec(city.tax_income_per_min) + ' /分'));
        section.appendChild(this.makeRow('仓储上限', fmt(city.storage_capacity)));
    }

    // 电力:字段与格式参照 hud.js 的 DETAIL_BUILDERS.power(装机/可用/需求/余量/产能系数)
    makePowerSection(body, city) {
        const p = city.power || {};
        this.makeSection(body, '电力', [
            ['名义装机', fmtDec(p.capacity_per_min, 1) + ' /分'],
            ['可用发电', fmtDec(p.available_per_min, 1) + ' /分'],
            ['全城耗电', fmtDec(p.demand_per_min, 1) + ' /分'],
            ['余量', fmtDec(p.spare_per_min, 1) + ' /分'],
            ['产能系数', pct(p.factor)],
        ]);
    }

    // 国防:威胁等级中文由服务端下发(threat_level_zh),老响应缺失时用前端兜底表翻译
    makeDefenseSection(body, city) {
        const d = city.defense || {};
        this.makeSection(body, '国防', [
            ['威胁等级', d.threat_level_zh || threatLevelName(d.threat_level) || '-'],
            ['有效国防值', fmtDec(d.defense_score, 1)],
            ['威胁需求', fmtDec(d.threat_demand, 1)],
            ['覆盖率', pct(d.coverage)],
        ]);
    }

    makeGovernanceSection(body, city) {
        const g = city.governance || {};
        this.makeSection(body, '治理', [
            ['治理负载', pct(g.load)],
            ['治理效率', pct(g.efficiency)],
            ['有效容量', fmtDec(g.capacity, 1)],
        ]);
    }

    // 建筑:总数 / 在建数(status === 'constructing')+ 按定义 category 的分类计数。
    // 分类中文名走 enum-names.js 的 categoryName;拿不到定义时只显示总数与在建
    makeBuildingSection(body, city) {
        const buildings = Array.isArray(city.buildings) ? city.buildings : [];
        const constructing = buildings.filter((b) => b.status === 'constructing').length;

        const rows = [
            ['总数', fmt(buildings.length)],
            ['在建', fmt(constructing)],
        ];

        // building_id → category 映射(/api/definitions/buildings,已缓存在 state.definitions)
        const catById = {};
        (this.state.definitions || []).forEach((d) => { catById[d.building_id] = d.category; });

        const counts = {};
        buildings.forEach((b) => {
            const cat = catById[b.building_id];
            if (!cat) return;
            counts[cat] = (counts[cat] || 0) + 1;
        });

        // 按枚举表的固定顺序列出(只列非零的分类);定义表新增的未知分类跟在最后,回落显示 code
        Object.keys(BUILDING_CATEGORY_NAMES).forEach((cat) => {
            if (counts[cat]) rows.push([categoryName(cat), fmt(counts[cat])]);
        });
        Object.keys(counts).forEach((cat) => {
            if (!BUILDING_CATEGORY_NAMES[cat]) rows.push([categoryName(cat), fmt(counts[cat])]);
        });

        this.makeSection(body, '建筑', rows);
    }

    makeSection(body, title, rows) {
        const t = document.createElement('div');
        t.className = 'stats-section-title';
        t.textContent = title;
        body.appendChild(t);

        const box = document.createElement('div');
        box.className = 'stats-section';
        rows.forEach((r) => box.appendChild(this.makeRow(r[0], r[1])));
        body.appendChild(box);

        return box;
    }

    makeRow(key, value) {
        const row = document.createElement('div');
        row.className = 'stats-row';

        const k = document.createElement('span');
        k.className = 'stats-row-key';
        k.textContent = key;
        row.appendChild(k);

        const v = document.createElement('span');
        v.className = 'stats-row-value';
        v.textContent = value;
        row.appendChild(v);

        return row;
    }
}
