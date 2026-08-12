<?php

namespace App\Support;

// 稳定错误码:进入生产后保持不变(CLAUDE §32)。游戏业务码由后续子计划追加。
final class ErrorCode
{
    // 基础设施 / 通用
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const NOT_FOUND = 'NOT_FOUND';
    public const TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';
    public const FORBIDDEN = 'FORBIDDEN';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    public const CSRF_TOKEN_MISMATCH = 'CSRF_TOKEN_MISMATCH';
    public const HTTP_ERROR = 'HTTP_ERROR';

    // 登录
    public const BAD_CREDENTIALS = 'BAD_CREDENTIALS';

    // 账号已被封禁(W11-C1 任务4)。与 BAD_CREDENTIALS 分开一个码是刻意的:
    // 密码对了但账号被封,前端要给的是「你的账号已被停用,请联系客服」而不是「密码错误」——
    // 后者会让被封玩家反复重试密码,把客服工单变成一堆「我密码明明是对的」。
    // 泄漏面可接受:能拿到这个码的前提是**已经通过密码校验**,不构成账号枚举。
    public const ACCOUNT_BANNED = 'ACCOUNT_BANNED';

    // 游戏经济 Mutation(建造/升级/拆除等)
    public const INSUFFICIENT_RESOURCE = 'INSUFFICIENT_RESOURCE';
    public const BUILDING_LIMIT_REACHED = 'BUILDING_LIMIT_REACHED';
    public const INVALID_POSITION = 'INVALID_POSITION';
    public const LAND_OCCUPIED = 'LAND_OCCUPIED';
    public const REVISION_CONFLICT = 'REVISION_CONFLICT';
    public const INVALID_BUILDING = 'INVALID_BUILDING';

    // 劳动力不足:本次分配会让全城已分配工人超过 floor(population × 0.60)(CLAUDE §32 预留码)
    public const WORKER_NOT_AVAILABLE = 'WORKER_NOT_AVAILABLE';

    // 仓储已满:本次增量会让资源超过当前仓储上限。
    // 管理员补偿走「超出即拒绝」而不是静默截断 —— 悄悄少发的补偿比发不出去更难追查
    public const STORAGE_FULL = 'STORAGE_FULL';

    // 科技研究(M2-B1,CLAUDE §32 预留码)
    // TECH_NOT_UNLOCKED:前置科技尚未解锁(在研不算解锁)
    public const TECH_NOT_UNLOCKED = 'TECH_NOT_UNLOCKED';

    // 时代不满足(M2-B6 起共三个落点,一律按 cities.era_order 判定):
    //   研究:该科技所属时代高于城市当前时代
    //   建造:该建筑所属时代高于城市当前时代
    //   时代升级:下一时代的条件没有全部达标(此时响应带 details.requirements 逐维清单)
    public const ERA_REQUIRED = 'ERA_REQUIRED';

    // 已有项目在研:同时只允许 1 项(重复提交同一项、或第二项并行下单,都是这个码)
    public const RESEARCH_IN_PROGRESS = 'RESEARCH_IN_PROGRESS';

    // 幂等:同一 key 被复用到不同操作或不同参数上(409)
    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';

    // ---- M3-D3 市场(CLAUDE §32 已列 MARKET_LIMIT_REACHED,其余两个由 backlog §5.3 追加)----

    // 成交量上限:单笔超过单窗额度、或本窗 / 本小时累计额度已用尽(§69「单笔和时间窗口成交量限制」)。
    // 响应 details 会带上剩余额度,前端据此提示玩家「还能买多少」
    public const MARKET_LIMIT_REACHED = 'MARKET_LIMIT_REACHED';

    // 该资源不在现货市场上:未登记 / non_tradeable(knowledge、money)/ capacity_contract(electricity)。
    // 三种情况共用一个码是刻意的 —— 对客户端而言结论都是「这个不能买卖」,
    // 再细分只会告诉攻击者「这个 code 存在但换个方式能交易」
    public const RESOURCE_NOT_TRADEABLE = 'RESOURCE_NOT_TRADEABLE';

    // 全市场已停市(后台开关 market_enabled = false)。价目查询不受影响,只挡买卖
    public const MARKET_CLOSED = 'MARKET_CLOSED';

    // ---- M3-D1 NPC(CLAUDE §32 已列 NPC_ALREADY_ASSIGNED,其余三个由 backlog §3.2 追加)----

    // 该 NPC 已在岗(§52「NPC 不能同时被分配到两个互斥岗位」/ §67 点名的作弊检测项)。
    // 换岗必须先 unassign 再 assign,不提供「一步换岗」——两步语义在审计里才留得下完整轨迹
    public const NPC_ALREADY_ASSIGNED = 'NPC_ALREADY_ASSIGNED';

    // 目标建筑的 NPC 槽位已满(槽位数是后台可调的 npc_slots_per_building / _l3)
    public const NPC_SLOT_FULL = 'NPC_SLOT_FULL';

    // 招募池为空:当前时代下没有任何 recruit_source = recruit 的原型可招。
    // 也用于「派驻目标不是一栋可用建筑」之外的 NPC 不可用情形(已离场 / 已辞退)
    public const NPC_NOT_AVAILABLE = 'NPC_NOT_AVAILABLE';

    // 时代不满足:候选原型的 min_era 高于城市当前时代。
    // 与建造的 ERA_REQUIRED 分开一个码,是为了让前端能给出「先升时代才招得到这一档人」的专门文案
    public const NPC_ERA_REQUIRED = 'NPC_ERA_REQUIRED';

    // ---- M3-D2 工具 / 道具(backlog §4.2 点名三个,另加两个与 NPC 对称的)----

    // 目标建筑的装备槽位已满(槽位数是后台可调的 item_slots_per_building)。
    // 注意:**同 category 装第二件不是错误**(B2 明文「第二件同类不报错,只是不生效」),
    // 只有槽位真的占满了才是这个码
    public const ITEM_SLOT_FULL = 'ITEM_SLOT_FULL';

    // 工具已损毁(耐久归零,B4 已批的「损毁消失」):不能再装备,只能重新制作
    public const ITEM_BROKEN = 'ITEM_BROKEN';

    // 制作前置建筑缺失:§7 的 crafting_source 指向的建筑在本城没有 active 实例。
    // 只有 crafting_building_id 非空的工具才可能返回它(手工制作与未映射来源不设建筑门槛)
    public const CRAFTING_BUILDING_MISSING = 'CRAFTING_BUILDING_MISSING';

    // 该工具已经装备在某栋建筑上(与 NPC_ALREADY_ASSIGNED 对称,409)。
    // 换楼必须先 unequip 再 equip —— 两步语义在审计里才留得下完整轨迹
    public const ITEM_ALREADY_EQUIPPED = 'ITEM_ALREADY_EQUIPPED';

    // 全服停产(后台开关 item_craft_enabled = false)。装备 / 卸下不受影响,只挡新制作
    public const ITEM_CRAFT_DISABLED = 'ITEM_CRAFT_DISABLED';

    // ---- M3-D4 随机事件(CLAUDE §32 已列 EVENT_EXPIRED,其余三个由 backlog §6.3 追加)----

    // 事件已过期:expires_at 已到点(懒结算会把它翻成 status=expired)。
    // 过期后选项一律不可领 —— 「过了期还能领」是 §70 五道校验里最容易漏的一道
    public const EVENT_EXPIRED = 'EVENT_EXPIRED';

    // 该实例已经结算过(status 不是 active)。
    // 判定靠**条件更新的影响行数**(UPDATE … WHERE status='active'),不是「先查后写」——
    // 先查后写会在并发下双领(backlog §11.3 点名)
    public const EVENT_ALREADY_RESOLVED = 'EVENT_ALREADY_RESOLVED';

    // 选项不合法:不是 a/b/c、或该事件根本没有这个选项(§9.2 里很多事件只有 A/B)。
    // 也用于「事件有选项却没传 choice」——服务器不替玩家挑
    public const EVENT_OPTION_INVALID = 'EVENT_OPTION_INVALID';

    // 事件系统已停用(后台开关 event_enabled = false)。
    // 只挡新事件的触发与结算,不影响已生效实例的到期消退
    public const EVENT_DISABLED = 'EVENT_DISABLED';

    // 事件并发上限已满(W11-C1 任务5,管理员手动触发的唯一新增码)。
    // 自然触发路径撞到上限时是「这一窗作废,不顺延」(静默),没有对外错误码;
    // 手动触发必须给出明确回执 —— 否则管理员会以为是自己点错了而反复重试。
    // details 会带 limit / current / 具体是哪一档(全局 max_active、灾害档、或同事件已生效)
    public const EVENT_LIMIT_REACHED = 'EVENT_LIMIT_REACHED';
}
