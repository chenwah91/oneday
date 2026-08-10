// 建造面板:拉取可建建筑列表,渲染可滚动的按钮列表;选中后进入放置模式
import { api } from '../core/api.js';
import { state, setState } from '../core/state.js';
import { selectBuilding, cancelPlacement, onPlacementChange, getPlacement } from '../modules/build.js';
import { loadResourceNames, resourceName } from '../modules/resources.js';
import { categoryName } from '../core/enum-names.js';

// 成本紧凑展示,如 "木材20 石料5"(cost 的键是资源 code,显示时翻成中文名)
function formatCost(cost) {
    return Object.entries(cost || {})
        .map(([code, amt]) => resourceName(code) + amt)
        .join(' ');
}

// el:面板挂载容器(#panel,底部停靠,移动端友好)
export async function mountBuildPanel(el) {
    el.innerHTML = '';
    el.classList.add('build-panel');

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

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'build-cancel-btn';
    cancelBtn.textContent = '取消放置';
    cancelBtn.hidden = true;
    cancelBtn.addEventListener('click', () => cancelPlacement());
    header.appendChild(cancelBtn);

    el.appendChild(header);

    const list = document.createElement('div');
    list.className = 'build-list';
    el.appendChild(list);

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

    // 放置模式变化时:高亮当前选中项、显示/隐藏取消按钮
    onPlacementChange((placement) => {
        cancelBtn.hidden = !placement;
        Object.keys(buttons).forEach((id) => {
            buttons[id].classList.toggle('active', !!placement && placement.buildingId === id);
        });
    });
}
