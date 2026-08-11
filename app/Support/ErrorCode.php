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
}
