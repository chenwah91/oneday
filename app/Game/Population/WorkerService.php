<?php

namespace App\Game\Population;

use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\GameSetting;
use App\Support\Idempotency;
use App\Support\SecurityLogger;
use Illuminate\Support\Facades\DB;

// 工人分配(v3.2 §10.4):完整安全链(所有权/幂等/Revision/规则/事务+行锁/不变量/审计/revision+1)
//
// workers 是绝对值设置(不是增量):0 = 把这栋楼的人全撤走。
// 规则两条:
//   1) workers <= 该实例「当前等级」的 worker_required(超编)
//   2) 全城 Σassigned(含本次变更后) <= floor(population × 0.60)(§10.4 availableWorkers)
class WorkerService
{
    public static function assign(City $city, int $instanceId, int $workers, ?string $idempotencyKey, ?int $expectedRevision): array
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
            // 审计负责业务可追溯,Security Log 负责异常检测(CLAUDE §60),两者并行不互相替代
            SecurityLogger::log('security.authorization_failed', [
                'user_id' => (int) $city->user_id, 'route' => 'api/city/workers/assign',
                'reason' => 'NOT_OWNER', 'entity_type' => 'building', 'entity_id' => (string) $instanceId,
            ]);
            throw new GameRuleException(ErrorCode::FORBIDDEN, 403);
        }

        // 请求指纹:只含业务参数,不含 expected_revision(重试时 revision 可能已变)
        $requestHash = Idempotency::hash(AuditAction::WORKER_ASSIGN, ['instanceId' => $instanceId, 'workers' => $workers]);

        // 幂等:同一 user+key+action+参数已处理则直接成功返回(不重复改分配);key 被复用则 409
        if ($idempotencyKey !== null) {
            $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::WORKER_ASSIGN, $requestHash);
            if ($existing) {
                return self::snapshot($city->fresh(), $instanceId);
            }
        }

        return DB::transaction(function () use ($city, $instanceId, $workers, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后重新校验,关闭"锁前检查、锁后写入"之间的并发窗口(TOCTOU),与 Build/Upgrade 对齐
            if ($idempotencyKey !== null) {
                $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::WORKER_ASSIGN, $requestHash);
                if ($existing) {
                    return self::snapshot($city->fresh(), $instanceId);
                }
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先跑 Time Delta 结算(CLAUDE §51):
            // 1) 劳动力上限按人口算,而人口正是结算的产物,不结算就会拿过期人口放行超额分配;
            // 2) 工人数会改变产出(workerFactor),不结算就改人,新的用工率会追溯到改人之前的时段。
            $sim = SimulationService::applyLocked($locked, now());

            // 加锁并结算后重新读实例:防止并发拆除/升级导致的幽灵分配
            $inst = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)->first();
            if (! $inst) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            $required = (int) DB::table('building_level_definition')
                ->where('building_id', $inst->building_id)->where('level', $inst->level)
                ->value('worker_required');

            // 规则 1:超编 —— 一栋楼最多只要 worker_required 个人,多派没有意义
            if ($workers > $required) {
                throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
            }

            // 规则 2:全城劳动力上限 availableWorkers = floor(population × 0.60)(§10.4)。
            // 人口取结算后的最新值(不是请求进来时的旧快照)。
            // 例外:只减不增的操作永远放行 —— 人口暴跌(饥荒/迁出)会让历史分配天然超上限,
            // 此时若连撤人都拒绝,玩家会被锁死在超编状态里出不来。
            // 这条例外是后台可配开关(game_settings.worker_assign_allow_decrease_always,默认 true):
            // 关掉后连撤人也要满足上限,留给运营处理「超编状态被玩家当收益长期占用」这类极端情况。
            $available = SimulationService::availableWorkers($sim['population']);
            $before = (int) $inst->assigned_workers;
            $othersAssigned = (int) DB::table('city_building_instances')
                ->where('city_id', $city->id)->where('id', '!=', $instanceId)
                ->sum('assigned_workers');
            $allowDecreaseAlways = (bool) GameSetting::get(GameSetting::WORKER_ASSIGN_ALLOW_DECREASE_ALWAYS, true);
            $isIncrease = $workers > $before;
            if (($isIncrease || ! $allowDecreaseAlways) && $othersAssigned + $workers > $available) {
                throw new GameRuleException(ErrorCode::WORKER_NOT_AVAILABLE, 422);
            }

            // 影响行数校验:防止实例在校验与写入之间被并发拆除,产生"假成功"(revision 空涨)
            $affected = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)
                ->update(['assigned_workers' => $workers, 'updated_at' => now()]);
            if ($affected === 0) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            // 不变量(CLAUDE §52「worker assigned <= available workers」):锁内按落库后的真实合计复核。
            // 注意人口下跌(饥荒/迁出)会让历史分配自然超出上限,那属于结算侧的既有状态,
            // 这里只保证「本次 Mutation 之后」不成立超额,不去反向裁撤玩家已有的分配。
            $totalAssigned = (int) DB::table('city_building_instances')->where('city_id', $city->id)->sum('assigned_workers');
            if ($totalAssigned > max($available, $othersAssigned + $before)) {
                throw new GameRuleException(ErrorCode::WORKER_NOT_AVAILABLE, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::WORKER_ASSIGN, $requestHash);
            }

            AuditLogger::record(AuditAction::WORKER_ASSIGN, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'before_json' => ['assigned' => $before], 'after_json' => ['assigned' => $workers],
                'delta_json' => ['assigned' => $workers - $before],
                'idempotency_key' => $idempotencyKey,
                'metadata_json' => ['buildingId' => $inst->building_id, 'level' => (int) $inst->level, 'workerRequired' => $required],
            ]);

            // 返回 diff:契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
            return [
                'revision'          => $newRevision,
                'building'          => ['id' => $instanceId, 'assigned_workers' => $workers, 'worker_required' => $required],
                'available_workers' => $available,
                'assigned_workers'  => $totalAssigned,
                'population'        => $sim['population'],
            ];
        });
    }

    // 全城已分配工人合计(快照与幂等重放共用)
    public static function totalAssigned(int $cityId): int
    {
        return (int) DB::table('city_building_instances')->where('city_id', $cityId)->sum('assigned_workers');
    }

    // 幂等重放:返回当前实例/劳动力快照,不重复改分配
    private static function snapshot(City $city, int $instanceId): array
    {
        $inst = DB::table('city_building_instances')->where('id', $instanceId)->first();
        $required = $inst
            ? (int) DB::table('building_level_definition')
                ->where('building_id', $inst->building_id)->where('level', $inst->level)->value('worker_required')
            : 0;

        return [
            'revision'          => (int) $city->revision,
            'building'          => [
                'id'               => $instanceId,
                'assigned_workers' => $inst ? (int) $inst->assigned_workers : 0,
                'worker_required'  => $required,
            ],
            'available_workers' => SimulationService::availableWorkers((int) $city->population),
            'assigned_workers'  => self::totalAssigned((int) $city->id),
            'population'        => (int) $city->population,
        ];
    }
}
