// NPC 等级曲线(v3.2 §6.2 的 10 行):升级经验 / 主技能加成 / 维护费减免上限。
// 主键 level 只读 —— 改它会让 city_npcs.skill_level 指向的那一级查不到(静默失去全部加成)。

import { createDefinitionPanel } from '../ui/definition-table.js';

export const npcCurvePanel = createDefinitionPanel({
    id: 'npc-curve',
    label: 'NPC 曲线',
    title: 'NPC 等级曲线(10 级)',
    hint: '逐级纵向比较才看得出斜率。<b>主技能加成</b>上限 0.9:再高会让单个 NPC 顶爆 §6.4 的单人帽 1.60,改了不会有任何变化。<b>维护费减免上限</b>封顶 0.9(1.0 = 维护费归零)。',
    listUrl: '/api/admin/definitions/npc-skill-curve',
    listKey: 'curve',
    editUrl: '/api/admin/definitions/npc-skill-curve',
    idFields: [{ row: 'level', param: 'level', label: '等级', numeric: true }],
    readonlyColumns: [],
    labels: {
        xp_to_next: '升级所需经验',
        primary_bonus: '主技能加成',
        maintenance_reduction_cap: '维护费减免上限',
    },
    fieldMeta: {
        xp_to_next: { integer: true, min: 0, max: 10000000 },
        primary_bonus: { min: 0, max: 0.9 },
        maintenance_reduction_cap: { min: 0, max: 0.9 },
    },
});
