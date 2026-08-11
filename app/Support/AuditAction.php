<?php

namespace App\Support;

// 稳定审计 Action Code。
// Action 码进入生产后保持稳定(CLAUDE §55),只增不改:历史审计行里的字符串不会跟着改名,
// 改动会让旧记录与新记录对不上,后台查询/统计全部失真。只允许新增,不允许重命名或删除。
// 另:只登记确实有落点的码,纯常量(无人写入)不加。
final class AuditAction
{
    public const AUTH_REGISTER = 'AUTH.REGISTER';
    public const AUTH_LOGIN_SUCCESS = 'AUTH.LOGIN_SUCCESS';
    public const AUTH_LOGIN_FAILED = 'AUTH.LOGIN_FAILED';
    public const AUTH_LOGOUT = 'AUTH.LOGOUT';

    // 建城(CityFactory 首次真正建城时写,兜底重入不重复写)
    public const CITY_CREATE = 'CITY.CREATE';

    // 建筑经济 Mutation
    public const BUILDING_BUILD = 'BUILDING.BUILD';
    public const BUILDING_UPGRADE = 'BUILDING.UPGRADE';
    public const BUILDING_DEMOLISH = 'BUILDING.DEMOLISH';

    // 取消升级(M2-C5,v3.2 §3.2「返还 70%,资金不返还」):
    // delta 记实际退到手的材料(已按仓储上限截断),被截掉的量在 metadata.truncated
    public const BUILDING_UPGRADE_CANCEL = 'BUILDING.UPGRADE_CANCEL';

    // 工人分配(绝对值设置:before/after 记该实例分配前后的工人数)
    public const WORKER_ASSIGN = 'WORKER.ASSIGN';

    // 科技研究(M2-B1)
    // RESEARCH_START:下单时写,delta 记扣掉的知识
    // UNLOCK:懒结算到点翻牌时写(每项科技恰好一条,由条件更新的受影响行数保证)
    public const TECH_RESEARCH_START = 'TECH.RESEARCH_START';
    public const TECH_UNLOCK = 'TECH.UNLOCK';

    // 时代升级(M2-B6):before/after 记升级前后的 era_key / era_order,
    // metadata 记达标当时的逐维实测值(事后回查「他当时是靠什么过的线」)
    public const ERA_UPGRADE = 'ERA.UPGRADE';

    // 市场成交(M3-D3,§56 明文要求经济类日志带资源变化):
    // delta_json 记 {资源: ±数量, money: ±金额};
    // metadata_json 记 resource / quantity / unit_price / mid_price / fee / slippage / money_delta / window_index,
    // 让「价格投诉」能被拆回「基准价 × 滑点 × 手续费」三段
    public const MARKET_BUY = 'MARKET.BUY';
    public const MARKET_SELL = 'MARKET.SELL';

    // NPC(M3-D1):
    // RECRUIT   招募,delta 记 {money: -招募价};metadata 记服务器掷出的稀有度与被抽中的 npc_id
    //           (§30/§66:稀有度由服务器权威掷点,掷出结果必须落库才不可复掷)
    // ASSIGN    派驻到建筑实例,delta 记该 NPC 给这栋楼带来的百分比加成
    // UNASSIGN  撤下,delta 同上取负
    // DISMISS   辞退,delta 记释放掉的工资 / 口粮速率(这是辞退唯一的经济意义)
    // LEAVE     士气过低自行离职(由懒结算写,不是玩家操作,actor 是 system)
    // NATURAL_GROWTH 自然增长送人(同上,actor 是 system)
    public const NPC_RECRUIT = 'NPC.RECRUIT';
    public const NPC_ASSIGN = 'NPC.ASSIGN';
    public const NPC_UNASSIGN = 'NPC.UNASSIGN';
    public const NPC_DISMISS = 'NPC.DISMISS';
    public const NPC_LEAVE = 'NPC.LEAVE';
    public const NPC_NATURAL_GROWTH = 'NPC.NATURAL_GROWTH';

    // 安全:越权操作被拒
    public const SECURITY_AUTHORIZATION_FAILED = 'SECURITY.AUTHORIZATION_FAILED';

    // 安全:触发限流(AppServiceProvider 各限流器的 response 回调)
    public const SECURITY_RATE_LIMIT = 'SECURITY.RATE_LIMIT';

    // 安全:Revision 冲突。写入点在全局异常 render,不在事务内 ——
    // 冲突是在事务里抛出的,同事务写的审计会被回滚一起抹掉(见 bootstrap/app.php)
    public const SECURITY_REVISION_CONFLICT = 'SECURITY.REVISION_CONFLICT';

    // 安全:可疑行为(当前落点为幂等键复用 409,同样在全局 render 写)
    public const SECURITY_SUSPICIOUS_ACTIVITY = 'SECURITY.SUSPICIOUS_ACTIVITY';

    // 管理后台
    public const ADMIN_LOGIN = 'ADMIN.LOGIN';
    public const ADMIN_CONFIG_CHANGE = 'ADMIN.CONFIG_CHANGE';

    // 管理员补偿(CLAUDE §80 / SECURITY.md「补偿统一使用 ADMIN.COMPENSATION」):
    // actor 是管理员,user_id / city_id 是被补偿的玩家与其城市,before/after/delta/reason 齐全
    public const ADMIN_COMPENSATION = 'ADMIN.COMPENSATION';
}
