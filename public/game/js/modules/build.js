// 建造交互:管理"放置模式"状态、提交建造请求、把返回 diff 合并进 state,并驱动重绘
import { api } from '../core/api.js';
import { newIdempotencyKey } from '../core/idempotency.js';
import { state, setState } from '../core/state.js';
import { errorText, insufficientDetailText } from '../core/error-messages.js';
import { render as renderBuildings } from '../renderer/buildings.js';
import { updateHud } from '../ui/hud.js';
import { notifyError } from '../ui/notification.js';

// placement 是纯客户端状态(不是 API 载荷),沿用 JS 的 camelCase;
// 凡是发给服务器或从快照读回的字段一律 snake_case 全小写(用户 2026-08-10 拍板)
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
    placement = { buildingId: def.building_id, footprint: def.footprint, name: def.name };
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
        // 幂等键防重复提交(网络重试不重复扣建);expected_revision 防旧页面覆盖新状态
        const diff = await api.post('/api/city/build', {
            building_id: buildingId, x: gx, y: gy,
            idempotency_key: newIdempotencyKey(),
            expected_revision: state.city ? state.city.revision : undefined,
        });
        applyDiff(diff, buildingId, gx, gy);
        cancelPlacement(); // 建造成功后退出放置模式
        if (worldRef) renderBuildings(worldRef, state.city.buildings);
        updateHud(state.city);
    } catch (err) {
        // 缺料时优先给逐项明细(W12:后端给 INSUFFICIENT_RESOURCE 补了 details.missing),
        // 拿不到明细(老响应)回落 errorText 的笼统文案
        notifyError(insufficientDetailText(err) || errorText(err, '建造失败,请重试'));
    }
}

// 用 /api/city/build 返回的 diff 合并进 state.city:
// 后端 diff 回传 { revision, resources, money, delta, building? },其中 building 是本次新建的实例
// (真实 id / status / construction_finished_at)。M2-C5 起建造要等 duration_seconds,
// 拿到服务器给的完工时刻,施工倒计时立刻就能画,不必等下一轮快照。
// 幂等重放路径没有 building 字段,这时回落到本地合成记录(id 为 local-xxx,面板会禁用操作按钮)。
// 字段名必须与快照里的建筑记录完全一致,否则渲染层与详情面板读不到。
function applyDiff(diff, buildingId, x, y) {
    const city = state.city;
    if (!city) return;

    const resources = Object.assign({}, city.resources, diff.resources);
    const buildings = city.buildings.slice();
    const created = diff.building || null;
    buildings.push({
        id: created ? created.id : 'local-' + Date.now(),
        building_id: buildingId,
        level: created ? created.level : 1,
        x,
        y,
        // 服务器权威状态:建造下单后是 constructing,到点由结算翻成 active
        status: created ? created.status : 'constructing',
        construction_finished_at: created ? created.construction_finished_at : null,
        assigned_workers: 0,
        worker_required: 0,
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
