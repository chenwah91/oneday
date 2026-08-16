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

    // 工具 / 道具(M3-D2,§55 的命名风格「域.动作」):
    // CRAFT    制作,delta 记扣掉的材料与资金;metadata 记耐久上限与制作建筑
    // EQUIP    装备到建筑实例,delta 记该工具给这栋楼带来的百分比加成
    // UNEQUIP  卸下,delta 同上取负(耐久保留)
    // BROKEN   耐久归零损毁(由懒结算写,不是玩家操作,actor 是 system;B4 已批「损毁消失」)
    public const ITEM_CRAFT = 'ITEM.CRAFT';
    public const ITEM_EQUIP = 'ITEM.EQUIP';
    public const ITEM_UNEQUIP = 'ITEM.UNEQUIP';
    public const ITEM_BROKEN = 'ITEM.BROKEN';

    // 随机事件(M3-D4,CLAUDE §55 已列 EVENT.TRIGGER / RESOLVE / REWARD 三个码):
    // TRIGGER  触发。**actor 是 system**(不是玩家操作):由懒结算的资格窗口掷点产生。
    //          metadata 记 window_index / 权重与三个修正系数 / 掷点结果(rolled),
    //          delta 记自动效果当场造成的资源变化 —— 「我离线回来粮食怎么少了」要靠它回答
    // RESOLVE  玩家选择并结算(delta 记该选项造成的资源变化,metadata 记未生效的 unmapped 清单)
    // REWARD   正向事件的资源发放(§13 修正方向:正向事件直接发资源而不是加乘区)。
    //          与 TRIGGER / RESOLVE 分开一条,是因为「发了多少」必须能单独查、单独统计
    // EXPIRE   过期作废(actor 也是 system,由懒结算翻;没有资源变化,只留状态轨迹)
    public const EVENT_TRIGGER = 'EVENT.TRIGGER';
    public const EVENT_RESOLVE = 'EVENT.RESOLVE';
    public const EVENT_REWARD = 'EVENT.REWARD';
    public const EVENT_EXPIRE = 'EVENT.EXPIRE';

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
    //
    // ══ 后台认证三兄弟:与玩家的 AUTH.* 完全分开(用户 2026-08-16 拍板)═══════════════
    // 后台自 2026-08-15 起走独立的 admin guard 与独立登录端点,两套会话在同一浏览器里并存。
    // 审计上也必须彻底分家:原先后台的失败与登出复用 AUTH.LOGIN_FAILED / AUTH.LOGOUT,
    // 靠 metadata.surface 与 actor_type 两个**字段**去区分 —— 运营按 action 一查就混在一起,
    // 「有人在敲后台门」和「有人在爆破玩家号」这两件处置优先级完全不同的事被算成同一堆。
    // 现在三个动作各有各的码,`WHERE action LIKE 'ADMIN.%'` 就是后台的全部认证活动。
    // §55 说「Action Code 进入生产后尽量保持稳定」—— 系统尚未上线,现在正是分家的时机。
    public const ADMIN_LOGIN = 'ADMIN.LOGIN';
    public const ADMIN_LOGIN_FAILED = 'ADMIN.LOGIN_FAILED';
    public const ADMIN_LOGOUT = 'ADMIN.LOGOUT';
    public const ADMIN_CONFIG_CHANGE = 'ADMIN.CONFIG_CHANGE';

    // 管理员补偿(CLAUDE §80 / SECURITY.md「补偿统一使用 ADMIN.COMPENSATION」):
    // actor 是管理员,user_id / city_id 是被补偿的玩家与其城市,before/after/delta/reason 齐全
    public const ADMIN_COMPENSATION = 'ADMIN.COMPENSATION';

    // 封禁 / 解禁(W11-C1 任务4):
    // actor 是管理员(actor_type=admin),user_id 是被封 / 被解禁的玩家,
    // before/after 记 banned_at 与 ban_reason 两列的前后值,reason_code 记管理员填的原因。
    // 两个动作分成两个码而不是一个 ADMIN.PLAYER_BAN 带 after 判方向:
    // 「这半年封了多少人 / 解了多少人」要能各自 COUNT,混成一个码就统计不出来了。
    // **封禁绝不删除任何玩家数据**,这两条审计就是「账号状态被谁在什么时候改过」的唯一凭据
    public const ADMIN_PLAYER_BAN = 'ADMIN.PLAYER_BAN';
    public const ADMIN_PLAYER_UNBAN = 'ADMIN.PLAYER_UNBAN';
}
