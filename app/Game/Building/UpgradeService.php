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
use Illuminate\Support\Facades\DB;

// 升级:L1→L2→L3,即时生效;严格所有权校验
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

            // 锁内先跑 Time Delta 结算(CLAUDE §51):
            // 1) 不结算就扣款,玩家可用"离线期间已被吃掉的旧快照资源"升级;
            // 2) 不结算就升级,新等级会追溯生产升级之前的时段。
            $sim = SimulationService::applyLocked($locked, now());

            // 加锁后重新读取实例:防止并发拆除导致的幽灵升级(instance 已不存在则视为 NOT_FOUND)
            $inst = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)->first();
            if (! $inst) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }
            if ((int) $inst->level >= 3) {
                throw new GameRuleException(ErrorCode::BUILDING_LIMIT_REACHED, 422);
            }

            $nextLevel = (int) $inst->level + 1;
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

            // 扣资源
            $delta = [];
            foreach ($cost as $res => $amt) {
                if ($res === ResourceCode::MONEY) { DB::table('cities')->where('id', $city->id)->decrement('money', $amt); }
                else { DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $res)->decrement('amount', $amt); }
                $delta[$res] = -$amt;
            }

            // 影响行数校验:防止实例在扣款与写级之间被并发拆除,产生"扣了钱但没升级"的幽灵升级
            // (升级不自动调整工人:派工由玩家自理,§10.4 用户裁决 2026-08-10;超编对产出无害,workerFactor 封顶 1)
            $affected = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)
                ->update(['level' => $nextLevel, 'updated_at' => now()]);
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
                'before_json' => ['level' => $nextLevel - 1], 'after_json' => ['level' => $nextLevel], 'delta_json' => $delta,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'revision'  => $newRevision,
                'building'  => ['id' => $instanceId, 'level' => $nextLevel],
                'resources' => DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
                'money'     => (float) DB::table('cities')->where('id', $city->id)->value('money'),
                'delta'     => $delta,
            ];
        });
    }

    // 幂等重放:返回当前实例/资源快照,不重复扣费/升级
    private static function snapshot(City $city, int $instanceId): array
    {
        $level = (int) DB::table('city_building_instances')->where('id', $instanceId)->value('level');

        return [
            'revision'  => (int) $city->revision,
            'building'  => ['id' => $instanceId, 'level' => $level],
            'resources' => DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
            'money'     => (float) $city->money,
            'delta'     => [],
        ];
    }
}
