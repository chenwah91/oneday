<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Definition\GameDataVersion;
use App\Game\Population\WorkerService;
use App\Game\Simulation\SimulationService;
use App\Game\Technology\TechService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 城市只读快照
class CityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $city = CityFactory::createForUser($user); // 幂等:兜底老账号

        $sim = SimulationService::simulate($city);

        // 科技懒结算(M2-B1):把 finished_at 已到点的在研项翻成 unlocked。
        // 刻意不放进 SimulationService —— 解锁不产生资源变化,不该占结算内核的一段;
        // 放在结算之后是为了让「知识刚够、这一秒才产出」的场景也能按最新库存判断(研究端点锁内另有一次)
        TechService::settleFinished((int) $city->id);

        $city = $city->fresh();

        $resources = $city->resources()->pluck('amount', 'resource_id')
            ->map(fn ($a) => (float) $a)->all();

        // 建筑列表联查该级的 worker_required:前端工人面板要「已分配 / 需求」两个数才画得出用工率,
        // 只给 assigned 会逼前端再拉一次 Definition 接口(§38 反 N+1)
        $buildings = DB::table('city_building_instances as ci')
            ->leftJoin('building_level_definition as bl', function ($j) {
                $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
            })
            ->where('ci.city_id', $city->id)
            ->orderBy('ci.id')
            ->get(['ci.id', 'ci.building_id', 'ci.level', 'ci.x', 'ci.y', 'ci.status', 'ci.assigned_workers', 'bl.worker_required'])
            ->map(fn ($b) => [
                'id' => (int) $b->id, 'building_id' => $b->building_id, 'level' => (int) $b->level,
                'x' => (int) $b->x, 'y' => (int) $b->y, 'status' => $b->status,
                'assigned_workers' => (int) $b->assigned_workers,
                'worker_required'  => (int) $b->worker_required,
            ])->all();

        // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板):
        // 这里是「结算内核内部数组」→「HTTP 契约」的唯一转换处,SimulationService 的内部键名保持原样不动
        return ApiResponse::ok(['data' => [
            // data_version:当前全局数值版本(§64),前端可据此判断本地缓存的 Definition 是否过期
            'data_version' => GameDataVersion::current(),
            // server_time:服务器权威时间(§11.1),施工倒计时等一切计时都要以它对时,绝不能用客户端时间
            'server_time'  => now()->toIso8601String(),
            'city' => [
                'id'                  => $city->id,
                'name'                => $city->name,
                'revision'            => $city->revision,
                'population'          => $city->population,
                'population_capacity' => $sim['populationCapacity'],
                // 人口名义增减(人/分钟,§10.3 口径,未夹人口容量):HUD 的人口趋势用
                'population_growth_per_min' => $sim['populationGrowthPerMin'],
                // 劳动力(§10.4):可用 = floor(人口 × 0.60);已分配 = 全城各建筑 assigned_workers 之和
                'available_workers'   => SimulationService::availableWorkers((int) $city->population),
                'assigned_workers'    => WorkerService::totalAssigned((int) $city->id),
                // 民生三值(§10.2 / §10.8):happiness 是落库的持久状态,health / security 是当场派生的覆盖率映射
                'happiness'           => $sim['happiness'],
                'health'              => $sim['health'],
                'security'            => $sim['security'],
                'money'               => (float) $city->money,
                'map_width'           => $city->map_width,
                'map_height'          => $city->map_height,
                'storage_capacity'    => $sim['storageCapacity'],
                'last_simulated_at'   => $city->last_simulated_at->toIso8601String(),
                'resources'           => $resources,
                'rates_per_min'       => $sim['ratesPerMin'],
                'buildings'           => $buildings,
                // 科技(M2-B1):已解锁 tech_id 列表 + 在研项 + 派生的时代进度。
                // 定义(名称/费用/时长/前置)不在快照里,前端从 /api/definitions/technologies 单独取一次
                'technologies'        => TechService::snapshot((int) $city->id),
            ],
        ]]);
    }
}
