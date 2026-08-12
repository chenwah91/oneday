<?php

namespace App\Http\Controllers\Admin;

use App\Game\Event\EventRuntimeService;
use App\Game\Simulation\SimulationService;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 管理员手动触发事件(W11-C1 任务5,测试 / 线上复现用)。
//
// 权限 edit_definition(admin 及以上):强制触发会真实改变玩家的资源与状态,
// 风险等同于改一行事件定义,所以与「改数值」同一档,不给 game_master / support。
//
// ⚠️ 部署提醒(刻意**不加** game_settings 开关):
// 这个端点没有独立的总开关。要不要在生产环境暴露,由部署清单决定 ——
// 加开关等于多一个「以为关了其实没关」的状态,而权限已经收到 admin 及以上、
// 每一次触发都强制填 reason 并留两条审计,再加一层布尔开关只会稀释责任。
// deploy checklist 里应写明:生产若不需要复现能力,直接在反向代理 / 路由层屏蔽本路由。
//
// Controller 只做三件事(CLAUDE §11):输入 allowlist 校验 → 定位城市 → 调服务层。
// GameRuleException 不在此捕获,交 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)。
class AdminEventTriggerController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city_id'  => ['required', 'integer', 'min:1'],
            'event_id' => ['required', 'string', 'max:32'],
            // reason 与补偿 / 定义调整同口径:至少 5 字,上限 80 对齐 audit_logs.reason_code 列宽
            'reason'   => ['required', 'string', 'min:5', 'max:80'],
        ]);

        $city = City::find((int) $data['city_id']);
        if ($city === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        // 照玩家路径的纪律:先跑一次完整结算 + 事件懒结算,再触发。
        //   · SimulationService::simulate 把这座城推进到此刻(它自己锁城市行);
        //   · EventRuntimeService::settle 补算这段时间该自然触发的事件、并把过期实例翻牌
        //     —— 不先跑它,管理员会在一堆「过期但还挂着 active」的实例上撞到假的并发上限。
        // 随后 forceTrigger 再自锁一次城市行,在锁内重新结算后落地(见该方法注释)
        $sim = SimulationService::simulate($city);
        EventRuntimeService::settle($city, $sim);

        return ApiResponse::ok(['data' => EventRuntimeService::forceTrigger(
            $city->fresh(),
            trim((string) $data['event_id']),
            (int) $request->user()->id,
            trim((string) $data['reason'])
        )]);
    }
}
