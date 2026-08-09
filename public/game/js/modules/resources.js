// 资源显示名:资源主键已改成英文 code(wood/stone/food…),中文名只在服务器的
// resource_definition.name 里。这里启动时拉一次 /api/definitions/resources 存进 state,
// 全前端统一用 resourceName(code) 取显示文本,不要在任何面板里再写死中文资源名。
import { api } from '../core/api.js';
import { state, setState } from '../core/state.js';

// 容量类产出的中文名:它们出现在建筑等级定义的 output 里,但不是库存资源,
// 服务器的 resource_definition 表里没有它们,所以在前端补一张小表。
// 与 app/Game/Resource/ResourceCode::CAPACITY 一一对应,改动时两边同步。
export const CAPACITY_NAMES = {
    population_capacity: '人口容量',
    storage_capacity: '仓储容量',
    governance_capacity: '治理容量',
    transport_capacity: '运输容量',
    defense_score: '国防值',
    trade_capacity: '贸易容量',
    finance_capacity: '金融容量',
    medical_capacity: '医疗容量',
};

// 容量类判定:数值是一次性容量而非每分钟速率,展示时不加 "/分"
export function isCapacity(code) {
    return Object.prototype.hasOwnProperty.call(CAPACITY_NAMES, code);
}

// 拉取资源定义并缓存进 state.resourceNames({ code: 中文名 });失败时退回空表,
// 显示会回落成 code 本身,不至于因为一个只读接口挂掉整个进游戏流程
export async function loadResourceNames() {
    if (state.resourceNames) return state.resourceNames;

    let names = {};
    try {
        const data = await api.get('/api/definitions/resources');
        (data.resources || []).forEach((r) => { names[r.code] = r.name; });
    } catch (e) {
        names = {};
    }

    setState({ resourceNames: Object.assign({}, CAPACITY_NAMES, names) });
    return state.resourceNames;
}

// code → 中文显示名;查不到时原样返回 code(宁可显示 code,也不要显示空白)
export function resourceName(code) {
    if (!code) return '';
    return (state.resourceNames && state.resourceNames[code]) || CAPACITY_NAMES[code] || code;
}
