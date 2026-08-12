// 底部导航条(W12 前端框架):8 个分类 tab(图标 + 两字标签竖排),游戏操作的统一入口。
//
// 职责边界:纯 UI 组件,只管画按钮、高亮、角标 —— 不认识任何面板。
// 点 tab 只回调 items 里的 onToggle(key),开合与互斥都由装配处(main.js)决定;
// 面板被互斥关掉 / 自己点 × 关掉时,装配处再调 setActive 把对应 tab 熄灯。
//
// 模块级单例(与 ui/hud.js 的 mountHud/updateHud 同一范式):全局只有一条导航。
let itemEls = {};   // key → tab 按钮节点
let badgeEls = {};  // key → 角标节点
let activeKey = null;

// el:挂载容器(#nav);items:[{ key, icon, label, onToggle }]
export function mountNav(el, items) {
    el.innerHTML = '';
    itemEls = {};
    badgeEls = {};
    activeKey = null;

    const bar = document.createElement('div');
    bar.className = 'nav-bar';

    items.forEach((item) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'nav-item';
        btn.title = item.label;
        btn.setAttribute('aria-label', item.label);

        const icon = document.createElement('span');
        icon.className = 'nav-icon';
        icon.textContent = item.icon;
        btn.appendChild(icon);

        const label = document.createElement('span');
        label.className = 'nav-label';
        label.textContent = item.label;
        btn.appendChild(label);

        // 角标默认隐藏:装配处按需 setBadge(如闲置 NPC 数 / 耐久预警件数)
        const badge = document.createElement('span');
        badge.className = 'nav-badge';
        badge.hidden = true;
        btn.appendChild(badge);

        btn.addEventListener('click', () => item.onToggle(item.key));

        itemEls[item.key] = btn;
        badgeEls[item.key] = badge;
        bar.appendChild(btn);
    });

    el.appendChild(bar);
}

// 高亮同步:key 为 null 时全部熄灯。导航自己不猜面板状态,谁亮谁灭由装配处说了算
export function setActive(key) {
    activeKey = key || null;
    Object.keys(itemEls).forEach((k) => {
        itemEls[k].classList.toggle('active', k === activeKey);
    });
}

// 角标计数:count <= 0 隐藏,超过 99 显示 99+(沿用原 FAB 红点的口径)
export function setBadge(key, count) {
    const badge = badgeEls[key];
    if (!badge) return;
    const n = Number(count) || 0;
    badge.textContent = n > 99 ? '99+' : String(n);
    badge.hidden = n <= 0;
}
