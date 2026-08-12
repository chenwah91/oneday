// 建筑定义(v3.2 §3 的 94 行):只改同类建造上限 max_count。
// footprint 只读 —— 改占地会让存量建筑瞬间互相重叠(见后端 BUILDING_DEF_EDITABLE 注释)。

import { createDefinitionPanel } from '../ui/definition-table.js';
import { escapeHtml } from '../core/dom.js';

export const buildingsPanel = createDefinitionPanel({
    id: 'building-limit',
    label: '建筑上限',
    title: '建筑定义 / 建造上限(94 行)',
    hint: '只开放<b>同类建造上限</b>。上限必须是 1~10000 的整数:填 0 会让<b>已建成</b>的实例当场变成非法,玩家一拆就再也建不回来。占地(footprint)只读。',
    listUrl: '/api/admin/definitions/buildings',
    listKey: 'buildings',
    editUrl: '/api/admin/definitions/building',
    idFields: [{ row: 'building_id', param: 'building_id', label: 'building_id' }],
    readonlyColumns: [
        { key: 'name', label: '名称' },
        { key: 'era_key', label: '时代' },
        { key: 'category', label: '类别' },
        { key: 'footprint', label: '占地', format: (r) => escapeHtml(`${r.footprint_w}×${r.footprint_h}`) },
    ],
    labels: { max_count: '同类建造上限' },
    fieldMeta: { max_count: { integer: true, min: 1, max: 10000 } },
    search: { placeholder: '按 building_id / 名称 / 类别筛选', fields: ['building_id', 'name', 'category', 'era_key'] },
});
