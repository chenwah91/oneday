// 顶部 tab 导航 + location.hash 路由。
//
// 两条设计:
//   ① **数据驱动**:面板清单是一个 PANELS 数组(id / label / permission / load),
//      加一个面板只加一行,不再动导航、显隐、初始化三个地方;
//   ② **懒加载**:切到哪个面板才调它的 load(),首屏只加载仪表盘。
//      后台有 14 个面板、其中 8 个是整表定义(科技 50 行 / 建筑 94 行 / NPC 150 行),
//      一次性全拉会让登录后卡住好几秒,而运营 90% 的时间只看其中一个。
//
// 面板挂载后 DOM 保留(只切显隐):搜索框内容、修改原因、展开的行在切走再切回时还在,
// 不必重填。要拿新数据由各面板自己的「刷新」按钮负责。

import { hasPermission } from './session.js';
import { escapeHtml } from './dom.js';

let panels = [];
let navEl = null;
let rootEl = null;
let activeId = null;
const mounted = new Map();

// hash 形如 #players 或 #audit?user_id=12(跨面板跳转带参数用)
function parseHash() {
    const raw = (location.hash || '').replace(/^#/, '');
    const idx = raw.indexOf('?');
    const id = idx === -1 ? raw : raw.slice(0, idx);
    const params = {};
    if (idx !== -1) {
        new URLSearchParams(raw.slice(idx + 1)).forEach((v, k) => { params[k] = v; });
    }
    return { id, params };
}

function visiblePanels() {
    return panels.filter((p) => hasPermission(p.permission));
}

export function renderNav() {
    if (!navEl) return;
    navEl.innerHTML = visiblePanels()
        .map((p) => `<a class="tab${p.id === activeId ? ' tab-active' : ''}" href="#${escapeHtml(p.id)}" data-tab="${escapeHtml(p.id)}">${escapeHtml(p.label)}</a>`)
        .join('');
}

async function activate(id, params) {
    const list = visiblePanels();
    if (!list.length) return;

    let panel = list.find((p) => p.id === id);
    if (!panel) {
        // 未知 / 无权限的 hash:回落到第一个可见面板(通常是仪表盘)
        panel = list[0];
        location.replace('#' + panel.id);
        return;
    }

    activeId = panel.id;
    renderNav();

    let container = mounted.get(panel.id);
    const first = !container;
    if (first) {
        container = document.createElement('section');
        container.className = 'panel';
        container.dataset.panel = panel.id;
        rootEl.appendChild(container);
        mounted.set(panel.id, container);
    }

    mounted.forEach((node, key) => { node.classList.toggle('hidden', key !== panel.id); });

    if (first) {
        try {
            await panel.load(container);
        } catch (err) {
            container.innerHTML = `<div class="auth-error">面板加载失败:${escapeHtml(err && err.error ? err.error : '未知错误')}</div>`;
        }
    }

    // 跨面板跳转带来的参数(如从玩家详情跳审计并带上 user_id)
    if (params && Object.keys(params).length && typeof panel.apply === 'function') {
        panel.apply(params, container);
    }
}

function onHashChange() {
    const { id, params } = parseHash();
    activate(id, params);
}

export function initRouter(options) {
    panels = options.panels || [];
    navEl = options.nav;
    rootEl = options.root;

    if (!initRouter.bound) {
        window.addEventListener('hashchange', onHashChange);
        initRouter.bound = true;
    }

    const { id, params } = parseHash();
    activate(id, params);
}

// 跨面板跳转:navigate('audit', {user_id: 12})
export function navigate(id, params) {
    const qs = new URLSearchParams(params || {}).toString();
    const next = '#' + id + (qs ? '?' + qs : '');
    if (location.hash === next) {
        onHashChange(); // hash 没变不会触发 hashchange,手动走一次
        return;
    }
    location.hash = next;
}

// 登出:卸掉全部已挂载面板,免得下一个账号看到上一个账号的数据
export function resetRouter() {
    mounted.forEach((node) => node.remove());
    mounted.clear();
    activeId = null;
    if (navEl) navEl.innerHTML = '';
}
