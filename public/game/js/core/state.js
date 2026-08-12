// 简单客户端状态 + 订阅
// resourceNames:资源 code → 中文显示名,由 modules/resources.js 启动时拉取
// technologyDefs:科技定义(50 节点),由 ui/technology-panel.js 首次打开面板时拉取一次
// market:市场价目快照(GET /api/market/prices 的整个响应:窗口信息 + 28 行价目),
//         由 ui/market-panel.js 打开面板时拉取、窗口到点时刷新;它是全服共享数据,不进城市快照
// NPC:运行时数据随城市快照下发(city.npcs:清单 / 派驻关系 / 工资口粮汇总 / 槽位规则),
//      所以**不另设 state 槽位** —— 再存一份就会与快照裂成两个口径
// npcDefs:NPC 定义(GET /api/definitions/npcs 的整个响应:150 原型 + 12 技能 + 10 级曲线),
//      由 ui/npc-panel.js 打开面板时拉一次。运行时数据仍在快照里,两者分工不变 ——
//      这里只放「这一版数值里都有些什么人 / 升一级要多少 XP」这种全服静态定义
// itemDefs:工具定义(可制作清单 + 材料成本),由 ui/item-panel.js 打开面板时拉一次。
//      与 technologyDefs 同一先例(定义数据不进快照);玩家侧端点尚未就绪时留 null,
//      面板据此显示缺口提示而不是空白(见 item-panel.js 顶部说明)
// events:事件详情(GET /api/city/events 的整个响应:active / recent / limits),
//      由 ui/event-dialog.js 拉取。城市快照里只有精简 summary(数量 + 名字 + 到期时刻),
//      选项文案与掷点结果只在这个独立端点里 —— 两者刻意不同口径,别拿 summary 当详情用
export const state = {
    user: null, city: null, definitions: null, resourceNames: null,
    technologyDefs: null, market: null, npcDefs: null, itemDefs: null, events: null,
};
const subs = [];
export function onChange(cb) { subs.push(cb); }
export function setState(patch) { Object.assign(state, patch); subs.forEach((cb) => cb(state)); }
