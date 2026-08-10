<?php

namespace App\Game\Admin;

use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\User;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\Idempotency;
use App\Support\SecurityLogger;
use Illuminate\Support\Facades\DB;

// 管理员补偿 / 扣减(CLAUDE §80、SECURITY.md「管理员操作」)。
//
// 存在的意义:线上出经济 bug 时,唯一合法的修数据通道。禁止直接 UPDATE 生产库(红线 + §86),
// 所有人工补偿必须留下「谁 / 何时 / 给谁 / 改了什么 / 改前改后 / 为什么」。
//
// 安全链顺序与 BuildService 完全一致(CLAUDE §42 / §51):
//   幂等预检 → 事务 → cities 行锁 → 幂等复检(关 TOCTOU) → 锁内先跑 Time Delta 结算
//   → 规则校验(不得为负 / 不得超仓储上限) → 应用 delta → 不变量复核 → 审计 → revision+1
//
// 先结算再改余额是硬要求:不结算就写绝对值,会把「离线期间本该被吃掉的产出」一起写回去,
// 相当于补偿顺手退回了时间。
class CompensationService
{
    // 单次补偿的绝对值上限:纯粹的防手滑护栏(多打几个零),不是游戏规则。
    // 真要发超过这个量,分多次发 —— 每次都会各留一条审计
    public const MAX_ABS_DELTA = 1000000000.0;

    /**
     * @param  User    $admin            操作的管理员(审计 actor)
     * @param  City    $city             被补偿玩家的城市
     * @param  string  $resource         资源 code(含 money)
     * @param  float   $delta            正数补偿 / 负数扣减
     * @param  string  $reason           必填原因(进审计 reason_code,列宽 80)
     * @param  ?string $ticket           工单 / 参考号(可选,进 metadata)
     * @param  ?string $idempotencyKey   幂等键(可选)
     */
    public static function apply(
        User $admin,
        City $city,
        string $resource,
        float $delta,
        string $reason,
        ?string $ticket,
        ?string $idempotencyKey
    ): array {
        // 资源必须是已知 code,且不能是容量类(容量是建筑算出来的派生值,不存在库存,补它没有意义)
        if (! self::isAdjustable($resource)) {
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }
        if ($delta === 0.0 || abs($delta) > self::MAX_ABS_DELTA) {
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        // 请求指纹:只含业务参数(给谁 / 什么资源 / 多少),不含 reason/ticket ——
        // 同一次补偿重试时管理员可能补填了工单号,不该被判成 key 复用
        $requestHash = Idempotency::hash(AuditAction::ADMIN_COMPENSATION, [
            'city_id'  => (int) $city->id,
            'resource' => $resource,
            'delta'    => $delta,
        ]);

        // 幂等键归属管理员(是他发起的这次操作),city_id 记被补偿的城市
        $adminId = (int) $admin->id;

        if ($idempotencyKey !== null) {
            $existing = Idempotency::check($adminId, $idempotencyKey, AuditAction::ADMIN_COMPENSATION, $requestHash);
            if ($existing) {
                return self::snapshot($city->fresh(), $resource, true);
            }
        }

        return DB::transaction(function () use ($admin, $adminId, $city, $resource, $delta, $reason, $ticket, $idempotencyKey, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后复检,关闭「锁前检查、锁后写入」之间的并发窗口(TOCTOU),与 Build/Worker 对齐
            if ($idempotencyKey !== null) {
                $existing = Idempotency::check($adminId, $idempotencyKey, AuditAction::ADMIN_COMPENSATION, $requestHash);
                if ($existing) {
                    return self::snapshot($city->fresh(), $resource, true);
                }
            }

            // 锁内先跑 Time Delta 结算(CLAUDE §51):余额校验与写入都必须基于结算后的最新值
            $sim = SimulationService::applyLocked($locked, now());

            $isMoney = $resource === ResourceCode::MONEY;
            $before = $isMoney
                ? (float) $sim['money']
                : (float) ($sim['resources'][$resource] ?? 0);
            $after = $before + $delta;

            // 规则 1:结果不得为负(扣减扣穿 = 拒绝并回滚,不做「扣到 0 为止」的静默截断)
            if ($after < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            // 规则 2:仓储上限口径与内核一致(SimulationService 把资源夹在 [0, storageCapacity])。
            // 只在「本次增加把它顶过上限」时拒绝:负 delta 即使结果仍高于上限(仓储缩水的历史存量)
            // 也放行,否则管理员连帮玩家降回合法区间都做不到。
            // 资金不受仓储约束(存在 cities.money,内核不夹上限),故不参与本条。
            $storageCapacity = (float) $sim['storageCapacity'];
            if (! $isMoney && $delta > 0 && $after > $storageCapacity) {
                throw new GameRuleException(ErrorCode::STORAGE_FULL, 422);
            }

            // 应用 delta:写绝对值(锁在手上,且 applyLocked 刚把结算结果落过库,$before 即库中现值)
            if ($isMoney) {
                DB::table('cities')->where('id', $city->id)->update(['money' => $after]);
            } else {
                // city_resources 复合主键 (city_id, resource_id):玩家从没持有过该资源时 upsert 直接建行
                DB::table('city_resources')->upsert(
                    [['city_id' => $city->id, 'resource_id' => $resource, 'amount' => $after]],
                    ['city_id', 'resource_id'],
                    ['amount']
                );
            }

            // 不变量复核(CLAUDE §52):按落库后的真实值再查一遍,不信任内存计算结果
            $moneyAfter = (float) DB::table('cities')->where('id', $city->id)->value('money');
            $negative = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($negative > 0 || $moneyAfter < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store($adminId, (int) $city->id, $idempotencyKey, AuditAction::ADMIN_COMPENSATION, $requestHash);
            }

            // 审计(§63 管理员改玩家状态的必备六件套:Admin ID / Reason / Before / After / Delta / 时间)
            AuditLogger::record(AuditAction::ADMIN_COMPENSATION, 'success', [
                'actor_type'           => 'admin',
                'actor_id'             => $adminId,
                'user_id'              => (int) $city->user_id,
                'city_id'              => (int) $city->id,
                'entity_type'          => 'city_resource',
                'entity_id'            => $resource,
                'city_revision_before' => (int) $locked->revision,
                'city_revision_after'  => $newRevision,
                'reason_code'          => $reason,
                'before_json'          => [$resource => $before],
                'after_json'           => [$resource => $after],
                'delta_json'           => [$resource => $delta],
                'idempotency_key'      => $idempotencyKey,
                'metadata_json'        => [
                    'ticket'         => $ticket,
                    'admin_username' => $admin->username,
                    'resource'       => $resource,
                ],
            ]);

            // Security Log(§60):审计负责业务可追溯,安全日志负责异常检测 ——
            // 「同一管理员短时间内大量补偿」这类信号要在这里才看得出来
            SecurityLogger::log('admin.compensation', [
                'actor_id'    => $adminId,
                'user_id'     => (int) $city->user_id,
                'city_id'     => (int) $city->id,
                'action'      => AuditAction::ADMIN_COMPENSATION,
                'entity_type' => 'city_resource',
                'entity_id'   => $resource,
                'route'       => 'api/admin/compensation',
            ]);

            return [
                'city_id'          => (int) $city->id,
                'user_id'          => (int) $city->user_id,
                'resource'         => $resource,
                'delta'            => $delta,
                'before'           => $before,
                'after'            => $after,
                'money'            => $moneyAfter,
                'revision'         => $newRevision,
                'storage_capacity' => $storageCapacity,
                'replayed'         => false,
            ];
        });
    }

    // 可补偿的资源:31 种库存资源 + 资金。容量类(人口容量/仓储容量等)是派生值,不可补
    public static function isAdjustable(string $resource): bool
    {
        if (ResourceCode::isCapacity($resource)) {
            return false;
        }

        return isset(ResourceCode::CHINESE_NAMES[$resource]);
    }

    // 幂等重放:不重复入账,只回当前余额快照(before === after,delta 记 0)
    private static function snapshot(City $city, string $resource, bool $replayed): array
    {
        $amount = $resource === ResourceCode::MONEY
            ? (float) $city->money
            : (float) (DB::table('city_resources')
                ->where('city_id', $city->id)->where('resource_id', $resource)->value('amount') ?? 0);

        return [
            'city_id'          => (int) $city->id,
            'user_id'          => (int) $city->user_id,
            'resource'         => $resource,
            'delta'            => 0.0,
            'before'           => $amount,
            'after'            => $amount,
            'money'            => (float) $city->money,
            'revision'         => (int) $city->revision,
            'storage_capacity' => null,
            'replayed'         => $replayed,
        ];
    }
}
