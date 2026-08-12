<?php

namespace App\Http\Controllers\City;

use App\Game\Building\ConstructionService;
use App\Game\City\CityFactory;
use App\Game\Simulation\SimulationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\Idempotency;
use App\Support\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 拆除:幂等 + Revision + 所有权校验 + 材料返还 + 删除实例 + 审计
//
// 返还口径(M2-C5,v3.2 §10.9 / §3.2,资金一律不返还):
//   active       已完工等级(L1 建造 + 已完成的每次升级)累计材料 × 50%
//   constructing 拆除 = 取消建造,该次 L1 建造材料 × 70%(还没完工,没有 50% 的部分)
//   upgrading    先按取消退该次升级材料 × 70%,再按拆除退已完工等级累计材料 × 50%
// 50% < 70% 是 §10.9 的明文要求(「拆除返还低于升级取消返还 70%,防止拆建套利」)
//
// GameRuleException 不在此捕获,交由 bootstrap/app.php 的全局 render 统一转 ApiResponse(CLAUDE §78)
class DemolishController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // idempotency_key / expected_revision 保持可选:强制化会破坏已发布 PWA 的契约
        // 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
        $data = $request->validate([
            'instance_id'       => ['required', 'integer'],
            'idempotency_key'   => ['nullable', 'string', 'max:100'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        $city = CityFactory::createForUser($request->user());
        $instanceId = (int) $data['instance_id'];
        $idempotencyKey = $data['idempotency_key'] ?? null;
        $expectedRevision = isset($data['expected_revision']) ? (int) $data['expected_revision'] : null;
        // 请求指纹:只含业务参数,不含 expected_revision(重试时 revision 可能已变)
        $requestHash = Idempotency::hash(AuditAction::BUILDING_DEMOLISH, ['instanceId' => $instanceId]);

        // 幂等:锁前先查。必须早于"实例是否存在"判定 —— 重放时实例已被删,否则会误报 NOT_FOUND
        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::BUILDING_DEMOLISH, $requestHash) !== null) {
            return ApiResponse::ok(['data' => self::replayPayload($city, $instanceId)]);
        }

        // 所有权:先全局查实例(不能只在本城范围内查,否则"不存在"与"不属于本城"无法区分)
        $inst = DB::table('city_building_instances')->where('id', $instanceId)->first();
        if (! $inst) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        if ((int) $inst->city_id !== (int) $city->id) {
            AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId, 'reason_code' => 'NOT_OWNER',
            ]);
            // 审计负责业务可追溯,Security Log 负责异常检测(CLAUDE §60),两者并行不互相替代
            SecurityLogger::log('security.authorization_failed', [
                'user_id' => (int) $city->user_id, 'route' => $request->path(),
                'reason' => 'NOT_OWNER', 'entity_type' => 'building', 'entity_id' => (string) $instanceId,
            ]);
            return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
        }

        $result = DB::transaction(function () use ($city, $instanceId, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后重新校验,关闭"锁前检查、锁后写入"之间的并发窗口(TOCTOU),不再删第二次/不再退第二次
            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::BUILDING_DEMOLISH, $requestHash) !== null) {
                return self::replayPayload($city, $instanceId);
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先跑 Time Delta 结算(CLAUDE §51):
            // 1) 必须先把"拆除前时段"的产出结清,否则被拆建筑这段时间应得的产出会丢失;
            // 2) 返还要按结算后的库存判断仓储余量;
            // 3) 结算会顺手把到点的工程翻正,于是"完工瞬间点拆除"按 active 口径算 50%,不会被当成取消退 70%。
            $sim = SimulationService::applyLocked($locked, now());

            // 结算后重读实例:上一步的懒完工可能刚改过 status / level,返还必须按最新状态算
            $inst = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)->first();
            if (! $inst) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            $refund = self::refundFor($inst);

            // 限定 city_id 并校验影响行数:防止实例在所有权校验与加锁之间被并发拆除,产生"假成功"(revision 空涨)
            $affected = DB::table('city_building_instances')->where('id', $instanceId)->where('city_id', $city->id)->delete();
            if ($affected !== 1) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            // 返还入库:资源夹在仓储上限(与结算内核同口径),被截掉的量进审计 metadata;资金不返还
            [$granted, $truncated] = ConstructionService::grantRefund(
                (int) $city->id, $refund, (float) $sim['storageCapacity']
            );

            // 不变量(CLAUDE §52):资源不为负 / 资金不为负
            $neg = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($neg > 0 || (float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            $rev = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $rev]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::BUILDING_DEMOLISH, $requestHash);
            }

            AuditLogger::record(AuditAction::BUILDING_DEMOLISH, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $rev,
                'before_json' => ['level' => $inst->level, 'x' => $inst->x, 'y' => $inst->y, 'status' => $inst->status],
                // delta 记实际退到手的材料(已按仓储上限截断),不是名义应退量
                'delta_json' => $granted,
                'metadata_json' => [
                    'buildingId'    => $inst->building_id,
                    'status'        => $inst->status,
                    'refundNominal' => $refund,
                    'truncated'     => $truncated,
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'revision'      => $rev,
                'demolished_id' => $instanceId,
                // resources / delta / truncated 三个都是资源 code => 数量的 map:统一过 ApiResponse::map,
                // 空时序列化成 `{}` 而不是 `[]`(与 BuildService::snapshotDiff 同一条口径)
                'resources'     => ApiResponse::map(DB::table('city_resources')->where('city_id', $city->id)
                    ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all()),
                'money'         => (float) DB::table('cities')->where('id', $city->id)->value('money'),
                'delta'         => ApiResponse::map($granted),
                'truncated'     => ApiResponse::map($truncated),
            ];
        });

        return ApiResponse::ok(['data' => $result]);
    }

    // 按实例状态算出本次拆除的名义返还材料(资金已在 ConstructionService::materialCost 里剔除)
    private static function refundFor(object $inst): array
    {
        $level = (int) $inst->level;

        // 建造中:L1 还没完工,没有「已完工等级」可退,整栋按取消建造算 70%。
        // v3.2 只对「取消升级」写死了 70%,取消建造沿用同一比例(见汇报的假设清单)
        if ($inst->status === ConstructionService::STATUS_CONSTRUCTING) {
            return ConstructionService::scale(
                ConstructionService::materialCost($inst->building_id, $level),
                ConstructionService::cancelRefundRate()
            );
        }

        // 已完工等级的累计建造材料 × 50%(§10.9)
        $refund = ConstructionService::scale(
            ConstructionService::cumulativeMaterialCost($inst->building_id, $level),
            ConstructionService::demolishRefundRate()
        );

        // 升级中:那笔已经付掉、还没变成等级的升级材料,按取消口径再退 70%
        if ($inst->status === ConstructionService::STATUS_UPGRADING) {
            $refund = ConstructionService::mergeRefund($refund, ConstructionService::scale(
                ConstructionService::materialCost($inst->building_id, $level + 1),
                ConstructionService::cancelRefundRate()
            ));
        }

        return $refund;
    }

    // 幂等重放:实例已被删,只回当前 revision 与资源快照,绝不重复退款
    private static function replayPayload(object $city, int $instanceId): array
    {
        return [
            'revision'      => (int) DB::table('cities')->where('id', $city->id)->value('revision'),
            'demolished_id' => $instanceId,
            // 幂等重放路径的 delta / truncated 恒为空 —— 这里最容易漏,不包就永远输出 `[]`
            'resources'     => ApiResponse::map(DB::table('city_resources')->where('city_id', $city->id)
                ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all()),
            'money'         => (float) DB::table('cities')->where('id', $city->id)->value('money'),
            'delta'         => ApiResponse::map([]),
            'truncated'     => ApiResponse::map([]),
        ];
    }
}
