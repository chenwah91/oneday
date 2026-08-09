// 建造交互:管理"放置模式"状态、提交建造请求、把返回 diff 合并进 state,并驱动重绘
import { api } from '../core/api.js';
import { newIdempotencyKey } from '../core/idempotency.js';
import { state, setState } from '../core/state.js';
import { errorText } from '../core/error-messages.js';
import { render as renderBuildings } from '../renderer/buildings.js';
import { updateHud } from '../ui/hud.js';
import { notifyError } from '../ui/notification.js';

let placement = null; // { buildingId, footprint, name } | null,非 null 即"放置模式"
let worldRef = null; // pixi-app 的世界容器,用于建造成功后重绘建筑
const listeners = [];

// world:main.js 在初始化 PixiJS 后传入,供建造成功后调用 renderBuildings
export function initBuildModule(world) {
    worldRef = world;
}

// 订阅放置模式变化(build-panel.js 用来高亮选中项 / 显示取消按钮)
export function onPlacementChange(cb) {
    listeners.push(cb);
}

function notify() {
    listeners.forEach((cb) => cb(placement));
}

export function getPlacement() {
    return placement;
}

// def:/api/definitions/buildings 返回的单条建筑定义
export function selectBuilding(def) {
    placement = { buildingId: def.buildingId, footprint: def.footprint, name: def.name };
    notify();
}

export function cancelPlacement() {
    placement = null;
    notify();
}

// 地格点击回调(由 map.js 触发):放置模式下才发起建造请求
export async function handleTileClick(gx, gy) {
    if (!placement) return;
    const buildingId = placement.buildingId;

    try {
        // 幂等键防重复提交(网络重试不重复扣建);expectedRevision 防旧页面覆盖新状态
        const diff = await api.post('/api/city/build', {
            buildingId, x: gx, y: gy,
            idempotencyKey: newIdempotencyKey(),
            expectedRevision: state.city ? state.city.revision : undefined,
        });
        applyDiff(diff, buildingId, gx, gy);
        cancelPlacement(); // 建造成功后退出放置模式
        if (worldRef) renderBuildings(worldRef, state.city.buildings);
        updateHud(state.city);
    } catch (err) {
        notifyError(errorText(err, '建造失败,请重试'));
    }
}

// 用 /api/city/build 返回的 diff 合并进 state.city:
// 后端 diff 目前只回传 { revision, resources, money, delta },不含新建筑实体详情,
// 这里用本地已知的 buildingId/x/y/level(=1)/status 合成一条记录先行显示,
// 下一次轮询 /api/city 会用服务端权威数据(含真实 id)覆盖。
function applyDiff(diff, buildingId, x, y) {
    const city = state.city;
    if (!city) return;

    const resources = Object.assign({}, city.resources, diff.resources);
    const buildings = city.buildings.slice();
    buildings.push({
        id: 'local-' + Date.now(),
        buildingId,
        level: 1,
        x,
        y,
        status: 'active',
    });

    setState({
        city: Object.assign({}, city, {
            resources,
            money: diff.money,
            revision: diff.revision,
            buildings,
        }),
    });
}
