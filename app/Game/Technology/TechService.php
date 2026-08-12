<?php

namespace App\Game\Technology;

use App\Game\City\EraService;
use App\Game\Modifier\ConsumptionPoint;
use App\Game\Modifier\ModifierTarget;
use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\GameSetting;
use App\Support\Idempotency;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 科技研究(M2-B1):开始研究 → 计时 → 到点解锁。
//
// 时代口径(M2-B6 起):城市时代读 cities.era_order,B1 的「由已解锁科技派生时代」过渡逻辑已删除。
//
// 完整安全链照 BuildService 的顺序走:
//   幂等预检 → 事务 → cities 行锁 → 幂等复检 → Revision 校验 → 锁内先结算
//   → 规则校验 → 扣费 → 写状态 → 不变量 → 审计 → revision + 1
//
// 完成判定走「懒结算但不进内核」:settleFinished() 把 finished_at <= now 的在研项翻成 unlocked,
// 在快照组装与 research 端点锁内各调一次。**不改 SimulationService** ——
// 科技对产出的 tech 乘区(七乘区之一)本段不接线,留给 B3。
class TechService
{
    public const STATUS_RESEARCHING = 'researching';
    public const STATUS_UNLOCKED = 'unlocked';

    // 研究加速的最低速度倍率(安全夹取):research_speed_pct 合计到 −90% 以下就按 0.1 倍速算。
    // 与 ConstructionService::CONSTRUCTION_SPEED_FLOOR 逐字同一个值、同一条理由 ——
    // 除数不能是 0 或负数,否则工期会变成无穷大或负数(负工期 = 研究瞬间完成,那是能被刷的)。
    // 现有数据里这条 target 只有正值(N048 +8% / N070 +16% / N080 +25% / N106 +8% /
    // N130 +17% / N140 +28%),这个夹子是为「将来有负面事件投稿 −100% 甚至更负」准备的
    public const RESEARCH_SPEED_FLOOR = 0.1;

    // ---- 研究入口 ----

    // city 必须是当前登录玩家自己的城(由 CityFactory::createForUser 保证,端点不接受 city_id)
    public static function research(City $city, string $techId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        // 请求指纹:只含业务参数,不含 expected_revision(重试时 revision 可能已变)
        $requestHash = Idempotency::hash(AuditAction::TECH_RESEARCH_START, ['techId' => $techId]);

        // 幂等:同一 user+key+action+参数已处理则直接成功返回(不重复扣知识);key 被复用则 409
        if ($idempotencyKey !== null) {
            $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::TECH_RESEARCH_START, $requestHash);
            if ($existing) {
                return self::diff($city->fresh());
            }
        }

        $def = DB::table('technology_definition')->where('tech_id', $techId)->first();
        if (! $def) {
            // 科技 ID 不在定义表:属于输入不合法(客户端只该提交定义表里存在的 ID)
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        return DB::transaction(function () use ($city, $def, $techId, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后重新校验,关闭"锁前检查、锁后写入"之间的并发窗口(TOCTOU),与 Build/Worker 对齐
            if ($idempotencyKey !== null) {
                $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::TECH_RESEARCH_START, $requestHash);
                if ($existing) {
                    return self::diff($city->fresh());
                }
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            $now = now();

            // 锁内先跑 Time Delta 结算(CLAUDE §51):
            // 知识是靠 K 系建筑产出的库存资源,不结算就扣费 = 拿离线期间还没入库的知识付款
            $sim = SimulationService::applyLocked($locked, $now);

            // 锁内再把到点的在研项翻牌:否则「上一项其实早已完成」会被误判成"还有项目在研",
            // 玩家要多等一次快照轮询才能下一单
            self::settleFinished((int) $city->id, $now);

            // 规则 1:本城对该科技的既有状态(唯一索引 (city_id, tech_id) 在库层同样兜底)
            $own = DB::table('city_technologies')
                ->where('city_id', $city->id)->where('tech_id', $techId)->first();
            if ($own) {
                throw new GameRuleException(
                    $own->status === self::STATUS_RESEARCHING
                        ? ErrorCode::RESEARCH_IN_PROGRESS   // 这一项正在研究
                        : ErrorCode::VALIDATION_ERROR,      // 已解锁,重复提交属客户端状态过期
                    422
                );
            }

            // 规则 2:在研项数不得超过 research_parallel_limit(默认 1 = v3.2 的单线研究)。
            // 从 exists() 改成 count():上限可调之后「有没有在研」不再等于「还能不能再开一项」
            $parallelLimit = max(1, (int) GameSetting::get(GameSetting::RESEARCH_PARALLEL_LIMIT));
            $researchingCount = DB::table('city_technologies')
                ->where('city_id', $city->id)->where('status', self::STATUS_RESEARCHING)->count();
            if ($researchingCount >= $parallelLimit) {
                throw new GameRuleException(ErrorCode::RESEARCH_IN_PROGRESS, 422);
            }

            $eraOrders = EraService::orders();
            $unlocked = self::unlockedIds((int) $city->id);

            // 规则 3:时代要求(M2-B6 起直接读 cities.era_order,B1 的「已解锁科技最高时代」派生逻辑已作废)。
            // 口径按 v3.2 §5.1:「升级时代只开放该时代科技树」—— 只能研究**不高于当前时代**的科技,
            // 不再放行「当前时代 + 1」。想研究下一代科技必须先走 POST /api/city/era/upgrade。
            $currentEra = (int) $locked->era_order;
            $needEra = (int) ($eraOrders[$def->era_key] ?? PHP_INT_MAX);
            if ($needEra > $currentEra) {
                throw new GameRuleException(ErrorCode::ERA_REQUIRED, 422);
            }

            // 规则 4:前置科技全部已解锁(在研不算解锁)
            foreach (self::prerequisitesOf($def) as $pre) {
                if (! in_array($pre, $unlocked, true)) {
                    throw new GameRuleException(ErrorCode::TECH_NOT_UNLOCKED, 422);
                }
            }

            // 规则 5:费用足额 —— 一律用结算后的最新余额(资金 money 单列在 cities.money)
            $cost = self::costOf($def);
            foreach ($cost as $res => $amt) {
                $have = $res === ResourceCode::MONEY ? (float) $sim['money'] : (float) ($sim['resources'][$res] ?? 0);
                if ($have < $amt) {
                    throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
                }
            }

            // 扣费:v3.2 §5 的科技只有 knowledge_cost 一项,这里仍按「成本表」写,
            // 将来定义表加别的资源不用改这段
            $delta = [];
            foreach ($cost as $res => $amt) {
                if ($amt <= 0) {
                    continue;
                }
                if ($res === ResourceCode::MONEY) {
                    DB::table('cities')->where('id', $city->id)->decrement('money', $amt);
                } else {
                    DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $res)->decrement('amount', $amt);
                }
                $delta[$res] = -$amt;
            }

            // 计时:finished_at = now + 折减后的研究时长(定义里是 DECIMAL 分钟,换算成整秒)。
            //
            // ---- 研究加速(D0.3 的 research_speed_pct,**唯一消费点就是这里**,W7 接线)----
            //
            // 投稿者:§6.3 的 6 位学者类 NPC(N048 +8% / N070 +16% / N080 +25% /
            // N106 +8% / N130 +17% / N140 +28%,§6.1 的 SKILL_RESEARCH 也指向这条 target)。
            //
            // 口径与施工加速**逐字一致** —— 时长 ÷ (1 + Σpct),不是 × (1 − Σpct):
            // 后者在 Σpct ≥ 1 时会把时长打成 0 或负数(两位学者 +28% 与几件加成叠起来就够得着),
            // 而速度式无论加成多大都只是趋近于 0、永远到不了 0。下限另夹 RESEARCH_SPEED_FLOOR。
            //
            // 取值时机(v3.2 附录 A.3「研究一经开始不受建筑变动影响」):
            // 在**城市行锁内取一次**,当场把 finished_at 算死写库。此后招人 / 辞退 / 后台改数值
            // 一律不追溯已在研的项目 —— 否则同一项研究会出现两套真相(下单时的口径 vs 结算时的口径),
            // 玩家还会看到进度条倒退。这与「不追溯在研中的」是同一句话。
            // 全局工期倍率(tech_research_minutes_multiplier)先乘在定义值上,再走加速折减 ——
            // 与 ConstructionService::plannedSeconds 的顺序逐字一致(倍率是定义表的贵贱,加速是这座城的快慢)
            $researchSpeedPct = ConsumptionPoint::pct(ModifierTarget::RESEARCH_SPEED_PCT, (int) $city->id, $now);
            $speedMultiplier = max(self::RESEARCH_SPEED_FLOOR, 1.0 + $researchSpeedPct);
            $minutesMultiplier = (float) GameSetting::get(GameSetting::TECH_RESEARCH_MINUTES_MULTIPLIER);
            $baseSeconds = (int) max(0, round(((float) $def->research_minutes) * $minutesMultiplier * 60));
            // 取整方向 round:工期不是资源,四舍五入不产生任何可套利的零头(与 plannedSeconds 同口径)
            $durationSeconds = (int) max(0, round($baseSeconds / $speedMultiplier));
            $finishedAt = $now->copy()->addSeconds($durationSeconds);

            DB::table('city_technologies')->insert([
                'city_id'     => $city->id,
                'tech_id'     => $techId,
                'status'      => self::STATUS_RESEARCHING,
                'started_at'  => $now,
                'finished_at' => $finishedAt,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            // 不变量(CLAUDE §52):资源不为负(扣前已校验,双保险)
            $neg = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($neg > 0 || (float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }
            // 不变量:全城在研项数不超过上限(按落库后的真实行数复核,不信任上面的预检结果)
            $researching = DB::table('city_technologies')
                ->where('city_id', $city->id)->where('status', self::STATUS_RESEARCHING)->count();
            if ($researching > $parallelLimit) {
                throw new GameRuleException(ErrorCode::RESEARCH_IN_PROGRESS, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::TECH_RESEARCH_START, $requestHash);
            }

            AuditLogger::record(AuditAction::TECH_RESEARCH_START, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'technology', 'entity_id' => $techId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'delta_json' => $delta, 'idempotency_key' => $idempotencyKey,
                'metadata_json' => [
                    'branch'          => $def->branch,
                    'era'             => $def->era_key,
                    // researchMinutes 是定义值(基础口径,保留原字段名不动)。
                    // durationSeconds 记的是**实际**时长(已含研究加速),baseDurationSeconds 是折减前的秒数,
                    // researchSpeedPct 是本次吃到的加成 —— 三个都记,照 BuildService 的先例:
                    // 半年后要能回答「他这条科技为什么只花了 47 分钟」
                    'researchMinutes' => (float) $def->research_minutes,
                    'durationSeconds' => $durationSeconds,
                    'baseDurationSeconds' => $baseSeconds,
                    'researchSpeedPct' => round($researchSpeedPct, 6),
                    'finishedAt'      => $finishedAt->toIso8601String(),
                ],
            ]);

            return self::diff($city->fresh(), $delta);
        });
    }

    // ---- 懒结算:到点的在研项翻成已解锁 ----

    // 返回本次真正翻牌的条数。
    // 只做「researching + finished_at <= now → unlocked」这一件事,不碰资源/人口/revision:
    // 解锁本身不产生经济变化,没必要为它涨 revision 把玩家手里的 expected_revision 打旧。
    public static function settleFinished(int $cityId, ?CarbonInterface $now = null): int
    {
        $now = $now ?: now();

        $due = DB::table('city_technologies')
            ->where('city_id', $cityId)
            ->where('status', self::STATUS_RESEARCHING)
            ->where('finished_at', '<=', $now)
            ->orderBy('id')
            ->get(['id', 'tech_id', 'started_at', 'finished_at']);

        if ($due->isEmpty()) {
            return 0;
        }

        $city = DB::table('cities')->where('id', $cityId)->first(['id', 'user_id']);
        $settled = 0;

        foreach ($due as $row) {
            // 条件更新:status 仍是 researching 才翻牌。并发下(快照与研究端点同时跑)
            // 只有一方 affected = 1,保证 TECH.UNLOCK 恰好写一条(CLAUDE §53 审计不重不漏)
            $affected = DB::table('city_technologies')
                ->where('id', $row->id)
                ->where('status', self::STATUS_RESEARCHING)
                ->update(['status' => self::STATUS_UNLOCKED, 'updated_at' => $now]);
            if ($affected !== 1) {
                continue;
            }
            $settled++;

            AuditLogger::record(AuditAction::TECH_UNLOCK, 'success', [
                'actor_id' => $city?->user_id, 'user_id' => $city?->user_id, 'city_id' => $cityId,
                'entity_type' => 'technology', 'entity_id' => $row->tech_id,
                'before_json' => ['status' => self::STATUS_RESEARCHING],
                'after_json'  => ['status' => self::STATUS_UNLOCKED],
                'metadata_json' => [
                    'startedAt'  => Carbon::parse($row->started_at)->toIso8601String(),
                    'finishedAt' => Carbon::parse($row->finished_at)->toIso8601String(),
                ],
            ]);
        }

        return $settled;
    }

    // ---- 只读查询 ----

    // 快照区块:已解锁 tech_id 列表 + 在研项 + 时代进度
    // $eraOrder 由调用方从 cities.era_order 传入(B6 起城市时代是落库列,不再由科技派生)
    public static function snapshot(int $cityId, int $eraOrder): array
    {
        $rows = DB::table('city_technologies')
            ->where('city_id', $cityId)->orderBy('tech_id')
            ->get(['tech_id', 'status', 'started_at', 'finished_at']);

        $unlocked = [];
        $researching = null;
        foreach ($rows as $r) {
            if ($r->status === self::STATUS_UNLOCKED) {
                $unlocked[] = $r->tech_id;
                continue;
            }
            if ($r->status === self::STATUS_RESEARCHING) {
                $researching = [
                    'tech_id'     => $r->tech_id,
                    'started_at'  => Carbon::parse($r->started_at)->toIso8601String(),
                    'finished_at' => Carbon::parse($r->finished_at)->toIso8601String(),
                ];
            }
        }

        // 时代进度:前端据此把「超时代」的节点置灰并说明原因。
        // max_research_era_order 保留(前端契约不变),但按 v3.2 §5.1「只开放该时代科技树」,
        // 它现在恒等于当前时代,而不是 B1 时期的当前时代 + 1
        return [
            'unlocked'               => $unlocked,
            'researching'            => $researching,
            'current_era_order'      => $eraOrder,
            'max_research_era_order' => $eraOrder,
        ];
    }

    // 本城已解锁的 tech_id
    public static function unlockedIds(int $cityId): array
    {
        return DB::table('city_technologies')
            ->where('city_id', $cityId)->where('status', self::STATUS_UNLOCKED)
            ->orderBy('tech_id')->pluck('tech_id')->all();
    }

    // 定义里的前置科技列表
    private static function prerequisitesOf(object $def): array
    {
        return json_decode($def->prerequisite_tech_ids ?: '[]', true) ?: [];
    }

    // 定义里的研究成本(v3.2 §5 只有 knowledge_cost 一项)× 全局知识花费倍率。
    //
    // 倍率恰为 1.0 时原样返回定义值(默认配置下这条路径一个字节都不改);
    // 非 1.0 时向上取整 —— 与建造 / 升级成本同一个保守方向,零头永远算玩家的
    private static function costOf(object $def): array
    {
        $cost = (int) $def->knowledge_cost;
        $multiplier = (float) GameSetting::get(GameSetting::TECH_KNOWLEDGE_COST_MULTIPLIER);

        return [ResourceCode::KNOWLEDGE => $multiplier === 1.0 ? $cost : (int) ceil($cost * $multiplier)];
    }

    // 返回资源/revision/科技状态简要 diff(契约字段一律 snake_case 全小写)
    private static function diff(City $city, array $delta = []): array
    {
        return [
            'revision'     => (int) $city->revision,
            // map 型(键为资源 code)一律过 ApiResponse::map:空时也要是 `{}` 不是 `[]`(见 BuildService::snapshotDiff)。
            // technologies 是快照区块,里头 unlocked 是**列表型**,保持数组不动
            'resources'    => ApiResponse::map(DB::table('city_resources')->where('city_id', $city->id)
                ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all()),
            'money'        => (float) $city->money,
            'delta'        => ApiResponse::map($delta),
            'technologies' => self::snapshot((int) $city->id, (int) $city->era_order),
        ];
    }
}
