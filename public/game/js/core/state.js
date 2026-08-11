// 简单客户端状态 + 订阅
// resourceNames:资源 code → 中文显示名,由 modules/resources.js 启动时拉取
// technologyDefs:科技定义(50 节点),由 ui/technology-panel.js 首次打开面板时拉取一次
// market:市场价目快照(GET /api/market/prices 的整个响应:窗口信息 + 28 行价目),
//         由 ui/market-panel.js 打开面板时拉取、窗口到点时刷新;它是全服共享数据,不进城市快照
// NPC:运行时数据随城市快照下发(city.npcs:清单 / 派驻关系 / 工资口粮汇总 / 槽位规则),
//      所以**不另设 state 槽位** —— 再存一份就会与快照裂成两个口径
export const state = { user: null, city: null, definitions: null, resourceNames: null, technologyDefs: null, market: null };
const subs = [];
export function onChange(cb) { subs.push(cb); }
export function setState(patch) { Object.assign(state, patch); subs.forEach((cb) => cb(state)); }
