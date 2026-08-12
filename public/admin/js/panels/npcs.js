// NPC 定义(v3.2 §6.3 的 150 行):工资 / 口粮 / 初始技能 / 等级 / 特性强度倍率。
//
// 改成与其它定义同款的**整表**(此前是「填 id → 选字段 → 填值」的单条表单:
// 150 行里横向比较不了「这一档工资是不是偏了」,而那正是调数值时唯一要回答的问题)。
// 150 行整表 + 右上角搜索框按 npc_id / 中文名 / 类别 / 稀有度定位。
//
// 结构列(稀有度 / 主技能 / 来源 / 特性文案)只读:改它们要走 Seed + 迁移。

import { createDefinitionPanel } from '../ui/definition-table.js';

export const npcsPanel = createDefinitionPanel({
    id: 'npc',
    label: 'NPC 定义',
    title: 'NPC 定义(150 行)',
    hint: '只开放数值列。<b>初始等级 / 上限等级</b>必须是 1~10 的整数(§6.2 曲线只有 10 级,填 0 或 3.5 会让该 NPC 静默失去全部加成)。<b>特性强度倍率</b>上限 10:再高会顶爆 §6.4 单人帽 1.60 与 §13 总帽 2.75,改了不会有任何变化。',
    listUrl: '/api/admin/definitions/npcs',
    listKey: 'npcs',
    editUrl: '/api/admin/definitions/npc',
    idFields: [{ row: 'npc_id', param: 'npc_id', label: 'npc_id' }],
    readonlyColumns: [
        { key: 'name_zh', label: '中文名' },
        { key: 'category', label: '类别' },
        { key: 'rarity', label: '稀有度' },
        { key: 'min_era', label: '时代' },
        { key: 'primary_skill_id', label: '主技能' },
        { key: 'recruit_source', label: '来源' },
        { key: 'trait_desc_zh', label: '特性', wrap: true },
    ],
    labels: {
        wage_per_min: '工资/分',
        food_per_min: '口粮/分',
        initial_skill_value: '初始技能值',
        initial_skill_level: '初始等级',
        max_level: '上限等级',
        trait_multiplier: '特性强度倍率',
    },
    fieldMeta: {
        wage_per_min: { min: 0 },
        food_per_min: { min: 0 },
        initial_skill_value: { min: 0 },
        initial_skill_level: { integer: true, min: 1, max: 10 },
        max_level: { integer: true, min: 1, max: 10 },
        trait_multiplier: { min: 0, max: 10 },
    },
    search: {
        placeholder: '按 npc_id / 中文名 / 类别 / 稀有度定位',
        fields: ['npc_id', 'name_zh', 'name_key', 'category', 'rarity', 'primary_skill_id'],
    },
});
