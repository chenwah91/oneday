import { api } from './core/api.js';
import { state, setState } from './core/state.js';
import { renderAuth } from './ui/auth.js';
import { mountHud, updateHud } from './ui/hud.js';

const app = document.getElementById('app');

async function bootApp() {
    const data = await api.get('/api/city');
    setState({ city: data.city });
    app.innerHTML = '<div id="hud"></div><div id="stage"></div><div id="panel"></div>';
    mountHud(document.getElementById('hud'));
    updateHud(state.city);
    // P3 任务接 PixiJS 地图与建造面板(在 stage/panel 挂载)
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
