import { CONFIG } from './core/config.js';
import { api } from './core/api.js';
import { state, setState } from './core/state.js';
import { renderAuth } from './ui/auth.js';
import { mountHud, updateHud } from './ui/hud.js';
import { initPixiApp } from './renderer/pixi-app.js';
import { renderMap } from './renderer/map.js';
import { render as renderBuildings } from './renderer/buildings.js';
import { mountBuildPanel } from './ui/build-panel.js';
import { initBuildModule, handleTileClick } from './modules/build.js';

const app = document.getElementById('app');

async function bootApp() {
    const data = await api.get('/api/city');
    setState({ city: data.city });
    app.innerHTML = '<div id="hud"></div><div id="stage"></div><div id="panel"></div>';
    mountHud(document.getElementById('hud'));
    updateHud(state.city);

    // 等距地图:初始化 PixiJS,画地图 + 现有建筑
    const stageEl = document.getElementById('stage');
    const pixiApp = initPixiApp(stageEl);
    pixiApp.centerOn(state.city.mapWidth, state.city.mapHeight);

    renderMap(pixiApp.world, state.city.mapWidth, state.city.mapHeight, handleTileClick, pixiApp.isDragging);
    renderBuildings(pixiApp.world, state.city.buildings);

    // 建造面板:挂到 #panel;建造模块拿到 world 引用,建造成功后自行重绘
    initBuildModule(pixiApp.world);
    await mountBuildPanel(document.getElementById('panel'));

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
