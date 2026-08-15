// 建造面板(W12 起是可开合的底部 sheet,默认收起):拉取可建建筑列表,渲染可滚动的按钮列表;
// 选中后进入放置模式 —— 此时自动收起 sheet 让玩家能点地图,浮动的「取消放置」按钮放置中常显。
// 放置状态本身归 modules/build.js 管,这里只是它的显示层与开关。
//
// W16「跟着规则流程只开放当前可用的建筑」:94 座建筑一次性平铺在一条横向滚动条里没法用,
// 现在按服务端 BuildService 的闸门顺序(时代 → 科技 → 数量上限)分三态显示:
//   可建     正常可点,排最前
//   已满     数量已达 max_count,半透明不可点,排其后(玩家仍看得见「我已经有了」)
//   未解锁   时代或科技不够,默认**不显示**,由表头的「🔒 未解锁 N」按钮切换出来看目标
// 这里只是显示层:玩家改 JS 点亮按钮照样过不了服务端(CLAUDE §66),口径不同也只是提示不准。
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

// 本城已解锁科技(M2-B1 快照的 city.technologies.unlocked):在研的不算解锁,与服务端一致
function unlockedTechs() {
    const tech = (state.city && state.city.technologies) || null;
    return new Set((tech && tech.unlocked) || []);
}

// 各建筑已有座数。**施工中的也计入** —— 与 BuildService 数量上限那一句 count() 同口径
// (它不过滤 status),否则玩家在施工期间会看到一个点下去必然 BUILDING_LIMIT_REACHED 的按钮
function builtCounts() {
    const counts = {};
    ((state.city && state.city.buildings) || []).forEach((b) => {
        counts[b.building_id] = (counts[b.building_id] || 0) + 1;
    });
    return counts;
}

// 单栋建筑的开放状态。闸门顺序与 BuildService 逐条对齐:时代 → 科技 → 数量上限
// (占地与材料要等玩家选好位置才知道,不在这里判)
function statusOf(def, order, techs, counts) {
    const count = counts[def.building_id] || 0;
    const max = Number(def.max_count) || 0;

    if ((Number(def.era_order) || 0) > order) {
        return { kind: 'locked', note: '需要时代 ' + (def.era_name || def.era), count, max };
    }
    if (def.tech_id && !techs.has(def.tech_id)) {
        return { kind: 'locked', note: '需要科技 ' + (def.tech_name || def.tech_id), count, max };
    }
    if (count >= max) {
        return { kind: 'maxed', note: '已达上限 ' + count + '/' + max, count, max };
    }
    return { kind: 'open', note: count > 0 ? '已有 ' + count + '/' + max : '', count, max };
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

    // 未解锁切换:默认收起(「只开放可以用的」),点开是给玩家看下一步该往哪走的
    let showLocked = false;
    const lockedToggle = document.createElement('button');
    lockedToggle.type = 'button';
    lockedToggle.className = 'build-locked-toggle';
    lockedToggle.hidden = true;
    lockedToggle.addEventListener('click', () => {
        showLocked = !showLocked;
        refresh();
    });
    header.appendChild(lockedToggle);

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

    // 一座都不可建时的空态(全满 / 时代太早),否则玩家只看到一条空白滚动条
    const empty = document.createElement('div');
    empty.className = 'build-empty';
    empty.hidden = true;
    list.appendChild(empty);

    const buttons = {};
    const notes = {};
    (state.definitions || []).forEach((def) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'build-item';

        const name = document.createElement('span');
        name.className = 'build-item-name';
        name.textContent = def.name;

        const cost = document.createElement('span');
        cost.className = 'build-item-cost';
        cost.textContent = formatCost(def.level1 && def.level1.cost);

        // 状态行(已有几座 / 已达上限 / 需要什么):没话说时整行隐藏,不占高度
        const note = document.createElement('span');
        note.className = 'build-item-note';
        note.hidden = true;

        btn.appendChild(name);
        btn.appendChild(cost);
        btn.appendChild(note);

        btn.addEventListener('click', () => {
            const current = getPlacement();
            if (current && current.buildingId === def.building_id) {
                cancelPlacement(); // 再次点击同一项:取消放置模式
            } else {
                selectBuilding(def);
            }
        });

        buttons[def.building_id] = btn;
        notes[def.building_id] = note;
        list.appendChild(btn);
    });

    // 按当前城市状态重算三态。刻意只改 class / order / hidden 而不重建 DOM:
    // 每次快照都重建 94 个按钮既浪费,也会把放置中的 active 高亮抹掉。
    // 排序用 flex 的 order(.build-list 本就是 flex),不动 DOM 顺序
    function refresh() {
        const order = cityEraOrder();
        const techs = unlockedTechs();
        const counts = builtCounts();
        let openCount = 0;
        let lockedCount = 0;

        (state.definitions || []).forEach((def) => {
            const btn = buttons[def.building_id];
            if (!btn) return;
            const st = statusOf(def, order, techs, counts);
            const locked = st.kind === 'locked';
            const maxed = st.kind === 'maxed';

            if (st.kind === 'open') openCount += 1;
            if (locked) lockedCount += 1;

            btn.disabled = locked || maxed;
            btn.classList.toggle('era-locked', locked);
            btn.classList.toggle('is-maxed', maxed);
            // 可建 → 已满 → 未解锁:一眼扫过去先看到现在能建什么
            btn.style.order = st.kind === 'open' ? '0' : (maxed ? '1' : '2');
            btn.hidden = locked && !showLocked;

            const note = notes[def.building_id];
            note.textContent = st.note;
            note.hidden = st.note === '';

            const cat = categoryName(def.category);
            const era = def.era_name || def.era;
            btn.title = locked
                ? st.note + ' · ' + cat
                : (era ? era + ' · ' + cat : cat);
        });

        lockedToggle.hidden = lockedCount === 0;
        lockedToggle.textContent = showLocked ? '收起未解锁' : '🔒 未解锁 ' + lockedCount;
        lockedToggle.classList.toggle('active', showLocked);

        // 空态只在「连一座能建的都没有、也没展开未解锁」时出现
        const nothingVisible = openCount === 0 && !showLocked;
        empty.hidden = !nothingVisible;
        if (nothingVisible) {
            // 现行数值里每座建筑都有前置科技,新城市 0 科技 —— 起手空列表是常态,
            // 提示必须直接说清下一步该干什么,否则新玩家会以为面板坏了
            empty.textContent = lockedCount > 0
                ? '现在还没有能建的建筑。先去「科技」研究前置科技,或点右上「未解锁」看各建筑差什么'
                : '暂无可建的建筑';
        }
    }

    refresh();
    // 时代升级 / 科技解锁 / 建成一座 都会改变三态:跟着 state 变化重算,不必重建整个面板
    onChange(refresh);

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
