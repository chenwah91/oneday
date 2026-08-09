<?php

namespace App\Game\Building;

use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

// 建造:完整安全链(幂等/Revision/占地/上限/资源/事务/审计)
class BuildService
{
    public static function build(City $city, string $buildingId, int $x, int $y, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        // 幂等:同一 user+key 已处理则直接成功返回(不重复扣建)
        if ($idempotencyKey !== null) {
            $existing = DB::table('idempotency_keys')->where('user_id', $city->user_id)->where('key', $idempotencyKey)->first();
            if ($existing) {
                return self::snapshotDiff($city->fresh());
            }
        }

        $def = DB::table('building_definition')->where('building_id', $buildingId)->first();
        if (! $def) {
            throw new GameRuleException(ErrorCode::INVALID_BUILDING, 422);
        }
        $lvl = DB::table('building_level_definition')->where('building_id', $buildingId)->where('level', 1)->first();
        if (! $lvl) {
            throw new GameRuleException(ErrorCode::INVALID_BUILDING, 422);
        }
        $cost = json_decode($lvl->cost_json, true) ?: [];

        return DB::transaction(function () use ($city, $def, $buildingId, $x, $y, $cost, $idempotencyKey, $expectedRevision) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后重新校验,关闭"锁前检查、锁后写入"之间的并发窗口(TOCTOU)
            if ($idempotencyKey !== null) {
                $existing = DB::table('idempotency_keys')->where('user_id', $city->user_id)->where('key', $idempotencyKey)->first();
                if ($existing) {
                    return self::snapshotDiff($city->fresh());
                }
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先跑 Time Delta 结算(CLAUDE §51):
            // 1) 不结算就扣款,玩家可用"离线期间已被吃掉的旧快照资源"建造;
            // 2) 不结算就建造,新建筑会追溯生产建成之前的时段。
            $sim = SimulationService::applyLocked($locked, now());

            // 占地:落在地图内
            $w = (int) $def->footprint_w; $h = (int) $def->footprint_h;
            if ($x < 0 || $y < 0 || $x + $w > $locked->map_width || $y + $h > $locked->map_height) {
                throw new GameRuleException(ErrorCode::INVALID_POSITION, 422);
            }

            // 占地:与现有建筑不重叠(矩形相交)
            $others = DB::table('city_building_instances as ci')
                ->join('building_definition as bd', 'ci.building_id', '=', 'bd.building_id')
                ->where('ci.city_id', $city->id)
                ->select('ci.x', 'ci.y', 'bd.footprint_w', 'bd.footprint_h')->get();
            foreach ($others as $o) {
                if ($x < $o->x + $o->footprint_w && $x + $w > $o->x && $y < $o->y + $o->footprint_h && $y + $h > $o->y) {
                    throw new GameRuleException(ErrorCode::LAND_OCCUPIED, 422);
                }
            }

            // 数量上限
            $count = DB::table('city_building_instances')->where('city_id', $city->id)->where('building_id', $buildingId)->count();
            if ($count >= (int) $def->max_count) {
                throw new GameRuleException(ErrorCode::BUILDING_LIMIT_REACHED, 422);
            }

            // 资源足额:一律用结算后的最新余额(资金单列在 cities.money)
            foreach ($cost as $res => $amt) {
                $have = $res === '资金' ? (float) $sim['money'] : (float) ($sim['resources'][$res] ?? 0);
                if ($have < $amt) { throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422); }
            }

            // 扣资源
            $delta = [];
            foreach ($cost as $res => $amt) {
                if ($res === '资金') {
                    DB::table('cities')->where('id', $city->id)->decrement('money', $amt);
                } else {
                    DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $res)->decrement('amount', $amt);
                }
                $delta[$res] = -$amt;
            }

            // 建实体
            $instanceId = DB::table('city_building_instances')->insertGetId([
                'city_id' => $city->id, 'building_id' => $buildingId, 'level' => 1,
                'x' => $x, 'y' => $y, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // 不变量:资源不为负(扣前已校验,双保险)
            $neg = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($neg > 0 || (float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                DB::table('idempotency_keys')->insert([
                    'user_id' => $city->user_id, 'key' => $idempotencyKey, 'action' => AuditAction::BUILDING_BUILD,
                    'response_status' => 200, 'created_at' => now(),
                ]);
            }

            AuditLogger::record(AuditAction::BUILDING_BUILD, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'delta_json' => $delta, 'idempotency_key' => $idempotencyKey,
                'metadata_json' => ['buildingId' => $buildingId, 'x' => $x, 'y' => $y],
            ]);

            return self::snapshotDiff($city->fresh(), $delta);
        });
    }

    // 返回资源/revision 简要 diff
    private static function snapshotDiff(City $city, array $delta = []): array
    {
        return [
            'revision'  => (int) $city->revision,
            'resources' => DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
            'money'     => (float) $city->money,
            'delta'     => $delta,
        ];
    }
}
