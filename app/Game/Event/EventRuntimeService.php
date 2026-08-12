<?php

namespace App\Game\Event;

use App\Game\Simulation\SimConstants;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\GameSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 随机事件的触发引擎(v3.2 §9.1 + backlog §6.2 + 9.D2/D3/D5 批准口径)。
//
// ══ 懒结算,不跑 cron ═════════════════════════════════════════════════════════
// 结构照 NpcRuntimeService:独立时钟 cities.event_settled_at,在快照与事件端点上被动触发,
// 按经过的**资格窗口数**补算。共享主机的 cron 会漏跑 / 延迟 / 并发,而事件一旦漏了一窗就再也补不回来。
//
// ══ 窗口与 EPOCH(9.D5 批准:与市场共用 EPOCH,窗长各自定义)═════════════════
//   window_index = floor(unix 时间戳 / event_window_seconds)
// 原点与 PriceEngine 一样固定取 Unix 纪元 0 —— 两个系统因此天然对齐同一条时间轴(9.D5「共用同一 EPOCH 常量」),
// 而窗长各自可调(事件 60 秒 / 市场 60 秒,都在 game_settings 里)。
// 用 0 做原点的理由也一样:换成「开服时间」就要多存一个配置,配置一改历史窗口号的含义就变了。
//
// ══ 掷点确定性(§30 / §66 / backlog §11.3)═══════════════════════════════════
// 每个窗口的「要不要触发 / 抽中哪一条」都由 EventRandom(HMAC(密钥, city|window|label))派生:
// 同一座城市、同一个窗口,重算一百次都是同一个结果 → 玩家不能靠「退出再上线」把不喜欢的事件刷掉。
//
// ══ 离线补算(9.D3 批准)═════════════════════════════════════════════════════
// 12h = 720 窗 × 8% ≈ 期望 57.6 次。**单次结算最多补 3 次**(后台可调),
// 且冷却与并发上限逐窗推进,不用 1−(1−p)^n 一把梭(那样会丢掉冷却与并发的语义)。
//
// ══ 补算出来的事件用「此刻」作触发时刻(实现口径,写进注释以免被当成 bug)═══════
// 掷点用的是窗口号(可重算、防刷),但实例的 triggered_at / expires_at 一律取**本次结算时刻**。
// 若按窗口时刻回填,离线 12 小时补出来的事件会在生成的同一瞬间就过期 ——
// 玩家永远看不到它的选项,只会收到一条「你损失了 300 粮食」的通知。
final class EventRuntimeService
{
    // 快照 / 事件端点的入口:自开事务 + 锁城市行。$sim = 本次 SimulationService 的结算结果
    public static function settle(City $city, array $sim): void
    {
        DB::transaction(function () use ($city, $sim) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            $now = now();

            // ① 到期作废:与总开关无关。关掉事件系统只是「不再触发新的」,
            //    已生效的实例仍然照常到期消退 —— 否则一关开关,全服的减益就永久卡在生效状态
            self::expireOverdue($locked, $now);

            $last = $locked->event_settled_at !== null
                ? Carbon::parse($locked->event_settled_at)
                : Carbon::parse($locked->last_simulated_at);

            $elapsed = max(0, $now->getTimestamp() - $last->getTimestamp());
            // 离线封顶:超出部分不补算,但时钟照样推进到 $now(否则积压会被反复重算)
            $elapsed = min($elapsed, (int) GameSetting::get(GameSetting::MAX_OFFLINE_SECONDS));

            DB::table('cities')->where('id', $city->id)->update(['event_settled_at' => $now]);

            if ($elapsed <= 0 || GameSetting::get(GameSetting::EVENT_ENABLED) !== true) {
                return;
            }

            self::rollWindows($locked, $sim, $now, $now->copy()->subSeconds($elapsed));
        });
    }

    // ---------- 到期作废(§70:过期即不可领)----------

    // 条件更新一次翻完,再逐行补审计。
    // 先 UPDATE 再查 —— 反过来(先查后写)在并发下会把同一条翻两次、写两条 EXPIRE 审计
    private static function expireOverdue(object $locked, Carbon $now): void
    {
        $overdue = DB::table('city_events')
            ->where('city_id', $locked->id)
            ->where('status', EventCode::STATUS_ACTIVE)
            ->where('expires_at', '<=', $now)
            ->lockForUpdate()
            ->get(['id', 'event_id', 'triggered_at', 'expires_at']);

        if ($overdue->isEmpty()) {
            return;
        }

        DB::table('city_events')
            ->whereIn('id', $overdue->pluck('id')->all())
            ->where('status', EventCode::STATUS_ACTIVE)
            ->update(['status' => EventCode::STATUS_EXPIRED]);

        foreach ($overdue as $row) {
            AuditLogger::record(AuditAction::EVENT_EXPIRE, 'success', [
                // 不是玩家操作:actor 是 system,但 user_id / city_id 照记,方便按玩家回查
                'actor_type' => 'system', 'actor_id' => null,
                'user_id' => $locked->user_id, 'city_id' => $locked->id,
                'entity_type' => 'city_event', 'entity_id' => (string) $row->id,
                'reason_code' => 'EXPIRED',
                'before_json' => ['status' => EventCode::STATUS_ACTIVE],
                'after_json'  => ['status' => EventCode::STATUS_EXPIRED],
                'metadata_json' => ['event_id' => $row->event_id, 'expires_at' => (string) $row->expires_at],
            ]);
        }
    }

    // ---------- 逐窗掷点 ----------

    private static function rollWindows(object $locked, array $sim, Carbon $now, Carbon $windowStart): void
    {
        $windowSeconds = max(1, (int) GameSetting::get(GameSetting::EVENT_WINDOW_SECONDS));
        $firstWindow = intdiv($windowStart->getTimestamp(), $windowSeconds) + 1;
        $lastWindow = intdiv($now->getTimestamp(), $windowSeconds);
        if ($firstWindow > $lastWindow) {
            return;
        }

        $budget = (int) GameSetting::get(GameSetting::EVENT_OFFLINE_MAX_TRIGGERS);
        if ($budget <= 0) {
            return;
        }

        $chance = (float) GameSetting::get(GameSetting::EVENT_TRIGGER_CHANCE);
        $maxActive = (int) GameSetting::get(GameSetting::EVENT_MAX_ACTIVE);
        $maxDisaster = (int) GameSetting::get(GameSetting::EVENT_MAX_ACTIVE_DISASTER);

        // 并发与冷却的当前状态:整段补算期间在内存里滚动,不逐窗查库
        $active = self::activeSummary((int) $locked->id, $now);
        $cooldowns = DB::table('city_event_cooldowns')->where('city_id', $locked->id)
            ->pluck('available_at', 'event_id')
            ->map(fn ($at) => Carbon::parse($at))->all();

        $metrics = EventCondition::snapshot($locked, $sim);
        $definitions = EventDefinition::enabled();

        for ($window = $firstWindow; $window <= $lastWindow; $window++) {
            if ($budget <= 0 || count($active['ids']) >= $maxActive) {
                break;
            }
            if (! EventRandom::chance($chance, (int) $locked->id, $window, 'trigger')) {
                continue;
            }

            $definition = self::pickCandidate($definitions, $metrics, $active, $cooldowns, $now, $maxDisaster, (int) $locked->id, $window);
            if ($definition === null) {
                continue; // 掷中了但没有合格候选(冷却 / 条件 / 并发):这一窗作废,不顺延
            }

            self::trigger($locked, $sim, $definition, $window, $now, $active, $cooldowns);
            $budget--;

            // 触发的自动效果会改资源 / 人口 / 幸福,后续窗口的条件判定必须看到新状态
            $locked = DB::table('cities')->where('id', $locked->id)->first();
            $sim = self::refreshSim($sim, $locked);
            $metrics = EventCondition::snapshot($locked, $sim);
        }
    }

    // 生效中的实例摘要:[ids => [event_id…], disaster => 灾害/国防类计数]
    private static function activeSummary(int $cityId, Carbon $now): array
    {
        $rows = DB::table('city_events as ce')
            ->join('event_definition as ed', 'ce.event_id', '=', 'ed.event_id')
            ->where('ce.city_id', $cityId)
            ->where('ce.status', EventCode::STATUS_ACTIVE)
            ->where('ce.expires_at', '>', $now)
            ->get(['ce.event_id', 'ed.category']);

        $ids = [];
        $disaster = 0;
        foreach ($rows as $row) {
            $ids[] = (string) $row->event_id;
            if (in_array($row->category, EventCode::CATEGORY_GROUP_DISASTER_DEFENSE, true)) {
                $disaster++;
            }
        }

        return ['ids' => $ids, 'disaster' => $disaster];
    }

    // 候选池 + 权重掷点。返回中签的定义,或 null(没有合格候选)
    private static function pickCandidate(
        array $definitions,
        array $metrics,
        array $active,
        array $cooldowns,
        Carbon $now,
        int $maxDisaster,
        int $cityId,
        int $window
    ): ?array {
        $weights = [];
        $details = [];

        foreach ($definitions as $eventId => $definition) {
            // 同一事件不重复叠加(§9.1「同一 event_id 受冷却限制」的同期版本)
            if (in_array($eventId, $active['ids'], true)) {
                continue;
            }
            // 冷却未到
            if (isset($cooldowns[$eventId]) && $cooldowns[$eventId]->greaterThan($now)) {
                continue;
            }
            // 灾害 / 国防类的独立并发上限(§9.1)
            if (in_array($definition['category'], EventCode::CATEGORY_GROUP_DISASTER_DEFENSE, true)
                && $active['disaster'] >= $maxDisaster) {
                continue;
            }

            [$weight, $detail] = EventCondition::weight($definition, $metrics);
            if ($weight <= 0) {
                continue;
            }

            $weights[$eventId] = $weight;
            $details[$eventId] = $detail;
        }

        if ($weights === []) {
            return null;
        }

        // 键顺序固定(EventDefinition::all 按 event_id 排序),掷点才可重算
        $picked = EventRandom::weightedKey($weights, $cityId, $window, 'pick');
        if ($picked === null) {
            return null;
        }

        $definition = $definitions[$picked];
        // 权重明细随定义一起带下去,进 EVENT.TRIGGER 的审计:
        // 「为什么偏偏抽中这一条」半年后要回答得出来
        $definition['_weight'] = $weights[$picked];
        $definition['_weight_detail'] = $details[$picked];
        $definition['_weight_pool'] = array_map(fn ($w) => round($w, 4), $weights);

        return $definition;
    }

    // ---------- 管理员手动触发(W11-C1 任务5,测试 / 线上复现用)----------

    // 强制在某座城市触发指定事件。**复用 trigger() 同一条落地路径** ——
    // 同一个 city_events 实例、同一个 EventEffect、同一条 EVENT.TRIGGER 审计、同一份冷却写入。
    // 不另起一套「管理员专用的简化触发」是硬要求:两条路径迟早会漂移,
    // 而复现出来的现场一旦与线上不是同一条代码,复现本身就没有意义了。
    //
    // ══ 跳过什么、不跳过什么 ═══════════════════════════════════════════════════
    //   跳过:① 要不要触发的权重掷点(EventRandom::chance)—— 复现不该看运气;
    //         ② 冷却(city_event_cooldowns)—— 冷却是给自然节奏用的,不是安全边界。
    //   照常尊重:并发上限 max_active / 灾害档 max_active_disaster、同事件不重复叠加、
    //         事件总开关与该事件的 enabled、锁内先结算。
    //         并发上限**必须**守住:它挡的是「同时挂 5 个减益把城市打崩」,
    //         而那正是手滑连点两下就会发生的事(满了返回 422,不静默排队)。
    //
    // ══ 与自然触发怎么区分 ═════════════════════════════════════════════════════
    // EVENT.TRIGGER 那条审计的 actor 仍是 system(它记录的是「事件发生了」这件事,口径不能变),
    // 另外**再写一条** ADMIN.CONFIG_CHANGE(actor_type=admin,metadata 带 event_id / reason / forced=true)。
    // 两条审计共享同一个 entity_id(实例 id),按实例 id 一查就知道这一次是不是人为触发的。
    public static function forceTrigger(City $city, string $eventId, int $adminId, string $reason): array
    {
        $definition = EventDefinition::find($eventId);
        if ($definition === null) {
            throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
        }

        // 总开关与逐事件开关照常尊重:事件被关掉通常是因为它本身算错了 / 被刷,
        // 这种时候最不该给一个「绕过开关也能放出来」的后门。
        // 真要复现,管理员手上就有 /api/admin/definitions/event 可以先把它打开(同一档权限)
        if (GameSetting::get(GameSetting::EVENT_ENABLED) !== true || ! $definition['enabled']) {
            throw new GameRuleException(ErrorCode::EVENT_DISABLED, 422);
        }

        return DB::transaction(function () use ($city, $definition, $adminId, $reason) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            $now = now();

            // 到期作废先跑一遍:否则「已过期但还没被翻牌」的实例会占着并发名额,
            // 让管理员看到一个假的「上限已满」
            self::expireOverdue($locked, $now);

            $active = self::activeSummary((int) $locked->id, $now);
            $maxActive = (int) GameSetting::get(GameSetting::EVENT_MAX_ACTIVE);
            $maxDisaster = (int) GameSetting::get(GameSetting::EVENT_MAX_ACTIVE_DISASTER);
            $isDisaster = in_array($definition['category'], EventCode::CATEGORY_GROUP_DISASTER_DEFENSE, true);

            // 同一事件不重复叠加(与自然路径 pickCandidate 的第一道过滤同口径):
            // 叠第二份会让持续型 modifier 双倍生效,而那是纯粹的数值事故
            if (in_array($definition['event_id'], $active['ids'], true)) {
                throw new GameRuleException(ErrorCode::EVENT_LIMIT_REACHED, 422, [
                    'limit' => 'already_active', 'event_id' => $definition['event_id'],
                ]);
            }
            if (count($active['ids']) >= $maxActive) {
                throw new GameRuleException(ErrorCode::EVENT_LIMIT_REACHED, 422, [
                    'limit' => 'max_active', 'current' => count($active['ids']), 'max' => $maxActive,
                ]);
            }
            if ($isDisaster && $active['disaster'] >= $maxDisaster) {
                throw new GameRuleException(ErrorCode::EVENT_LIMIT_REACHED, 422, [
                    'limit' => 'max_active_disaster', 'current' => $active['disaster'], 'max' => $maxDisaster,
                ]);
            }

            // 锁内先跑 Time Delta 结算(照玩家路径纪律):
            // 正向事件按「当前真实产能」折算发放量,不结算就会用上一段的旧产能算奖励
            $sim = SimulationService::applyLocked($locked, $now);
            $locked = DB::table('cities')->where('id', $locked->id)->first();

            $windowSeconds = max(1, (int) GameSetting::get(GameSetting::EVENT_WINDOW_SECONDS));
            $window = intdiv($now->getTimestamp(), $windowSeconds);

            // 冷却表传空数组:手动触发不读冷却(跳过),但 trigger() 内部照常**写**新的冷却行 ——
            // 复现完之后自然路径不该立刻再抽到同一条
            $cooldowns = [];
            $instanceId = self::trigger($locked, $sim, $definition, $window, $now, $active, $cooldowns);

            // 第二条审计:与自然触发的区分点。actor_type=admin,reason 强制填,
            // metadata 里 forced=true 让「按实例 id 反查是不是人为触发」成为一次等值查询
            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $adminId,
                'user_id' => $locked->user_id, 'city_id' => $locked->id,
                'entity_type' => 'city_event', 'entity_id' => (string) $instanceId,
                'reason_code' => $reason,
                'metadata_json' => [
                    'forced'       => true,
                    'event_id'     => $definition['event_id'],
                    'reason'       => $reason,
                    'window_index' => $window,
                    // 跳过了哪两件事,写进审计而不是只写在代码注释里
                    'skipped'      => ['weight_roll', 'cooldown'],
                ],
            ]);

            return [
                'event_instance_id' => $instanceId,
                'event_id'          => $definition['event_id'],
                'name_zh'           => $definition['name_zh'],
                'city_id'           => (int) $locked->id,
                'triggered_at'      => $now->toIso8601String(),
                'window_index'      => $window,
                'active_count'      => count($active['ids']),
                'max_active'        => $maxActive,
            ];
        });
    }

    // ---------- 触发 ----------

    // 返回新建实例的 id(自然路径不用它,手动触发路径要靠它挂第二条审计)
    private static function trigger(
        object $locked,
        array $sim,
        array $definition,
        int $window,
        Carbon $now,
        array &$active,
        array &$cooldowns
    ): int {
        // 有选项但持续时间为 0 的事件(EVT_GRANARY_PEST / EVT_REFUGEES…)也必须有一个过期时刻:
        // §70 要求 expires_at 非空,而「永不过期的待办」会让玩家的事件列表越积越长
        $duration = $definition['duration_minutes'] > 0
            ? $definition['duration_minutes']
            : (int) GameSetting::get(GameSetting::EVENT_CHOICE_WINDOW_MINUTES);

        $expiresAt = $now->copy()->addMinutes($duration);

        $instanceId = (int) DB::table('city_events')->insertGetId([
            'city_id'      => $locked->id,
            'event_id'     => $definition['event_id'],
            'status'       => EventCode::STATUS_ACTIVE,
            'triggered_at' => $now,
            'expires_at'   => $expiresAt,
            'window_index' => $window,
            'rolled_json'  => null,
            'applied_json' => null,
        ]);

        $effect = new EventEffect(
            $locked,
            $sim,
            $definition,
            [(int) $locked->id, $window],
            EventService::lossReduction((int) $locked->id, $now)
        );
        ['rolled' => $rolled, 'applied' => $applied] = $effect->applyAuto(
            $instanceId,
            $now,
            // 持续型 modifier 的结束时刻恒取「触发 + duration_minutes」:
            // duration=0 的事件不该因为「给玩家留了 60 分钟做选择」而让减益也持续 60 分钟
            $now->copy()->addMinutes($definition['duration_minutes'])
        );

        // 资源落库放在最后一次做:中间的多条效果只在内存里累计,
        // 避免「先扣后加」在数据库上留下一串中间态(也少几次往返)
        $applied['resources'] = $effect->commitResources();

        DB::table('city_events')->where('id', $instanceId)->update([
            'rolled_json'  => json_encode($rolled, JSON_UNESCAPED_UNICODE),
            'applied_json' => json_encode($applied, JSON_UNESCAPED_UNICODE),
        ]);

        // 冷却(§9.1):从触发时刻起算
        DB::table('city_event_cooldowns')->updateOrInsert(
            ['city_id' => $locked->id, 'event_id' => $definition['event_id']],
            ['available_at' => $now->copy()->addMinutes($definition['cooldown_minutes'])]
        );

        $active['ids'][] = $definition['event_id'];
        if (in_array($definition['category'], EventCode::CATEGORY_GROUP_DISASTER_DEFENSE, true)) {
            $active['disaster']++;
        }
        $cooldowns[$definition['event_id']] = $now->copy()->addMinutes($definition['cooldown_minutes']);

        self::auditTrigger($locked, $definition, $instanceId, $window, $expiresAt, $rolled, $applied, $effect->notes());

        return $instanceId;
    }

    // 触发审计(actor = system)。正向事件的资源发放另写一条 EVENT.REWARD ——
    // 「发了多少」必须能单独查、单独统计,混在 TRIGGER 里就统计不出来了
    private static function auditTrigger(
        object $locked,
        array $definition,
        int $instanceId,
        int $window,
        Carbon $expiresAt,
        array $rolled,
        array $applied,
        array $notes
    ): void {
        $delta = $applied['resources'] ?? [];

        AuditLogger::record(AuditAction::EVENT_TRIGGER, 'success', [
            'actor_type' => 'system', 'actor_id' => null,
            'user_id' => $locked->user_id, 'city_id' => $locked->id,
            'entity_type' => 'city_event', 'entity_id' => (string) $instanceId,
            'after_json'  => ['status' => EventCode::STATUS_ACTIVE, 'expires_at' => $expiresAt->toDateTimeString()],
            'delta_json'  => $delta,
            'metadata_json' => [
                'event_id'     => $definition['event_id'],
                'category'     => $definition['category'],
                'event_type'   => $definition['event_type'],
                'window_index' => $window,
                // §9.1 的权重公式三件套 + 本窗候选池:掷点结果可完整复盘
                'base_weight'  => $definition['base_weight'],
                'weight'       => round($definition['_weight'] ?? 0, 4),
                'weight_detail' => $definition['_weight_detail'] ?? [],
                'weight_pool'  => $definition['_weight_pool'] ?? [],
                'rolled'       => $rolled,
                'population_delta' => $applied['population'] ?? 0,
                'happiness_delta'  => $applied['happiness'] ?? 0,
                'notes'        => $notes,
            ],
        ]);

        $reward = array_filter($delta, fn ($amount) => $amount > 0);
        if ($reward !== [] && $definition['event_type'] === EventCode::TYPE_POSITIVE) {
            AuditLogger::record(AuditAction::EVENT_REWARD, 'success', [
                'actor_type' => 'system', 'actor_id' => null,
                'user_id' => $locked->user_id, 'city_id' => $locked->id,
                'entity_type' => 'city_event', 'entity_id' => (string) $instanceId,
                'delta_json' => $reward,
                'metadata_json' => [
                    'event_id' => $definition['event_id'],
                    'source'   => 'auto_effect',
                    // §13 帽修正方向的落地记号:正向事件是「直接发资源」而不是加乘区
                    'grant_mode' => 'direct_resource',
                ],
            ]);
        }
    }

    // 触发改过库之后,把 $sim 里被改动的几项同步成最新值(条件判定与后续效果都读它)。
    // 只同步「事件会改的那几项」:资源与资金由 EventEffect 自己在内存里推进过,
    // 这里补的是从 cities 行才读得到的人口与幸福
    private static function refreshSim(array $sim, object $locked): array
    {
        $sim['population'] = (int) $locked->population;
        $sim['happiness'] = (float) $locked->happiness;
        $sim['money'] = (float) $locked->money;
        $sim['resources'] = DB::table('city_resources')->where('city_id', $locked->id)
            ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all();

        return $sim;
    }
}
