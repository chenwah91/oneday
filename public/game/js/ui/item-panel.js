// 工具面板(M3 前端波二):库存工具清单 + 制作 / 装备 / 卸下 / 耐久。
//
// 职责边界(CLAUDE §5 / §45 / §66):面板只提交意图(item_id / city_item_id /
// building_instance_id + 幂等键 + expected_revision)。材料够不够、时代到没到、槽位满没满
// 一律由服务器在锁内再判一次 —— 这里的置灰与「材料不足」标红只是显示层的提前提示,
// 玩家改 JS 把按钮点亮也没用。
//
// 数据来源:
//   ① 运行时:GET /api/city 快照的 city.items 区块(list / equipment / 计数 / 槽位规则 / 耐久预警);
//   ② 定义:GET /api/definitions/items —— **玩家侧这个端点目前不存在**(见下),制作区据此降级。
//
// ══ 契约缺口(W7 交付汇报已列,等裁决)═════════════════════════════════════════
//   ① 没有玩家侧工具定义端点。制作必须提交 item_id,而「有哪些工具 / 材料成本多少 /
//      要什么时代与制作建筑」三样都只在 item_definition 里,玩家侧一个都读不到
//      (/api/admin/definitions/items 是 edit_definition 权限的后台端点,普通玩家 403)。
//      本面板按 DefinitionController 的既有形状去试 GET /api/definitions/items:
//      端点在 → 制作区正常显示成本预检;端点不在 → 制作区显示缺口提示,其余功能照常。
//      解析刻意写得宽松(name / cost 等字段多种写法都认),后端补齐后前端零改动即可点亮。
//   ② 没有修理端点。routes/web.php 的 M3-ITEM 锚点只有 craft / equip / unequip,
//      全仓库搜不到任何 repair;§7 与 backlog §9 B4 的口径是「耐久归零即损毁,重新制作」。
//      所以本面板**不画一个打不通的修理按钮**:修理区只在快照的 items 块出现
//      repair_enabled = true 时才渲染(后端补上端点时顺手带这个开关即可)。
//   ③ 快照的 items 契约没有 equip_target_desc_zh —— 工具显示名只能靠 enum-names.js
//      的临时小表补(见该文件的 ITEM_EQUIP_TARGET_NAMES 注释)。
//
// 面板范式照 ui/npc-panel.js / ui/market-panel.js:类模块 + 指纹跳过重绘 + 409 恢复 + 筛选。
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
import { onChange, setState } from '../core/state.js';
import { errorText } from '../core/error-messages.js';
import { newIdempotencyKey } from '../core/idempotency.js';
import {
    itemCategoryName, itemDisplayName, itemDurabilityModeName,
    itemEffectText, itemSourceName, itemTierName,
} from '../core/enum-names.js';
import { notifySuccess, notifyError } from './notification.js';
import { updateHud } from './hud.js';
import { resourceName } from '../modules/resources.js';
import { fmt, fmtDec } from '../utils/format.js';

// 制作语境的错误码覆盖:泛化文案说不清「为什么做不出来」
const CRAFT_ERRORS = {
    ERA_REQUIRED: '当前时代还造不了这件工具,先升级时代',
    INSUFFICIENT_RESOURCE: '材料或资金不足,做不了这件工具',
    NOT_FOUND: '没有这件工具的定义,刷新页面后重试',
    VALIDATION_ERROR: '这件工具的定义有问题,暂时做不了',
};

// 装备语境:后端把「楼没建成 / 楼不属于本城 / 工具已不在」都归到 NOT_FOUND
const EQUIP_ERRORS = {
    NOT_FOUND: '这栋建筑还不能装备(要先建成),或这件工具已不在库存里',
};

const FILTERS = [
    { key: 'all', label: '全部' },
    { key: 'equipped', label: '已装备' },
    { key: 'stored', label: '库存' },
    { key: 'warning', label: '预警' },
];

// 快照 items.equipment 在没有任何装备时是 JSON 数组 []、有装备时是对象 —— 两种形状都要读得动
// (与 npc-panel.js 的 assignedIds 同一处理)
function equippedIds(equipment, instanceId) {
    if (!equipment) return [];
    const list = equipment[instanceId] || equipment[String(instanceId)];
    return Array.isArray(list) ? list : [];
}

// 建造成功后 build.js 会先塞一条 id 为 "local-xxx" 的临时记录,等轮询回来才有真实 id;
// 这种记录不能拿去请求装备(后端要求 integer)
function isSynced(id) {
    return /^\d+$/.test(String(id));
}

export class ItemPanel {
    constructor({ api, state, onOpen }) {
        this.api = api;
        this.state = state;
        // onOpen:打开时通知外部(main.js 用它做面板互斥),自己不知道别的面板是谁
        this.onOpen = onOpen || null;

        this.rootEl = null;
        this.fabEl = null;
        this.badgeEl = null;   // 耐久预警红点(B4 的 durability_warning)
        this.listEl = null;
        this.opened = false;
        this.busy = false;
        this.defsLoading = false;
        this.defsFailed = false; // 定义端点不可用(缺口①):制作区降级显示
        this.filter = 'all';
        this.craftOpen = false;  // 制作区展开状态(默认收起,窄屏下先让玩家看到库存)
        this.pickerFor = null;   // 正在展开「选建筑」的 city_item_id
        this.valueRefs = null;   // 耐久条 / 耐久文本的原地更新引用
        this.lastSignature = '';
        this.unsubscribed = false;
    }

    // el:挂载容器(#stage,已是 position:relative,入口与面板都绝对定位其中)
    mount(el) {
        this.fabEl = document.createElement('button');
        this.fabEl.type = 'button';
        this.fabEl.className = 'game-fab item-fab';
        this.fabEl.title = '工具';
        this.fabEl.setAttribute('aria-label', '打开工具面板');
        this.fabEl.textContent = '🧰 工具';

        this.badgeEl = document.createElement('span');
        this.badgeEl.className = 'fab-badge';
        this.badgeEl.hidden = true;
        this.fabEl.appendChild(this.badgeEl);

        this.fabEl.addEventListener('click', () => (this.opened ? this.close() : this.open()));
        el.appendChild(this.fabEl);

        this.rootEl = document.createElement('div');
        this.rootEl.className = 'item-panel';
        this.rootEl.hidden = true;
        el.appendChild(this.rootEl);

        this.syncBadge();

        // 快照更新后:结构没变就只原地刷耐久(每 10 秒轮询一次,耐久一直在掉),
        // 免得把展开中的「选建筑」列表和滚动位置每 10 秒打断一次
        onChange(() => {
            if (this.unsubscribed) return;
            this.syncBadge();
            if (!this.opened) return;
            if (this.signature() === this.lastSignature) {
                this.syncValues();
                return;
            }
            this.render();
        });
    }

    async open() {
        if (this.onOpen) this.onOpen(this);

        this.opened = true;
        this.pickerFor = null;
        this.fabEl.classList.add('active');
        this.rootEl.hidden = false;
        this.render();

        await this.loadDefs();
        if (!this.opened) return; // 定义还在路上时玩家已经关掉面板
        this.render();
    }

    close() {
        if (!this.opened) return;
        this.opened = false;
        this.pickerFor = null;
        this.valueRefs = null;
        if (this.fabEl) this.fabEl.classList.remove('active');
        if (this.rootEl) {
            this.rootEl.hidden = true;
            this.rootEl.innerHTML = '';
        }
        this.listEl = null;
    }

    destroy() {
        this.unsubscribed = true;
        if (this.rootEl && this.rootEl.parentNode) this.rootEl.parentNode.removeChild(this.rootEl);
        if (this.fabEl && this.fabEl.parentNode) this.fabEl.parentNode.removeChild(this.fabEl);
        this.rootEl = null;
        this.fabEl = null;
        this.badgeEl = null;
        this.listEl = null;
    }

    // ---- 数据 ----

    // 快照里的工具区块;拿不到时退化成空,面板照常显示(空列表 + 制作入口)
    itemsState() {
        const city = this.state.city || {};
        const it = city.items || {};
        return {
            total: Number(it.total) || 0,
            stored: Number(it.stored) || 0,
            equipped: Number(it.equipped) || 0,
            broken: Number(it.broken) || 0,
            warning: Number(it.durability_warning) || 0,
            slots: Number(it.slots_per_building) || 0,
            // 修理开关(缺口②):后端补上 repair 端点时在快照里带 true,前端才渲染修理按钮
            repairEnabled: it.repair_enabled === true,
            list: Array.isArray(it.list) ? it.list : [],
            equipment: it.equipment || {},
        };
    }

    buildings() {
        return (this.state.city && this.state.city.buildings) || [];
    }

    buildingById(instanceId) {
        const list = this.buildings();
        for (let i = 0; i < list.length; i++) {
            if (String(list[i].id) === String(instanceId)) return list[i];
        }
        return null;
    }

    // 建筑显示名:定义表里的中文名 + 等级(定义由建造面板启动时缓存进 state.definitions)
    buildingName(instanceId) {
        const b = this.buildingById(instanceId);
        if (!b) return '建筑 #' + instanceId;
        return this.buildingDefName(b.building_id) + ' Lv' + b.level;
    }

    buildingDefName(buildingId) {
        const defs = this.state.definitions || [];
        const found = defs.filter((d) => d.building_id === buildingId)[0];
        return found ? found.name : buildingId;
    }

    // 持有量:资金单列在 city.money(与后端口径一致),其余走 city.resources
    heldOf(code) {
        const city = this.state.city || {};
        if (code === 'money') return Number(city.money) || 0;
        return Number((city.resources || {})[code]) || 0;
    }

    // 可制作清单:定义端点给什么就用什么,字段名容错(见文件头缺口①)
    craftDefs() {
        const defs = this.state.itemDefs;
        return Array.isArray(defs) ? defs : [];
    }

    // 面板结构指纹:耐久值**不进**指纹(它每轮询都在掉,走 syncValues 原地更新)
    signature() {
        const s = this.itemsState();
        const rows = s.list.map((i) => [i.id, i.status, i.equipped_instance_id].join(':')).join(',');
        return [
            s.total, s.stored, s.equipped, s.broken, s.repairEnabled ? 1 : 0, rows,
            this.buildings().map((b) => b.id + ':' + b.level + ':' + b.status).join(','),
            this.filter,
            this.craftOpen ? 1 : 0,
            this.craftDefs().length,
            this.defsLoading ? 1 : 0,
            this.defsFailed ? 1 : 0,
            this.pickerFor === null ? '-' : this.pickerFor,
            this.busy ? 1 : 0,
        ].join('|');
    }

    // ---- 渲染 ----

    render() {
        if (!this.rootEl || !this.opened) return;
        this.lastSignature = this.signature();
        this.valueRefs = [];
        this.rootEl.innerHTML = '';

        const s = this.itemsState();

        const header = document.createElement('div');
        header.className = 'item-header';

        const title = document.createElement('span');
        title.className = 'item-title';
        title.textContent = '工具';
        header.appendChild(title);

        const counts = document.createElement('span');
        counts.className = 'item-counts';
        counts.textContent = '在用 ' + s.equipped + ' · 库存 ' + s.stored + ' · 共 ' + s.total;
        header.appendChild(counts);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'item-close';
        closeBtn.title = '关闭';
        closeBtn.setAttribute('aria-label', '关闭');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => this.close());
        header.appendChild(closeBtn);

        this.rootEl.appendChild(header);

        // 全局规则:槽位与损毁计数。损毁的工具不在 list 里(行保留只为可追溯),
        // 给个计数是为了让玩家看出「工具是被用坏了,不是凭空消失」
        const meta = document.createElement('div');
        meta.className = 'item-summary';
        meta.textContent = '每栋建筑 ' + s.slots + ' 个工具槽 · 耐久预警 ' + s.warning
            + ' 件 · 已损毁 ' + s.broken + ' 件';
        this.rootEl.appendChild(meta);

        this.rootEl.appendChild(this.makeCraftBox());

        const filters = document.createElement('div');
        filters.className = 'item-filters';
        FILTERS.forEach((f) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'item-chip' + (this.filter === f.key ? ' active' : '');
            chip.textContent = f.label;
            chip.addEventListener('click', () => {
                this.filter = f.key;
                this.render();
            });
            filters.appendChild(chip);
        });
        this.rootEl.appendChild(filters);

        this.listEl = document.createElement('div');
        this.listEl.className = 'item-list';
        this.rootEl.appendChild(this.listEl);

        this.renderList();
    }

    // ---- 制作区 ----

    makeCraftBox() {
        const box = document.createElement('div');
        box.className = 'item-craft';

        const head = document.createElement('button');
        head.type = 'button';
        head.className = 'item-craft-head';
        head.setAttribute('aria-expanded', this.craftOpen ? 'true' : 'false');

        const label = document.createElement('span');
        label.className = 'item-craft-label';
        label.textContent = '制作工具';
        head.appendChild(label);

        const arrow = document.createElement('span');
        arrow.className = 'item-craft-arrow';
        arrow.textContent = this.craftOpen ? '收起 ▲' : '展开 ▼';
        head.appendChild(arrow);

        head.addEventListener('click', () => {
            this.craftOpen = !this.craftOpen;
            this.render();
        });
        box.appendChild(head);

        if (!this.craftOpen) return box;

        if (this.defsLoading) {
            box.appendChild(this.makeHint('工具定义加载中...'));
            return box;
        }

        // 缺口①:玩家侧没有工具定义端点时,把原因如实写出来 —— 制作要提交 item_id,
        // 而 item_id / 材料成本 / 时代与建筑前置都只在服务器的 item_definition 里。
        // 前端不猜、不内置一份成本表(内置的那一刻就与后台可调的定义分叉了)
        if (this.defsFailed || !this.craftDefs().length) {
            box.appendChild(this.makeHint(
                '制作暂不可用:玩家侧还没有工具定义端点(GET /api/definitions/items),'
                + '拿不到可制作清单与材料成本。已列入契约缺口清单,补齐后本区自动生效。'
            ));
            return box;
        }

        this.craftDefs().forEach((def) => box.appendChild(this.makeCraftRow(def)));

        box.appendChild(this.makeHint('材料与费用以服务器扣除为准;工具做出来是「库存中」,要装到建筑上才生效。'));

        return box;
    }

    makeCraftRow(def) {
        const row = document.createElement('div');
        row.className = 'item-craft-row';

        const head = document.createElement('div');
        head.className = 'item-craft-row-head';

        const name = document.createElement('span');
        name.className = 'item-craft-name';
        name.textContent = itemDisplayName(def.item_id, def.category);
        head.appendChild(name);

        const era = document.createElement('span');
        era.className = 'item-craft-era';
        era.textContent = def.min_era ? '时代 ' + def.min_era : '';
        head.appendChild(era);

        row.appendChild(head);

        const effect = itemEffectText(def.effect_code, def.effect_value, def.unit);
        if (effect) {
            const eff = document.createElement('div');
            eff.className = 'item-craft-effect';
            eff.textContent = effect + (def.durability ? ' · 耐久 ' + fmt(def.durability) : '');
            row.appendChild(eff);
        }

        // 成本预检:逐项列出材料 / 费用,缺的那一项标橙。
        // **只是显示层的提前提示**,真正扣不扣得动由服务器在锁内按结算后余额判(§45)
        const cost = this.costOf(def);
        const costRow = document.createElement('div');
        costRow.className = 'item-craft-cost';
        let affordable = true;

        Object.keys(cost).forEach((code, idx) => {
            const need = Number(cost[code]) || 0;
            const have = this.heldOf(code);
            const ok = have >= need;
            if (!ok) affordable = false;

            const cell = document.createElement('span');
            cell.className = 'item-cost-cell' + (ok ? '' : ' is-missing');
            cell.textContent = (idx ? ' · ' : '') + resourceName(code) + ' ' + fmtDec(need, 0)
                + '(有 ' + fmtDec(have, 0) + ')';
            costRow.appendChild(cell);
        });

        if (!Object.keys(cost).length) {
            const cell = document.createElement('span');
            cell.className = 'item-cost-cell';
            cell.textContent = '成本未知(定义端点没给 craft_cost)';
            costRow.appendChild(cell);
        }

        row.appendChild(costRow);

        // 制作建筑前置(§7 的 crafting_source):定义里给了 building_id 才校验;
        // 手工制作 / 来源建筑不在 94 栋内的两类不设建筑门槛
        const needBuilding = def.crafting_building_id || null;
        let buildingOk = true;
        if (needBuilding) {
            buildingOk = this.buildings().some((b) => b.building_id === needBuilding && b.status === 'active');
        }

        const source = document.createElement('div');
        source.className = 'item-craft-source' + (buildingOk ? '' : ' is-missing');
        source.textContent = needBuilding
            ? '制作于:' + this.buildingDefName(needBuilding) + (buildingOk ? '' : '(还没建)')
            : '制作于:' + (def.crafting_source_desc_zh || '无需建筑');
        row.appendChild(source);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'item-btn item-btn-primary';
        btn.textContent = this.busy ? '处理中...' : '制作';
        btn.disabled = this.busy;
        btn.title = affordable && buildingOk ? '' : '条件可能不满足,服务器会再判一次';
        btn.addEventListener('click', () => this.doCraft(def));
        row.appendChild(btn);

        if (!affordable) row.classList.add('is-short');

        return row;
    }

    // 成本字段容错:后端补端点时可能叫 craft_cost / cost / craft_cost_json 里的任一种
    costOf(def) {
        const cost = def.craft_cost || def.cost || null;
        return cost && typeof cost === 'object' ? cost : {};
    }

    makeHint(text) {
        const hint = document.createElement('div');
        hint.className = 'item-hint';
        hint.textContent = text;
        return hint;
    }

    // ---- 列表 ----

    renderList() {
        const s = this.itemsState();
        const rows = s.list.filter((i) => {
            if (this.filter === 'equipped') return i.equipped_instance_id !== null;
            if (this.filter === 'stored') return i.equipped_instance_id === null;
            if (this.filter === 'warning') return i.durability_warning === true;
            return true;
        });

        if (!rows.length) {
            const empty = document.createElement('div');
            empty.className = 'item-empty';
            if (s.total === 0) {
                empty.textContent = '还没有工具。展开上面的「制作工具」做一件,装到建筑上就能提高产量。';
            } else if (this.filter === 'warning') {
                empty.textContent = '没有接近报废的工具';
            } else {
                empty.textContent = this.filter === 'equipped' ? '还没有装备中的工具' : '库存里没有闲置工具';
            }
            this.listEl.appendChild(empty);
            return;
        }

        rows.forEach((i) => this.listEl.appendChild(this.makeCard(i)));
    }

    makeCard(item) {
        const equipped = item.equipped_instance_id !== null;

        const card = document.createElement('div');
        card.className = 'item-card' + (equipped ? ' is-equipped' : ' is-stored');

        const head = document.createElement('div');
        head.className = 'item-card-head';

        const name = document.createElement('span');
        name.className = 'item-card-name';
        name.textContent = itemDisplayName(item.item_id, item.category);
        head.appendChild(name);

        const tag = document.createElement('span');
        tag.className = 'item-tag';
        tag.textContent = item.item_id;
        head.appendChild(tag);

        card.appendChild(head);

        const effect = itemEffectText(item.effect_code, item.effect_value, item.unit);
        const eff = document.createElement('div');
        eff.className = 'item-card-effect';
        // §7:单栋建筑内同类只取最高,异类相乘 —— 这条不说清楚,玩家会往一栋楼里堆同款
        eff.textContent = (effect || itemCategoryName(item.category)) + ' · 同栋同类只取最高';
        card.appendChild(eff);

        // 耐久条:left / max。耐久预警标记(durability_warning)由服务器按后台阈值下发,
        // 前端不自己拿百分比再判一次阈值(阈值是后台可调的运营参数)
        card.appendChild(this.makeDurability(item));

        const meta = document.createElement('div');
        meta.className = 'item-card-meta';
        meta.textContent = itemTierName(item.durability_tier) + ' · '
            + itemDurabilityModeName(item.durability_mode) + ' · 来源 ' + itemSourceName(item.acquired_source);
        card.appendChild(meta);

        const status = document.createElement('div');
        status.className = 'item-card-status' + (equipped ? ' is-on-duty' : '');
        status.textContent = equipped
            ? '已装备 · ' + this.buildingName(item.equipped_instance_id)
            : '库存中(不产生任何加成,也不掉耐久)';
        card.appendChild(status);

        card.appendChild(this.makeActions(item, equipped));

        if (this.pickerFor !== null && String(this.pickerFor) === String(item.id)) {
            card.appendChild(this.makePicker(item));
        }

        return card;
    }

    makeDurability(item) {
        const max = Number(item.durability_max) || 0;
        const left = Number(item.durability_left) || 0;
        const pct = max > 0 ? Math.max(0, Math.min(1, left / max)) : 0;
        const warn = item.durability_warning === true;

        const box = document.createElement('div');
        box.className = 'item-durability';

        const bar = document.createElement('div');
        bar.className = 'item-dura-bar';

        const fill = document.createElement('div');
        fill.className = 'item-dura-fill' + (warn ? ' is-warning' : '');
        fill.style.width = (pct * 100).toFixed(1) + '%';
        bar.appendChild(fill);
        box.appendChild(bar);

        const text = document.createElement('div');
        text.className = 'item-dura-text' + (warn ? ' is-warning' : '');
        text.textContent = '耐久 ' + fmtDec(left, 1) + ' / ' + fmt(max)
            + (warn ? ' · 快报废了,归零即损毁' : '');
        box.appendChild(text);

        // 指纹没变时靠这两个引用原地刷新,不重建 DOM
        if (this.valueRefs) this.valueRefs.push({ id: item.id, fill: fill, text: text });

        return box;
    }

    makeActions(item, equipped) {
        const actions = document.createElement('div');
        actions.className = 'item-card-actions';

        if (equipped) {
            const off = document.createElement('button');
            off.type = 'button';
            off.className = 'item-btn';
            off.textContent = '卸下';
            off.disabled = this.busy;
            off.addEventListener('click', () => this.doUnequip(item));
            actions.appendChild(off);
        } else {
            const on = document.createElement('button');
            on.type = 'button';
            on.className = 'item-btn item-btn-primary';
            on.textContent = this.pickerFor !== null && String(this.pickerFor) === String(item.id) ? '收起' : '装备';
            on.disabled = this.busy;
            on.addEventListener('click', () => {
                this.pickerFor = this.pickerFor !== null && String(this.pickerFor) === String(item.id) ? null : item.id;
                this.render();
            });
            actions.appendChild(on);
        }

        // 修理:缺口② —— 端点不存在时**不画按钮**,免得给玩家一个点了必报错的入口。
        // 后端补上 POST /api/city/item/repair 并在快照 items 块带 repair_enabled=true 即可点亮
        if (this.itemsState().repairEnabled) {
            const fix = document.createElement('button');
            fix.type = 'button';
            fix.className = 'item-btn';
            fix.textContent = '修理';
            fix.disabled = this.busy || Number(item.durability_left) >= Number(item.durability_max);
            fix.addEventListener('click', () => this.doRepair(item));
            actions.appendChild(fix);
        }

        return actions;
    }

    // 选建筑:只列已建成(active)的实例,槽位满的置灰。
    // 槽位数来自服务器下发的 slots_per_building,不在前端写死
    makePicker(item) {
        const box = document.createElement('div');
        box.className = 'item-picker';

        const s = this.itemsState();
        const candidates = this.buildings().filter((b) => b.status === 'active' && isSynced(b.id));

        if (!candidates.length) {
            box.appendChild(this.makeHint('没有可装备的建筑(要先有已建成的建筑)。'));
            return box;
        }

        candidates.forEach((b) => {
            const used = equippedIds(s.equipment, b.id).length;
            const full = used >= s.slots;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'item-picker-item' + (full ? ' is-full' : '');
            btn.disabled = this.busy || full;

            const label = document.createElement('span');
            label.textContent = this.buildingName(b.id) + ' (' + b.x + ',' + b.y + ')';
            btn.appendChild(label);

            const slot = document.createElement('span');
            slot.className = 'item-picker-slot';
            slot.textContent = used + ' / ' + s.slots + (full ? ' 已满' : '');
            btn.appendChild(slot);

            btn.addEventListener('click', () => this.doEquip(item, b.id));
            box.appendChild(btn);
        });

        return box;
    }

    // 指纹未变时的轻量同步:只改随时间掉的耐久,不动 DOM 结构
    syncValues() {
        const refs = this.valueRefs;
        if (!refs || !refs.length) return;

        const byId = {};
        this.itemsState().list.forEach((i) => { byId[i.id] = i; });

        refs.forEach((ref) => {
            const item = byId[ref.id];
            if (!item) return;
            const max = Number(item.durability_max) || 0;
            const left = Number(item.durability_left) || 0;
            const pct = max > 0 ? Math.max(0, Math.min(1, left / max)) : 0;
            const warn = item.durability_warning === true;

            ref.fill.style.width = (pct * 100).toFixed(1) + '%';
            ref.fill.className = 'item-dura-fill' + (warn ? ' is-warning' : '');
            ref.text.className = 'item-dura-text' + (warn ? ' is-warning' : '');
            ref.text.textContent = '耐久 ' + fmtDec(left, 1) + ' / ' + fmt(max)
                + (warn ? ' · 快报废了,归零即损毁' : '');
        });
    }

    // 入口红点:耐久预警件数(B4)。面板关着也要更新
    syncBadge() {
        if (!this.badgeEl) return;
        const warn = this.itemsState().warning;
        this.badgeEl.textContent = warn > 99 ? '99+' : String(warn);
        this.badgeEl.hidden = warn <= 0;
    }

    // ---- 请求 ----

    // 工具定义:玩家侧端点尚未就绪(缺口①),失败一律静默降级 —— 不弹错误提示,
    // 因为「没有这个端点」不是玩家做错了什么
    async loadDefs() {
        if (this.craftDefs().length || this.defsLoading) return;

        this.defsLoading = true;
        if (this.opened) this.render();

        try {
            const data = await this.api.get('/api/definitions/items');
            const list = (data && (data.items || data.item_definitions)) || [];
            setState({ itemDefs: Array.isArray(list) ? list : [] });
            this.defsFailed = !Array.isArray(list) || !list.length;
        } catch (e) {
            this.defsFailed = true;
        } finally {
            this.defsLoading = false;
        }
    }

    async refreshCity() {
        try {
            const res = await this.api.get('/api/city');
            setState({ city: res.city });
            updateHud(this.state.city);
        } catch (e) {
            // 刷新失败不打断当前操作,交给 main.js 的定期轮询兜底
        }
    }

    // 三个动作的响应形状一致:{ revision, resources, money, delta[, item] }。
    // 资源与资金一律用服务器返回值覆盖,不做本地推算;工具清单本身不在响应里,
    // 所以动作成功后再拉一次权威快照(新造的工具、槽位占用都要从快照来)
    applyDiff(diff) {
        const city = this.state.city;
        if (!city || !diff) return;

        setState({
            city: Object.assign({}, city, {
                revision: diff.revision,
                resources: Object.assign({}, city.resources, diff.resources),
                money: diff.money,
            }),
        });

        updateHud(this.state.city);
    }

    // 统一的失败处理:提示 + 必要时拉一次权威快照(与建筑 / NPC 面板同一条 409 恢复流程)
    async handleError(err, fallback, overrides) {
        notifyError(errorText(err, fallback, overrides));
        const code = err && err.error;
        if (code === 'REVISION_CONFLICT' || code === 'NOT_FOUND' || code === 'ITEM_SLOT_FULL'
            || code === 'ITEM_BROKEN' || code === 'ITEM_ALREADY_EQUIPPED'
            || code === 'INSUFFICIENT_RESOURCE') {
            await this.refreshCity();
        }
    }

    async doCraft(def) {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/item/craft', {
                item_id: String(def.item_id),
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);

            // 花费一律读服务器返回的 delta(负数 = 扣掉),不拿前端预检的成本冒充成交
            const spent = Object.keys(diff.delta || {})
                .map((code) => resourceName(code) + ' ' + fmtDec(Math.abs(diff.delta[code]), 0))
                .join(' · ');
            notifySuccess('已制作 ' + itemDisplayName(def.item_id, def.category)
                + (spent ? ' · 花费 ' + spent : ''));

            await this.refreshCity();
        } catch (err) {
            await this.handleError(err, '制作失败,请重试', CRAFT_ERRORS);
        } finally {
            this.busy = false;
            this.render();
        }
    }

    async doEquip(item, buildingInstanceId) {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/item/equip', {
                city_item_id: Number(item.id),
                building_instance_id: Number(buildingInstanceId),
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);
            notifySuccess('已把 ' + itemDisplayName(item.item_id, item.category)
                + ' 装到 ' + this.buildingName(buildingInstanceId));
            this.pickerFor = null;
            await this.refreshCity();
        } catch (err) {
            await this.handleError(err, '装备失败,请重试', EQUIP_ERRORS);
        } finally {
            this.busy = false;
            this.render();
        }
    }

    async doUnequip(item) {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/item/unequip', {
                city_item_id: Number(item.id),
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);
            notifySuccess('已卸下 ' + itemDisplayName(item.item_id, item.category) + '(剩余耐久保留)');
            await this.refreshCity();
        } catch (err) {
            await this.handleError(err, '卸下失败,请重试');
        } finally {
            this.busy = false;
            this.render();
        }
    }

    // 修理:端点由后端补齐后才可达(缺口②);按钮本身也只在 repair_enabled 时才渲染
    async doRepair(item) {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/item/repair', {
                city_item_id: Number(item.id),
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);
            notifySuccess('已修理 ' + itemDisplayName(item.item_id, item.category));
            await this.refreshCity();
        } catch (err) {
            await this.handleError(err, '修理失败,请重试');
        } finally {
            this.busy = false;
            this.render();
        }
    }
}
