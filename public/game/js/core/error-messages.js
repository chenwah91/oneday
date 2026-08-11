// 后端稳定错误码 -> 中文提示的唯一来源(CLAUDE §32:前端按 Error Code 显示本地文案)
// 与 app/Support/ErrorCode.php 对应;新增错误码时只改这一张表,不要在各面板里另建副本

export const ERROR_MESSAGES = {
    // 游戏经济 Mutation(建造/升级/拆除)
    INSUFFICIENT_RESOURCE: '资源不足',
    LAND_OCCUPIED: '这里已被占用',
    INVALID_POSITION: '不能建在这里',
    BUILDING_LIMIT_REACHED: '已达数量上限',
    INVALID_BUILDING: '无效的建筑类型',
    MAX_LEVEL: '已达最高等级',
    REVISION_CONFLICT: '数据已更新,请重试',
    IDEMPOTENCY_KEY_REUSED: '操作重复提交,请刷新后重试',

    // 人口 / 劳动力(v3.2 §10.4)
    WORKER_NOT_AVAILABLE: '可用工人不足,先从别的建筑撤回工人或增加人口',

    // 仓储已满:本次增量会让资源超过当前仓储上限(市场买入、拆除返还、管理员补偿共用)
    STORAGE_FULL: '仓储已满,先扩建仓库或先处理掉一些资源',

    // NPC(M3-D1):四个码分工明确,别在面板里合并成一句「操作失败」
    NPC_ALREADY_ASSIGNED: '这名 NPC 已在其它岗位,先撤下才能换岗',
    NPC_SLOT_FULL: '这栋建筑的 NPC 槽位已满,先撤下一个或选别的建筑',
    NPC_NOT_AVAILABLE: '这名 NPC 或目标建筑当前不可用',
    NPC_ERA_REQUIRED: '当前时代还招不到这一档人才,先升级时代',

    // 市场(M3-D3)
    // MARKET_LIMIT_REACHED 的默认文案只说结论;两条口径(等下一窗 vs 贸易容量不足)
    // 要读响应 details 才分得出来,由 ui/market-panel.js 现算现拼
    MARKET_LIMIT_REACHED: '成交量已达上限,等下一窗再试',
    RESOURCE_NOT_TRADEABLE: '这种资源不能在现货市场买卖',
    MARKET_CLOSED: '市场已停市,暂时无法买卖',

    // 科技研究(M2-B1)
    TECH_NOT_UNLOCKED: '前置科技尚未解锁',
    RESEARCH_IN_PROGRESS: '已有科技在研究中,完成后才能开始下一项',

    // 时代(M2-B6):同一个码有三个落点(研究 / 建造 / 时代升级),
    // 这里给通用文案,各面板用 overrides 换成本场景的说法
    ERA_REQUIRED: '当前时代还不能进行该操作',

    // 通用 / 基础设施
    VALIDATION_ERROR: '输入有误,请检查后重试',
    AUTH_REQUIRED: '登录已过期,请刷新页面重新登录',
    CSRF_TOKEN_MISMATCH: '登录状态已过期,请刷新页面后重试',
    FORBIDDEN: '没有权限执行该操作',
    NOT_FOUND: '目标不存在,可能已被移除',
    TOO_MANY_REQUESTS: '操作过于频繁,请稍后再试',
    INTERNAL_ERROR: '服务器出错了,请稍后再试',
};

// err:api.js 抛出的 { status, error, body };fallback:未知错误码时的兜底文案
// overrides:按调用场景覆盖个别错误码(如 BUILDING_LIMIT_REACHED 在升级语境下表示"已达最高等级")
export function errorText(err, fallback, overrides) {
    const code = err && err.error;
    if (code && overrides && overrides[code]) return overrides[code];
    if (code && ERROR_MESSAGES[code]) return ERROR_MESSAGES[code];
    return fallback || '操作失败,请重试';
}
