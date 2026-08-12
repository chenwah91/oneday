// 玩家资料面板(W12 前端波):用户名 / 邮箱 / 注册时间 + 退出登录。
//
// 面板范式照 ui/market-panel.js(constructor({api,state,onOpen}) / mount / open / close /
// opened / destroy),但**不建 FAB 入口按钮**:本波起面板由底部导航开合(框架侧统一接线),
// mount 只建面板 DOM,挂 #stage 内绝对定位。
//
// 数据来源:GET /api/me(打开面板时拉一次)。user.created_at 由后端本波补上,
// 老响应里没有这个字段时注册时间显示 '-',不报错(契约兜底)。
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
import { errorText } from '../core/error-messages.js';
import { notifyError } from './notification.js';

// ISO 时刻 → 「YYYY-MM-DD」;缺字段 / 解析失败一律回 '-'(注册时间只做展示,精确到日够用)
function dateText(iso) {
    const t = iso ? Date.parse(iso) : NaN;
    if (!t) return '-';
    const d = new Date(t);
    const pad = (n) => (n < 10 ? '0' + n : String(n));
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

export class ProfilePanel {
    constructor({ api, state, onOpen }) {
        this.api = api;
        this.state = state;
        // onOpen:打开时通知外部(装配处用它做面板互斥),自己不知道别的面板是谁
        this.onOpen = onOpen || null;

        this.rootEl = null;
        this.opened = false;
        this.busy = false;    // 退出登录请求进行中
        this.loading = false; // /api/me 请求进行中
        this.user = null;     // GET /api/me 的 user(每次打开重新拉,注册时间等字段可能随波次补齐)
    }

    // el:挂载容器(#stage,已是 position:relative,面板绝对定位其中)
    mount(el) {
        this.rootEl = document.createElement('div');
        this.rootEl.className = 'profile-panel';
        this.rootEl.hidden = true;
        el.appendChild(this.rootEl);
    }

    async open() {
        if (this.onOpen) this.onOpen(this);

        this.opened = true;
        this.rootEl.hidden = false;
        // 先置 loading 再画首帧:否则首次打开时(user 还没拉到)会闪一帧全 '-' 的空资料
        this.loading = true;
        this.render();

        await this.loadMe();
        if (!this.opened) return; // 请求还在路上时玩家已经关掉面板

        this.render();
    }

    close() {
        if (!this.opened) return;
        this.opened = false;
        if (this.rootEl) {
            this.rootEl.hidden = true;
            this.rootEl.innerHTML = '';
        }
    }

    destroy() {
        if (this.rootEl && this.rootEl.parentNode) this.rootEl.parentNode.removeChild(this.rootEl);
        this.rootEl = null;
    }

    // ---- 请求 ----

    async loadMe() {
        this.loading = true;
        try {
            const data = await this.api.get('/api/me');
            this.user = data.user || null;
        } catch (e) {
            // 拉不到就退回启动时存下的 state.user(没有 created_at,注册时间显示 '-'),不打断面板
            this.user = this.state.user || null;
            notifyError(errorText(e, '资料加载失败,请稍后重试'));
        } finally {
            this.loading = false;
        }
    }

    // 退出登录:POST /api/auth/logout 成功后整页重载 ——
    // main.js 启动时 /api/me 401 会自动回登录页,这里不用自己画登录界面
    async doLogout() {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            await this.api.post('/api/auth/logout');
            location.reload();
        } catch (err) {
            notifyError(errorText(err, '退出登录失败,请重试'));
            this.busy = false;
            this.render();
        }
    }

    // ---- 渲染 ----

    render() {
        if (!this.rootEl || !this.opened) return;
        this.rootEl.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'profile-header';

        const title = document.createElement('span');
        title.className = 'profile-title';
        title.textContent = '玩家资料';
        header.appendChild(title);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'profile-close';
        closeBtn.title = '关闭';
        closeBtn.setAttribute('aria-label', '关闭');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => this.close());
        header.appendChild(closeBtn);

        this.rootEl.appendChild(header);

        const body = document.createElement('div');
        body.className = 'profile-body';

        if (this.loading && !this.user) {
            const loading = document.createElement('div');
            loading.className = 'profile-empty';
            loading.textContent = '资料加载中...';
            body.appendChild(loading);
        } else {
            const u = this.user || {};
            body.appendChild(this.makeRow('用户名', u.username || '-'));
            body.appendChild(this.makeRow('邮箱', u.email || '-'));
            body.appendChild(this.makeRow('注册时间', dateText(u.created_at)));
        }

        this.rootEl.appendChild(body);

        const logout = document.createElement('button');
        logout.type = 'button';
        logout.className = 'profile-logout';
        logout.textContent = this.busy ? '退出中...' : '退出登录';
        logout.disabled = this.busy;
        logout.addEventListener('click', () => this.doLogout());
        this.rootEl.appendChild(logout);
    }

    makeRow(key, value) {
        const row = document.createElement('div');
        row.className = 'profile-row';

        const k = document.createElement('span');
        k.className = 'profile-row-key';
        k.textContent = key;
        row.appendChild(k);

        const v = document.createElement('span');
        v.className = 'profile-row-value';
        v.textContent = value;
        row.appendChild(v);

        return row;
    }
}
