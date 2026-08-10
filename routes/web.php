<?php

use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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

    // CSRF cookie:公开接口,供 SPA 首次取用 XSRF-TOKEN
    Route::get('/csrf-cookie', [\App\Http\Controllers\Auth\SessionController::class, 'csrfCookie']);

    Route::middleware('auth:web')->group(function () {
        // 当前登录用户
        Route::get('/me', [\App\Http\Controllers\Auth\SessionController::class, 'me']);

        // 登出:失效 session 并写审计
        Route::post('/auth/logout', [\App\Http\Controllers\Auth\SessionController::class, 'logout']);

        // 城市只读快照:先结算再返回聚合状态。
        // 独立限流(每用户每分钟 30 次):快照会跑结算与多表联查,是最贵的 GET(CLAUDE §48)
        Route::get('/city', [\App\Http\Controllers\City\CityController::class, 'show'])->middleware('throttle:snapshot');

        // 可建建筑列表:联查 L1 成本/产出,供前端建筑面板显示
        Route::get('/definitions/buildings', [\App\Http\Controllers\City\DefinitionController::class, 'buildings'])->middleware('throttle:api');

        // 资源定义:code → 中文显示名(资源主键已英文化,前端显示名一律从这里取)
        Route::get('/definitions/resources', [\App\Http\Controllers\City\DefinitionController::class, 'resources'])->middleware('throttle:api');

        // 科技定义:50 个节点的时代/分支/费用/时长/前置,供前端科技面板显示
        Route::get('/definitions/technologies', [\App\Http\Controllers\City\DefinitionController::class, 'technologies'])->middleware('throttle:api');

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
    Route::post('/definitions/building-level', [\App\Http\Controllers\Admin\AdminDefinitionController::class, 'editBuildingLevel'])->middleware('admin:edit_definition');

    // 管理员补偿(CLAUDE §80 / E7):查目标城市余额 / 提交补偿。
    // 权限 adjust_resource(game_master 及以上);写入端点再叠一层 admin_write 限流,
    // 「查」与「补」同权限:看得到余额才填得出 delta,不给低权角色留一个只读的经济窥探面
    Route::get('/compensation/lookup', [\App\Http\Controllers\Admin\AdminCompensationController::class, 'lookup'])->middleware('admin:adjust_resource');
    Route::post('/compensation', [\App\Http\Controllers\Admin\AdminCompensationController::class, 'compensate'])->middleware(['admin:adjust_resource', 'throttle:admin_write']);

    // 规则开关(game_settings):开关改变全服规则,与改数值同级 → edit_definition(admin 及以上)
    Route::get('/settings', [\App\Http\Controllers\Admin\AdminSettingController::class, 'index'])->middleware('admin:edit_definition');
    Route::post('/settings', [\App\Http\Controllers\Admin\AdminSettingController::class, 'update'])->middleware(['admin:edit_definition', 'throttle:admin_write']);
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
