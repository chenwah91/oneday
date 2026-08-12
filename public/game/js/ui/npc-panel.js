// NPC 面板(M3 前端波一):已招募清单 + 招募 / 派驻 / 撤下 / 辞退。
//
// 职责边界(CLAUDE §5 / §66):面板只提交意图(city_npc_id / building_instance_id + 幂等键 +
// expected_revision),抽到谁、扣多少钱、槽位够不够一律由服务器决定 —— 这里的置灰与槽位计数
// 只是显示层的提前提示,玩家改 JS 把按钮点亮也没用,服务端会用同一套规则再判一次。
//
// 数据来源:GET /api/city 快照的 city.npcs 区块(list / assignments / 汇总 / 槽位规则),
// 定义数据(名称/特性/等级曲线)没有玩家侧端点,所以本面板不显示招募池明细(见交付汇报的契约清单)。
//
// 面板范式照 ui/technology-panel.js:类模块 + 三态 + 指纹跳过重绘 + 409 恢复 + 筛选。
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
import { onChange, setState } from '../core/state.js';
import { errorText } from '../core/error-messages.js';
import { newIdempotencyKey } from '../core/idempotency.js';
import { npcRarityName, npcSkillName, npcStatusName } from '../core/enum-names.js';
import { notifySuccess, notifyError } from './notification.js';
import { updateHud } from './hud.js';
import { fmt, fmtDec } from '../utils/format.js';

// 离职警示线的兜底值:快照 city.npcs.morale_leave_threshold 才是权威(W7 起后端已下发,
// 来源是后台设定 npc_morale_leave_threshold)。这里的 30 只在老响应 / 字段缺失时用 ——
// 后台调过设定后前端立刻跟着变,不再有「面板画红线、服务端按另一个数判」的两套真相
const MORALE_ALERT_FALLBACK = 30;

// 招募语境的错误码覆盖:泛化文案说不清「为什么抽不到人」
const RECRUIT_ERRORS = {
    NPC_ERA_REQUIRED: '当前时代还没有可招募的人才,先升级时代',
    NPC_NOT_AVAILABLE: '当前没有可招募的人才',
    INSUFFICIENT_RESOURCE: '资金不足,招不到人(招募价按稀有度浮动)',
};

// 派驻语境的覆盖:后端把「楼没建成 / NPC 已离场」都归到 NPC_NOT_AVAILABLE
const ASSIGN_ERRORS = {
    NPC_NOT_AVAILABLE: '这栋建筑还不能派驻(要先建成),或这名 NPC 已离场',
    VALIDATION_ERROR: '派驻目标已变化,请刷新后重试',
};

const DISMISS_ERRORS = {
    NPC_NOT_AVAILABLE: '这名 NPC 已经离场了',
};

const FILTERS = [
    { key: 'all', label: '全部' },
    { key: 'assigned', label: '在岗' },
    { key: 'idle', label: '闲置' },
];

// 快照 npcs.assignments 在没有任何派驻时是 JSON 数组 []、有派驻时是对象 —— 两种形状都要读得动
function assignedIds(assignments, instanceId) {
    if (!assignments) return [];
    const list = assignments[instanceId] || assignments[String(instanceId)];
    return Array.isArray(list) ? list : [];
}

// 建造成功后 build.js 会先塞一条 id 为 "local-xxx" 的临时记录,等轮询回来才有真实 id;
// 这种记录不能拿去请求派驻(后端要求 integer)
function isSynced(id) {
    return /^\d+$/.test(String(id));
}

export class NpcPanel {
    constructor({ api, state, onOpen }) {
        this.api = api;
        this.state = state;
        // onOpen:打开时通知外部(main.js 用它做面板互斥),自己不知道别的面板是谁
        this.onOpen = onOpen || null;

        this.rootEl = null;     // 面板根节点
        this.listEl = null;     // 列表容器
        this.opened = false;
        this.busy = false;      // 请求进行中:禁用全部按钮,防重复提交
        this.filter = 'all';
        this.assignTarget = null; // 从建筑详情进来的派驻目标(building_instance_id)
        this.pickerFor = null;    // 正在展开「选建筑」的 city_npc_id
        this.confirm = null;      // { type: 'recruit' } | { type: 'dismiss', id }
        this.poolOpen = false;    // 招募池预览的展开状态(默认收起:它是 150 行的参考资料,不是主操作)
        this.defsLoading = false;
        this.valueRefs = null;    // 士气 / XP 的原地更新引用
        this.lastSignature = '';
        this.unsubscribed = false;
    }

    // el:挂载容器(#stage,已是 position:relative,面板绝对定位其中)。
    // W12 起入口在底部导航(main.js 接线),闲置红点也移到导航角标(main.js 的 syncNavBadges)
    mount(el) {
        this.rootEl = document.createElement('div');
        this.rootEl.className = 'npc-panel';
        this.rootEl.hidden = true;
        el.appendChild(this.rootEl);

        // 快照更新(轮询 / 本面板操作 / 其他面板)后同步刷新。
        // 指纹只含会改变结构或按钮状态的位;士气与 XP 每轮询一次都在动,进指纹会让面板
        // 每 10 秒整块重建一次 —— 展开中的「选建筑」列表和滚动位置都会被打断,
        // 所以指纹没变时走 syncValues() 原地改文本(与科技面板的 syncEraValues 同一处理)
        onChange(() => {
            if (this.unsubscribed || !this.opened) return;
            if (this.signature() === this.lastSignature) {
                this.syncValues();
                return;
            }
            this.render();
        });
    }

    // buildingInstanceId:可选。从建筑详情面板的「派驻 NPC」进来时带上,进入派驻模式
    async open(buildingInstanceId) {
        if (this.onOpen) this.onOpen(this);

        this.opened = true;
        this.assignTarget = buildingInstanceId !== undefined && buildingInstanceId !== null
            ? buildingInstanceId
            : null;
        this.pickerFor = null;
        this.confirm = null;
        if (this.assignTarget !== null) this.filter = 'idle'; // 派驻只能选闲置的人
        this.rootEl.hidden = false;
        this.render();

        // 定义(招募池 + 等级曲线)只拉一次,失败不打断面板 —— 运行时数据全在快照里,
        // 定义没到只是少了「预览」与「距下级还需」两处显示
        await this.loadDefs();
        if (!this.opened) return;
        this.render();
    }

    close() {
        if (!this.opened) return;
        this.opened = false;
        this.assignTarget = null;
        this.pickerFor = null;
        this.confirm = null;
        this.poolOpen = false;
        this.valueRefs = null;
        if (this.rootEl) {
            this.rootEl.hidden = true;
            this.rootEl.innerHTML = '';
        }
        this.listEl = null;
    }

    destroy() {
        this.unsubscribed = true;
        if (this.rootEl && this.rootEl.parentNode) this.rootEl.parentNode.removeChild(this.rootEl);
        this.rootEl = null;
        this.listEl = null;
    }

    // ---- 数据 ----

    // 快照里的 NPC 区块;拿不到时退化成空,面板照常显示(空列表 + 招募入口)
    npcState() {
        const city = this.state.city || {};
        const n = city.npcs || {};
        return {
            total: Number(n.total) || 0,
            idle: Number(n.idle) || 0,
            assigned: Number(n.assigned) || 0,
            wagePerMin: Number(n.wage_money_per_min) || 0,
            foodPerMin: Number(n.food_per_min) || 0,
            slots: Number(n.slots_per_building) || 0,
            slotsL3: Number(n.slots_per_building_l3) || 0,
            // 离职阈值:W7 起由服务器下发(后台设定的唯一口径),缺失才退回常量
            moraleAlert: Number(n.morale_leave_threshold) || MORALE_ALERT_FALLBACK,
            list: Array.isArray(n.list) ? n.list : [],
            assignments: n.assignments || {},
        };
    }

    // 定义数据(GET /api/definitions/npcs):招募池原型 / 技能表 / 等级曲线
    defs() {
        return this.state.npcDefs || null;
    }

    pool() {
        const d = this.defs();
        return d && Array.isArray(d.npcs) ? d.npcs : [];
    }

    // 升到下一级还需要多少 XP。曲线里的 xp_to_next 是**增量**(10 级为 0 = 满级),
    // 快照里的 xp 是**当前等级内**的累计 —— 两者口径对得上才敢相减
    xpToNext(npc) {
        const d = this.defs();
        const curve = d && Array.isArray(d.level_curve) ? d.level_curve : [];
        const row = curve.filter((c) => Number(c.level) === Number(npc.skill_level))[0];
        if (!row || !(Number(row.xp_to_next) > 0)) return null;
        return Math.max(0, Number(row.xp_to_next) - (Number(npc.xp) || 0));
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
        const defs = this.state.definitions || [];
        const found = defs.filter((d) => d.building_id === b.building_id)[0];
        return (found ? found.name : b.building_id) + ' Lv' + b.level;
    }

    // 该建筑的 NPC 槽位数(A5:L3 多一个槽,两个数都由服务器下发)
    slotsOf(building) {
        const s = this.npcState();
        return (Number(building.level) || 1) >= 3 ? s.slotsL3 : s.slots;
    }

    // 面板显示用到的数据指纹:只有这些值变了才需要重建 DOM
    signature() {
        const s = this.npcState();
        const rows = s.list.map((n) => [n.id, n.status, n.skill_level, n.assigned_instance_id].join(':')).join(',');
        return [
            s.total, s.idle, s.assigned, rows,
            this.buildings().map((b) => b.id + ':' + b.level + ':' + b.status).join(','),
            Math.floor(Number((this.state.city || {}).money) || 0),
            this.filter,
            this.assignTarget === null ? '-' : this.assignTarget,
            this.pickerFor === null ? '-' : this.pickerFor,
            this.confirm ? this.confirm.type + (this.confirm.id || '') : '-',
            this.busy ? 1 : 0,
            this.poolOpen ? 1 : 0,
            this.pool().length,
            this.defsLoading ? 1 : 0,
        ].join('|');
    }

    // ---- 渲染 ----

    render() {
        if (!this.rootEl || !this.opened) return;
        this.lastSignature = this.signature();
        this.valueRefs = [];
        this.rootEl.innerHTML = '';

        const s = this.npcState();

        const header = document.createElement('div');
        header.className = 'npc-header';

        const title = document.createElement('span');
        title.className = 'npc-title';
        title.textContent = 'NPC';
        header.appendChild(title);

        const counts = document.createElement('span');
        counts.className = 'npc-counts';
        counts.textContent = '在编 ' + s.total + ' · 在岗 ' + s.assigned + ' · 闲置 ' + s.idle;
        header.appendChild(counts);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'npc-close';
        closeBtn.title = '关闭';
        closeBtn.setAttribute('aria-label', '关闭');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => this.close());
        header.appendChild(closeBtn);

        this.rootEl.appendChild(header);

        // 全城常态开销:工资与口粮对 idle 的 NPC 照收(这正是「辞退」存在的意义)
        const upkeep = document.createElement('div');
        upkeep.className = 'npc-upkeep';
        upkeep.textContent = '全城开销:工资 ' + fmtDec(s.wagePerMin) + '/分 · 口粮 ' + fmtDec(s.foodPerMin) + '/分';
        this.rootEl.appendChild(upkeep);

        if (this.assignTarget !== null) this.rootEl.appendChild(this.makeAssignBanner());

        this.rootEl.appendChild(this.makeRecruitBox());

        const filters = document.createElement('div');
        filters.className = 'npc-filters';
        FILTERS.forEach((f) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'npc-chip' + (this.filter === f.key ? ' active' : '');
            chip.textContent = f.label;
            chip.addEventListener('click', () => {
                this.filter = f.key;
                this.render();
            });
            filters.appendChild(chip);
        });
        this.rootEl.appendChild(filters);

        this.listEl = document.createElement('div');
        this.listEl.className = 'npc-list';
        this.rootEl.appendChild(this.listEl);

        this.renderList();
    }

    // 派驻模式横幅:从建筑详情进来时显示,提示当前正在给哪栋楼选人
    makeAssignBanner() {
        const box = document.createElement('div');
        box.className = 'npc-assign-banner';

        const text = document.createElement('span');
        const b = this.buildingById(this.assignTarget);
        const used = assignedIds(this.npcState().assignments, this.assignTarget).length;
        const cap = b ? this.slotsOf(b) : 0;
        text.textContent = '正在为「' + this.buildingName(this.assignTarget) + '」选人(' + used + ' / ' + cap + ' 槽)';
        box.appendChild(text);

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'npc-link-btn';
        cancel.textContent = '取消';
        cancel.addEventListener('click', () => {
            this.assignTarget = null;
            this.render();
        });
        box.appendChild(cancel);

        return box;
    }

    // 招募区:盲抽。抽到谁、按什么价收钱由服务器掷点决定(CLAUDE §30),
    // 所以这里既不显示候选名单,也不预告价格 —— 玩家侧没有 NPC 定义端点(契约缺口见汇报)
    makeRecruitBox() {
        const box = document.createElement('div');
        box.className = 'npc-recruit';

        const head = document.createElement('div');
        head.className = 'npc-recruit-head';

        const label = document.createElement('span');
        label.className = 'npc-recruit-label';
        label.textContent = '招募人才';
        head.appendChild(label);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'npc-btn npc-btn-primary';
        btn.textContent = this.busy ? '处理中...' : '招募';
        btn.disabled = this.busy;
        btn.addEventListener('click', () => {
            this.confirm = { type: 'recruit' };
            this.render();
        });
        head.appendChild(btn);

        box.appendChild(head);

        const hint = document.createElement('div');
        hint.className = 'npc-hint';
        hint.textContent = '盲抽:抽到谁由服务器掷点决定,价格按稀有度与工资浮动,资金不足会被拒绝。';
        box.appendChild(hint);

        box.appendChild(this.makePoolPreview());

        if (this.confirm && this.confirm.type === 'recruit') {
            box.appendChild(this.makeConfirm(
                '确定花钱招募一名 NPC?抽到的稀有度与实际价格以服务器为准,招进来后每分钟要付工资与口粮。',
                '确定招募',
                () => this.doRecruit()
            ));
        }

        return box;
    }

    // 招募池预览(W7 契约补齐:GET /api/definitions/npcs)。
    //
    // 只是**参考资料**,不是选人界面 —— 招募入参里根本没有 npc_id,抽到谁由服务器掷点(§30 / §66)。
    // 所以这里不给任何「选中 / 招这个」的交互,只回答「这一版数值里都有些什么人」。
    // 默认收起:150 行放在主操作上面会把招募按钮挤下去
    makePoolPreview() {
        const box = document.createElement('div');
        box.className = 'npc-pool';

        const head = document.createElement('button');
        head.type = 'button';
        head.className = 'npc-pool-head';
        head.setAttribute('aria-expanded', this.poolOpen ? 'true' : 'false');

        const label = document.createElement('span');
        label.textContent = '招募池预览';
        head.appendChild(label);

        const arrow = document.createElement('span');
        arrow.className = 'npc-pool-arrow';
        arrow.textContent = this.poolOpen ? '收起 ▲' : '展开 ▼';
        head.appendChild(arrow);

        head.addEventListener('click', () => {
            this.poolOpen = !this.poolOpen;
            this.render();
        });
        box.appendChild(head);

        if (!this.poolOpen) return box;

        if (this.defsLoading) {
            const loading = document.createElement('div');
            loading.className = 'npc-hint';
            loading.textContent = 'NPC 定义加载中...';
            box.appendChild(loading);
            return box;
        }

        const list = this.pool();
        if (!list.length) {
            const empty = document.createElement('div');
            empty.className = 'npc-hint';
            empty.textContent = '招募池加载失败,关掉面板重开可重试。';
            box.appendChild(empty);
            return box;
        }

        // 按时代排序:当前时代之内的排前面,超时代的原型现在抽不到(服务端按 min_era 过滤候选池)
        const eraOrder = Number(((this.state.city || {}).era || {}).era_order) || 1;
        const rows = list.slice().sort((a, b) => Number(a.min_era_order) - Number(b.min_era_order));

        const scroll = document.createElement('div');
        scroll.className = 'npc-pool-list';

        rows.forEach((n) => {
            const locked = Number(n.min_era_order) > eraOrder;

            const row = document.createElement('div');
            row.className = 'npc-pool-row' + (locked ? ' is-locked' : '');

            const rowHead = document.createElement('div');
            rowHead.className = 'npc-pool-row-head';

            const name = document.createElement('span');
            name.className = 'npc-pool-name';
            // name_zh 为 null 时回落 npc_id:服务端刻意不编占位名(拟名待批),前端也不编
            name.textContent = n.name_zh || n.npc_id;
            rowHead.appendChild(name);

            const rarity = document.createElement('span');
            rarity.className = 'npc-rarity is-' + (n.rarity || 'common');
            rarity.textContent = npcRarityName(n.rarity);
            rowHead.appendChild(rarity);

            row.appendChild(rowHead);

            const meta = document.createElement('div');
            meta.className = 'npc-pool-meta';
            meta.textContent = '时代 ' + n.min_era + (locked ? '(未到)' : '')
                + ' · ' + npcSkillName(n.primary_skill_id)
                + ' · 工资 ' + fmtDec(n.wage_per_min) + '/分 · 口粮 ' + fmtDec(n.food_per_min) + '/分';
            row.appendChild(meta);

            if (n.trait_desc_zh) {
                const trait = document.createElement('div');
                trait.className = 'npc-pool-trait';
                trait.textContent = n.trait_desc_zh;
                row.appendChild(trait);
            }

            scroll.appendChild(row);
        });

        box.appendChild(scroll);

        const note = document.createElement('div');
        note.className = 'npc-hint';
        note.textContent = '共 ' + rows.length + ' 个原型;能不能抽到还要看时代与招募来源,实际由服务器掷点决定。';
        box.appendChild(note);

        return box;
    }

    // 二次确认条:招募要花钱、辞退是删除语义,都必须再点一次才提交
    makeConfirm(text, okLabel, onOk) {
        const box = document.createElement('div');
        box.className = 'npc-confirm';

        const msg = document.createElement('div');
        msg.className = 'npc-confirm-text';
        msg.textContent = text;
        box.appendChild(msg);

        const row = document.createElement('div');
        row.className = 'npc-confirm-actions';

        const yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'npc-btn npc-btn-danger';
        yes.textContent = this.busy ? '处理中...' : okLabel;
        yes.disabled = this.busy;
        yes.addEventListener('click', onOk);
        row.appendChild(yes);

        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'npc-btn npc-btn-ghost';
        no.textContent = '取消';
        no.disabled = this.busy;
        no.addEventListener('click', () => {
            this.confirm = null;
            this.render();
        });
        row.appendChild(no);

        box.appendChild(row);
        return box;
    }

    renderList() {
        const s = this.npcState();
        const rows = s.list.filter((n) => {
            if (this.filter === 'assigned') return n.assigned_instance_id !== null;
            if (this.filter === 'idle') return n.assigned_instance_id === null;
            return true;
        });

        if (!rows.length) {
            const empty = document.createElement('div');
            empty.className = 'npc-empty';
            if (s.total === 0) {
                empty.textContent = '还没有 NPC。点上面的「招募」抽一个,或等人口自然增长带来新人。';
            } else {
                empty.textContent = this.filter === 'assigned' ? '还没有派驻中的 NPC' : '没有闲置的 NPC';
            }
            this.listEl.appendChild(empty);
            return;
        }

        rows.forEach((n) => this.listEl.appendChild(this.makeItem(n)));
    }

    makeItem(npc) {
        const assigned = npc.assigned_instance_id !== null;

        const item = document.createElement('div');
        item.className = 'npc-item' + (assigned ? ' is-assigned' : ' is-idle');

        const head = document.createElement('div');
        head.className = 'npc-item-head';

        const name = document.createElement('span');
        name.className = 'npc-item-name';
        // name_zh 为 null 时回落 name_key:服务端刻意不编占位名(拟名待批),前端也不编
        name.textContent = npc.name_zh || npc.name_key || npc.npc_id;
        head.appendChild(name);

        const rarity = document.createElement('span');
        rarity.className = 'npc-rarity is-' + (npc.rarity || 'common');
        rarity.textContent = npcRarityName(npc.rarity);
        head.appendChild(rarity);

        item.appendChild(head);

        // 主技能 / 等级 / XP。W7 起等级曲线由 /api/definitions/npcs 下发,
        // 所以能多给一句「距下级还需」;曲线没到(或已满级)就照旧只给绝对值,不编分母
        const meta = document.createElement('div');
        meta.className = 'npc-item-meta';
        meta.textContent = this.metaText(npc);
        item.appendChild(meta);

        const moraleRow = document.createElement('div');
        moraleRow.className = 'npc-item-meta';

        const moraleLabel = document.createElement('span');
        moraleLabel.textContent = '士气 ';
        moraleRow.appendChild(moraleLabel);

        const alert = this.npcState().moraleAlert;

        const moraleValue = document.createElement('span');
        moraleValue.className = 'npc-morale' + (Number(npc.morale) < alert ? ' is-low' : '');
        moraleValue.textContent = fmtDec(npc.morale, 1);
        moraleRow.appendChild(moraleValue);

        const moraleNote = document.createElement('span');
        moraleNote.className = 'npc-morale-note';
        moraleNote.textContent = Number(npc.morale) < alert ? ' 有离职风险(低于 ' + fmtDec(alert, 0) + ')' : '';
        moraleRow.appendChild(moraleNote);

        item.appendChild(moraleRow);

        const upkeep = document.createElement('div');
        upkeep.className = 'npc-item-meta';
        upkeep.textContent = '工资 ' + fmtDec(npc.wage_per_min) + '/分 · 口粮 ' + fmtDec(npc.food_per_min) + '/分';
        item.appendChild(upkeep);

        const status = document.createElement('div');
        status.className = 'npc-item-status' + (assigned ? ' is-on-duty' : '');
        status.textContent = assigned
            ? npcStatusName(npc.status) + ' · ' + this.buildingName(npc.assigned_instance_id)
            : npcStatusName(npc.status) + '(在编,照发工资口粮)';
        item.appendChild(status);

        // 指纹没变时靠这两个引用原地刷新,不重建 DOM
        if (this.valueRefs) {
            this.valueRefs.push({ id: npc.id, meta: meta, morale: moraleValue, note: moraleNote });
        }

        item.appendChild(this.makeActions(npc, assigned));

        if (this.pickerFor !== null && String(this.pickerFor) === String(npc.id)) {
            item.appendChild(this.makePicker(npc));
        }

        if (this.confirm && this.confirm.type === 'dismiss' && String(this.confirm.id) === String(npc.id)) {
            item.appendChild(this.makeConfirm(
                '确定辞退「' + (npc.name_zh || npc.name_key) + '」?辞退后不可恢复,释放工资 '
                    + fmtDec(npc.wage_per_min) + '/分、口粮 ' + fmtDec(npc.food_per_min) + '/分。',
                '确定辞退',
                () => this.doDismiss(npc)
            ));
        }

        return item;
    }

    makeActions(npc, assigned) {
        const actions = document.createElement('div');
        actions.className = 'npc-item-actions';

        // 派驻模式(从建筑详情进来):闲置的人直接给一个「派驻到此」,不用再选一次楼
        if (this.assignTarget !== null && !assigned) {
            const quick = document.createElement('button');
            quick.type = 'button';
            quick.className = 'npc-btn npc-btn-primary';
            quick.textContent = '派驻到此';
            quick.disabled = this.busy;
            quick.addEventListener('click', () => this.doAssign(npc, this.assignTarget));
            actions.appendChild(quick);
        } else if (assigned) {
            const off = document.createElement('button');
            off.type = 'button';
            off.className = 'npc-btn';
            off.textContent = '撤下';
            off.disabled = this.busy;
            off.addEventListener('click', () => this.doUnassign(npc));
            actions.appendChild(off);
        } else {
            const on = document.createElement('button');
            on.type = 'button';
            on.className = 'npc-btn npc-btn-primary';
            on.textContent = this.pickerFor !== null && String(this.pickerFor) === String(npc.id) ? '收起' : '派驻';
            on.disabled = this.busy;
            on.addEventListener('click', () => {
                this.pickerFor = this.pickerFor !== null && String(this.pickerFor) === String(npc.id) ? null : npc.id;
                this.render();
            });
            actions.appendChild(on);
        }

        const fire = document.createElement('button');
        fire.type = 'button';
        fire.className = 'npc-btn npc-btn-outline-danger';
        fire.textContent = '辞退';
        fire.disabled = this.busy;
        fire.addEventListener('click', () => {
            this.confirm = { type: 'dismiss', id: npc.id };
            this.pickerFor = null;
            this.render();
        });
        actions.appendChild(fire);

        return actions;
    }

    // 选建筑:只列已建成(active)的实例,槽位满的置灰。
    // 槽位规则来自服务器下发的 slots_per_building / _l3,不在前端写死
    makePicker(npc) {
        const box = document.createElement('div');
        box.className = 'npc-picker';

        const s = this.npcState();
        const candidates = this.buildings().filter((b) => b.status === 'active' && isSynced(b.id));

        if (!candidates.length) {
            const empty = document.createElement('div');
            empty.className = 'npc-hint';
            empty.textContent = '没有可派驻的建筑(要先有已建成的建筑)。';
            box.appendChild(empty);
            return box;
        }

        candidates.forEach((b) => {
            const used = assignedIds(s.assignments, b.id).length;
            const cap = this.slotsOf(b);
            const full = used >= cap;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'npc-picker-item' + (full ? ' is-full' : '');
            btn.disabled = this.busy || full;

            const label = document.createElement('span');
            label.textContent = this.buildingName(b.id) + ' (' + b.x + ',' + b.y + ')';
            btn.appendChild(label);

            const slot = document.createElement('span');
            slot.className = 'npc-picker-slot';
            slot.textContent = used + ' / ' + cap + (full ? ' 已满' : '');
            btn.appendChild(slot);

            btn.addEventListener('click', () => this.doAssign(npc, b.id));
            box.appendChild(btn);
        });

        return box;
    }

    // 指纹未变时的轻量同步:只改会随时间漂移的士气 / XP 文本,不动 DOM 结构
    syncValues() {
        const refs = this.valueRefs;
        if (!refs || !refs.length) return;

        const byId = {};
        this.npcState().list.forEach((n) => { byId[n.id] = n; });

        const alert = this.npcState().moraleAlert;

        refs.forEach((ref) => {
            const n = byId[ref.id];
            if (!n) return;
            const meta = this.metaText(n);
            if (ref.meta.textContent !== meta) ref.meta.textContent = meta;

            const low = Number(n.morale) < alert;
            ref.morale.textContent = fmtDec(n.morale, 1);
            ref.morale.className = 'npc-morale' + (low ? ' is-low' : '');
            ref.note.textContent = low ? ' 有离职风险(低于 ' + fmtDec(alert, 0) + ')' : '';
        });
    }

    // 一行 NPC 的技能 / 等级 / XP 文案(渲染与原地刷新共用一份,免得两处写法漂移)
    metaText(npc) {
        const base = npcSkillName(npc.primary_skill_id) + ' · Lv' + npc.skill_level
            + ' · 技能值 ' + fmt(npc.skill_value) + ' · XP ' + fmt(npc.xp);
        const need = this.xpToNext(npc);
        return need === null ? base : base + ' · 距下级还需 ' + fmt(need);
    }

    // ---- 请求 ----

    // NPC 定义:150 原型 + 技能表 + 等级曲线,一次拉够。
    // 失败静默降级(招募池预览与「距下级还需」两处不显示),不弹错误 —— 它不影响任何操作
    async loadDefs() {
        if (this.pool().length || this.defsLoading) return;

        this.defsLoading = true;
        if (this.opened) this.render();

        try {
            const data = await this.api.get('/api/definitions/npcs');
            setState({ npcDefs: data || null });
        } catch (e) {
            // 定义拉不到不影响面板主体功能(运行时数据全在城市快照里)
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

    // 四个动作的响应形状一致:{ revision, resources, money, delta[, npc] }。
    // 资源与资金一律用服务器返回值覆盖,不做本地推算;NPC 清单本身不在响应里,
    // 所以动作成功后再拉一次权威快照(招募新增的人、槽位占用都要从快照来)
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

    // 统一的失败处理:提示 + 必要时拉一次权威快照(与建筑 / 科技面板同一条 409 恢复流程)
    async handleError(err, fallback, overrides) {
        notifyError(errorText(err, fallback, overrides));
        const code = err && err.error;
        if (code === 'REVISION_CONFLICT' || code === 'NOT_FOUND' || code === 'NPC_ALREADY_ASSIGNED'
            || code === 'NPC_SLOT_FULL' || code === 'NPC_NOT_AVAILABLE') {
            await this.refreshCity();
        }
    }

    async doRecruit() {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/npc/recruit', {
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);
            const npc = diff.npc || null;
            const cost = diff.delta && diff.delta.money ? Math.abs(diff.delta.money) : 0;
            notifySuccess(npc
                ? '招募成功:' + (npc.name_zh || npc.name_key) + '(' + npcRarityName(npc.rarity) + ')· 花费 ' + fmtDec(cost)
                : '招募成功');
            this.confirm = null;
            await this.refreshCity();
        } catch (err) {
            await this.handleError(err, '招募失败,请重试', RECRUIT_ERRORS);
        } finally {
            this.busy = false;
            this.render();
        }
    }

    async doAssign(npc, buildingInstanceId) {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/npc/assign', {
                city_npc_id: Number(npc.id),
                building_instance_id: Number(buildingInstanceId),
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);
            notifySuccess('已派驻 ' + (npc.name_zh || npc.name_key) + ' 到 ' + this.buildingName(buildingInstanceId));
            this.pickerFor = null;
            // 派驻模式退出时把筛选切回「全部」:进模式时为了选人筛的是「闲置」,
            // 人派出去之后那一档必然是空的,停在空列表上会让玩家以为人不见了
            if (this.assignTarget !== null && this.filter === 'idle') this.filter = 'all';
            this.assignTarget = null;
            await this.refreshCity();
        } catch (err) {
            await this.handleError(err, '派驻失败,请重试', ASSIGN_ERRORS);
        } finally {
            this.busy = false;
            this.render();
        }
    }

    async doUnassign(npc) {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/npc/unassign', {
                city_npc_id: Number(npc.id),
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);
            notifySuccess('已撤下 ' + (npc.name_zh || npc.name_key));
            await this.refreshCity();
        } catch (err) {
            await this.handleError(err, '撤下失败,请重试');
        } finally {
            this.busy = false;
            this.render();
        }
    }

    async doDismiss(npc) {
        if (this.busy) return;
        this.busy = true;
        this.render();

        try {
            const diff = await this.api.post('/api/city/npc/dismiss', {
                city_npc_id: Number(npc.id),
                idempotency_key: newIdempotencyKey(),
                expected_revision: this.state.city ? this.state.city.revision : undefined,
            });
            this.applyDiff(diff);
            notifySuccess('已辞退 ' + (npc.name_zh || npc.name_key)
                + ',释放工资 ' + fmtDec(npc.wage_per_min) + '/分、口粮 ' + fmtDec(npc.food_per_min) + '/分');
            this.confirm = null;
            await this.refreshCity();
        } catch (err) {
            await this.handleError(err, '辞退失败,请重试', DISMISS_ERRORS);
        } finally {
            this.busy = false;
            this.render();
        }
    }
}
