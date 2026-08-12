// 背包资源面板(W12 前端波):资金 + 全部资源的库存与每分钟速率。
//
// 面板范式照 ui/market-panel.js(constructor({api,state,onOpen}) / mount / open / close /
// opened / destroy),但**不建 FAB 入口按钮**:本波起面板由底部导航开合(框架侧统一接线),
// mount 只建面板 DOM,挂 #stage 内绝对定位。
//
// 数据来源:
//   - 数量 / 速率:城市快照(city.resources / city.rates_per_min,服务端派生,前端不推算)。
//     打开时先 GET /api/city 刷一次并 setState,已打开期间用 onChange 跟随主轮询刷新。
//   - 清单顺序与显示名:GET /api/definitions/resources(首次打开拉一次,缓存在实例上;
//     state.resourceNames 只是 code→名字的 map,没有顺序,所以这里要自己留一份定义列表)。
//   - 仓储上限口径:SimulationService 把**每种资源各自**夹在 [0, storageCapacity]
//     (不是全部资源加总共享一个上限),顶部说明按这个口径写,不自己发明规则。
//   - 资金不占仓储(§10.5 资金是纯数值),单列一行,不进资源清单。
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
import { onChange, setState } from '../core/state.js';
import { errorText } from '../core/error-messages.js';
import { notifyError } from './notification.js';
import { updateHud } from './hud.js';
import { fmt, fmtDec } from '../utils/format.js';
import { resourceName } from '../modules/resources.js';

export class BackpackPanel {
    constructor({ api, state, onOpen }) {
        this.api = api;
        this.state = state;
        // onOpen:打开时通知外部(装配处用它做面板互斥),自己不知道别的面板是谁
        this.onOpen = onOpen || null;

        this.rootEl = null;
        this.listEl = null;
        this.opened = false;
        this.unsubscribed = false;
        this.defs = null; // /api/definitions/resources 的定义列表(带顺序),首次打开拉一次

        // 快照更新后跟随重绘:面板是纯只读文本,整块重建不打断任何输入,只保滚动位置
        onChange(() => {
            if (this.unsubscribed || !this.opened) return;
            this.render();
        });
    }

    // el:挂载容器(#stage,已是 position:relative,面板绝对定位其中)
    mount(el) {
        this.rootEl = document.createElement('div');
        this.rootEl.className = 'backpack-panel';
        this.rootEl.hidden = true;
        el.appendChild(this.rootEl);
    }

    async open() {
        if (this.onOpen) this.onOpen(this);

        this.opened = true;
        this.rootEl.hidden = false;
        this.render();

        // 打开时刷一次权威快照 + 确保资源定义就位(定义只拉一次)
        await Promise.all([this.refreshCity(), this.loadDefs()]);
        if (!this.opened) return; // 请求还在路上时玩家已经关掉面板

        this.render();
    }

    close() {
        if (!this.opened) return;
        this.opened = false;
        this.listEl = null;
        if (this.rootEl) {
            this.rootEl.hidden = true;
            this.rootEl.innerHTML = '';
        }
    }

    destroy() {
        this.unsubscribed = true;
        if (this.rootEl && this.rootEl.parentNode) this.rootEl.parentNode.removeChild(this.rootEl);
        this.rootEl = null;
        this.listEl = null;
    }

    // ---- 请求 ----

    async refreshCity() {
        try {
            const res = await this.api.get('/api/city');
            setState({ city: res.city });
            updateHud(this.state.city);
        } catch (e) {
            notifyError(errorText(e, '背包刷新失败,稍后会随轮询自动更新'));
        }
    }

    async loadDefs() {
        if (this.defs) return;
        try {
            const data = await this.api.get('/api/definitions/resources');
            this.defs = Array.isArray(data.resources) ? data.resources : null;
        } catch (e) {
            // 拉不到定义时清单退化成「快照里已有的资源」(见 resourceCodes 的兜底),不挡面板
        }
    }

    // 清单要列的资源 code(按定义顺序;资金单列,不进清单)。
    // 定义拉不到时退化:只列快照里出现过的资源,显示名走 resourceName 兜底
    resourceCodes() {
        if (this.defs) {
            return this.defs.map((d) => d.code).filter((c) => c && c !== 'money');
        }
        const city = this.state.city || {};
        return Object.keys(city.resources || {}).filter((c) => c !== 'money').sort();
    }

    // ---- 渲染 ----

    render() {
        if (!this.rootEl || !this.opened) return;
        const prevScroll = this.listEl ? this.listEl.scrollTop : 0;
        this.rootEl.innerHTML = '';

        const city = this.state.city || {};
        const resources = city.resources || {};
        const rates = city.rates_per_min || {};

        const header = document.createElement('div');
        header.className = 'backpack-header';

        const title = document.createElement('span');
        title.className = 'backpack-title';
        title.textContent = '背包';
        header.appendChild(title);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'backpack-close';
        closeBtn.title = '关闭';
        closeBtn.setAttribute('aria-label', '关闭');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => this.close());
        header.appendChild(closeBtn);

        this.rootEl.appendChild(header);

        // 仓储上限:每种资源各自夹在这个上限内(SimulationService 逐资源 clamp 的口径)
        const summary = document.createElement('div');
        summary.className = 'backpack-summary';
        summary.textContent = '仓储上限 ' + fmt(city.storage_capacity) + '(每种资源各自计)';
        this.rootEl.appendChild(summary);

        // 资金单列:不占仓储,也不进下面的资源清单
        const money = document.createElement('div');
        money.className = 'backpack-money';

        const moneyName = document.createElement('span');
        moneyName.className = 'backpack-name';
        moneyName.textContent = '💰 ' + resourceName('money');
        money.appendChild(moneyName);

        const moneyVal = document.createElement('span');
        moneyVal.className = 'backpack-amount';
        moneyVal.textContent = fmt(city.money);
        money.appendChild(moneyVal);

        this.rootEl.appendChild(money);

        // 资源清单:定义顺序;数量没有按 0 显示;速率带正负号,0 不显示
        const list = document.createElement('div');
        list.className = 'backpack-list';
        this.listEl = list;

        const codes = this.resourceCodes();
        if (!codes.length) {
            const empty = document.createElement('div');
            empty.className = 'backpack-empty';
            empty.textContent = this.state.city ? '还没有任何资源' : '快照加载中...';
            list.appendChild(empty);
        }

        codes.forEach((code) => {
            list.appendChild(this.makeRow(code, resources[code], rates[code]));
        });

        this.rootEl.appendChild(list);

        const note = document.createElement('div');
        note.className = 'backpack-note';
        note.textContent = '工具装备在『工具』面板管理';
        this.rootEl.appendChild(note);

        list.scrollTop = prevScroll;
    }

    makeRow(code, amount, rate) {
        const row = document.createElement('div');
        row.className = 'backpack-row';

        const name = document.createElement('span');
        name.className = 'backpack-name';
        name.textContent = resourceName(code);
        row.appendChild(name);

        const val = document.createElement('span');
        val.className = 'backpack-amount';
        val.textContent = fmt(amount || 0);
        row.appendChild(val);

        // 速率:0(或缺失)不显示;正负号明确给出,小数留一位(0.5/分 这种量级要看得见)
        const r = Number(rate) || 0;
        const rateEl = document.createElement('span');
        rateEl.className = 'backpack-rate' + (r > 0 ? ' is-up' : (r < 0 ? ' is-down' : ''));
        rateEl.textContent = r !== 0 ? (r > 0 ? '+' : '') + fmtDec(r, 1) + '/分' : '';
        row.appendChild(rateEl);

        return row;
    }
}
