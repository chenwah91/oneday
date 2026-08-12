import { CONFIG } from './core/config.js';
import { api } from './core/api.js';
import { state, setState } from './core/state.js';
import { renderAuth } from './ui/auth.js';
import { mountHud, updateHud, setEventBadgeHandler } from './ui/hud.js';
import { initPixiApp } from './renderer/pixi-app.js';
import { renderMap } from './renderer/map.js';
import { render as renderBuildings, setBuildingClickHandler, setBuildingsInteractive } from './renderer/buildings.js';
import { mountBuildPanel } from './ui/build-panel.js';
import { mountBuildingPanel, openBuildingPanel, closeBuildingPanel, setNpcPanelOpener } from './ui/building-panel.js';
import { TechnologyPanel } from './ui/technology-panel.js';
import { NpcPanel } from './ui/npc-panel.js';
import { MarketPanel } from './ui/market-panel.js';
import { ItemPanel } from './ui/item-panel.js';
import { EventDialog } from './ui/event-dialog.js';
import { initBuildModule, handleTileClick, onPlacementChange, getPlacement } from './modules/build.js';
import { loadResourceNames } from './modules/resources.js';

const app = document.getElementById('app');

async function bootApp() {
    // 资源 code → 中文显示名:HUD/面板挂载前必须先就位(内部已容错,失败时退回显示 code)
    await loadResourceNames();

    const data = await api.get('/api/city');
    setState({ city: data.city });
    app.innerHTML = '<div id="hud"></div><div id="stage"></div><div id="panel"></div>';
    mountHud(document.getElementById('hud'));
    updateHud(state.city);

    // 等距地图:初始化 PixiJS,画地图 + 现有建筑
    const stageEl = document.getElementById('stage');
    const pixiApp = initPixiApp(stageEl);
    pixiApp.centerOn(state.city.map_width, state.city.map_height);

    renderMap(pixiApp.world, state.city.map_width, state.city.map_height, handleTileClick, pixiApp.isDragging);
    renderBuildings(pixiApp.world, state.city.buildings);

    // 建造面板:挂到 #panel;建造模块拿到 world 引用,建造成功后自行重绘
    initBuildModule(pixiApp.world);
    await mountBuildPanel(document.getElementById('panel'));

    // 建筑详情面板:挂在 #stage 内绝对定位,升级/拆除后自行重绘建筑层与 HUD
    mountBuildingPanel(stageEl, pixiApp.world);

    // 左下角的四个 FAB 面板(科技 / NPC / 市场 / 工具):都挂在 #stage 内绝对定位,与右上角的建筑详情错开。
    // 互斥打开:任何一个面板 open 时先把其余的关掉 —— 四块都是 340px 宽的浮层,叠在一起没法用。
    // 互斥逻辑放在 main.js(装配处),面板之间互相不认识,谁也不 import 谁
    const fabPanels = [];
    let eventDialog = null;
    const closeOtherPanels = (self) => {
        fabPanels.forEach((p) => {
            if (p !== self) p.close();
        });
    };
    // 面板被打开时:除了收起其余面板,连事件弹窗一起收 —— 400px 宽的屏上只有一屏的位置,
    // 弹窗停在顶部、面板从底部展开,两者同时在场必然互相压住(实测 400×800:弹窗 8~300、面板 119~627)
    const onPanelOpen = (self) => {
        closeOtherPanels(self);
        if (eventDialog) eventDialog.close();
    };

    const technologyPanel = new TechnologyPanel({ api, state, onOpen: onPanelOpen });
    const npcPanel = new NpcPanel({ api, state, onOpen: onPanelOpen });
    const marketPanel = new MarketPanel({ api, state, onOpen: onPanelOpen });
    const itemPanel = new ItemPanel({ api, state, onOpen: onPanelOpen });
    fabPanels.push(technologyPanel, npcPanel, marketPanel, itemPanel);
    fabPanels.forEach((p) => p.mount(stageEl));

    // 事件弹窗:**不占底部导航位**。事件有到期时间(过期就领不到了),属于「该提醒玩家一次」
    // 的信息,所以做成自动弹出的浮层;玩家收起后由 HUD 的 🔔 角标随时调回来。
    // 与四个面板互斥(同一块屏幕位置),但**开着面板时不自动弹** —— 只亮角标,
    // 不打断玩家手上正在做的事(canAutoOpen 就是这条规则的落点)
    eventDialog = new EventDialog({
        api, state,
        onOpen: () => closeOtherPanels(null),
        canAutoOpen: () => !fabPanels.some((p) => p.opened),
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
