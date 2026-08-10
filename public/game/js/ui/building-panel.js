// 建筑详情面板:点击地图上已有建筑后打开,提供升级 / 拆除入口
// 职责边界:build-panel.js 是"可建建筑列表",本文件是"已建建筑详情",两者互不依赖
// 服务器权威:面板只提交意图(instanceId + 幂等键 + expectedRevision),等级/资源一律以响应为准
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

let rootEl = null; // 面板根节点(挂在 #stage 内,绝对定位)
let worldRef = null; // pixi 世界容器:升级/拆除后重绘建筑层
let openId = null; // 当前打开的建筑实例 id,null = 关闭
let confirming = false; // 拆除二次确认条是否展开
let busy = false; // 请求进行中:禁用按钮,防重复提交
let lastSignature = ''; // 上次渲染时的数据指纹,用于跳过无关刷新

function defsById() {
    const map = {};
    (state.definitions || []).forEach((d) => { map[d.buildingId] = d; });
    return map;
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
// (10 秒一次的快照轮询大多只改资源/人口,重建会打断玩家正按下的按钮)
function signature() {
    const b = currentBuilding();
    if (!b) return 'none';
    return [b.id, b.level, b.x, b.y, b.status, state.definitions ? 1 : 0, busy ? 1 : 0, confirming ? 1 : 0].join('|');
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

    const def = defsById()[b.buildingId] || null;
    const level = Number(b.level) || 1;
    const synced = isSynced(b);

    // 标题栏
    const header = document.createElement('div');
    header.className = 'bldg-header';

    const title = document.createElement('span');
    title.className = 'bldg-title';
    title.textContent = (def && def.name) || b.buildingId;
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
            instanceId: Number(b.id),
            idempotencyKey: newIdempotencyKey(),
            expectedRevision: state.city ? state.city.revision : undefined,
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
            instanceId: Number(b.id),
            idempotencyKey: newIdempotencyKey(),
            expectedRevision: state.city ? state.city.revision : undefined,
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

// 升级响应:{ revision, building:{id,level}, resources, money, delta }
// 等级/资源/资金一律用服务器返回值覆盖,不做任何本地推算
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

// 拆除响应:{ revision, demolishedId };M1 不返还资源,所以只动 buildings 与 revision
function applyDemolishDiff(diff) {
    const city = state.city;
    if (!city || !diff) return;

    const removedId = String(diff.demolishedId);
    const buildings = (city.buildings || []).filter((b) => String(b.id) !== removedId);

    setState({
        city: Object.assign({}, city, {
            revision: diff.revision,
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
