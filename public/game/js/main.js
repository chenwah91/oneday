import { CONFIG } from './core/config.js';
import { api } from './core/api.js';
import { state, setState, onChange } from './core/state.js';
import { renderAuth } from './ui/auth.js';
import { mountHud, updateHud, setEventBadgeHandler } from './ui/hud.js';
import { initPixiApp } from './renderer/pixi-app.js';
import { renderMap } from './renderer/map.js';
import { render as renderBuildings, setBuildingClickHandler, setBuildingsInteractive } from './renderer/buildings.js';
import { mountBuildPanel, buildPanel } from './ui/build-panel.js';
import { mountBuildingPanel, openBuildingPanel, closeBuildingPanel, setNpcPanelOpener } from './ui/building-panel.js';
import { TechnologyPanel } from './ui/technology-panel.js';
import { NpcPanel } from './ui/npc-panel.js';
import { MarketPanel } from './ui/market-panel.js';
import { ItemPanel } from './ui/item-panel.js';
import { BackpackPanel } from './ui/backpack-panel.js';
import { StatsPanel } from './ui/stats-panel.js';
import { ProfilePanel } from './ui/profile-panel.js';
import { EventDialog } from './ui/event-dialog.js';
import { mountNav, setActive, setBadge } from './ui/nav.js';
import { initBuildModule, handleTileClick, onPlacementChange, getPlacement } from './modules/build.js';
import { loadResourceNames } from './modules/resources.js';

const app = document.getElementById('app');

async function bootApp() {
    // 资源 code → 中文显示名:HUD/面板挂载前必须先就位(内部已容错,失败时退回显示 code)
    await loadResourceNames();

    const data = await api.get('/api/city');
    setState({ city: data.city });
    // 布局三段(CLAUDE §21 Mobile:Compact HUD / 地图 / Bottom Navigation);
    // 所有面板浮层仍挂 #stage 内绝对定位,导航条常驻在 #nav
    app.innerHTML = '<div id="hud"></div><div id="stage"></div><div id="nav"></div>';
    mountHud(document.getElementById('hud'));
    updateHud(state.city);

    // 等距地图:初始化 PixiJS,画地图 + 现有建筑
    const stageEl = document.getElementById('stage');
    const pixiApp = initPixiApp(stageEl);
    pixiApp.centerOn(state.city.map_width, state.city.map_height);

    renderMap(pixiApp.world, state.city.map_width, state.city.map_height, handleTileClick, pixiApp.isDragging);
    renderBuildings(pixiApp.world, state.city.buildings);

    // 建造 sheet(W12 起可开合,默认收起):挂在 #stage 内底部停靠;
    // 建造模块拿到 world 引用,建造成功后自行重绘
    initBuildModule(pixiApp.world);
    await mountBuildPanel(stageEl);

    // 建筑详情面板:挂在 #stage 内绝对定位,升级/拆除后自行重绘建筑层与 HUD
    mountBuildingPanel(stageEl, pixiApp.world);

    // 八个操作面板(建造/科技/市场/工具/招募/背包/统计/我的):入口统一收进底部导航(W12)。
    // 互斥打开:任何一个面板 open 时先把其余的关掉 —— 全是叠在地图上的浮层,叠在一起没法用。
    // 互斥逻辑放在 main.js(装配处),面板之间互相不认识,谁也不 import 谁
    const panels = [];
    const panelKeys = new Map(); // 面板实例 → 导航 key(开面板时点亮对应 tab)
    let eventDialog = null;

    const closeOtherPanels = (self) => {
        panels.forEach((p) => {
            if (p !== self) p.close();
        });
    };
    // 面板被打开时:除了收起其余面板,连事件弹窗一起收(一屏只有一个位置,理由见下方
    // EventDialog 的注释),并点亮对应 tab —— 高亮统一在装配处同步,面板自己不碰导航
    const onPanelOpen = (self) => {
        closeOtherPanels(self);
        if (eventDialog) eventDialog.close();
        setActive(panelKeys.get(self) || null);
    };

    const technologyPanel = new TechnologyPanel({ api, state, onOpen: onPanelOpen });
    const npcPanel = new NpcPanel({ api, state, onOpen: onPanelOpen });
    const marketPanel = new MarketPanel({ api, state, onOpen: onPanelOpen });
    const itemPanel = new ItemPanel({ api, state, onOpen: onPanelOpen });
    // 背包 / 统计 / 我的:W12 并行交付的三个新面板,接口契约与现有面板一致
    // (constructor({api, state, onOpen}) / mount / open / close / opened)
    const backpackPanel = new BackpackPanel({ api, state, onOpen: onPanelOpen });
    const statsPanel = new StatsPanel({ api, state, onOpen: onPanelOpen });
    const profilePanel = new ProfilePanel({ api, state, onOpen: onPanelOpen });
    // 建造 sheet 也纳入互斥:它同样是叠在地图上的底部浮层;onOpen 由装配处注入
    buildPanel.onOpen = onPanelOpen;

    [technologyPanel, npcPanel, marketPanel, itemPanel, backpackPanel, statsPanel, profilePanel]
        .forEach((p) => p.mount(stageEl));

    // 8 个 tab 与面板的对应关系(导航只认 key,面板互相不认识);
    // npc 面板在导航里的语义是「招募」
    const navEntries = [
        { key: 'build', icon: '🏗️', label: '建造', panel: buildPanel },
        { key: 'tech', icon: '🔬', label: '科技', panel: technologyPanel },
        { key: 'market', icon: '🏪', label: '市场', panel: marketPanel },
        { key: 'item', icon: '🧰', label: '工具', panel: itemPanel },
        { key: 'npc', icon: '👥', label: '招募', panel: npcPanel },
        { key: 'backpack', icon: '🎒', label: '背包', panel: backpackPanel },
        { key: 'stats', icon: '📊', label: '统计', panel: statsPanel },
        { key: 'profile', icon: '👤', label: '我的', panel: profilePanel },
    ];

    // 当前开着的面板对应的导航 key(全关时 null)
    const openedNavKey = () => {
        const open = navEntries.filter((e) => e.panel.opened)[0];
        return open ? open.key : null;
    };

    navEntries.forEach((entry) => {
        panels.push(entry.panel);
        panelKeys.set(entry.panel, entry.key);
        // 面板不只被导航关:× 按钮 / 互斥 / 放置模式自动收起都直接调 close(),
        // 这里包一层让导航跟着熄灯 —— 关完之后按「谁还开着」重算高亮。
        // 实例属性会盖住原方法,面板内部的 this.close() 也会走到这层
        const rawClose = entry.panel.close.bind(entry.panel);
        entry.panel.close = () => {
            rawClose();
            setActive(openedNavKey());
        };
    });

    // 底部导航:点 tab 开对应面板,再点收起;高亮由 onPanelOpen / 包过的 close 统一同步,
    // 导航自己不直接操作 active
    mountNav(document.getElementById('nav'), navEntries.map((entry) => ({
        key: entry.key,
        icon: entry.icon,
        label: entry.label,
        onToggle: () => {
            if (entry.panel.opened) entry.panel.close();
            else entry.panel.open();
        },
    })));

    // 导航角标:闲置 NPC(招募)与耐久预警(工具)。原先挂在 FAB 上的两个红点移到导航,
    // 由装配处从快照统一算 —— 面板关着也要更新,所以跟着 state 变化走
    const syncNavBadges = () => {
        const city = state.city || {};
        setBadge('npc', Number((city.npcs || {}).idle) || 0);
        setBadge('item', Number((city.items || {}).durability_warning) || 0);
    };
    syncNavBadges();
    onChange(syncNavBadges);

    // 事件弹窗:**不占导航位**。事件有到期时间(过期就领不到了),属于「该提醒玩家一次」
    // 的信息,所以做成自动弹出的浮层;玩家收起后由 HUD 的 🔔 角标随时调回来。
    // 与面板互斥(同一块屏幕位置),但**开着任何面板或正在放置建筑时不自动弹** ——
    // 只亮角标,不打断玩家手上正在做的事(canAutoOpen 就是这条规则的落点)
    eventDialog = new EventDialog({
        api, state,
        onOpen: () => closeOtherPanels(null),
        canAutoOpen: () => !panels.some((p) => p.opened) && !getPlacement(),
    });
    eventDialog.mount(stageEl);
    setEventBadgeHandler(() => eventDialog.open());

    // 建筑详情里的「派驻 NPC」入口:把玩家送去 NPC 面板并带上目标建筑,
    // 派驻规则只在 NPC 面板一处实现(建筑详情不直接依赖 npc-panel.js)
    setNpcPanelOpener((instanceId) => npcPanel.open(instanceId));

    // 点击已有建筑 → 打开详情。两道闸门:
    // 1) 放置模式优先(此时建筑层已被关掉命中,这里再判一次兜底,避免边缘时序问题)
    // 2) 拖拽平移结束的那一下不算点击(与 map.js 地格点击同一判据)
    setBuildingClickHandler((building) => {
        if (getPlacement()) return;
        if (pixiApp.isDragging()) return;
        openBuildingPanel(building);
    });

    // 进入放置模式:整层建筑不参与命中(点击穿透到地格),同时收起已打开的详情面板
    // (建造 sheet 的自动收起与浮动「取消放置」按钮在 build-panel.js 自己处理)
    onPlacementChange((placement) => {
        setBuildingsInteractive(!placement);
        if (placement) closeBuildingPanel();
    });

    // 轮询:定期刷新城市快照,让生产累积/资源变化对玩家可见
    setInterval(async () => {
        try {
            const res = await api.get('/api/city');
            setState({ city: res.city });
            updateHud(state.city);
            renderBuildings(pixiApp.world, state.city.buildings);
        } catch (e) {
            // 轮询失败静默重试,不打断当前游戏画面
        }
    }, CONFIG.pollMs);
}

async function start() {
    try {
        const me = await api.get('/api/me');
        setState({ user: me.user });
        await bootApp();
    } catch (e) {
        renderAuth(app, async () => { await bootApp(); });
    }
}
start();
export { bootApp };
