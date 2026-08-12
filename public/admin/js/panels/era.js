// 时代升级门槛(v3.2 §5.1 的 9 行):七个数值门槛。
// buildings_json 只读 —— 必须建筑清单是升级路径的拓扑,填一栋目标时代的建筑就是死锁。
//
// ⚠️ defense 一列同时是**国防威胁需求**的来源,后端在响应里回 warning,保存后会显示在结果条里。

import { createDefinitionPanel } from '../ui/definition-table.js';
import { escapeHtml } from '../core/dom.js';

export const eraPanel = createDefinitionPanel({
    id: 'era',
    label: '时代门槛',
    title: '时代升级门槛(I→II … IX→X)',
    hint: '主键 = <b>目标时代</b>(2 表示 I→II 那一档)。七列都必须是整数。⚠️ 改 <b>defense</b> 会连带改变全服国防威胁需求(保存后看结果条的 warning)。必须建筑清单只读。',
    listUrl: '/api/admin/definitions/era-requirements',
    listKey: 'requirements',
    editUrl: '/api/admin/definitions/era-requirements',
    idFields: [{ row: 'era_order', param: 'era_order', label: '目标时代', numeric: true }],
    readonlyColumns: [
        {
            key: 'buildings_json',
            label: '必须建筑',
            wrap: true,
            format: (r) => {
                if (!r.buildings_json) return '<span class="muted">-</span>';
                let list = r.buildings_json;
                if (typeof list === 'string') {
                    try { list = JSON.parse(list); } catch (e) { return escapeHtml(String(r.buildings_json)); }
                }
                if (!Array.isArray(list) || !list.length) return '<span class="muted">-</span>';
                return escapeHtml(list.join(' / '));
            },
        },
    ],
    labels: {
        population: '人口',
        knowledge: '知识',
        food: '粮食',
        money: '资金',
        governance: '治理',
        happiness: '幸福度',
        defense: '国防 ⚠',
    },
    fieldMeta: {
        population: { integer: true, min: 0, max: 2000000 },
        knowledge: { integer: true, min: 0, max: 1000000 },
        food: { integer: true, min: 0, max: 15000000 },
        money: { integer: true, min: 0, max: 8000000 },
        governance: { integer: true, min: 0, max: 1200000 },
        happiness: { integer: true, min: 0, max: 100 },
        defense: { integer: true, min: 0, max: 80000 },
    },
});
