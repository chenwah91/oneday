<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\City\EraService;
use App\Game\Definition\GameDataVersion;
use App\Game\NPC\NpcRuntimeService;
use App\Game\NPC\NpcService;
use App\Game\Population\WorkerService;
use App\Game\Simulation\SimulationService;
use App\Game\Technology\TechService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        // NPC 运行时懒结算(M3-D1:XP / 士气 / 离职 / 自然增长)。
        // 与科技懒结算同一条路径、同一个理由:这四件事都不产生资源变化,不该占结算内核的一段;
        // 它们要写 city_npcs,而 M3 只允许内核改一处(总线的通用支出消费点)。
        // 位置在结算之后:士气要用结算后的幸福与欠费状态,自然增长要用结算后的人口与人口容量
        NpcRuntimeService::settle($city, $sim);

        // 随机事件懒结算(M3-D4:到期作废 + 资格窗口掷点 + 自动效果)。
        // 同一条懒结算路径,位置刻意排在最后:事件的触发条件要读**结算后**的
        // 人口 / 幸福 / 治安 / 财政预警,正向事件的「直接发资源」也要按结算后的产能折算。
        // 触发写的持续型 modifier 从**下一次**结算开始生效 —— 这是懒结算的固有语义,
        // 不是漏账:本次窗口早已按当时不存在的事件算完了
        \App\Game\Event\EventRuntimeService::settle($city, $sim);

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
            ->get(['ci.id', 'ci.building_id', 'ci.level', 'ci.x', 'ci.y', 'ci.status',
                'ci.construction_finished_at', 'ci.assigned_workers', 'bl.worker_required'])
            ->map(fn ($b) => [
                'id' => (int) $b->id, 'building_id' => $b->building_id, 'level' => (int) $b->level,
                'x' => (int) $b->x, 'y' => (int) $b->y, 'status' => $b->status,
                // 施工 / 升级完工时刻(M2-C5):服务器权威时间,前端拿它做视觉倒计时,
                // 到点后仍要拉快照确认(客户端时间不可信,§16.3)。NULL = 没有在进行的工程
                'construction_finished_at' => $b->construction_finished_at !== null
                    ? Carbon::parse($b->construction_finished_at)->toIso8601String()
                    : null,
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
                // 财政 / 治理(§10.5 / §10.6):都是派生值,不落库。
                // tax_income_per_min 与 rates_per_min 同为「最后一段口径」的速率;
                // governance.load 越界会按四档压低 efficiency,直接打折税收
                'tax_income_per_min'  => $sim['taxIncomePerMin'],
                // 维护资金速率(资金/分钟):财政预警的分母,也是玩家判断「还能撑多久」的唯一依据
                'maintenance_money_per_min' => $sim['maintenanceMoneyPerMin'],
                // 财政预警(§10.5)'none' | 'yellow' | 'red':资金可支撑维护 < 10 分钟转黄、< 3 分钟转红。
                // 服务端派生而不是让前端自己拿资金除维护 —— 阈值属于数值规格,不能有第二份口径
                'fiscal_warning'      => $sim['fiscalWarning'],
                'governance'          => [
                    'load'       => $sim['governanceLoad'],
                    'efficiency' => $sim['governanceEfficiency'],
                    'capacity'   => $sim['governanceCapacity'],
                ],
                // 物流(§10.7 / §11 综合面板「运输使用率」):load 是 §11 的 transport_load_rate 口径(比值,不是百分数),
                // factor 就是七乘区里 logistics 那一格的实际值,congestion 对应 §10.7 的拥堵警报
                'logistics'           => [
                    'capacity'       => $sim['transportCapacity'],
                    'demand_per_min' => $sim['transportDemandPerMin'],
                    'load'           => $sim['transportLoad'],
                    'factor'         => $sim['logisticsFactor'],
                    'congestion'     => $sim['transportCongestion'],
                ],
                'map_width'           => $city->map_width,
                'map_height'          => $city->map_height,
                'storage_capacity'    => $sim['storageCapacity'],
                'last_simulated_at'   => $city->last_simulated_at->toIso8601String(),
                'resources'           => $resources,
                'rates_per_min'       => $sim['ratesPerMin'],
                'buildings'           => $buildings,
                // 时代(M2-B6):当前时代 + 下一时代的逐维升级条件(已是最高时代时 next 为 null)。
                // 条件里的当前值全部取自本次结算结果 $sim,与升级端点锁内判定的口径完全一致
                'era'                 => EraService::snapshot($city, $sim),
                // 科技(M2-B1):已解锁 tech_id 列表 + 在研项 + 时代进度(时代读 cities.era_order)。
                // 定义(名称/费用/时长/前置)不在快照里,前端从 /api/definitions/technologies 单独取一次
                'technologies'        => TechService::snapshot((int) $city->id, (int) $city->era_order),

                // ================= M3 共享文件锚点(D0.4,W1-A 一次性预置)=================
                //
                // 纪律(backlog §10.2):每个任务只在自己系统的锚点块内增删,
                // 禁止重排、禁止格式化他人行、禁止在锚点外改动。锚点是纯注释,预置本身零行为变化。
                //
                // 另一条口径:快照体积必须可控(§15「避免每次返回完整城市」)。
                // 各系统往这里加字段前先问一句「这个数据能不能走自己的独立端点」——
                // 市场就是因为这条被明确挡在快照之外的(见下面的 M3-MARKET 锚点)。

                // ---- M3-NPC ----(W2-A:NPC 摘要 / 未分配徽标 / 工资口粮速率)
                // 已招募清单 + 派驻关系(building_instance_id => [city_npc_id…])。
                // 为什么放进快照而不是独立端点:NPC 数量是个位到几十的量级(自然增长有上限、
                // 招募要花钱),体积可控;而建筑详情面板要画「NPC 槽位」区块时必须和建筑列表同一帧,
                // 拆成两个端点反而会出现「楼已经在了、人还没到」的闪烁。
                // 定义数据(名称 / 特性 / 等级曲线)不在这里,前端另取一次即可
                'npcs'                => NpcService::snapshot((int) $city->id),
                // ---- /M3-NPC ----

                // ---- M3-ITEM ----(W3-A:建筑装备摘要 / 耐久预警)
                // 已持有工具清单 + 装备关系(building_instance_id => [city_item_id…])。
                // 为什么放进快照而不是独立端点:与 NPC 同一条理由 —— 工具数量是个位到几十的量级
                // (每件都要材料 + 建筑前置),体积可控;而建筑详情面板要画「装备」区块时必须和
                // 建筑列表同一帧,拆成两个端点反而会出现「楼已经在了、工具还没到」的闪烁。
                // 定义数据(名称 / 效果 / 成本)不在这里,前端另取一次即可。
                // ItemService::snapshot 内部会先跑一次耐久懒结算(理由见该方法的注释:
                // CityController 只允许在锚点内插行,而耐久必须在读 city_items 之前结清)
                'items'               => \App\Game\Item\ItemService::snapshot($city, $sim),
                // ---- /M3-ITEM ----

                // ---- M3-MARKET ----
                // **本锚点刻意留空**:市场信息走独立端点 GET /api/market/prices,不塞进城市快照
                // (backlog §5.3 / §10.2:既避免 CityController 成为两个 agent 的争抢点,也避免快照体积失控)。
                // W2-B 不得在此插入任何字段。
                // ---- /M3-MARKET ----

                // ---- M3-EVENT ----(W3-B:active 事件实例数 / 最近一条通知,详情走 GET /api/city/events)
                // 只给「有几个事件生效中 + 它们的名字与到期时刻」——足够 HUD 打红点与做倒计时。
                // 选项文案、掷点结果、未生效的 unmapped 清单都在独立端点里,不塞进快照(§15 体积可控)
                'events'              => \App\Game\Event\EventService::summary((int) $city->id),
                // ---- /M3-EVENT ----

                // ---- M3-POWER ----(W4-A:发电 / 耗电 / powerFactor)
                // 电力(§3.3 energyFactor + §8 RS017 capacity_contract + 9.F4「流量不做库存」)。
                // 与 logistics 块逐字同构:capacity 是分子、demand 是分母、factor 就是七乘区里
                // power 那一格的实际值、shortage 对应物流的 congestion。
                // electricity **不在 resources 里**(它不是库存资源),想看电网只能看这一块。
                //   available_per_min 是事件减益(EVT_BLACKOUT)之后的可用发电,capacity 是名义装机;
                //   usage_rate 用名义装机作分母(经营指标不该被断电本身推高,见 PowerService 的注释)
                'power'               => [
                    'capacity_per_min'  => $sim['powerCapacityPerMin'],
                    'available_per_min' => $sim['powerAvailablePerMin'],
                    'demand_per_min'    => $sim['powerDemandPerMin'],
                    'spare_per_min'     => $sim['powerSparePerMin'],
                    'usage_rate'        => $sim['powerUsageRate'],
                    'factor'            => $sim['powerFactor'],
                    'shortage'          => $sim['powerShortage'],
                    'event_pct'         => $sim['powerEventPct'],
                ],
                // ---- /M3-POWER ----

                // ---- M3-DEFENSE ----(W4-B:threat_level 与国防区块,§11 的两个字段)
                // 国防(§11 的 defense_score / threat_level + §17「国防值 + 威胁等级」)。
                // 派生值,不落库 —— 与 health / security 同一条口径:
                //   defense_score      有效国防值 =(建筑口径 + 工具/NPC flat)×(1 + NPC/事件 pct)
                //   defense_score_base 建筑口径(内核从 output_json 聚合的容量值),两者并列给出,
                //                      玩家才分得清「常备城防」与「临时增援」
                //   threat_demand      §5.1「国防最低」× 全局倍率 ×(1 + 事件抬升),来源是 EraService(单一来源)
                //   threat_level       low / medium / high(§11 的 enum),按覆盖率分档,阈值后台可调
                'defense'             => \App\Game\Defense\DefenseService::snapshot($city, $sim),
                // ---- /M3-DEFENSE ----

                // ================= M3 锚点结束 =================
            ],
        ]]);
    }
}
