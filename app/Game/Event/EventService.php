<?php

namespace App\Game\Event;

use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcBonus;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\GameSetting;
use App\Support\Idempotency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 事件的玩家侧:快照读取 + 选项结算(CLAUDE §70 全套校验)。
//
// 触发在 EventRuntimeService(系统侧,懒结算);本类只处理**玩家发起**的那一半。
//
// ══ §70 的五道校验,逐条落在 resolve() 里 ══════════════════════════════════════
//   ① 属于该玩家   → Controller 层查全表比 city_id(越权留痕),服务层再按 city_id 锁一次(Fail Closed);
//   ② status=ACTIVE → **条件更新的影响行数**判定,不是「先查后写」(并发双领,backlog §11.3 点名);
//   ③ 没有过期      → expires_at 与此刻比较,过期顺手翻成 expired 并拒绝;
//   ④ choice 合法   → 只认定义里真实存在的选项键(§9.2 里很多事件只有 A/B);
//   ⑤ 未领取        → 与 ② 同一条:status 一旦不是 active 就再也回不去。
final class EventService
{
    // 快照里 recent 区块的条数上限:已结算 / 已过期的事件只作「最近发生了什么」的回顾,
    // 不做历史查询(那是审计的活)
    private const RECENT_LIMIT = 10;

    // ---------- 只读 ----------

    // GET /api/city/events 的完整数据块
    public static function snapshot(int $cityId, ?Carbon $now = null): array
    {
        $now ??= now();
        $definitions = EventDefinition::all();

        $rows = DB::table('city_events')->where('city_id', $cityId)
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT + 20)
            ->get();

        $active = [];
        $recent = [];

        foreach ($rows as $row) {
            $definition = $definitions[$row->event_id] ?? null;
            if ($definition === null) {
                continue; // 定义已被删(理论上不会:定义表只增不删),跳过而不是让整个快照炸掉
            }

            if ($row->status === EventCode::STATUS_ACTIVE && Carbon::parse($row->expires_at)->greaterThan($now)) {
                $active[] = self::toContract($row, $definition, $now);

                continue;
            }

            if (count($recent) < self::RECENT_LIMIT) {
                $recent[] = [
                    'event_instance_id' => (int) $row->id,
                    'event_id'          => (string) $row->event_id,
                    'name_zh'           => $definition['name_zh'],
                    // 过期但懒结算还没翻牌的实例,对玩家一律显示为 expired(与 resolve 的判定同口径)
                    'status'            => $row->status === EventCode::STATUS_ACTIVE
                        ? EventCode::STATUS_EXPIRED
                        : (string) $row->status,
                    'chosen_option'     => $row->chosen_option,
                    'triggered_at'      => Carbon::parse($row->triggered_at)->toIso8601String(),
                ];
            }
        }

        return [
            'active_count' => count($active),
            'active'       => $active,
            'recent'       => $recent,
            // 规则参数一并下发:前端要画「最多 3 个」「还有多久到期」,数值必须来自服务器(§45)
            'limits' => [
                'enabled'              => GameSetting::get(GameSetting::EVENT_ENABLED) === true,
                'max_active'           => (int) GameSetting::get(GameSetting::EVENT_MAX_ACTIVE),
                'max_active_disaster'  => (int) GameSetting::get(GameSetting::EVENT_MAX_ACTIVE_DISASTER),
                'window_seconds'       => (int) GameSetting::get(GameSetting::EVENT_WINDOW_SECONDS),
                'trigger_chance'       => (float) GameSetting::get(GameSetting::EVENT_TRIGGER_CHANCE),
                'offline_max_triggers' => (int) GameSetting::get(GameSetting::EVENT_OFFLINE_MAX_TRIGGERS),
            ],
        ];
    }

    // 城市快照(CityController 的 M3-EVENT 锚点)用的**精简**区块:
    // 只给数量与最小标识,详情走独立端点 —— §15「避免每次返回完整城市」
    public static function summary(int $cityId, ?Carbon $now = null): array
    {
        $now ??= now();
        $definitions = EventDefinition::all();

        $rows = DB::table('city_events')->where('city_id', $cityId)
            ->where('status', EventCode::STATUS_ACTIVE)
            ->where('expires_at', '>', $now)
            ->orderBy('id')
            ->get(['id', 'event_id', 'expires_at']);

        return [
            'active_count' => $rows->count(),
            'active' => $rows->map(fn ($r) => [
                'event_instance_id' => (int) $r->id,
                'event_id'          => (string) $r->event_id,
                'name_zh'           => $definitions[$r->event_id]['name_zh'] ?? (string) $r->event_id,
                'expires_at'        => Carbon::parse($r->expires_at)->toIso8601String(),
            ])->all(),
        ];
    }

    // 单个实例的契约表示。**unmapped_zh 一并下发**:哪一段文案没有真实生效,玩家与后台都看得见
    private static function toContract(object $row, array $definition, Carbon $now): array
    {
        $options = [];
        foreach (EventCode::OPTIONS as $key) {
            $option = $definition['options_json'][$key] ?? null;
            if ($option === null) {
                continue;
            }
            $options[] = [
                'key'         => $key,
                'label_zh'    => $option['label_zh'] ?? $key,
                'desc_zh'     => $definition['option_' . $key . '_desc_zh'] ?? null,
                'unmapped_zh' => $option['unmapped_zh'] ?? [],
            ];
        }

        return [
            'event_instance_id' => (int) $row->id,
            'event_id'          => (string) $row->event_id,
            'name_zh'           => $definition['name_zh'],
            'category'          => $definition['category'],
            'event_type'        => $definition['event_type'],
            'status'            => (string) $row->status,
            'triggered_at'      => Carbon::parse($row->triggered_at)->toIso8601String(),
            'expires_at'        => Carbon::parse($row->expires_at)->toIso8601String(),
            // 剩余秒数由服务器算(客户端时间不可信,§16.3);前端拿它做倒计时,到点仍要重新拉一次
            'remaining_seconds' => max(0, Carbon::parse($row->expires_at)->getTimestamp() - $now->getTimestamp()),
            'duration_minutes'  => $definition['duration_minutes'],
            'condition_desc_zh' => $definition['condition_desc_zh'],
            'auto_effect_desc_zh' => $definition['auto_effect_desc_zh'],
            'auto_unmapped_zh'  => $definition['auto_effect_json']['unmapped_zh'] ?? [],
            // 已掷出的结果与已造成的变化:损失早在触发时就定死了(§11.3 掷点落库),
            // 给玩家看是为了让「我到底损失了多少」有据可查,不是给他改的余地
            'rolled'   => json_decode((string) $row->rolled_json, true) ?: [],
            'applied'  => json_decode((string) $row->applied_json, true) ?: [],
            'options'  => $options,
        ];
    }

    // ---------- 事件损失减免(D0.3 登记的 event_loss_reduction_pct,消费点就是这里)----------

    // 全城当前的损失减免比例,夹在 [0, 0.9]。两个来源:
    //   ① 事件自己写下的持续型 modifier(将来的「危机管理」类正向事件);
    //   ② NPC 特性(§6.3 里 N001 等的「危机事件稳定度提高」,trait_json 已结构化成该 target)。
    // 之所以由本类聚合而不是各系统自己扣:D0.3 的纪律是「每个 target 只有一个消费点」。
    public static function lossReduction(int $cityId, ?Carbon $now = null): float
    {
        $now ??= now();

        $total = (float) DB::table('city_active_modifiers')
            ->where('city_id', $cityId)
            ->where('target', ModifierTarget::EVENT_LOSS_REDUCTION_PCT)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->sum('value');

        // NPC 特性侧:只读 NPC 的定义与在编状态,不写、不改 NPC 系统的任何东西
        if (DB::getSchemaBuilder()->hasTable('city_npcs')) {
            $traits = DB::table('city_npcs as cn')
                ->join('npc_definition as nd', 'cn.npc_id', '=', 'nd.npc_id')
                ->where('cn.city_id', $cityId)
                ->whereIn('cn.status', ['idle', 'assigned'])
                ->pluck('nd.trait_json');

            foreach ($traits as $json) {
                foreach (NpcBonus::specsFromJson($json) as $spec) {
                    if ($spec->target === ModifierTarget::EVENT_LOSS_REDUCTION_PCT
                        && $spec->op === ModifierSpec::OP_PCT
                        && $spec->scope === ModifierSpec::SCOPE_CITY) {
                        $total += $spec->value;
                    }
                }
            }
        }

        return max(0.0, min(0.9, $total));
    }

    // ---------- 结算 ----------

    // POST /api/city/events/resolve。安全链逐段照 BuildService / TradeService 的模板走(CLAUDE §42)
    public static function resolve(City $city, int $instanceId, ?string $choice, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        // ---- 1. 总开关 ----
        // 停用事件系统通常是因为事件本身出了问题(算错 / 被刷),这时候最不该让玩家继续领结算。
        // 排在最前:停用期间连幂等键都不该落,否则重开后旧 key 会带着旧参数重放
        if (GameSetting::get(GameSetting::EVENT_ENABLED) !== true) {
            throw new GameRuleException(ErrorCode::EVENT_DISABLED, 422);
        }

        $requestHash = Idempotency::hash(AuditAction::EVENT_RESOLVE, [
            'instanceId' => $instanceId,
            'choice'     => $choice,
        ]);

        // ---- 2. 幂等(锁前快速路径)----
        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::EVENT_RESOLVE, $requestHash) !== null) {
            return self::diff($city->fresh(), [], self::instanceRow($instanceId));
        }

        return DB::transaction(function () use ($city, $instanceId, $choice, $idempotencyKey, $expectedRevision, $requestHash) {
            // ---- 3. 行锁 ----
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后重新校验,关掉「锁前检查、锁后写入」之间的并发窗口(TOCTOU)
            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::EVENT_RESOLVE, $requestHash) !== null) {
                return self::diff($city->fresh(), [], self::instanceRow($instanceId));
            }

            // ---- 4. Revision ----
            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // ---- 5. 锁内先跑 Time Delta 结算 ----
            // 不结算就发奖励,「按当前产能折算」的正向发放会用上一段的旧产能;
            // 也可能让玩家拿离线期间早被吃掉的旧余额去付选项成本
            $sim = SimulationService::applyLocked($locked, now());

            $now = now();

            // ---- 6. 实例:按 (id, city_id) 锁 —— 服务层不假设调用方一定做过所有权校验(Fail Closed)----
            $instance = DB::table('city_events')
                ->where('id', $instanceId)->where('city_id', $city->id)
                ->lockForUpdate()->first();

            if (! $instance) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }
            // §70 ② / ⑤:非 active = 已结算或已作废,不可再领
            if ($instance->status !== EventCode::STATUS_ACTIVE) {
                throw new GameRuleException(ErrorCode::EVENT_ALREADY_RESOLVED, 409);
            }
            // §70 ③:过期即不可领。
            //
            // 这里**只拒绝、不翻状态**:抛异常会回滚整个事务,顺手写的 status=expired 也会被一起抹掉
            // (试过一版就是这么错的)。翻牌统一交给懒结算的 expireOverdue() ——
            // 它在自己的事务里跑,才留得下 EVENT.EXPIRE 审计。
            // 玩家侧不会看到「过期了还挂着 active」:GET /api/city/events 先跑一次懒结算,
            // 而且快照对「已过期但还没翻牌」的实例一律显示为 expired(见 snapshot)。
            if (Carbon::parse($instance->expires_at)->lessThanOrEqualTo($now)) {
                throw new GameRuleException(ErrorCode::EVENT_EXPIRED, 422);
            }

            $definition = EventDefinition::find((string) $instance->event_id);
            if ($definition === null) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            // ---- 7. §70 ④:choice 合法性 ----
            $option = self::resolveOption($definition, $choice);

            // ---- 8. 应用选项效果 ----
            $rolled = json_decode((string) $instance->rolled_json, true) ?: [];
            $applied = json_decode((string) $instance->applied_json, true) ?: ['resources' => [], 'population' => 0, 'happiness' => 0];

            $effect = new EventEffect(
                $locked,
                $sim,
                $definition,
                [(int) $city->id, (int) $instance->window_index],
                self::lossReduction((int) $city->id, $now)
            );
            $effect->applyOption($instance, $option ?? ['effects' => [], 'unmapped_zh' => []], $rolled, $applied);
            $delta = $effect->commitResources();

            // ---- 9. 状态推进:**条件更新的影响行数**决定成败(§11.3:不能先查后写)----
            $updated = DB::table('city_events')
                ->where('id', $instanceId)
                ->where('status', EventCode::STATUS_ACTIVE)
                ->update([
                    'status'        => EventCode::STATUS_RESOLVED,
                    'chosen_option' => $choice,
                    'resolved_at'   => $now,
                    'rolled_json'   => json_encode($rolled, JSON_UNESCAPED_UNICODE),
                    'applied_json'  => json_encode($applied, JSON_UNESCAPED_UNICODE),
                ]);

            if ($updated === 0) {
                // 并发下另一条请求先结算了:整个事务回滚,资源变化一起撤销
                throw new GameRuleException(ErrorCode::EVENT_ALREADY_RESOLVED, 409);
            }

            // 结算即结束「等玩家做选择」的状态:duration=0 的事件(选择窗口)到此终止,
            // 持续型事件的 modifier 保留到 expires_at,由到期自然消退
            if ($definition['duration_minutes'] <= 0) {
                DB::table('city_active_modifiers')
                    ->where('source_type', 'event')->where('source_id', $instanceId)
                    ->where('ends_at', '>', $now)
                    // 例外:治安这类「瞬时冲击但必须有时长」的 flat 不能被结算抹掉,
                    // 否则 EVT_REFUGEES 选「拒绝」加的治安会在同一秒被自己清掉
                    ->where('target', ModifierTarget::SLOT_EVENT)
                    ->update(['ends_at' => $now]);
            }

            // ---- 10. 不变量(§52):资源与资金均 ≥ 0 ----
            $negative = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($negative > 0 || (float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::EVENT_RESOLVE, $requestHash);
            }

            // ---- 11. 审计 ----
            self::auditResolve($city, $definition, $instanceId, $choice, $option, $delta, $applied, $effect->notes(), (int) $locked->revision, $newRevision, $idempotencyKey);

            return self::diff($city->fresh(), $delta, self::instanceRow($instanceId));
        });
    }

    // §70 ④:choice 必须是该事件**真实存在**的选项。
    // 事件有选项却不传 choice 一律拒绝 —— 服务器不替玩家挑(挑错了是要扣资源的)
    private static function resolveOption(array $definition, ?string $choice): ?array
    {
        $options = array_filter($definition['options_json'] ?? [], fn ($o) => $o !== null);

        if ($options === []) {
            // 没有任何选项的事件:传了 choice 视为非法输入(客户端在猜接口)
            if ($choice !== null) {
                throw new GameRuleException(ErrorCode::EVENT_OPTION_INVALID, 422);
            }

            return null;
        }

        if ($choice === null || ! isset($options[$choice])) {
            throw new GameRuleException(ErrorCode::EVENT_OPTION_INVALID, 422, [
                'available_options' => array_keys($options),
            ]);
        }

        return $options[$choice];
    }

    private static function auditResolve(
        City $city,
        array $definition,
        int $instanceId,
        ?string $choice,
        ?array $option,
        array $delta,
        array $applied,
        array $notes,
        int $revisionBefore,
        int $revisionAfter,
        ?string $idempotencyKey
    ): void {
        AuditLogger::record(AuditAction::EVENT_RESOLVE, 'success', [
            'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
            'entity_type' => 'city_event', 'entity_id' => (string) $instanceId,
            'city_revision_before' => $revisionBefore, 'city_revision_after' => $revisionAfter,
            'before_json' => ['status' => EventCode::STATUS_ACTIVE],
            'after_json'  => ['status' => EventCode::STATUS_RESOLVED, 'chosen_option' => $choice],
            'delta_json'  => $delta,
            'idempotency_key' => $idempotencyKey,
            'metadata_json' => [
                'event_id' => $definition['event_id'],
                'option'   => $choice,
                'option_label' => $option['label_zh'] ?? null,
                // 没有生效的那一段文案:玩家投诉「我选了它但什么都没发生」时,这里就是答案
                'unmapped_zh'  => $option['unmapped_zh'] ?? [],
                'notes'        => $notes,
                'population_delta' => $applied['population'] ?? 0,
                'happiness_delta'  => $applied['happiness'] ?? 0,
            ],
        ]);

        $reward = array_filter($delta, fn ($amount) => $amount > 0);
        if ($reward !== []) {
            AuditLogger::record(AuditAction::EVENT_REWARD, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'city_event', 'entity_id' => (string) $instanceId,
                'delta_json' => $reward,
                'idempotency_key' => $idempotencyKey,
                'metadata_json' => [
                    'event_id'   => $definition['event_id'],
                    'source'     => 'option:' . (string) $choice,
                    'grant_mode' => 'direct_resource',
                ],
            ]);
        }
    }

    // 单行实例的契约表示(结算响应里回带,前端据此更新那一张卡片)
    public static function instanceRow(int $instanceId): ?array
    {
        $row = DB::table('city_events')->where('id', $instanceId)->first();
        if (! $row) {
            return null;
        }

        $definition = EventDefinition::find((string) $row->event_id);
        if ($definition === null) {
            return null;
        }

        return self::toContract($row, $definition, now());
    }

    // 资源/revision 简要 diff(与 BuildService::snapshotDiff 同一形状,前端一套解析代码走天下)
    private static function diff(City $city, array $delta = [], ?array $event = null): array
    {
        $diff = [
            'revision'  => (int) $city->revision,
            'resources' => DB::table('city_resources')->where('city_id', $city->id)
                ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
            'money'     => (float) $city->money,
            'population' => (int) $city->population,
            'happiness'  => (float) $city->happiness,
            'delta'     => $delta,
        ];

        if ($event !== null) {
            $diff['event'] = $event;
        }

        return $diff;
    }
}
