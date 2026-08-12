// 工具 / 道具定义(v3.2 §7 的 24 行):耐久 / 效果值 / 拆解基数。
// 结构列(category / durability_tier / effect_code / effect_json / craft_cost_json)只读。
// 全局规则(装备槽位数 / 每档耐久分钟数 / 预警阈值 / 制作与耐久开关)在「规则参数」面板。

import { createDefinitionPanel } from '../ui/definition-table.js';
import { escapeHtml } from '../core/dom.js';

export const itemsPanel = createDefinitionPanel({
    id: 'item',
    label: '工具',
    title: '工具定义(24 行)',
    hint: '改一行 effect_value 就是改全服产量上限。<b>effect_value 会同步重写 effect_json 的 specs</b>(保留原有符号,减免类仍是减免);specs 为空的那几件表示效果尚未结构化,装上去没有任何作用。耐久必须是 ≥1 的整数。',
    listUrl: '/api/admin/definitions/items',
    listKey: 'items',
    editUrl: '/api/admin/definitions/item',
    idFields: [{ row: 'item_id', param: 'item_id', label: 'item_id' }],
    readonlyColumns: [
        { key: 'name_key', label: '名称 key' },
        { key: 'category', label: '类别' },
        { key: 'min_era', label: '时代' },
        { key: 'equip_target_desc_zh', label: '装备对象', wrap: true },
        { key: 'durability_tier', label: '耐久档' },
        { key: 'effect_code', label: '效果码' },
        { key: 'unit', label: '单位' },
        {
            key: 'effect_json',
            label: 'specs(只读)',
            wrap: true,
            format: (r) => {
                const raw = r.effect_json;
                if (!raw) return '<span class="muted">-</span>';
                let parsed = raw;
                if (typeof raw === 'string') {
                    try { parsed = JSON.parse(raw); } catch (e) { return escapeHtml(raw); }
                }
                const specs = (parsed && parsed.specs) || [];
                if (!specs.length) return '<span class="cell-warn">specs 为空(装上没作用)</span>';
                return escapeHtml(specs.map((s) => `${s.target}=${s.value}`).join(' / '));
            },
        },
    ],
    labels: {
        durability: '耐久',
        effect_value: '效果值',
        trade_value: '拆解基数',
    },
    fieldMeta: {
        durability: { integer: true, min: 1, max: 1000000 },
        effect_value: { min: 0, max: 1000 },
        trade_value: { min: 0, max: 1000000 },
    },
    search: { placeholder: '按 item_id / 名称 / 类别筛选', fields: ['item_id', 'name_key', 'category', 'effect_code'] },
});
