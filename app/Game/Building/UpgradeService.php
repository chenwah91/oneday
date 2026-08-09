<?php

namespace App\Game\Building;

use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
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

        if ((int) $inst->level >= 3) {
            throw new GameRuleException(ErrorCode::BUILDING_LIMIT_REACHED, 422);
        }

        $nextLevel = (int) $inst->level + 1;
        $lvl = DB::table('building_level_definition')->where('building_id', $inst->building_id)->where('level', $nextLevel)->first();
        $cost = json_decode($lvl->cost_json, true) ?: [];

        return DB::transaction(function () use ($city, $inst, $instanceId, $nextLevel, $cost, $expectedRevision, $idempotencyKey) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();
            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 资源足额(资金单列在 cities.money)
            $resAmounts = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id');
            foreach ($cost as $res => $amt) {
                $have = $res === '资金' ? (float) $locked->money : (float) ($resAmounts[$res] ?? 0);
                if ($have < $amt) { throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422); }
            }

            // 扣资源
            $delta = [];
            foreach ($cost as $res => $amt) {
                if ($res === '资金') { DB::table('cities')->where('id', $city->id)->decrement('money', $amt); }
                else { DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $res)->decrement('amount', $amt); }
                $delta[$res] = -$amt;
            }

            DB::table('city_building_instances')->where('id', $instanceId)->update(['level' => $nextLevel, 'updated_at' => now()]);

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

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
}
