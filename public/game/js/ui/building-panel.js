// 建筑详情面板:点击地图上已有建筑后打开,提供派工 / 升级 / 拆除入口
// 职责边界:build-panel.js 是"可建建筑列表",本文件是"已建建筑详情",两者互不依赖
// 服务器权威:面板只提交意图(instance_id + 幂等键 + expected_revision),等级/资源/工人一律以响应为准
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
import { api } from '../core/api.js';
import { state, setState, onChange } from '../core/state.js';
import { errorText } from '../core/error-messages.js';
import { newIdempotencyKey } from '../core/idempotency.js';
import { notifySuccess, notifyError } from './notification.js';
import { render as renderBuildings } from '../renderer/buildings.js';
import { updateHud } from './hud.js';
import { fmt } from '../utils/format.js';
import { resourceName, isCapacity } from '../modules/resources.js';
import { categoryName } from '../core/enum-names.js';

const MAX_LEVEL = 3; // 与后端 UpgradeService 一致:L1→L2→L3

// 升级语境下的错误码文案覆盖:后端满级复用了 BUILDING_LIMIT_REACHED,这里译成"已达最高等级"
const UPGRADE_ERRORS = { BUILDING_LIMIT_REACHED: '已达最高等级' };

// 派工语境下的覆盖:后端"超过该级 worker_required"复用了 VALIDATION_ERROR,泛化文案说不清原因
const WORKER_ERRORS = { VALIDATION_ERROR: '超过这栋建筑的用工需求,请刷新后重试' };

let rootEl = null; // 面板根节点(挂在 #stage 内,绝对定位)
let worldRef = null; // pixi 世界容器:升级/拆除后重绘建筑层
let openId = null; // 当前打开的建筑实例 id,null = 关闭
let confirming = false; // 拆除二次确认条是否展开
let busy = false; // 请求进行中:禁用按钮,防重复提交
let lastSignature = ''; // 上次渲染时的数据指纹,用于跳过无关刷新

function defsById() {
    const map = {};
    (state.definitions || []).forEach((d) => { map[d.building_id] = d; });
    return map;
}

// 全城劳动力池(快照口径,服务器权威):free = 可用 − 已分配
function cityWorkerPool() {
    const c = state.city || {};
    const available = Number(c.available_workers) || 0;
    const assigned = Number(c.assigned_workers) || 0;
    return { available, assigned, free: Math.max(0, available - assigned) };
}

// 从当前快照里找回打开中的建筑;找不到说明已被拆除/被服务器覆盖
function currentBuilding() {
    if (openId === null) return null;
    const list = (state.city && state.city.buildings) || [];
    for (let i = 0; i < list.length; i++) {
        if (String(list[i].id) === String(openId)) return list[i];
    }
    return null;
}

// 建造成功后 build.js 会先塞一条 id 为 "local-xxx" 的临时记录,等轮询回来才有真实 id;
// 这种记录不能拿去请求升级/拆除(后端要求 integer),先禁用按钮并提示同步中
function isSynced(b) {
    return b != null && /^\d+$/.test(String(b.id));
}

// 产出展示:定义接口目前只回传 L1 的 output(数组 [{resource, rate_per_min}])
// resource 是英文 code,显示时翻成中文名;容量类不是每分钟速率,不加 "/分"
function formatOutput(output) {
    if (!Array.isArray(output) || output.length === 0) return '';
    return output.map((o) => {
        const code = o && o.resource;
        const rate = o && o.rate_per_min;
        if (!code) return '';
        return resourceName(code) + ' ' + fmt(rate) + (isCapacity(code) ? '' : '/分');
    }).filter(Boolean).join('  ');
}

// 面板显示用到的数据指纹:只有这些值变了才需要重建 DOM
// (10 秒一次的快照轮询大多只改资源,重建会打断玩家正按下的按钮)
// 派工区块要跟着「本栋工人数」与「全城劳动力池」走,所以两者都要进指纹
function signature() {
    const b = currentBuilding();
    if (!b) return 'none';
    const pool = cityWorkerPool();
    return [
        b.id, b.level, b.x, b.y, b.status,
        b.assigned_workers, b.worker_required, pool.available, pool.assigned,
        state.definitions ? 1 : 0, busy ? 1 : 0, confirming ? 1 : 0,
    ].join('|');
}

function makeRow(key, value) {
    const row = document.createElement('div');
    row.className = 'bldg-row';

    const k = document.createElement('span');
    k.className = 'bldg-row-key';
    k.textContent = key;

    const v = document.createElement('span');
    v.className = 'bldg-row-value';
    v.textContent = value;

    row.appendChild(k);
    row.appendChild(v);
    return row;
}

function makeButton(className, text, onClick) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'bldg-btn ' + className;
    btn.textContent = text;
    btn.addEventListener('click', onClick);
    return btn;
}

// 派工按钮:触控区 ≥ 44px(CLAUDE §22),样式与 .bldg-btn 分开,避免撑坏底部操作区布局
function makeWorkerButton(text, ariaLabel, onClick, extraClass) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'bldg-worker-btn' + (extraClass ? ' ' + extraClass : '');
    btn.textContent = text;
    btn.setAttribute('aria-label', ariaLabel);
    btn.addEventListener('click', onClick);
    return btn;
}

// 派工区块(v3.2 §10.4):worker_required = 0 的建筑(住宅/仓库等)不需要人,整块不渲染。
// 没派工人就不生产是预期玩法(用户 2026-08-10 拍板),所以这里必须让玩家一眼看到并能直接派人。
function makeWorkerSection(b, synced) {
    const required = Number(b.worker_required) || 0;
    if (required <= 0) return null;

    const assigned = Number(b.assigned_workers) || 0;
    const pool = cityWorkerPool();

    const box = document.createElement('div');
    box.className = 'bldg-workers';

    const head = document.createElement('div');
    head.className = 'bldg-workers-head';

    const label = document.createElement('span');
    label.className = 'bldg-workers-label';
    label.textContent = '工人';
    head.appendChild(label);

    const count = document.createElement('span');
    count.className = 'bldg-workers-count ' + (assigned >= required ? 'is-full' : 'is-idle');
    count.textContent = assigned + ' / ' + required;
    head.appendChild(count);

    box.appendChild(head);

    const steps = document.createElement('div');
    steps.className = 'bldg-workers-steps';

    const canSub = synced && !busy && assigned > 0;
    const canAdd = synced && !busy && assigned < required && pool.free > 0;
    // 补满:一次补到该级需求,但不能超过全城还剩下的可用工人
    const fillTo = Math.min(required, assigned + pool.free);

    const clearBtn = makeWorkerButton('撤空', '撤走全部工人', () => doAssignWorkers(b, 0));
    clearBtn.disabled = !canSub;
    steps.appendChild(clearBtn);

    const minusBtn = makeWorkerButton('−', '减少一名工人', () => doAssignWorkers(b, assigned - 1), 'bldg-worker-step');
    minusBtn.disabled = !canSub;
    steps.appendChild(minusBtn);

    const plusBtn = makeWorkerButton('+', '增加一名工人', () => doAssignWorkers(b, assigned + 1), 'bldg-worker-step');
    plusBtn.disabled = !canAdd;
    steps.appendChild(plusBtn);

    const fillBtn = makeWorkerButton('补满', '把工人补到需求上限', () => doAssignWorkers(b, fillTo));
    fillBtn.disabled = !synced || busy || fillTo <= assigned;
    steps.appendChild(fillBtn);

    box.appendChild(steps);

    const hint = document.createElement('div');
    hint.className = 'bldg-workers-hint';
    if (assigned >= required) {
        hint.textContent = '工人已配齐,产出不受人力限制';
    } else if (pool.free <= 0) {
        hint.textContent = '全城已无闲置工人(可用 ' + pool.available + ' 人已派完)';
    } else {
        hint.textContent = '全城闲置 ' + pool.free + ' 人 · 工人不足时产出按比例打折';
    }
    box.appendChild(hint);

    return box;
}

// 全量重绘面板内容:状态很小,直接重建 DOM 比做差量更不容易出错
function render() {
    if (!rootEl) return;
    lastSignature = signature();

    const b = currentBuilding();
    if (!b) {
        // 建筑已不在快照里(被拆除或被服务器覆盖):直接收起面板
        openId = null;
        confirming = false;
        rootEl.hidden = true;
        rootEl.innerHTML = '';
        return;
    }

    rootEl.hidden = false;
    rootEl.innerHTML = '';

    const def = defsById()[b.building_id] || null;
    const level = Number(b.level) || 1;
    const synced = isSynced(b);

    // 标题栏
    const header = document.createElement('div');
    header.className = 'bldg-header';

    const title = document.createElement('span');
    title.className = 'bldg-title';
    title.textContent = (def && def.name) || b.building_id;
    header.appendChild(title);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'bldg-close';
    closeBtn.title = '关闭';
    closeBtn.setAttribute('aria-label', '关闭');
    closeBtn.textContent = '×';
    closeBtn.addEventListener('click', () => closeBuildingPanel());
    header.appendChild(closeBtn);

    rootEl.appendChild(header);

    // 明细:拿不到的字段直接不显示
    const body = document.createElement('div');
    body.className = 'bldg-body';
    body.appendChild(makeRow('等级', 'Lv' + level + ' / ' + MAX_LEVEL));
    body.appendChild(makeRow('位置', '(' + b.x + ', ' + b.y + ')'));
    // def.category 是英文 code(v3.2 §0.2),显示时翻成中文
    if (def && def.category) body.appendChild(makeRow('分类', categoryName(def.category)));
    if (def && def.footprint) body.appendChild(makeRow('占地', def.footprint.w + ' × ' + def.footprint.h));
    if (b.status && b.status !== 'active') body.appendChild(makeRow('状态', b.status));

    const outputText = formatOutput(def && def.level1 && def.level1.output);
    if (outputText) body.appendChild(makeRow('L1 产出', outputText));

    rootEl.appendChild(body);

    // 派工区:worker_required > 0 才出现
    const workers = makeWorkerSection(b, synced);
    if (workers) rootEl.appendChild(workers);

    // 操作区
    const actions = document.createElement('div');
    actions.className = 'bldg-actions';

    const maxed = level >= MAX_LEVEL;
    const upgradeBtn = makeButton('bldg-btn-upgrade', maxed ? '已满级' : '升级到 Lv' + (level + 1), () => doUpgrade(b));
    upgradeBtn.disabled = maxed || busy || !synced;
    actions.appendChild(upgradeBtn);

    const demolishBtn = makeButton('bldg-btn-demolish', '拆除', () => {
        confirming = true;
        render();
    });
    demolishBtn.disabled = busy || !synced;
    actions.appendChild(demolishBtn);

    rootEl.appendChild(actions);

    if (!synced) {
        const hint = document.createElement('div');
        hint.className = 'bldg-hint';
        hint.textContent = '建筑同步中,请稍候再操作';
        rootEl.appendChild(hint);
    }

    // 二次确认条:拆除是删除语义,必须再确认一次
    if (confirming) {
        const confirm = document.createElement('div');
        confirm.className = 'bldg-confirm';

        const text = document.createElement('div');
        text.className = 'bldg-confirm-text';
        text.textContent = '确定拆除?拆除不返还资源';
        confirm.appendChild(text);

        const row = document.createElement('div');
        row.className = 'bldg-confirm-actions';

        const yes = makeButton('bldg-btn-danger', busy ? '拆除中...' : '确定拆除', () => doDemolish(b));
        yes.disabled = busy || !synced;
        row.appendChild(yes);

        const no = makeButton('bldg-btn-ghost', '取消', () => {
            confirming = false;
            render();
        });
        no.disabled = busy;
        row.appendChild(no);

        confirm.appendChild(row);
        rootEl.appendChild(confirm);
    }
}

// 冲突/失效后重新拉一次权威快照,免得玩家要等下一轮轮询才能重试
async function refreshCity() {
    try {
        const res = await api.get('/api/city');
        setState({ city: res.city });
        if (worldRef) renderBuildings(worldRef, state.city.buildings);
        updateHud(state.city);
    } catch (e) {
        // 刷新失败不打断当前操作,交给 main.js 的定期轮询兜底
    }
}

// 请求失败的统一处理:提示 + 必要时刷新快照
async function handleMutationError(err, fallback, overrides) {
    notifyError(errorText(err, fallback, overrides));
    const code = err && err.error;
    if (code === 'REVISION_CONFLICT' || code === 'NOT_FOUND') {
        await refreshCity();
    }
}

async function doUpgrade(b) {
    if (busy || !isSynced(b)) return;
    busy = true;
    render();

    try {
        const diff = await api.post('/api/city/upgrade', {
            instance_id: Number(b.id),
            idempotency_key: newIdempotencyKey(),
            expected_revision: state.city ? state.city.revision : undefined,
        });
        applyUpgradeDiff(diff);
        const level = (diff.building && diff.building.level) || null;
        notifySuccess(level ? '升级成功,当前 Lv' + level : '升级成功');
    } catch (err) {
        await handleMutationError(err, '升级失败,请重试', UPGRADE_ERRORS);
    } finally {
        busy = false;
        render();
    }
}

async function doDemolish(b) {
    if (busy || !isSynced(b)) return;
    busy = true;
    render();

    try {
        const diff = await api.post('/api/city/demolish', {
            instance_id: Number(b.id),
            idempotency_key: newIdempotencyKey(),
            expected_revision: state.city ? state.city.revision : undefined,
        });
        applyDemolishDiff(diff);
        notifySuccess('已拆除');
    } catch (err) {
        await handleMutationError(err, '拆除失败,请重试');
    } finally {
        busy = false;
        confirming = false;
        render();
    }
}

// 派工:workers 是绝对值(0 = 撤空),与后端 WorkerService 的语义一致。
// 每次点击立即提交,busy 期间禁用全部按钮:客户端不做本地推算,状态一律等服务器回话。
async function doAssignWorkers(b, workers) {
    if (busy || !isSynced(b)) return;

    const target = Math.max(0, Math.floor(Number(workers) || 0));
    const current = Number(b.assigned_workers) || 0;
    if (target === current) return; // 没有变化就不发请求,免得白涨一次 revision

    busy = true;
    render();

    try {
        const diff = await api.post('/api/city/workers/assign', {
            instance_id: Number(b.id),
            workers: target,
            idempotency_key: newIdempotencyKey(),
            expected_revision: state.city ? state.city.revision : undefined,
        });
        applyWorkerDiff(diff);
        notifySuccess(target > current
            ? '已派 ' + (target - current) + ' 名工人'
            : '已撤回 ' + (current - target) + ' 名工人');
    } catch (err) {
        // REVISION_CONFLICT / NOT_FOUND 会自动刷一次权威快照,玩家看到新数字后可直接重试
        await handleMutationError(err, '派工失败,请重试', WORKER_ERRORS);
    } finally {
        busy = false;
        render();
    }
}

// 派工响应:{ revision, building:{id,assigned_workers,worker_required}, available_workers, assigned_workers, population }
// 本栋工人数、全城劳动力、人口一律用服务器返回值覆盖,不做本地推算;不重绘建筑层(工人数不改变外观)
function applyWorkerDiff(diff) {
    const city = state.city;
    if (!city || !diff) return;

    const changed = diff.building || null;
    const buildings = (city.buildings || []).map((b) => {
        if (!changed || String(b.id) !== String(changed.id)) return b;
        return Object.assign({}, b, {
            assigned_workers: changed.assigned_workers,
            worker_required: changed.worker_required,
        });
    });

    setState({
        city: Object.assign({}, city, {
            revision: diff.revision,
            available_workers: diff.available_workers,
            assigned_workers: diff.assigned_workers,
            population: diff.population,
            buildings,
        }),
    });

    updateHud(state.city); // HUD 的人口与「劳动力 已用/可用」跟着刷新,不整页刷新
}

// 升级响应:{ revision, building:{id,level}, resources, money, delta }
// 等级/资源/资金一律用服务器返回值覆盖,不做任何本地推算。
// 注:升级后新等级的 worker_required 不在响应里,派工区块的上限会略微滞后到下一次快照;
// 由于需求只增不减,滞后期间只会「少派」不会「多派」,服务器侧也另有校验,不做本地猜测。
function applyUpgradeDiff(diff) {
    const city = state.city;
    if (!city || !diff) return;

    const resources = Object.assign({}, city.resources, diff.resources);
    const changed = diff.building || null;
    const buildings = (city.buildings || []).map((b) => {
        if (!changed || String(b.id) !== String(changed.id)) return b;
        return Object.assign({}, b, { level: changed.level });
    });

    setState({
        city: Object.assign({}, city, {
            resources,
            money: diff.money,
            revision: diff.revision,
            buildings,
        }),
    });

    if (worldRef) renderBuildings(worldRef, state.city.buildings);
    updateHud(state.city);
}

// 拆除响应:{ revision, demolished_id };M1 不返还资源,所以只动 buildings 与 revision
function applyDemolishDiff(diff) {
    const city = state.city;
    if (!city || !diff) return;

    const removedId = String(diff.demolished_id);
    const removed = (city.buildings || []).find((b) => String(b.id) === removedId) || null;
    const buildings = (city.buildings || []).filter((b) => String(b.id) !== removedId);

    // 拆掉的建筑会把工人放回劳动力池,但拆除响应不带全城劳动力;
    // 这里只做「显示层」的减法(下一次快照轮询会用服务器权威值覆盖),
    // 否则 HUD 与其他建筑的 "+" 按钮会误以为还没有闲置工人,最长要等一轮轮询才恢复
    const freed = removed ? (Number(removed.assigned_workers) || 0) : 0;
    const assignedWorkers = Math.max(0, (Number(city.assigned_workers) || 0) - freed);

    setState({
        city: Object.assign({}, city, {
            revision: diff.revision,
            assigned_workers: assignedWorkers,
            buildings,
        }),
    });

    if (worldRef) renderBuildings(worldRef, state.city.buildings);
    updateHud(state.city);
}

// el:挂载容器(#stage,已是 position:relative,面板绝对定位其中,不占用底部建造面板空间)
// world:pixi 世界容器,升级/拆除成功后重绘建筑层
export function mountBuildingPanel(el, world) {
    worldRef = world || null;

    rootEl = document.createElement('div');
    rootEl.className = 'bldg-detail';
    rootEl.hidden = true;
    el.appendChild(rootEl);

    // 快照更新(轮询/建造/其他面板)后同步刷新:等级变化要重画,建筑被移除要自动收起
    onChange(() => {
        if (openId === null) return;
        if (signature() === lastSignature) return; // 与面板无关的变化(资源/人口)不重建 DOM
        render();
    });
}

// building:city.buildings 里的一条记录(由 renderer/buildings.js 的点击回调传入)
export function openBuildingPanel(building) {
    if (!building || building.id === undefined) return;
    if (String(building.id) !== String(openId)) confirming = false; // 换了建筑就收起确认条
    openId = building.id;
    render();
}

export function closeBuildingPanel() {
    if (openId === null && (!rootEl || rootEl.hidden)) return;
    openId = null;
    confirming = false;
    if (rootEl) {
        rootEl.hidden = true;
        rootEl.innerHTML = '';
    }
}
