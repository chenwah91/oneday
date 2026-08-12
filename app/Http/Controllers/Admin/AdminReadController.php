<?php

namespace App\Http\Controllers\Admin;

use App\Game\Event\EventCode;
use App\Game\NPC\NpcCode;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use App\Support\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 后台只读:当前管理员 / 玩家 / 城市 / 审计 / 全服仪表盘
class AdminReadController extends Controller
{
    // 游标分页的 limit 口径(与 audit 一致):默认 50,上限 200
    private const PAGE_DEFAULT = 50;
    private const PAGE_MAX = 200;

    // 玩家列表「无参数」时的历史上限。保持 500 是为了兼容既有前端(R1 以来一直是这个值),
    // 新代码请一律带参数走游标分页
    private const PLAYERS_LEGACY_LIMIT = 500;

    // 仪表盘资源榜的条数
    private const DASHBOARD_TOP_RESOURCES = 10;

    // 当前管理员身份:username/role/permissions。
    // 供后台前端按权限显隐按钮(前端显隐只是体验优化,真正的拦截始终在 EnsureAdmin 中间件)
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = is_string($user->role) ? $user->role : null;

        return ApiResponse::ok(['data' => [
            'id'          => $user->id,
            'username'    => $user->username,
            'role'        => $role,
            'permissions' => Role::permissionsFor($role),
        ]]);
    }

    // ---------- 全服仪表盘(W11-C1 任务1)----------

    // GET /api/admin/dashboard —— 运营首页的一屏数字。
    //
    // ══ 硬纪律:纯聚合,绝不逐城循环 ═════════════════════════════════════════
    // 每一块都是**一条** SQL 聚合(COUNT / SUM / GROUP BY),查询条数是**常量 7**,
    // 与玩家数、城市数、建筑数完全无关。这条纪律不是性能洁癖:
    // 「先取全部城市再 foreach 累加」在 1000 座城时就是 1000+ 次往返,而仪表盘是运营每天
    // 开着不关的页面 —— 它会变成全站最贵的一个 GET。测试里用 DB::listen 数查询条数守住这条线。
    //
    // ══ 口径说明(数字要能被解释,否则运营对不上账)═══════════════════════════
    //   · 「今日」= 应用时区(config/app.php)的当天 00:00 起,响应里带 window.today_start;
    //   · 「24h 活跃」按 cities.last_simulated_at —— 玩家只要拉过一次快照 / 做过一次操作就会被推进,
    //     它是本项目唯一免费的活跃度信号(不额外维护 last_login_at);
    //   · 「资金总额」只统计 cities.money(玩家手上的钱),不含任何未落地的应收;
    //   · 「在职 NPC」= idle + assigned,left 是已离场的历史行,不算人头;
    //   · 「生效事件」= status=active 且未到期 —— 事件是懒结算的,过期但还没被翻牌的实例
    //     在库里仍是 active,不排掉的话这个数字会长期虚高。
    public function dashboard(): JsonResponse
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $activeSince = $now->copy()->subDay();

        // ① 账号:总数 / 今日新增 / 已封禁 / 后台人员,一条聚合出四个数
        $users = DB::table('users')->selectRaw(
            'COUNT(*) AS total,
             SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS new_today,
             SUM(CASE WHEN banned_at IS NOT NULL THEN 1 ELSE 0 END) AS banned,
             SUM(CASE WHEN role <> ? THEN 1 ELSE 0 END) AS staff',
            [$todayStart, Role::PLAYER]
        )->first();

        // ② 城市:总数 / 资金总额 / 24h 活跃
        $cities = DB::table('cities')->selectRaw(
            'COUNT(*) AS total,
             COALESCE(SUM(money), 0) AS money_total,
             SUM(CASE WHEN last_simulated_at >= ? THEN 1 ELSE 0 END) AS active_24h',
            [$activeSince]
        )->first();

        // ③ 资源存量 Top 10:GROUP BY resource_id 一次算完全服;
        //    显示名顺手从 resource_definition 带出来,免得前端再拉一次定义表
        $resources = DB::table('city_resources as cr')
            ->leftJoin('resource_definition as rd', 'rd.resource_id', '=', 'cr.resource_id')
            ->groupBy('cr.resource_id', 'rd.name')
            ->orderByDesc('total')
            ->limit(self::DASHBOARD_TOP_RESOURCES)
            ->get(['cr.resource_id', 'rd.name', DB::raw('SUM(cr.amount) AS total')]);

        // ④ 建筑实例:总数 / active(在建与升级中的不算 active)
        $buildings = DB::table('city_building_instances')->selectRaw(
            'COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active',
            ['active']
        )->first();

        // ⑤ 在职 NPC(idle + assigned)
        $npcEmployed = DB::table('city_npcs')->whereIn('status', NpcCode::ACTIVE_STATUSES)->count();

        // ⑥ 生效中的事件实例
        $eventsActive = DB::table('city_events')
            ->where('status', EventCode::STATUS_ACTIVE)
            ->where('expires_at', '>', $now)
            ->count();

        // ⑦ 今日后台操作条数(ADMIN.* 全系列)。'ADMIN.%' 里没有 _,不存在通配符转义问题
        $adminActionsToday = DB::table('audit_logs')
            ->where('action', 'like', 'ADMIN.%')
            ->where('occurred_at', '>=', $todayStart)
            ->count();

        return ApiResponse::ok(['data' => [
            // 统计时间戳:仪表盘会被前端缓存 / 截图,没有它就分不清「数字没动」还是「页面没刷新」
            'generated_at' => $now->toIso8601String(),
            'window' => [
                'today_start'  => $todayStart->toIso8601String(),
                'active_since' => $activeSince->toIso8601String(),
            ],
            'players' => [
                'total'      => (int) $users->total,
                'new_today'  => (int) $users->new_today,
                'banned'     => (int) $users->banned,
                // 后台人员数(role <> player):让「玩家总数」可被减法还原,不必再查一次
                'staff'      => (int) $users->staff,
                'active_24h' => (int) $cities->active_24h,
            ],
            'cities' => [
                'total'       => (int) $cities->total,
                'money_total' => round((float) $cities->money_total, 2),
            ],
            'resources_top' => $resources->map(fn ($r) => [
                'resource_id' => (string) $r->resource_id,
                'name'        => $r->name === null ? (string) $r->resource_id : (string) $r->name,
                'total'       => round((float) $r->total, 2),
            ])->all(),
            'buildings' => [
                'total'  => (int) $buildings->total,
                'active' => (int) $buildings->active,
            ],
            'npcs'   => ['employed' => (int) $npcEmployed],
            'events' => ['active' => (int) $eventsActive],
            'audit'  => ['admin_actions_today' => (int) $adminActionsToday],
        ]]);
    }

    // ---------- 玩家 ----------

    // 玩家列表:联查城市 id,仅输出安全字段(不含 password)。
    //
    // ══ 两种模式(W11-C1 任务2)═══════════════════════════════════════════════
    //   · **完全不带参数** → 原样返回(id 升序 + 500 条上限),与 R1 以来的契约逐字节兼容;
    //   · 带任一参数(q / role / before_id / limit)→ 游标分页模式:id 降序 + limit 默认 50 上限 200,
    //     响应多给一个 next_before_id。
    // 为什么不直接把默认行为改成分页:现有前端页面按「一次拿全」写的,悄悄改成 50 条
    // 会让玩家列表突然少掉一大半,而这种「少了但没报错」的回归最难被发现。
    public function players(Request $request): JsonResponse
    {
        $query = DB::table('users as u')
            ->leftJoin('cities as c', 'c.user_id', '=', 'u.id')
            ->select('u.id', 'u.username', 'u.email', 'u.role', 'u.created_at',
                'u.banned_at', 'u.ban_reason', 'c.id as city_id');

        if (! $request->hasAny(['q', 'role', 'before_id', 'limit'])) {
            $rows = $query->orderBy('u.id')->limit(self::PLAYERS_LEGACY_LIMIT)->get();

            return ApiResponse::ok(['data' => ['players' => $rows->map(self::playerRow(...))->all()]]);
        }

        // q:username / email **前缀**匹配。刻意不做 %关键字% 的中缀匹配 ——
        // 中缀 LIKE 用不上索引,玩家表大了就是全表扫;运营真正要的是「按用户名开头找人」
        $keyword = trim((string) $request->query('q', ''));
        if ($keyword !== '') {
            $prefix = self::escapeLike($keyword).'%';
            $query->where(function ($w) use ($prefix) {
                $w->where('u.username', 'like', $prefix)->orWhere('u.email', 'like', $prefix);
            });
        }

        // role:只认 Role::all() 里的合法值(Fail Closed),不合法直接 422 而不是「查不到」——
        // 静默返回空列表会让运营以为「这个角色一个人都没有」
        $role = trim((string) $request->query('role', ''));
        if ($role !== '') {
            if (! Role::isValid($role)) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                    'errors' => ['role' => ['角色不合法,合法值:'.implode(' / ', Role::all())]],
                ]);
            }
            $query->where('u.role', $role);
        }

        // before_id 游标:比 offset 分页稳(翻页期间有人注册也不会漏行 / 重复行)
        $beforeId = (int) $request->query('before_id', 0);
        if ($beforeId > 0) {
            $query->where('u.id', '<', $beforeId);
        }

        $limit = self::clampLimit($request);
        $rows = $query->orderByDesc('u.id')->limit($limit)->get();
        $players = $rows->map(self::playerRow(...))->all();

        return ApiResponse::ok(['data' => [
            'players' => $players,
            'limit'   => $limit,
            // 取满才可能还有下一页;取不满一律 null,前端据此停止翻页
            'next_before_id' => count($players) === $limit ? (int) $rows->last()->id : null,
        ]]);
    }

    // 玩家详情:基础信息 + 城市摘要(revision/population/money/建筑数)
    public function playerDetail(int $id): JsonResponse
    {
        // 仅取展示所需字段,password/remember_token 等敏感列不进内存
        $u = DB::table('users')->where('id', $id)
            ->select('id', 'username', 'email', 'role', 'created_at', 'banned_at', 'ban_reason')->first();
        if (! $u) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        $city = DB::table('cities')->where('user_id', $id)->first();
        $citySummary = null;
        if ($city) {
            $citySummary = [
                'id' => $city->id, 'revision' => $city->revision, 'population' => $city->population,
                'money' => (float) $city->money,
                'buildingCount' => DB::table('city_building_instances')->where('city_id', $city->id)->count(),
            ];
        }

        return ApiResponse::ok(['data' => [
            'player' => [
                'id' => $u->id, 'username' => $u->username, 'email' => $u->email,
                'role' => $u->role, 'createdAt' => $u->created_at,
                // 封禁状态(W11-C1 任务4):banned_at 非空 = 已封禁
                'created_at' => $u->created_at,
                'banned_at'  => $u->banned_at,
                'ban_reason' => $u->ban_reason,
            ],
            'city' => $citySummary,
        ]]);
    }

    // ---------- 审计 ----------

    // 审计列表:最近审计记录,limit 强制 clamp 到 [1,200]。
    //
    // 过滤维度(W11-C1 任务3):action(精确 / 前缀 LIKE)、user_id、city_id、request_id、
    // occurred_at 起止(from / to)、before_id 游标。
    // 行的字段名保持既有 camelCase 不动(前端在读);新加的 next_before_id 是纯增量字段。
    public function audit(Request $request): JsonResponse
    {
        $limit = self::clampLimit($request);
        $q = DB::table('audit_logs')->orderByDesc('id')->limit($limit);

        // ---- action:精确 或 前缀通配 ----
        $action = trim((string) $request->query('action', ''));
        if ($action !== '') {
            // allowlist 正则:只放行大写字母 / 下划线 / 点 / %。
            // 目的是挡住「注入通配」——不校验的话一个 '%' 就能把 action 过滤变成「全都要」,
            // 更糟的是 '_' 在 LIKE 里是任意单字符,能被用来横向探测未知的 action 码
            if (preg_match('/^[A-Z_.%]+$/', $action) !== 1) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                    'errors' => ['action' => ['action 只允许大写字母、下划线、点与 % 通配符']],
                ]);
            }

            if (str_contains($action, '%')) {
                // 只保留 % 一种通配语义:_ 一律转义成字面量,
                // 否则 ADMIN.PLAYER_BAN 会连 ADMIN.PLAYERXBAN 之类一起匹配
                $q->where('action', 'like', str_replace('_', '\\_', $action));
            } else {
                $q->where('action', $action);
            }
        }

        // ---- 精确过滤:user_id / city_id / request_id ----
        foreach (['user_id', 'city_id'] as $column) {
            $value = $request->query($column);
            if ($value !== null && $value !== '') {
                $q->where($column, (int) $value);
            }
        }

        $requestId = trim((string) $request->query('request_id', ''));
        if ($requestId !== '') {
            $q->where('request_id', $requestId);
        }

        // ---- occurred_at 起止(闭区间):按城市 + 时间查正是新加的 idx_audit_city_time 服务的场景 ----
        foreach (['from' => '>=', 'to' => '<='] as $param => $operator) {
            $raw = trim((string) $request->query($param, ''));
            if ($raw === '') {
                continue;
            }
            try {
                $when = Carbon::parse($raw);
            } catch (\Throwable) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                    'errors' => [$param => ['时间格式无法解析']],
                ]);
            }
            $q->where('occurred_at', $operator, $when->format('Y-m-d H:i:s'));
        }

        // ---- 游标分页 ----
        $beforeId = (int) $request->query('before_id', 0);
        if ($beforeId > 0) {
            $q->where('id', '<', $beforeId);
        }

        // with_delta=1:仅追加 delta_json 一列(W11-2 补偿历史小表要显示每笔发了多少)。
        // 列表默认不带任何 JSON 列的尺寸理由见 auditDetail 注释——delta 是四列里最小的一列
        // (资源差额映射,几十字节),按需带上不破坏那条纪律;before/after/metadata 仍只走详情
        $withDelta = $request->query('with_delta') === '1';

        $rows = $q->get();
        $audit = $rows->map(fn ($r) => [
            'id' => $r->id, 'occurredAt' => $r->occurred_at, 'action' => $r->action,
            'actorType' => $r->actor_type, 'actorId' => $r->actor_id, 'userId' => $r->user_id,
            'cityId' => $r->city_id, 'status' => $r->status, 'reasonCode' => $r->reason_code,
            'requestId' => $r->request_id,
        ] + ($withDelta ? ['delta' => json_decode($r->delta_json ?? 'null', true)] : []))->all();

        return ApiResponse::ok(['data' => [
            'audit' => $audit,
            'limit' => $limit,
            'next_before_id' => count($audit) === $limit ? (int) $rows->last()->id : null,
        ]]);
    }

    // 单条审计详情:列表刻意不下发的四个 JSON 列在这里给全。
    //
    // 为什么分成两个端点:before/after/delta/metadata 四列加起来经常是几 KB,
    // 列表一次 200 条就是几 MB —— 而运营 99% 的时间只是在扫「谁在什么时候做了什么」。
    // 契约字段一律 snake_case(新端点走项目现行约定;列表的 camelCase 是历史包袱,不动它)。
    public function auditDetail(int $id): JsonResponse
    {
        $row = DB::table('audit_logs')->where('id', $id)->first();
        if (! $row) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        return ApiResponse::ok(['data' => ['audit' => [
            'id'                   => (int) $row->id,
            'occurred_at'          => (string) $row->occurred_at,
            'request_id'           => $row->request_id,
            'trace_id'             => $row->trace_id,
            'idempotency_key'      => $row->idempotency_key,
            'actor_type'           => $row->actor_type,
            'actor_id'             => $row->actor_id === null ? null : (int) $row->actor_id,
            'user_id'              => $row->user_id === null ? null : (int) $row->user_id,
            'city_id'              => $row->city_id === null ? null : (int) $row->city_id,
            'action'               => $row->action,
            'entity_type'          => $row->entity_type,
            'entity_id'            => $row->entity_id,
            'city_revision_before' => $row->city_revision_before === null ? null : (int) $row->city_revision_before,
            'city_revision_after'  => $row->city_revision_after === null ? null : (int) $row->city_revision_after,
            'status'               => $row->status,
            'reason_code'          => $row->reason_code,
            // IP 保留:read_audit 的存在意义就是查滥用,没有 IP 就查不出「同一个人开了几个号」。
            // user_agent_hash 不下发 —— 它是哈希,对人没有可读性,只在自动化比对时才有用
            'ip_address'           => $row->ip_address,
            'game_data_version'    => $row->game_data_version,
            'before_json'          => self::decodeJson($row->before_json),
            'after_json'           => self::decodeJson($row->after_json),
            'delta_json'           => self::decodeJson($row->delta_json),
            'metadata_json'        => self::decodeJson($row->metadata_json),
            // Hash Chain 两列:审计是否被改过要靠它们复核(命令 audit:verify-chain)
            'previous_hash'        => $row->previous_hash ?? null,
            'event_hash'           => $row->event_hash ?? null,
        ]]]);
    }

    // ---------- 私有工具 ----------

    // limit clamp:默认 50,夹在 [1, 200]。玩家列表与审计共用同一口径
    private static function clampLimit(Request $request): int
    {
        return min(self::PAGE_MAX, max(1, (int) $request->query('limit', self::PAGE_DEFAULT)));
    }

    // 玩家行的契约表示。
    // camelCase 三键是历史契约(前端在读,不能删);snake_case 是项目现行约定(2026-08-10 拍板),
    // 新字段一律只出 snake_case —— 两套并存只是过渡,不要再给旧风格添新键
    private static function playerRow(object $r): array
    {
        return [
            'id' => $r->id, 'username' => $r->username, 'email' => $r->email, 'role' => $r->role,
            'createdAt' => $r->created_at, 'cityId' => $r->city_id,
            'created_at' => $r->created_at, 'city_id' => $r->city_id,
            'banned_at'  => $r->banned_at,
            'ban_reason' => $r->ban_reason,
        ];
    }

    // LIKE 通配符转义:\ % _ 三个字符一律转成字面量(反斜杠必须最先转,否则会把自己转出来的也再转一次)。
    // 没有这一步,运营在搜索框敲一个 % 就等于「匹配所有人」
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    // 审计 JSON 列解码:NULL 保持 NULL;解出来是数组时统一包成对象,
    // 空 map 才不会被 json_encode 编成 [](ApiResponse::map 的同一条纪律)
    private static function decodeJson(?string $json): mixed
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? ApiResponse::map($decoded) : $decoded;
    }
}
