// 随机事件定义(v3.2 §9.2 的 30 行)。
//
// 用户 2026-08-10 拍板③:「所有事件必须在管理员后台可设定(权重/效果/开关)」。
// 五个可编辑项:开关 / 权重 / 冷却 / 持续 / 效果强度倍率。
//
// 本面板两处与其它定义面板不同:
//   ① **停用必须填停用原因**(后端 W11-B 起强制):disabled_reason 会随列表长期显示给下一个人看,
//      它回答的是「这条事件为什么是灰的」——「依赖还没落地」与「谁手滑关的」处理方式完全相反;
//   ② 每行可**触发到指定城市**(POST /events/trigger):走与自然触发同一条落地路径,
//      只跳过权重掷点与冷却,并发上限照常尊重。会真实改变该玩家的资源,所以 reason 至少 5 字。
//
// 全局参数(触发概率 / 并发上限 / 离线补算上限 / 权重三修正系数)在「规则参数」面板。

import { createDefinitionPanel } from '../ui/definition-table.js';
import { api, errorMessage } from '../core/api.js';
import { escapeHtml, toast } from '../core/dom.js';

const EVENT_TYPE_LABELS = { positive: '正向', negative: '负向' };

export const eventsPanel = createDefinitionPanel({
    id: 'event',
    label: '事件',
    title: '随机事件定义(30 行)',
    hint: '<b>停用</b>时必须在同行的「停用原因」里写明理由(启用时会自动清空)。<b>效果落地</b>列里 mapped=0 表示这条事件开了也不会有任何后果。右上角填 city_id 后,行尾「触发到城市」可强制触发一次(reason 至少 5 字,会真实改变该玩家的资源)。',
    listUrl: '/api/admin/definitions/events',
    listKey: 'events',
    editUrl: '/api/admin/definitions/event',
    idFields: [{ row: 'event_id', param: 'event_id', label: 'event_id' }],
    readonlyColumns: [
        { key: 'name_zh', label: '名称' },
        { key: 'category', label: '类别' },
        { key: 'event_type', label: '类型', format: (r) => escapeHtml(EVENT_TYPE_LABELS[r.event_type] || r.event_type) },
        { key: 'min_era', label: '时代' },
        { key: 'condition_desc_zh', label: '触发条件', wrap: true },
    ],
    labels: {
        enabled: '开关',
        base_weight: '权重',
        cooldown_minutes: '冷却(分)',
        duration_minutes: '持续(分)',
        effect_multiplier: '效果强度',
    },
    fieldMeta: {
        enabled: {
            numeric: true,
            options: [
                { value: 1, label: '已启用' },
                { value: 0, label: '已停用' },
            ],
        },
        base_weight: { min: 0, max: 10000 },
        cooldown_minutes: { integer: true, min: 0, max: 10080 },
        duration_minutes: { integer: true, min: 0, max: 10080 },
        effect_multiplier: { min: 0, max: 10 },
    },
    extraColumns: [
        {
            label: '效果落地',
            render: (row) => {
                const mapped = Number(row.mapped_effect_count || 0);
                const unmapped = Number(row.unmapped_effect_count || 0);
                const cls = mapped === 0 ? 'cell-warn' : '';
                return `<span class="${cls}">生效 ${mapped} / 未映射 ${unmapped}</span>`;
            },
        },
        {
            label: '停用原因',
            render: (row) => `<input type="text" class="event-disabled-reason" maxlength="255"
                                     placeholder="停用时必填"
                                     value="${escapeHtml(row.disabled_reason || '')}">`,
        },
    ],
    rowClass: (row) => (Number(row.enabled) === 1 ? '' : 'row-disabled'),
    search: { placeholder: '按 event_id / 名称 / 类别筛选', fields: ['event_id', 'name_zh', 'category', 'event_type'] },

    // 停用时把同行的停用原因一起提交(后端同事务同审计写入,不会出现「已停用但原因还没落库」的中间态)
    extraPayload(field, value, { ctx, tr }) {
        if (field !== 'enabled') return {};
        const input = tr.querySelector('.event-disabled-reason');
        const text = input ? input.value.trim() : '';
        if (Number(value) === 0 && text === '') {
            ctx.setError('停用事件必须填写停用原因(后台列表要显示它)');
            if (input) input.focus();
            return null;
        }
        return { disabled_reason: text };
    },

    afterSave({ tr, row }) {
        // 开关改了就同步整行的灰显;启用时后端会把停用原因清成 NULL,前端跟着清
        const enabled = Number(row.enabled) === 1;
        tr.classList.toggle('row-disabled', !enabled);
        if (enabled) {
            const input = tr.querySelector('.event-disabled-reason');
            if (input) input.value = '';
        }
    },

    toolbar(node, ctx) {
        node.innerHTML = `
            <label class="inline-field">
                <span class="muted">触发目标 city_id</span>
                <input type="number" min="1" class="event-city-id" placeholder="如 12">
            </label>
        `;
        ctx.state.cityInput = node.querySelector('.event-city-id');
    },

    rowActions: [
        {
            key: 'trigger',
            label: '触发到城市',
            async run({ ctx, row, button }) {
                const cityId = ctx.state.cityInput ? ctx.state.cityInput.value.trim() : '';
                if (!cityId) {
                    ctx.setError('请先在右上角填写要触发到的 city_id');
                    return;
                }
                const reason = ctx.reason();
                if (reason === null) return;
                if (reason.length < 5) {
                    ctx.setError('手动触发会真实改变该玩家的资源,修改原因至少 5 字');
                    return;
                }

                button.disabled = true;
                try {
                    const data = await api.post('/api/admin/events/trigger', {
                        city_id: Number(cityId),
                        event_id: row.event_id,
                        reason,
                    });
                    const message = `已触发 ${data.event_id}(${data.name_zh})到城市 ${data.city_id},实例 id ${data.event_instance_id};该城生效事件 ${data.active_count}/${data.max_active}`;
                    ctx.setResult(message);
                    toast(message, 'ok');
                } catch (err) {
                    // EVENT_LIMIT_REACHED 有三种原因(同一条已在生效 / 并发上限 / 灾害并发上限),
                    // 后端在 details 里分得很清楚 —— 不摊开的话运营只会看到一句「已达上限」然后反复重试
                    const d = err && err.body ? err.body.details : null;
                    let extra = '';
                    if (d && d.limit === 'already_active') extra = `(${d.event_id} 已在该城生效中,等它结束再触发)`;
                    else if (d && d.limit === 'max_active') extra = `(该城生效事件 ${d.current}/${d.max},已满)`;
                    else if (d && d.limit === 'max_active_disaster') extra = `(该城灾害类事件 ${d.current}/${d.max},已满)`;
                    ctx.setError(errorMessage(err) + extra);
                } finally {
                    button.disabled = false;
                }
            },
        },
    ],
});
