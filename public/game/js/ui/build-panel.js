// 建造面板(W12 起是可开合的底部 sheet,默认收起):拉取可建建筑列表,渲染可滚动的按钮列表;
// 选中后进入放置模式 —— 此时自动收起 sheet 让玩家能点地图,浮动的「取消放置」按钮放置中常显。
// 放置状态本身归 modules/build.js 管,这里只是它的显示层与开关。
import { api } from '../core/api.js';
import { state, setState, onChange } from '../core/state.js';
import { selectBuilding, cancelPlacement, onPlacementChange, getPlacement } from '../modules/build.js';
import { loadResourceNames, resourceName } from '../modules/resources.js';
import { categoryName } from '../core/enum-names.js';

// 当前城市时代序号(M2-B6):快照的 city.era.era_order;拿不到时按 0 处理(全部按未解锁显示)
function cityEraOrder() {
    const era = (state.city && state.city.era) || null;
    return era ? Number(era.era_order) || 0 : 0;
}

// 成本紧凑展示,如 "木材20 石料5"(cost 的键是资源 code,显示时翻成中文名)
function formatCost(cost) {
    return Object.entries(cost || {})
        .map(([code, amt]) => resourceName(code) + amt)
        .join(' ');
}

// sheet 根节点与开合状态(模块级单例,与 ui/hud.js 同一范式:全局只有一个建造面板)
let rootEl = null;
let opened = false;

// 与其它面板一致的开合契约(open / close / opened),main.js 用它统一做互斥与导航接线;
// onOpen 由装配处注入(与类面板的 constructor({ onOpen }) 同义)。
// 注意:close 会被 main.js 包一层来同步导航熄灯,所以本文件内部收起 sheet 也必须
// 走 buildPanel.close(),不能直接改 hidden
export const buildPanel = {
    onOpen: null,

    get opened() {
        return opened;
    },

    open() {
        if (!rootEl || opened) return;
        if (this.onOpen) this.onOpen(this);
        opened = true;
        rootEl.hidden = false;
    },

    close() {
        if (!opened) return;
        opened = false;
        if (rootEl) rootEl.hidden = true;
    },
};

// el:挂载容器(#stage,已是 position:relative,sheet 绝对定位停靠其底部)
export async function mountBuildPanel(el) {
    rootEl = document.createElement('div');
    rootEl.className = 'build-panel';
    rootEl.hidden = true;
    el.appendChild(rootEl);

    // 浮动「取消放置」:进入放置模式后 sheet 已自动收起,退出放置的入口必须留在地图上(放置中常显)
    const cancelFloat = document.createElement('button');
    cancelFloat.type = 'button';
    cancelFloat.className = 'build-cancel-float';
    cancelFloat.textContent = '✕ 取消放置';
    cancelFloat.hidden = true;
    cancelFloat.addEventListener('click', () => cancelPlacement());
    el.appendChild(cancelFloat);

    // 成本要显示中文资源名,先确保 code→名称表已就位(已缓存则直接返回)
    await loadResourceNames();

    if (!state.definitions) {
        const data = await api.get('/api/definitions/buildings');
        setState({ definitions: data.buildings });
    }

    const header = document.createElement('div');
    header.className = 'build-panel-header';

    const title = document.createElement('span');
    title.className = 'build-panel-title';
    title.textContent = '建造';
    header.appendChild(title);

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'build-close';
    closeBtn.title = '关闭';
    closeBtn.setAttribute('aria-label', '关闭');
    closeBtn.textContent = '×';
    closeBtn.addEventListener('click', () => buildPanel.close());
    header.appendChild(closeBtn);

    rootEl.appendChild(header);

    const list = document.createElement('div');
    list.className = 'build-list';
    rootEl.appendChild(list);

    const buttons = {};
    (state.definitions || []).forEach((def) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'build-item';
        // category 是英文 code,展示时翻成中文
        const cat = categoryName(def.category);
        btn.title = def.era ? (def.era + ' · ' + cat) : cat;

        const name = document.createElement('span');
        name.className = 'build-item-name';
        name.textContent = def.name;

        const cost = document.createElement('span');
        cost.className = 'build-item-cost';
        cost.textContent = formatCost(def.level1 && def.level1.cost);

        btn.appendChild(name);
        btn.appendChild(cost);

        btn.addEventListener('click', () => {
            const current = getPlacement();
            if (current && current.buildingId === def.building_id) {
                cancelPlacement(); // 再次点击同一项:取消放置模式
            } else {
                selectBuilding(def);
            }
        });

        buttons[def.building_id] = btn;
        list.appendChild(btn);
    });

    // 时代闸门(M2-B6):超出当前时代的建筑禁用并标注需要的时代。
    // 与服务端 BuildService 同一规则,但这里只是显示层提示 —— 玩家改 JS 点亮按钮也过不了服务端(CLAUDE §66)
    function applyEraLocks() {
        const order = cityEraOrder();
        (state.definitions || []).forEach((def) => {
            const btn = buttons[def.building_id];
            if (!btn) return;
            const locked = (Number(def.era_order) || 0) > order;
            btn.disabled = locked;
            btn.classList.toggle('era-locked', locked);
            const cat = categoryName(def.category);
            btn.title = locked
                ? '需要时代 ' + def.era + ' · ' + cat
                : (def.era ? def.era + ' · ' + cat : cat);
        });
    }

    applyEraLocks();
    // 时代升级后快照会带来新的 era_order:跟着 state 变化重新算一遍闸门,不必重建整个面板
    onChange(applyEraLocks);

    // 放置模式变化:进入放置时自动收起 sheet(让玩家能点地图,收起走 buildPanel.close
    // 以便导航同步熄灯)、浮动取消按钮跟随显隐、高亮当前选中项
    onPlacementChange((placement) => {
        cancelFloat.hidden = !placement;
        if (placement) buildPanel.close();
        Object.keys(buttons).forEach((id) => {
            buttons[id].classList.toggle('active', !!placement && placement.buildingId === id);
        });
    });
}
