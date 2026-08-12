// 市场定义(v3.2 §8 的 26 行):基础价 / 价格区间 / 波动率 / 弹性 / 费率 / 流动性 / 交易模式。
//
// trade_mode 只在 spot ↔ non_tradeable 之间互切(单资源停市 / 复市);
// 现状是 capacity_contract(电力)的行,渲染成只读 —— 给一个必然 422 的下拉没有意义。
// 全市场级的开关与系数(停市 / 手续费倍率 / 滑点 / 成交量上限)在「规则参数」面板,不在这里。

import { createDefinitionPanel } from '../ui/definition-table.js';

export const marketPanel = createDefinitionPanel({
    id: 'market',
    label: '市场',
    title: '市场定义(26 行)',
    hint: '改一行基础价就是改全服价格。<b>交易模式</b>可在 spot(现货)与 non_tradeable(停市)之间互切,用于单资源止血;电力(capacity_contract)不可切换。改动后整行必须自洽:min_price 不得大于 max_price,现货的 base_price 必须大于 0。',
    listUrl: '/api/admin/definitions/market',
    listKey: 'market',
    editUrl: '/api/admin/definitions/market',
    idFields: [{ row: 'resource_id', param: 'resource_code', label: '资源 code' }],
    readonlyColumns: [
        { key: 'rs_code', label: 'RS' },
        { key: 'market_category', label: '类别' },
        { key: 'first_era', label: '首现时代' },
        { key: 'note', label: '备注', wrap: true },
    ],
    labels: {
        base_price: '基础价',
        min_price: '价格下限',
        max_price: '价格上限',
        volatility: '波动率',
        elasticity: '弹性',
        fee_rate: '费率',
        base_liquidity: '流动性',
        trade_mode: '交易模式',
    },
    fieldMeta: {
        base_price: { min: 0, max: 1000000 },
        min_price: { min: 0, max: 1000000 },
        max_price: { min: 0, max: 1000000 },
        volatility: { min: 0, max: 1 },
        elasticity: { min: 0, max: 10 },
        fee_rate: { min: 0, max: 0.9 },
        base_liquidity: { min: 0, max: 1000000000 },
        trade_mode: {
            options: [
                { value: 'spot', label: 'spot(现货可交易)' },
                { value: 'non_tradeable', label: 'non_tradeable(停市)' },
            ],
        },
    },
    search: { placeholder: '按资源 code / RS / 类别筛选', fields: ['resource_id', 'rs_code', 'market_category'] },
});
