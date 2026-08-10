import { CONFIG } from './core/config.js';
import { api } from './core/api.js';
import { state, setState } from './core/state.js';
import { renderAuth } from './ui/auth.js';
import { mountHud, updateHud } from './ui/hud.js';
import { initPixiApp } from './renderer/pixi-app.js';
import { renderMap } from './renderer/map.js';
import { render as renderBuildings, setBuildingClickHandler, setBuildingsInteractive } from './renderer/buildings.js';
import { mountBuildPanel } from './ui/build-panel.js';
import { mountBuildingPanel, openBuildingPanel, closeBuildingPanel } from './ui/building-panel.js';
import { TechnologyPanel } from './ui/technology-panel.js';
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

    // 科技面板(M2-B1):入口按钮 + 面板同样挂在 #stage 内绝对定位(左下角,与建筑详情面板错开)
    const technologyPanel = new TechnologyPanel({ api, state });
    technologyPanel.mount(stageEl);

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
