<?php

namespace App\Game\Item;

use App\Game\Building\ConstructionService;
use App\Game\Simulation\SimConstants;
use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\GameSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 工具耐久的懒结算(v3.2 §7 + backlog §4.3 / §9 B1 / B4)。
//
// 为什么不放进 SimulationService:
//   §7 的耐久要**写** city_items,而 M3 只允许内核改一处(总线的通用支出消费点);
//   项目里已经有两份现成的同款做法 —— TechService::settleFinished 与 NpcRuntimeService::settle:
//   懒结算挂在快照 / 端点上,不占结算内核的一段,也不新增每分钟 tick。本类照抄那条路径。
//
// 时钟:cities.item_settled_at 单独一列(既不复用 last_simulated_at 也不复用 npc_settled_at ——
// 两个系统共用一个时钟会互相吃掉对方的经过时间)。离线封顶沿用 SimConstants::MAX_OFFLINE_SECONDS。
//
// 「工作分钟」的口径(§7「生产 Tick 按工作时间扣耐久」+ backlog §4.3「只在建筑实际工作的分钟里扣
// (停产 / 半停工 / 缺料的建筑不扣)」),逐条落成四道闸:
//   ① 实例必须是 active 且完工戳已清(constructing / upgrading 的楼不生产);
//   ② 用工闸门:worker_gate_enabled 打开且该级需要工人时,一个工人都没派 = 不工作(§10.4 workerFactor = 0);
//   ③ 欠费半停工:本次结算判定为欠费($sim['maintenanceArrears'])→ 全城这一段都不扣;
//   ④ 缺料:配方里任何一种原料**结算后库存为 0** = 这栋楼这一段没料可吃,不扣。
// 四道闸一律取「宁可少扣」的方向 —— 玩家花材料做的工具被多扣耐久,是最容易被投诉且最难自证的一类账。
final class ItemRuntimeService
{
    // 快照路径入口:自开事务 + 锁城市行。$sim = 本次 SimulationService 的结算结果
    // (欠费状态与结算后库存都从它取,不再另算一份,避免两套口径)
    public static function settle(City $city, array $sim): void
    {
        DB::transaction(function () use ($city, $sim) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            self::settleLocked($locked, $sim, now());
        });
    }

    // 锁内入口:调用方必须已在事务内对该 cities 行 lockForUpdate(工具三个端点走这条)。
    // 与 settle() 的唯一差别就是不自开事务、不再加锁
    public static function settleLocked(object $lockedCity, array $sim, CarbonInterface $now): void
    {
        $last = $lockedCity->item_settled_at !== null
            ? Carbon::parse($lockedCity->item_settled_at)
            : Carbon::parse($lockedCity->last_simulated_at);

        $elapsed = max(0, $now->getTimestamp() - $last->getTimestamp());
        // 离线封顶:超出部分不补扣,但时钟照样推进到 $now(否则积压会被反复重算)
        $elapsed = min($elapsed, SimConstants::MAX_OFFLINE_SECONDS);

        DB::table('cities')->where('id', $lockedCity->id)->update(['item_settled_at' => $now]);
        // 内存里的行同步推进:同一请求内如果再次结算(端点里先 settleLocked 再走业务),
        // 不该拿着旧时间戳把同一段时间扣第二遍
        $lockedCity->item_settled_at = $now;

        $minutes = $elapsed / 60.0;
        if ($minutes <= 0) {
            return;
        }

        if (GameSetting::get(GameSetting::ITEM_DURABILITY_ENABLED) !== true) {
            return; // 运营救急:关掉后耐久不再递减(时钟仍然推进,开回来时不会突然补扣一大段)
        }

        // 欠费半停工:整城这一段都不算「在工作」(backlog §4.3「半停工的建筑不扣」)
        if ((bool) ($sim['maintenanceArrears'] ?? false)) {
            return;
        }

        $items = DB::table('city_items as ci')
            ->join('item_definition as id', 'ci.item_id', '=', 'id.item_id')
            ->where('ci.city_id', $lockedCity->id)
            ->where('ci.status', ItemCode::STATUS_EQUIPPED)
            ->whereNotNull('ci.equipped_instance_id')
            // uses 档(medical_item)不随时间递减:它按「使用次数」消耗,而「使用」这个动作
            // 要等医疗类消费点登记之后才存在(见 items.json 的 unmapped_zh)
            ->where('id.durability_mode', ItemCode::DURABILITY_MODE_WORK_MINUTES)
            ->lockForUpdate()
            ->get(['ci.id', 'ci.item_id', 'ci.durability_left', 'ci.equipped_instance_id', 'id.durability_tier']);

        if ($items->isEmpty()) {
            return;
        }

        $working = self::workingInstances(
            (int) $lockedCity->id,
            $items->pluck('equipped_instance_id')->unique()->all(),
            $sim
        );

        foreach ($items as $item) {
            if (! isset($working[(int) $item->equipped_instance_id])) {
                continue; // 这栋楼这一段没在工作 → 一点耐久都不扣
            }

            $perPoint = ItemCode::minutesPerDurabilityPoint((string) $item->durability_tier);
            $left = round(max(0.0, (float) $item->durability_left - $minutes / $perPoint), 2);

            if ($left > 0) {
                DB::table('city_items')->where('id', $item->id)
                    ->update(['durability_left' => $left, 'updated_at' => $now]);

                continue;
            }

            // B4 已批:耐久归零 = **损毁消失**(需重新制作)。
            // 行保留只为可追溯(与 NpcCode::STATUS_LEFT 同一处理),同时自动卸下 ——
            // 留在槽位上会让玩家「换不上新工具」却看不出原因
            DB::table('city_items')->where('id', $item->id)->update([
                'durability_left'      => 0,
                'status'               => ItemCode::STATUS_BROKEN,
                'equipped_instance_id' => null,
                'updated_at'           => $now,
            ]);

            AuditLogger::record(AuditAction::ITEM_BROKEN, 'success', [
                // 不是玩家操作:actor 是 system,但 user_id / city_id 照记,方便按玩家回查
                'actor_type' => 'system', 'actor_id' => null,
                'user_id' => $lockedCity->user_id, 'city_id' => $lockedCity->id,
                'entity_type' => 'city_item', 'entity_id' => (string) $item->id,
                'reason_code' => 'DURABILITY_EXHAUSTED',
                'before_json' => [
                    'status' => ItemCode::STATUS_EQUIPPED,
                    'durability_left' => (float) $item->durability_left,
                    'equipped_instance_id' => (int) $item->equipped_instance_id,
                ],
                'after_json'  => ['status' => ItemCode::STATUS_BROKEN, 'durability_left' => 0, 'equipped_instance_id' => null],
                'delta_json'  => ['durability_left' => -(float) $item->durability_left],
                'metadata_json' => [
                    'item_id' => $item->item_id,
                    'tier' => (string) $item->durability_tier,
                    'minutes' => round($minutes, 4),
                ],
            ]);
        }
    }

    // 「这一段在工作」的建筑实例集合(instance_id => true)。
    // 一次联查取齐状态 / 用工 / 配方,循环内零查库(承接 M2 的 N+1 纪律)
    private static function workingInstances(int $cityId, array $instanceIds, array $sim): array
    {
        if ($instanceIds === []) {
            return [];
        }

        $rows = DB::table('city_building_instances as ci')
            ->join('building_level_definition as bl', function ($j) {
                $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
            })
            ->where('ci.city_id', $cityId)
            ->whereIn('ci.id', $instanceIds)
            ->get(['ci.id', 'ci.status', 'ci.construction_finished_at', 'ci.assigned_workers',
                'bl.worker_required', 'bl.input_json']);

        $gateEnabled = (bool) GameSetting::get(GameSetting::WORKER_GATE_ENABLED, true);
        // 结算后的库存:缺料判定用它,与内核那一段用的是同一批数字
        $resources = (array) ($sim['resources'] ?? []);

        $working = [];

        foreach ($rows as $row) {
            // ① 只有已完工、且完工戳已清的 active 实例才在生产(与内核 applyLocked 的取数条件一致)
            if ($row->status !== ConstructionService::STATUS_ACTIVE || $row->construction_finished_at !== null) {
                continue;
            }

            // ② 用工闸门(§10.4):需要工人却一个都没派 → workerFactor = 0 → 这栋楼没在工作
            if ($gateEnabled && (int) $row->worker_required > 0 && (int) $row->assigned_workers <= 0) {
                continue;
            }

            // ④ 缺料:配方里任何一种原料库存为 0,这一段就吃不上料
            //    (内核那边是「按满足率打折」,这里取最保守的二值判定 —— 宁可少扣)
            $starved = false;
            foreach (json_decode($row->input_json ?: '[]', true) ?: [] as $input) {
                if ((float) ($resources[$input['resource']] ?? 0) <= 0) {
                    $starved = true;
                    break;
                }
            }
            if ($starved) {
                continue;
            }

            $working[(int) $row->id] = true;
        }

        return $working;
    }
}
