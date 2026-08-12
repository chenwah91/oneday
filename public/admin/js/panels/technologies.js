// 科技定义(v3.2 §4 的 50 行):只改知识成本与研究时长。
// 前置 / 解锁 / 时代 / 分支四列只读 —— 那是科技树拓扑,改了会造出环或死锁(见后端 TECH_EDITABLE 注释)。

import { createDefinitionPanel } from '../ui/definition-table.js';
import { escapeHtml } from '../core/dom.js';

// JSON 数组列的紧凑显示:["TECH_I_SUST"] → TECH_I_SUST
function jsonList(value) {
    if (!value) return '<span class="muted">-</span>';
    let list = value;
    if (typeof value === 'string') {
        try { list = JSON.parse(value); } catch (e) { return escapeHtml(value); }
    }
    if (!Array.isArray(list) || !list.length) return '<span class="muted">-</span>';
    return escapeHtml(list.join(' / '));
}

export const technologiesPanel = createDefinitionPanel({
    id: 'tech',
    label: '科技',
    title: '科技定义(50 行)',
    hint: '只开放<b>知识成本</b>与<b>研究时长</b>两列。前置 / 解锁 / 时代 / 分支是科技树拓扑,改动会造出环或死锁,一律走 Seed + 迁移。',
    listUrl: '/api/admin/definitions/technologies',
    listKey: 'technologies',
    editUrl: '/api/admin/definitions/technology',
    idFields: [{ row: 'tech_id', param: 'tech_id', label: 'tech_id' }],
    readonlyColumns: [
        { key: 'name', label: '名称' },
        { key: 'era_key', label: '时代' },
        { key: 'branch', label: '分支' },
        { key: 'prerequisite_tech_ids', label: '前置', format: (r) => jsonList(r.prerequisite_tech_ids), wrap: true },
        { key: 'unlock_building_ids', label: '解锁建筑', format: (r) => jsonList(r.unlock_building_ids), wrap: true },
    ],
    labels: {
        knowledge_cost: '知识成本',
        research_minutes: '研究时长(分)',
    },
    fieldMeta: {
        knowledge_cost: { integer: true, min: 0, max: 10000000 },
        research_minutes: { min: 0, max: 10080 },
    },
    search: { placeholder: '按 tech_id / 名称 / 分支筛选', fields: ['tech_id', 'name', 'branch', 'era_key'] },
});
