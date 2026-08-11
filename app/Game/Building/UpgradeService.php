<?php

namespace App\Game\Building;

use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\Idempotency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 升级:L1→L2→L3;严格所有权校验。
//
// M2-C5 起改为计时升级(v3.2 §16.3「B3 = 做」):
//   下单 → 即时扣费 + status = upgrading + construction_finished_at = now + 下一级 duration_seconds
//   → 到点由 SimulationService::applyLocked 里的懒完工翻正成 active 并 level + 1。
//
// level 列在升级期间保持旧等级,真正写级的唯一落点是 ConstructionService::settleFinished。
// 这不是"升级期间照旧生产":v3.2 §3.2 明确「Level 2/3 升级时建筑进入 upgrading 状态:生产建筑默认暂停生产」,
// 停产由结算内核的 status = active 过滤天然实现(upgrading 不在生产集合里)。
// 保留旧等级是为了取消 / 拆除时算得清返还:取消要退回旧级,拆除要按「已完工等级」算 50%。
class UpgradeService
{
    public static function upgrade(City $city, int $instanceId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        // 所有权:先全局查实例(不能只在本城范围内查,否则"不存在"与"不属于本城"无法区分)
        $inst = DB::table('city_building_instances')->where('id', $instanceId)->first();
        if (! $inst) {
            throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
        }
        if ((int) $inst->city_id !== (int) $city->id) {
            AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'reason_code' => 'NOT_OWNER',
            ]);
            throw new GameRuleException(ErrorCode::FORBIDDEN, 403);
        }

        // 请求指纹:只含业务参数,不含 expected_revision(重试时 revision 可能已变)
        $requestHash = Idempotency::hash(AuditAction::BUILDING_UPGRADE, ['instanceId' => $instanceId]);

        // 幂等:同一 user+key+action+参数已处理则直接成功返回(不重复扣费/升级),与 BuildService 对齐;key 被复用则 409
        if ($idempotencyKey !== null) {
            $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::BUILDING_UPGRADE, $requestHash);
            if ($existing) {
                return self::snapshot($city->fresh(), $instanceId);
            }
        }

        return DB::transaction(function () use ($city, $instanceId, $expectedRevision, $idempotencyKey, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后重新校验,关闭"锁前检查、锁后写入"之间的并发窗口(TOCTOU),与 BuildService 对齐
            if ($idempotencyKey !== null) {
                $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::BUILDING_UPGRADE, $requestHash);
                if ($existing) {
                    return self::snapshot($city->fresh(), $instanceId);
                }
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            $now = now();

            // 锁内先跑 Time Delta 结算(CLAUDE §51):
            // 1) 不结算就扣款,玩家可用"离线期间已被吃掉的旧快照资源"升级;
            // 2) 不结算就升级,新等级会追溯生产升级之前的时段。
            // 这一步同时会把到点的施工/升级翻正(懒完工),所以下面读到的 status/level 一定是最新的
            $sim = SimulationService::applyLocked($locked, $now);

            // 加锁后重新读取实例:防止并发拆除导致的幽灵升级(instance 已不存在则视为 NOT_FOUND)
            $inst = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)->first();
            if (! $inst) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            // 施工中 / 已在升级中的建筑不能再下升级单(v3.2 §16.3 的队列语义:一栋楼同时只有一项工程)。
            // 复用 BUILDING_LIMIT_REACHED 会与"已满级"混淆,这里用 VALIDATION_ERROR:
            // 客户端状态过期才会发出这种请求,刷新快照即可看到真实状态
            if ($inst->status !== ConstructionService::STATUS_ACTIVE) {
                throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
            }

            if ((int) $inst->level >= 3) {
                throw new GameRuleException(ErrorCode::BUILDING_LIMIT_REACHED, 422);
            }

            $currentLevel = (int) $inst->level;
            $nextLevel = $currentLevel + 1;
            $lvl = DB::table('building_level_definition')->where('building_id', $inst->building_id)->where('level', $nextLevel)->first();
            if (! $lvl) {
                throw new GameRuleException(ErrorCode::INVALID_BUILDING, 422);
            }
            $cost = json_decode($lvl->cost_json, true) ?: [];

            // 资源足额:一律用结算后的最新余额(资金 money 单列在 cities.money)
            foreach ($cost as $res => $amt) {
                $have = $res === ResourceCode::MONEY ? (float) $sim['money'] : (float) ($sim['resources'][$res] ?? 0);
                if ($have < $amt) { throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422); }
            }

            // 扣资源(§16.3「资源在事务内扣除」:计时期间资源已经付掉,取消才按 70% 退)
            $delta = [];
            foreach ($cost as $res => $amt) {
                if ($res === ResourceCode::MONEY) { DB::table('cities')->where('id', $city->id)->decrement('money', $amt); }
                else { DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $res)->decrement('amount', $amt); }
                $delta[$res] = -$amt;
            }

            // 影响行数校验:防止实例在扣款与写状态之间被并发拆除,产生"扣了钱但没开工"的幽灵升级。
            // status 也进 where:并发的第二次升级下单只有一方能把 active 改成 upgrading
            // (升级不自动调整工人:派工由玩家自理,§10.4 用户裁决 2026-08-10;超编对产出无害,workerFactor 封顶 1)
            // 施工加速(D0.3 的 construction_speed_pct,消费点在 ConstructionService):
            // 与建造同一条通道 —— §7 / §6.3 的文案是「建造速度」,升级同样是施工
            $baseSeconds = max(0, (int) $lvl->duration_seconds);
            $durationSeconds = ConstructionService::plannedSeconds((int) $city->id, $baseSeconds);
            $finishedAt = $now->copy()->addSeconds($durationSeconds);
            $affected = DB::table('city_building_instances')
                ->where('id', $instanceId)->where('city_id', $city->id)
                ->where('status', ConstructionService::STATUS_ACTIVE)
                ->update([
                    'status'                   => ConstructionService::STATUS_UPGRADING,
                    'construction_finished_at' => $finishedAt,
                    'updated_at'               => $now,
                ]);
            if ($affected === 0) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            // 不变量:资源不为负(扣前已校验,双保险)
            $neg = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($neg > 0 || (float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::BUILDING_UPGRADE, $requestHash);
            }

            AuditLogger::record(AuditAction::BUILDING_UPGRADE, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                // before/after 记的是本次 Mutation 真正改到的字段:状态与目标等级。
                // 等级本身要等完工才 +1,所以 after.level 仍是旧级,target_level 才是这次买的东西
                'before_json' => ['level' => $currentLevel, 'status' => ConstructionService::STATUS_ACTIVE],
                'after_json'  => ['level' => $currentLevel, 'status' => ConstructionService::STATUS_UPGRADING],
                'delta_json' => $delta,
                'idempotency_key' => $idempotencyKey,
                'metadata_json' => [
                    'buildingId'      => $inst->building_id,
                    'targetLevel'     => $nextLevel,
                    // durationSeconds 记的是**实际**工期(已含施工加速);baseDurationSeconds 是定义值
                    'durationSeconds' => $durationSeconds,
                    'baseDurationSeconds' => $baseSeconds,
                    'finishedAt'      => $finishedAt->toIso8601String(),
                ],
            ]);

            return [
                'revision'  => $newRevision,
                'building'  => [
                    'id'                       => $instanceId,
                    'level'                    => $currentLevel,
                    'target_level'             => $nextLevel,
                    'status'                   => ConstructionService::STATUS_UPGRADING,
                    'construction_finished_at' => $finishedAt->toIso8601String(),
                ],
                'resources' => DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
                'money'     => (float) DB::table('cities')->where('id', $city->id)->value('money'),
                'delta'     => $delta,
            ];
        });
    }

    // ---- 取消升级(M2-C5,v3.2 §3.2 / §16.3) ----

    // 只有 upgrading 的实例可以取消;退还该次升级材料的 70%(资金不返还),状态回 active、完工戳清空。
    // 安全链与 upgrade 完全一致:所有权 → 幂等 → 事务 → 行锁 → 幂等复检 → Revision → 锁内结算
    //   → 规则校验 → 退款(夹仓储上限) → 不变量 → 审计 → revision + 1
    public static function cancel(City $city, int $instanceId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        // 所有权:先全局查实例(不能只在本城范围内查,否则"不存在"与"不属于本城"无法区分)
        $inst = DB::table('city_building_instances')->where('id', $instanceId)->first();
        if (! $inst) {
            throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
        }
        if ((int) $inst->city_id !== (int) $city->id) {
            AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'reason_code' => 'NOT_OWNER',
            ]);
            throw new GameRuleException(ErrorCode::FORBIDDEN, 403);
        }

        $requestHash = Idempotency::hash(AuditAction::BUILDING_UPGRADE_CANCEL, ['instanceId' => $instanceId]);

        if ($idempotencyKey !== null) {
            $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::BUILDING_UPGRADE_CANCEL, $requestHash);
            if ($existing) {
                return self::snapshot($city->fresh(), $instanceId);
            }
        }

        return DB::transaction(function () use ($city, $instanceId, $expectedRevision, $idempotencyKey, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后复检,关闭 TOCTOU 窗口(不重复退款)
            if ($idempotencyKey !== null) {
                $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::BUILDING_UPGRADE_CANCEL, $requestHash);
                if ($existing) {
                    return self::snapshot($city->fresh(), $instanceId);
                }
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            $now = now();

            // 锁内先结算:退款要按结算后的库存判断仓储余量,而且结算会顺手把「其实已经完工」的升级翻正,
            // 于是"到点后才点取消"会自然落到下面的 status 检查上被拒绝 —— 不会出现"完工了还能退款"
            $sim = SimulationService::applyLocked($locked, $now);

            $inst = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)->first();
            if (! $inst) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }
            if ($inst->status !== ConstructionService::STATUS_UPGRADING) {
                throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
            }

            // 在升级的目标等级 = 当前(旧)等级 + 1:level 要等完工才 +1,所以这里能直接算出该次升级买的是哪一级
            $currentLevel = (int) $inst->level;
            $targetLevel = $currentLevel + 1;
            $refund = ConstructionService::scale(
                ConstructionService::materialCost($inst->building_id, $targetLevel),
                ConstructionService::CANCEL_REFUND_RATE
            );

            // 先回状态:status 进 where,并发的第二次取消只有一方能改成功
            $affected = DB::table('city_building_instances')
                ->where('id', $instanceId)->where('city_id', $city->id)
                ->where('status', ConstructionService::STATUS_UPGRADING)
                ->update([
                    'status'                   => ConstructionService::STATUS_ACTIVE,
                    'construction_finished_at' => null,
                    'updated_at'               => $now,
                ]);
            if ($affected === 0) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            // 退款(资源夹仓储上限,截断量进审计 metadata;资金本就不返还)
            [$granted, $truncated] = ConstructionService::grantRefund(
                (int) $city->id, $refund, (float) $sim['storageCapacity']
            );

            // 不变量(CLAUDE §52):资源不为负 / 资金不为负
            $neg = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($neg > 0 || (float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::BUILDING_UPGRADE_CANCEL, $requestHash);
            }

            AuditLogger::record(AuditAction::BUILDING_UPGRADE_CANCEL, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'before_json' => ['level' => $currentLevel, 'status' => ConstructionService::STATUS_UPGRADING],
                'after_json'  => ['level' => $currentLevel, 'status' => ConstructionService::STATUS_ACTIVE],
                // delta 记的是实际退到手的量(被仓储截断之后),不是名义应退量
                'delta_json'  => $granted,
                'idempotency_key' => $idempotencyKey,
                'metadata_json' => [
                    'buildingId'   => $inst->building_id,
                    'targetLevel'  => $targetLevel,
                    'refundRate'   => ConstructionService::CANCEL_REFUND_RATE,
                    'refundNominal' => $refund,
                    // 被仓储上限截掉的部分:事后能回答「玩家为什么少收到了材料」
                    'truncated'    => $truncated,
                ],
            ]);

            return [
                'revision'  => $newRevision,
                'building'  => [
                    'id'                       => $instanceId,
                    'level'                    => $currentLevel,
                    'status'                   => ConstructionService::STATUS_ACTIVE,
                    'construction_finished_at' => null,
                ],
                'resources' => DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
                'money'     => (float) DB::table('cities')->where('id', $city->id)->value('money'),
                'delta'     => $granted,
                'truncated' => $truncated,
            ];
        });
    }

    // 幂等重放:返回当前实例/资源快照,不重复扣费/升级/退款
    private static function snapshot(City $city, int $instanceId): array
    {
        $inst = DB::table('city_building_instances')->where('id', $instanceId)->first();

        return [
            'revision'  => (int) $city->revision,
            'building'  => [
                'id'                       => $instanceId,
                'level'                    => $inst ? (int) $inst->level : 0,
                'status'                   => $inst ? $inst->status : null,
                'construction_finished_at' => $inst && $inst->construction_finished_at !== null
                    ? Carbon::parse($inst->construction_finished_at)->toIso8601String()
                    : null,
            ],
            'resources' => DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
            'money'     => (float) $city->money,
            'delta'     => [],
        ];
    }
}
