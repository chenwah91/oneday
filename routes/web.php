<?php

use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

// 根路径直达游戏入口(R1-B):玩家入口是 /game/,Laravel 骨架 welcome 页(82KB)对玩家无意义
Route::get('/', function () {
    return redirect('/game/');
});

// 基础设施探针(供中间件/健康检查测试)
Route::prefix('api')->group(function () {
    // _ping 仅用于测试/本地探活,生产环境不注册
    if (! app()->environment('production')) {
        Route::middleware('throttle:api')->get('/_ping', fn () => ApiResponse::ok(['data' => ['pong' => true]]));
    }

    // 健康检查探针不限流,避免探活请求被节流
    Route::get('/health', fn () => ApiResponse::ok([
        'data' => [
            'status'      => 'ok',
            // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
            'server_time' => now()->toIso8601String(),
        ],
    ]));

    // 注册接口:独立限流(同 IP 每分钟 10 次),减缓账号枚举探测
    Route::middleware('throttle:register')->post('/auth/register', \App\Http\Controllers\Auth\RegisterController::class);

    // 登录接口:throttle:auth 仅做粗粒度按 IP 限流(每分钟 20 次)兜底 DoS;
    // 真正的按账号失败次数限制(每 15 分钟 5 次、与 IP 无关)在 LoginController 内实现
    Route::post('/auth/login', \App\Http\Controllers\Auth\LoginController::class)->middleware('throttle:auth');

    // CSRF cookie:公开接口(未登录也能打),供 SPA 首次取用 XSRF-TOKEN。
    // 每次调用都会由 web 组起一个 session,不限流等于给了匿名用户一个免费的 session 生成器
    Route::get('/csrf-cookie', [\App\Http\Controllers\Auth\SessionController::class, 'csrfCookie'])->middleware('throttle:api');

    Route::middleware('auth:web')->group(function () {
        // 当前登录用户:普通只读端点,与其它 GET 同档(throttle:api)
        Route::get('/me', [\App\Http\Controllers\Auth\SessionController::class, 'me'])->middleware('throttle:api');

        // 登出:失效 session 并写审计。属认证域,与 login 同挂 throttle:auth(每 IP 每分钟 20 次)比 api 略严 ——
        // 一次登出即销毁 session,后续重放会先被 auth:web 拦成 401(排在限流之前,不计数),
        // 想刷满这档配额必须反复重新登录,而登录本身也走同一个限流器
        Route::post('/auth/logout', [\App\Http\Controllers\Auth\SessionController::class, 'logout'])->middleware('throttle:auth');

        // 城市只读快照:先结算再返回聚合状态。
        // 独立限流(每用户每分钟 30 次):快照会跑结算与多表联查,是最贵的 GET(CLAUDE §48)
        Route::get('/city', [\App\Http\Controllers\City\CityController::class, 'show'])->middleware('throttle:snapshot');

        // 可建建筑列表:联查 L1 成本/产出,供前端建筑面板显示
        Route::get('/definitions/buildings', [\App\Http\Controllers\City\DefinitionController::class, 'buildings'])->middleware('throttle:api');

        // 资源定义:code → 中文显示名(资源主键已英文化,前端显示名一律从这里取)
        Route::get('/definitions/resources', [\App\Http\Controllers\City\DefinitionController::class, 'resources'])->middleware('throttle:api');

        // 科技定义:50 个节点的时代/分支/费用/时长/前置,供前端科技面板显示
        Route::get('/definitions/technologies', [\App\Http\Controllers\City\DefinitionController::class, 'technologies'])->middleware('throttle:api');

        // NPC 定义(M3-W7):150 个原型 + 12 条技能 + 10 级曲线,供前端招募池预览与经验条显示。
        // 与其它 definitions 端点同一档(auth:web + throttle:api):它是全服共享的静态定义,不含任何玩家数据。
        // **不下发 trait_json 的 specs 结构**(理由见 DefinitionController::npcs 的注释)
        Route::get('/definitions/npcs', [\App\Http\Controllers\City\DefinitionController::class, 'npcs'])->middleware('throttle:api');

        // 工具定义(R1-B):24 件工具的制作目录(成本 / 时代 / 制作建筑 / 耐久 / 效果),供前端工具面板制作区显示。
        // 与其它 definitions 端点同一档(auth:web + throttle:api):全服共享的静态定义,不含任何玩家数据。
        // **不下发 effect_json 的 specs 结构**(理由见 DefinitionController::items 的注释)
        Route::get('/definitions/items', [\App\Http\Controllers\City\DefinitionController::class, 'items'])->middleware('throttle:api');

        // 建造:完整安全链(幂等/Revision/占地/上限/资源/审计)
        Route::post('/city/build', \App\Http\Controllers\City\BuildController::class)->middleware('throttle:api');

        // 升级:L1→L2→L3,严格所有权校验(越权 403 + 审计)
        Route::post('/city/upgrade', \App\Http\Controllers\City\UpgradeController::class)->middleware('throttle:api');

        // 取消升级(M2-C5):仅 upgrading 可取消,退还该次升级材料的 70%(v3.2 §3.2,资金不返还)
        Route::post('/city/upgrade/cancel', \App\Http\Controllers\City\UpgradeCancelController::class)->middleware('throttle:api');

        // 拆除:所有权校验(越权 403 + 审计),返还已完工等级建造材料的 50%(v3.2 §10.9)
        Route::post('/city/demolish', \App\Http\Controllers\City\DemolishController::class)->middleware('throttle:api');

        // 工人分配:绝对值设置,受实例 worker_required 与全城 available_workers 双重约束(v3.2 §10.4)
        Route::post('/city/workers/assign', \App\Http\Controllers\City\WorkerAssignController::class)->middleware('throttle:api');

        // 科技研究:一次性扣知识 + 按 research_minutes 计时,到点由懒结算翻成已解锁(CLAUDE §48 Research 必须限流)
        Route::post('/city/research', \App\Http\Controllers\City\ResearchController::class)->middleware('throttle:api');

        // 时代升级:升到下一时代,按 v3.2 §5.1 八维条件逐项校验(不达标 422 ERA_REQUIRED + 逐维清单)
        Route::post('/city/era/upgrade', \App\Http\Controllers\City\EraUpgradeController::class)->middleware('throttle:api');

        // ================= M3 共享文件锚点(D0.4,W1-A 一次性预置)=================
        //
        // 纪律(backlog §10.2,写进每个 M3 任务的 prompt):
        //   ① 每个任务**只在自己系统的锚点块内**增删,禁止重排、禁止格式化他人行、禁止在锚点外改动;
        //   ② 同一波次内两个任务若都要动同一个锚点,视为切分失败,必须重新划分;
        //   ③ 锚点是纯注释,预置本身零行为变化。
        // 目的:把两个 agent 并行时的 git 冲突面压到最小。

        // ---- M3-NPC ----(W2-A:招募 / 分配 / 辞退 / 加薪,全安全链 + Idempotency)

        // 招募:扣费 + **服务器权威掷点**决定抽到谁(入参没有 npc_id,§30 / §66)。
        // 与建造同档 throttle:api —— 它和建造一样是「花钱换实体」的经济写操作
        Route::post('/city/npc/recruit', [\App\Http\Controllers\City\NpcController::class, 'recruit'])->middleware('throttle:api');

        // 派驻 / 撤下:所有权 + 一 NPC 一岗互斥 + 单栋槽位上限(CLAUDE §48 明文要求 NPC Assign 限流)
        Route::post('/city/npc/assign', [\App\Http\Controllers\City\NpcController::class, 'assign'])->middleware('throttle:api');
        Route::post('/city/npc/unassign', [\App\Http\Controllers\City\NpcController::class, 'unassign'])->middleware('throttle:api');

        // 辞退:释放工资与口粮负担(idle 的 NPC 照样发工资,这是唯一的主动止损手段)
        Route::post('/city/npc/dismiss', [\App\Http\Controllers\City\NpcController::class, 'dismiss'])->middleware('throttle:api');

        // ---- /M3-NPC ----

        // ---- M3-ITEM ----(W3-A:制作 / 装备 / 卸下)

        // 制作:扣材料 + 建成一件工具实例(§7 的 crafting_source 与成本全部服务器判定)。
        // 与建造同档 throttle:api —— 它和建造一样是「花材料换实体」的经济写操作
        Route::post('/city/item/craft', [\App\Http\Controllers\City\ItemController::class, 'craft'])->middleware('throttle:api');

        // 装备 / 卸下:所有权 + 一件工具一栋楼互斥 + 单栋槽位上限(§7 / B2);卸下保留耐久
        Route::post('/city/item/equip', [\App\Http\Controllers\City\ItemController::class, 'equip'])->middleware('throttle:api');
        Route::post('/city/item/unequip', [\App\Http\Controllers\City\ItemController::class, 'unequip'])->middleware('throttle:api');

        // ---- /M3-ITEM ----

        // ---- M3-MARKET ----(W2-B:GET /market/prices 独立端点 + buy / sell,限流走独立 throttle:market)

        // 价目表:全服共享的只读端点,刻意不进城市快照(见 MarketPriceController 顶部注释)。
        // 与买卖同挂 throttle:market —— §48 明文「市场接口不能与普通 GET 使用完全一样的频率规则」
        Route::get('/market/prices', \App\Http\Controllers\City\MarketPriceController::class)->middleware('throttle:market');

        // 买入 / 卖出:完整安全链(停市 / 输入 / 可交易 / 幂等 / Revision / 结算 / 服务器算价 /
        // 手续费 + 滑点 + 成交量上限 + 移动平均 / 余额库存 / 仓储 / 行锁 / Invariant / 流水 / 审计)。
        // 入参不含成交价 —— 客户端不得提交价格(§45 / §66 / v3.2 §8.1)
        Route::post('/market/buy', [\App\Http\Controllers\City\MarketTradeController::class, 'buy'])->middleware('throttle:market');
        Route::post('/market/sell', [\App\Http\Controllers\City\MarketTradeController::class, 'sell'])->middleware('throttle:market');

        // ---- /M3-MARKET ----

        // ---- M3-EVENT ----(W3-B:GET /city/events + POST /city/events/resolve)

        // 事件列表:先跑一次结算再返回(事件是懒结算的,不跑就永远不触发)。
        // 限流挂 throttle:snapshot 而不是 api —— 它和 /api/city 一样会跑完整结算 + 多表联查,
        // 是最贵的 GET;§48 也明文要求事件接口不能与普通 GET 同一档频率
        Route::get('/city/events', [\App\Http\Controllers\City\EventController::class, 'index'])->middleware('throttle:snapshot');

        // 结算选项:§70 五道校验(归属 / ACTIVE / 未过期 / choice 合法 / 未领取)+ 幂等 + Revision + 审计。
        // 入参是 {event_instance_id, choice?} —— 客户端不提交任何奖励数值(§45 / §66)。
        // 与建造 / 招募同档 throttle:api:它同样是「一次点击 = 一次经济变化」的写操作
        Route::post('/city/events/resolve', [\App\Http\Controllers\City\EventController::class, 'resolve'])->middleware('throttle:api');

        // ---- /M3-EVENT ----

        // ---- M3-POWER ----(W4-A:电力系统若需要独立只读端点,放这里;不塞进城市快照)
        // ---- /M3-POWER ----

        // ---- M3-DEFENSE ----(W4-B:国防 / 威胁等级相关端点)
        // ---- /M3-DEFENSE ----

        // ================= M3 锚点结束 =================
    });
});

// 管理后台(CLAUDE §63 角色分级):
// 组级 'admin' 不带参数 = 兜底门槛(support 及以上才进得来,player 一律 403);
// 单个端点再挂具体权限,按最小权限收紧。权限表见 App\Support\Role。
Route::prefix('api/admin')->middleware(['auth:web', 'admin', 'throttle:api'])->group(function () {
    // 当前管理员身份:username/role/permissions,供后台按权限显隐 UI(任意管理角色可读自己的)
    Route::get('/me', [\App\Http\Controllers\Admin\AdminReadController::class, 'me']);

    // 只读:玩家列表 / 玩家详情(含城市摘要) / 审计日志
    Route::get('/players', [\App\Http\Controllers\Admin\AdminReadController::class, 'players'])->middleware('admin:read_player');
    Route::get('/players/{id}', [\App\Http\Controllers\Admin\AdminReadController::class, 'playerDetail'])->whereNumber('id')->middleware('admin:read_player');
    Route::get('/audit', [\App\Http\Controllers\Admin\AdminReadController::class, 'audit'])->middleware('admin:read_audit');

    // Definition 调整:某建筑三级可编辑字段快照 / 提交调整(allowlist + 审计 + 版本递增)。
    // 查看当前值是「调整流程」的第一步,与提交同挂 edit_definition:support / game_master 不碰游戏数值
    Route::get('/definitions/building-levels', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'buildingLevels'])->middleware('admin:edit_definition');
    // 写入端点叠 admin_write 限流(R1-B 走查补漏):与 market/item/event/settings/compensation 同一纪律,
    // 管理员账号被盗时批量改全服数值要先撞 20/min 上限
    Route::post('/definitions/building-level', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editBuildingLevel'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // NPC 定义调整(M3-D1):与建筑等级同一套机制(allowlist 字段 + 强制 reason + 审计 + 版本递增)
    Route::get('/definitions/npcs', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'npcs'])->middleware('admin:edit_definition');
    Route::post('/definitions/npc', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editNpc'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // 市场定义(M3-D3,v3.2 §8 的 26 行):查看 / 调整基础价、波动率、弹性、费率、流动性、价格区间。
    // 与建筑等级 / NPC 同权限(edit_definition):改一行基础价就是改全服价格,属最高风险的数值改动。
    // 全市场级的开关与系数(停市 / 手续费倍率 / 滑点系数 / 成交量上限)在 /api/admin/settings,不在这里
    Route::get('/definitions/market', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'marketDefinitions'])->middleware('admin:edit_definition');
    Route::post('/definitions/market', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editMarketDefinition'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // 工具定义(M3-D2,v3.2 §7 的 24 行):查看 / 调整耐久、效果值、拆解基数。
    // 与建筑等级 / NPC / 市场同权限(edit_definition):改一行 effect_value 就是改全服产量上限。
    // 全局规则(装备槽位数 / 每档耐久分钟数 / 预警阈值 / 制作与耐久开关)在 /api/admin/settings,不在这里
    Route::get('/definitions/items', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'items'])->middleware('admin:edit_definition');
    Route::post('/definitions/item', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editItem'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // 事件定义(M3-D4,v3.2 §9.2 的 30 行):查看 / 逐事件调整开关、权重、冷却、持续时间、效果强度。
    // 用户 2026-08-10 拍板③「所有事件必须在管理员后台可设定」的落点。
    // 与其它定义同权限(edit_definition):改一行权重就是改全服的事件频率。
    // 全局规则(触发概率 / 并发上限 / 离线补算上限 / 权重三修正系数)在 /api/admin/settings,不在这里
    Route::get('/definitions/events', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'events'])->middleware('admin:edit_definition');
    Route::post('/definitions/event', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editEvent'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // 建筑等级三个 JSON 列的**条目级**调整(W11-B):产出 / 投入速率与建造成本。
    // 与 building-level 同权限、同限流 —— 改一条 rate_per_min 就是改全服该建筑的产量,
    // 比七个外围列(工期 / 工人 / 维护)影响更大。只改已存在条目的数值,增删条目走迁移
    Route::post('/definitions/building-level-json', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editBuildingLevelJson'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // 建筑等级 Excel 导出 / 导入(W13-2):批量调整等级数值、为任意建筑补新等级行(等级上限已数据驱动)。
    // 导出是只读端点(与 GET building-levels 同权限);导入是批量写,叠 admin_write 限流 ——
    // 一次导入 = 一次事务 + 一次版本递增 + 一条汇总审计,行内校验与单格编辑器同一套 allowlist / 上限
    Route::get('/definitions/building-levels/export', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'exportBuildingLevels'])->middleware('admin:edit_definition');
    Route::post('/definitions/building-levels/import', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'importBuildingLevels'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // 科技定义(W11-B,v3.2 §4 的 50 行):查看 / 调整知识成本、研究时长。
    // 前置 / 解锁 / 时代 / 分支四列只读下发 —— 那是科技树拓扑,改了会造出环或死锁
    Route::get('/definitions/technologies', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'technologies'])->middleware('admin:edit_definition');
    Route::post('/definitions/technology', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editTechnology'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // 建筑定义(W11-B,v3.2 §3 的 94 行):查看 / 调整同类建造上限 max_count。
    // footprint 只读 —— 改占地会让存量建筑瞬间互相重叠
    Route::get('/definitions/buildings', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'buildings'])->middleware('admin:edit_definition');
    Route::post('/definitions/building', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editBuilding'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // NPC 等级曲线(W11-B,v3.2 §6.2 的 10 行):查看 / 调整升级经验、主技能加成、维护费减免上限。
    // 主键 level 只读 —— 改它会让 city_npcs.skill_level 指向的那一级查不到(静默失去全部加成)
    Route::get('/definitions/npc-skill-curve', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'npcSkillCurve'])->middleware('admin:edit_definition');
    Route::post('/definitions/npc-skill-curve', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editNpcSkillCurve'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // 时代升级门槛(W11-B,v3.2 §5.1 的 9 行):查看 / 调整七个数值门槛。
    // ⚠️ defense 一列同时是**国防威胁需求**的来源,改它会连带影响全服威胁等级(响应带 warning)。
    // buildings_json 只读 —— 必须建筑清单是升级路径拓扑,填一栋目标时代的建筑就是死锁
    Route::get('/definitions/era-requirements', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'eraRequirements'])->middleware('admin:edit_definition');
    Route::post('/definitions/era-requirements', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editEraRequirement'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // 管理员补偿(CLAUDE §80 / E7):查目标城市余额 / 提交补偿。
    // 权限 adjust_resource(game_master 及以上);写入端点再叠一层 admin_write 限流,
    // 「查」与「补」同权限:看得到余额才填得出 delta,不给低权角色留一个只读的经济窥探面
    Route::get('/compensation/lookup', [\App\Http\Controllers\Admin\AdminCompensationController::class, 'lookup'])->middleware('admin:adjust_resource');
    Route::post('/compensation', [\App\Http\Controllers\Admin\AdminCompensationController::class, 'compensate'])->middleware(['admin:adjust_resource', 'throttle:admin_write']);

    // 规则开关(game_settings):开关改变全服规则,与改数值同级 → edit_definition(admin 及以上)
    Route::get('/settings', [\App\Http\Controllers\Admin\AdminSettingController::class, 'index'])->middleware('admin:edit_definition');
    Route::post('/settings', [\App\Http\Controllers\Admin\AdminSettingController::class, 'update'])->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // ================= 运营端点(W11-C1)=================
    //
    // 与上面的 Definition 调整块分开:那一块回答「游戏数值该是多少」,这一块回答
    // 「现在服上发生了什么 / 谁该被处置」—— 两类改动的评审人和上线节奏都不一样。
    // 纯后端,UI 由下一波做。

    // 全服仪表盘:玩家 / 城市 / 资源 / 建筑 / NPC / 事件 / 今日后台操作的一屏数字。
    // 权限与玩家列表同档(read_player):它只是同一批只读数据的聚合视图,不含任何单个玩家的隐私字段。
    // 限流走组级 throttle:api —— 内部是常量 7 条聚合 SQL,与玩家规模无关(见 Controller 顶部纪律)
    Route::get('/dashboard', [\App\Http\Controllers\Admin\AdminReadController::class, 'dashboard'])->middleware('admin:read_player');

    // 单条审计详情:列表刻意不下发的 before/after/delta/metadata 四个 JSON 列在这里给全。
    // 与列表同权限(read_audit):能看列表就能看详情,拆权限只会让客服查一半案子要找人代劳
    Route::get('/audit/{id}', [\App\Http\Controllers\Admin\AdminReadController::class, 'auditDetail'])->whereNumber('id')->middleware('admin:read_audit');

    // 封禁 / 解禁:权限 ban_player(admin 及以上),写入端点一律叠 admin_write 限流 ——
    // 与补偿 / 定义调整同一纪律:管理员账号被盗时批量封号要先撞 20/min 上限。
    // **绝不删除玩家数据**:封禁只写 users.banned_at / ban_reason 两列(见 AdminBanController 顶部)
    Route::post('/players/{id}/ban', [\App\Http\Controllers\Admin\AdminBanController::class, 'ban'])->whereNumber('id')->middleware(['admin:ban_player', 'throttle:admin_write']);
    Route::post('/players/{id}/unban', [\App\Http\Controllers\Admin\AdminBanController::class, 'unban'])->whereNumber('id')->middleware(['admin:ban_player', 'throttle:admin_write']);

    // 手动触发事件(测试 / 线上复现):走与自然触发**同一条**落地路径,只跳过权重掷点与冷却,
    // 并发上限照常尊重。权限 edit_definition —— 强制触发会真实改玩家资源,风险等同于改一行事件定义。
    // 刻意不加 game_settings 开关(理由见 AdminEventTriggerController 顶部;生产要不要暴露写进 deploy checklist)
    Route::post('/events/trigger', \App\Http\Controllers\Admin\AdminEventTriggerController::class)->middleware(['admin:edit_definition', 'throttle:admin_write']);

    // ================= 运营端点结束 =================
});

// 仅测试环境:用于验证异常渲染,绝不在生产暴露
if (app()->environment('testing')) {
    Route::get('/api/_boom', function () {
        throw new \RuntimeException('boom');
    });

    Route::get('/api/_forbidden', function () {
        abort(403);
    });

    Route::get('/api/_csrf', function () {
        throw new \Illuminate\Session\TokenMismatchException();
    });
}
