<?php

namespace App\Game\NPC;

use App\Game\City\EraService;
use App\Game\Simulation\SimConstants;
use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\GameSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// NPC 运行时状态的懒结算:XP / 士气 / 离职 / 自然增长(backlog §9 A1 / A4 / A6)。
//
// 为什么不放进 SimulationService:
//   结算内核在 M3 只被允许改一处(总线的通用支出消费点),而这四件事都要**写** city_npcs。
//   项目里已经有现成的同款做法 —— TechService::settleFinished:懒结算挂在快照/端点上,
//   不占结算内核的一段,也不新增每分钟 tick。本类照抄那条路径。
//
// 时钟:cities.npc_settled_at 单独一列(不复用 last_simulated_at ——
// 两者共用一个时钟会互相吃掉对方的经过时间)。离线封顶沿用 SimConstants::MAX_OFFLINE_SECONDS,
// 加上「单次最多补算 N 名自然增长」的上限,挡住 backlog §11.4 点名的「离线雪崩」。
//
// 随机数一律走 NpcRandom(CSPRNG,服务器权威,客户端不参与,§30 / §66 / §11.3)。
final class NpcRuntimeService
{
    // 快照路径入口:自开事务 + 锁城市行。$sim = 本次 SimulationService 的结算结果
    // (幸福 / 人口 / 人口容量 / 欠费状态都从它取,不再另算一份,避免两套口径)
    public static function settle(City $city, array $sim): void
    {
        DB::transaction(function () use ($city, $sim) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            $now = now();
            $last = $locked->npc_settled_at !== null
                ? Carbon::parse($locked->npc_settled_at)
                : Carbon::parse($locked->last_simulated_at);

            $elapsed = max(0, $now->getTimestamp() - $last->getTimestamp());
            // 离线封顶:超出部分不补算,但时钟照样推进到 $now(否则积压会被反复重算)
            $elapsed = min($elapsed, SimConstants::MAX_OFFLINE_SECONDS);

            DB::table('cities')->where('id', $city->id)->update(['npc_settled_at' => $now]);

            $minutes = $elapsed / 60.0;
            if ($minutes <= 0) {
                return;
            }

            $npcs = DB::table('city_npcs')->where('city_id', $city->id)
                ->whereIn('status', NpcCode::ACTIVE_STATUSES)
                ->lockForUpdate()->get();

            if ($npcs->isNotEmpty()) {
                self::stepXp($npcs, $minutes);
                self::stepMorale($locked, $npcs, $sim, $minutes);
            }

            self::stepNaturalGrowth($locked, $sim, $minutes);
        });
    }

    // ---------- A6:工作 XP ----------

    // 「工作分钟数」口径:只有**已派驻**的 NPC 涨 XP(§6.2「工作中每 60 秒获得基础 XP」)。
    // 跨级要在一次结算里正确翻多级(§6.2 的曲线是累进的,离线 12h 可能连升好几级)。
    //
    // A6 里的另外三项(完成事件 +50 / 成功升级建筑 +100 / 高负载 ×1.5)本波次**不登记也不实现**:
    // 前两项的触发点在 D4 事件与升级服务里(都不在本波次的改动范围),第三项要读乘数积
    // (只有结算内核算得出来)。登记了却没人写入 = 误导运营的死配置,所以留给对应波次。
    private static function stepXp($npcs, float $minutes): void
    {
        $gain = (int) floor((float) GameSetting::get(GameSetting::NPC_XP_PER_MIN) * $minutes);
        if ($gain <= 0) {
            return;
        }

        $curve = DB::table('npc_skill_level_curve')->pluck('xp_to_next', 'level')
            ->map(fn ($x) => (int) $x)->all();
        $maxLevels = DB::table('npc_definition')->pluck('max_level', 'npc_id')
            ->map(fn ($l) => (int) $l)->all();

        foreach ($npcs as $n) {
            if ($n->status !== NpcCode::STATUS_ASSIGNED) {
                continue;
            }

            $maxLevel = $maxLevels[$n->npc_id] ?? 10;
            $level = (int) $n->skill_level;
            if ($level >= $maxLevel) {
                continue; // 满级:不再累计 XP(曲线上 10 级的 xp_to_next 本来就是 0)
            }

            $xp = (int) $n->xp + $gain;
            while ($level < $maxLevel && ($curve[$level] ?? 0) > 0 && $xp >= $curve[$level]) {
                $xp -= $curve[$level];
                $level++;
            }
            if ($level >= $maxLevel) {
                $xp = 0;
            }

            DB::table('city_npcs')->where('id', $n->id)
                ->update(['skill_level' => $level, 'xp' => $xp, 'updated_at' => now()]);

            // 内存里的行同步更新:后面的士气/离职判定用的是同一批对象
            $n->skill_level = $level;
            $n->xp = $xp;
        }
    }

    // ---------- A4:士气与离职 ----------

    // 三条速率可以叠加(欠薪 -2/min 与低幸福 -1/min 同时成立就是 -3/min);
    // 两者都不成立才按 +0.5/min 回升。§16.5 的重点是「发不出工资要有后果」——
    // 内核那边资金被夹在 0(不是负债),后果就落在这里的士气上。
    private static function stepMorale(object $locked, $npcs, array $sim, float $minutes): void
    {
        if (GameSetting::get(GameSetting::NPC_MORALE_ENABLED) !== true) {
            return;
        }

        $happiness = (float) ($sim['happiness'] ?? $locked->happiness);
        // 欠费信号直接取内核的判定结果:NPC 工资已经并进了全城维护速率,
        // 所以 maintenanceArrears = true 就等价于「这一段的工资 + 维护付不出来」
        $arrears = (bool) ($sim['maintenanceArrears'] ?? false);

        $lowThreshold = (float) GameSetting::get(GameSetting::NPC_MORALE_LOW_HAPPINESS_THRESHOLD);
        $delta = 0.0;
        if ($arrears) {
            $delta -= (float) GameSetting::get(GameSetting::NPC_MORALE_WAGE_ARREARS_PENALTY_PER_MIN);
        }
        if ($happiness < $lowThreshold) {
            $delta -= (float) GameSetting::get(GameSetting::NPC_MORALE_LOW_HAPPINESS_PENALTY_PER_MIN);
        }
        if ($delta === 0.0) {
            $delta = (float) GameSetting::get(GameSetting::NPC_MORALE_RECOVER_PER_MIN);
        }

        $leaveThreshold = (float) GameSetting::get(GameSetting::NPC_MORALE_LEAVE_THRESHOLD);
        $leaveChance = (float) GameSetting::get(GameSetting::NPC_MORALE_LEAVE_CHANCE);
        $leaveWindow = max(1.0, (float) GameSetting::get(GameSetting::NPC_MORALE_LEAVE_WINDOW_MINUTES));
        $windows = (int) floor($minutes / $leaveWindow);

        foreach ($npcs as $n) {
            $morale = max(0.0, min(100.0, (float) $n->morale + $delta * $minutes));

            $left = false;
            // 离职逐窗掷点(不用 1-(1-p)^n 一把梭:那样会丢掉「窗口」这个语义,
            // 也没法在将来给玩家发「第 N 个窗口他要走了」的预警通知)
            if ($morale < $leaveThreshold && $windows > 0 && $leaveChance > 0) {
                for ($w = 0; $w < $windows; $w++) {
                    if (NpcRandom::chance($leaveChance)) {
                        $left = true;
                        break;
                    }
                }
            }

            DB::table('city_npcs')->where('id', $n->id)->update([
                'morale'     => round($morale, 2),
                'updated_at' => now(),
            ]);

            if ($left) {
                // 离场本身走统一入口:状态位 / 岗位解绑 / 审计口径只有一份实现
                self::markLeft(
                    $locked,
                    $n,
                    'MORALE_TOO_LOW',
                    before: ['status' => $n->status, 'morale' => (float) $n->morale],
                    after: ['status' => NpcCode::STATUS_LEFT, 'morale' => round($morale, 2)],
                    delta: ['morale' => round($morale - (float) $n->morale, 2)],
                    metadata: ['windows' => $windows],
                );
            }
        }
    }

    // ---------- 离场:两个来源共用的唯一写入点 ----------

    // 把一名在编 NPC 翻成 left(顺手解绑岗位)并写 NPC.LEAVE 审计。
    //
    // 两个调用方:① A4 士气过低自行离职;② D4 事件 EVT_BRAIN_DRAIN 的人才流失。
    // 抽成一处不是为了少写几行,而是为了让「NPC 怎么离场」只有一份口径 ——
    // 状态位、assigned_instance_id 解绑、审计的 actor/entity 三件事各写一遍迟早会走样。
    private static function markLeft(
        object $locked,
        object $npc,
        string $reasonCode,
        array $before,
        array $after,
        array $delta,
        array $metadata
    ): void {
        DB::table('city_npcs')->where('id', $npc->id)->update([
            'status'               => NpcCode::STATUS_LEFT,
            'assigned_instance_id' => null,
            'updated_at'           => now(),
        ]);

        AuditLogger::record(AuditAction::NPC_LEAVE, 'success', [
            // 不是玩家操作:actor 是 system,但 user_id / city_id 照记,方便按玩家回查
            'actor_type' => 'system', 'actor_id' => null,
            'user_id' => $locked->user_id, 'city_id' => $locked->id,
            'entity_type' => 'city_npc', 'entity_id' => (string) $npc->id,
            'reason_code' => $reasonCode,
            'before_json' => $before,
            'after_json'  => $after,
            'delta_json'  => $delta,
            'metadata_json' => array_merge(['npc_id' => $npc->npc_id], $metadata),
        ]);
    }

    // 随机流失一名在编 NPC(v3.2 §9.2 EVT_BRAIN_DRAIN 人才流失的落点)。
    //
    // 为什么写在 NPC 模块而不是事件模块:city_npcs 的状态位归本模块所有(D1 的职责边界),
    // 事件系统只负责「什么时候流失」,不负责「怎么流失」。EVT_BRAIN_DRAIN 在 M3-D4 交付时
    // 之所以 Fail Closed 停用,原因正是这一条 —— 现在入口开在这里,边界仍然成立。
    //
    // 掷点由调用方注入($pick(count) → 下标):事件要的是**可重算**的确定性随机
    // (EventRandom = HMAC(密钥, 城市|窗口|标签),§11.3 防止玩家重登录刷结果),
    // 而本模块自己的掷点走 CSPRNG。两套随机源不该互相知道对方,所以用闭包传进来。
    //
    // 没有在编 NPC 时返回 null(空转,不抛):一座刚建好的城照样可能抽中这条事件,
    // 那时候「没人可流失」是正常状态,不是错误。
    public static function leaveRandom(object $locked, string $reasonCode, callable $pick, array $metadata = []): ?array
    {
        $npcs = DB::table('city_npcs')
            ->where('city_id', $locked->id)
            ->whereIn('status', NpcCode::ACTIVE_STATUSES)
            ->orderBy('id') // 顺序固定,掷点才可重算
            ->lockForUpdate()
            ->get();

        if ($npcs->isEmpty()) {
            return null;
        }

        $index = max(0, min($npcs->count() - 1, (int) $pick($npcs->count())));
        $npc = $npcs[$index];

        $def = DB::table('npc_definition')->where('npc_id', $npc->npc_id)->first();

        self::markLeft(
            $locked,
            $npc,
            $reasonCode,
            before: ['status' => $npc->status, 'assigned_instance_id' => $npc->assigned_instance_id],
            after: ['status' => NpcCode::STATUS_LEFT, 'assigned_instance_id' => null],
            // delta:流失释放掉的常态开销速率(与辞退同口径 —— 走人的经济含义就是这两条)
            delta: [
                'wage_money_per_min' => -(float) ($def->wage_per_min ?? 0),
                'food_per_min'       => -(float) ($def->food_per_min ?? 0),
            ],
            metadata: array_merge(['pool' => $npcs->count(), 'picked_index' => $index], $metadata),
        );

        return ['city_npc_id' => (int) $npc->id, 'npc_id' => (string) $npc->npc_id];
    }

    // ---------- A1:自然增长 ----------

    // 逐窗掷点 + 三道闸(住房空余 / 幸福门槛 / 全城自然增长上限)+ 单次补算上限。
    // 三道闸缺一不可:没有住房闸,爆满的城市还会自动长人;没有单次上限,
    // 离线 12h = 12 个窗口会一次性刷出一堆 NPC(backlog §11.4 的「离线雪崩」)。
    private static function stepNaturalGrowth(object $locked, array $sim, float $minutes): void
    {
        if (GameSetting::get(GameSetting::NPC_NATURAL_GROWTH_ENABLED) !== true) {
            return;
        }

        $window = max(1.0, (float) GameSetting::get(GameSetting::NPC_NATURAL_GROWTH_WINDOW_MINUTES));
        $windows = (int) floor($minutes / $window);
        if ($windows <= 0) {
            return;
        }

        $population = (float) ($sim['population'] ?? $locked->population);
        $populationCap = (float) ($sim['populationCapacity'] ?? 0);
        $happiness = (float) ($sim['happiness'] ?? $locked->happiness);

        // 住房空余率 = 1 − 人口 / 人口容量;容量为 0(还没盖住宅)一律视为没有空余
        $free = $populationCap > 0 ? 1.0 - $population / $populationCap : 0.0;
        if ($free < (float) GameSetting::get(GameSetting::NPC_NATURAL_GROWTH_HOUSING_FREE_MIN)) {
            return;
        }
        if ($happiness < (float) GameSetting::get(GameSetting::NPC_NATURAL_GROWTH_HAPPINESS_MIN)) {
            return;
        }

        // 上限只约束「自然增长来的」NPC:花钱招的不受这条限制
        $existing = DB::table('city_npcs')->where('city_id', $locked->id)
            ->where('acquired_source', NpcCode::SOURCE_NATURAL_GROWTH)
            ->whereIn('status', NpcCode::ACTIVE_STATUSES)->count();
        $cap = (int) floor($population / max(1.0, (float) GameSetting::get(GameSetting::NPC_NATURAL_GROWTH_CAP_PER_POPULATION)))
            + (int) GameSetting::get(GameSetting::NPC_NATURAL_GROWTH_CAP_BASE);
        if ($existing >= $cap) {
            return;
        }

        $pool = self::naturalGrowthPool((int) $locked->era_order);
        if ($pool->isEmpty()) {
            return;
        }

        $chance = (float) GameSetting::get(GameSetting::NPC_NATURAL_GROWTH_CHANCE);
        $offlineMax = (int) GameSetting::get(GameSetting::NPC_NATURAL_GROWTH_OFFLINE_MAX);
        $added = 0;

        for ($w = 0; $w < $windows; $w++) {
            if ($added >= $offlineMax || $existing + $added >= $cap) {
                break;
            }
            if (! NpcRandom::chance($chance)) {
                continue;
            }

            $def = $pool[NpcRandom::int(0, $pool->count() - 1)];
            $newId = NpcService::insertNpc((int) $locked->id, $def, NpcCode::SOURCE_NATURAL_GROWTH);
            $added++;

            AuditLogger::record(AuditAction::NPC_NATURAL_GROWTH, 'success', [
                'actor_type' => 'system', 'actor_id' => null,
                'user_id' => $locked->user_id, 'city_id' => $locked->id,
                'entity_type' => 'city_npc', 'entity_id' => (string) $newId,
                'after_json' => ['npc_id' => $def->npc_id, 'status' => NpcCode::STATUS_IDLE],
                // delta:这次自然增长带来的常态开销(招人是要花钱养的,自然增长也一样)
                'delta_json' => [
                    'wage_money_per_min' => (float) $def->wage_per_min,
                    'food_per_min'       => (float) $def->food_per_min,
                ],
                'metadata_json' => ['windows' => $windows, 'cap' => $cap, 'existing' => $existing + $added],
            ]);
        }
    }

    // 自然增长的候选池:recruit_source = natural_growth 且时代已到
    private static function naturalGrowthPool(int $eraOrder)
    {
        $orders = EraService::orders();

        return DB::table('npc_definition')
            ->where('recruit_source', NpcCode::SOURCE_NATURAL_GROWTH)->get()
            ->filter(fn ($d) => ($orders[$d->min_era] ?? PHP_INT_MAX) <= $eraOrder)
            ->values();
    }
}
