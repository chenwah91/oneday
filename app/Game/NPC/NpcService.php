<?php

namespace App\Game\NPC;

use App\Game\City\EraService;
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
use Illuminate\Support\Facades\DB;

// NPC 的四个玩家动作:招募 / 派驻 / 撤下 / 辞退(M3-D1,v3.2 §6 + backlog §3.2)。
//
// 安全链一律照 BuildService 的模板逐步走(CLAUDE §42):
//   幂等(锁前 + 锁后各一次,关掉 TOCTOU)→ Revision → 锁城市行 → 锁内先跑 Time Delta 结算
//   → 规则校验 → 扣费 / 改状态 → 不变量 → 审计(带 delta)→ revision + 1。
// 所有权校验在 Controller 层完成(要用 request 写 Security Log),这里只接已确权的实体。
//
// 「锁内先结算」不是可选项:不结算就扣款,玩家可以用离线期间早就被吃掉的旧余额招人;
// 不结算就派驻,新 NPC 的加成会追溯到派驻之前的时段。
final class NpcService
{
    // ---------- 招募 ----------

    // 服务器权威随机(CLAUDE §30 / §66:稀有度绝不能由客户端决定):
    // 入参里**没有 npc_id** —— 招募是「花钱抽一个人」,抽到谁由服务器掷点决定,
    // 客户端连候选池都不参与构造。掷出的结果直接落成 city_npcs 行(= 掷点结果落库,不可复掷)。
    public static function recruit(City $city, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        // 请求指纹:招募没有业务参数,同一 key 的重放就是同一次招募(不重复扣款、不再抽第二个人)
        $requestHash = Idempotency::hash(AuditAction::NPC_RECRUIT, []);

        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::NPC_RECRUIT, $requestHash) !== null) {
            return self::diff($city->fresh());
        }

        return DB::transaction(function () use ($city, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::NPC_RECRUIT, $requestHash) !== null) {
                return self::diff($city->fresh());
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            $sim = SimulationService::applyLocked($locked, now());

            $def = self::rollRecruit((int) $locked->era_order, (float) $sim['money']);
            $price = self::recruitPrice($def);

            if ($price > 0) {
                DB::table('cities')->where('id', $city->id)->decrement('money', $price);
            }

            $npcId = self::insertNpc((int) $city->id, $def, NpcCode::SOURCE_RECRUIT);

            // 不变量(CLAUDE §52):扣款后资金不得为负。扣前已按结算后余额校验过,这里是双保险
            if ((float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::NPC_RECRUIT, $requestHash);
            }

            AuditLogger::record(AuditAction::NPC_RECRUIT, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'city_npc', 'entity_id' => (string) $npcId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'delta_json' => [ResourceCode::MONEY => -$price],
                'idempotency_key' => $idempotencyKey,
                // 掷点结果进 metadata:半年后要能回答「这一抽当时抽到的是什么稀有度、按什么价收的钱」
                'metadata_json' => [
                    'npc_id'       => $def->npc_id,
                    'name_zh'      => $def->name_zh ?? null,
                    'rarity'       => $def->rarity,
                    'wage_per_min' => (float) $def->wage_per_min,
                    'food_per_min' => (float) $def->food_per_min,
                    'price'        => $price,
                ],
            ]);

            return self::diff($city->fresh(), [ResourceCode::MONEY => -$price], self::npcRow($npcId));
        });
    }

    // 招募掷点:先按「时代 + 可招募 + 付得起」筛出候选池,再按稀有度权重掷点,最后在该稀有度内均匀抽一个。
    //
    // 为什么先按余额筛再掷点:掷完才发现钱不够 → 玩家白点一次、还会以为「抽到了但被吞了」。
    // 先筛的副作用是穷城只抽得到便宜的人 —— 这与 §6.2「稀有度决定招募难度」的方向一致。
    private static function rollRecruit(int $eraOrder, float $money): object
    {
        $orders = EraService::orders();

        $all = DB::table('npc_definition')->where('recruit_source', NpcCode::SOURCE_RECRUIT)->get();
        if ($all->isEmpty()) {
            throw new GameRuleException(ErrorCode::NPC_NOT_AVAILABLE, 422);
        }

        $inEra = $all->filter(fn ($d) => ($orders[$d->min_era] ?? PHP_INT_MAX) <= $eraOrder);
        if ($inEra->isEmpty()) {
            // 池子不空但一个都没到时代 = 时代不够,不是「没人可招」
            throw new GameRuleException(ErrorCode::NPC_ERA_REQUIRED, 422);
        }

        $affordable = $inEra->filter(fn ($d) => self::recruitPrice($d) <= $money + 1e-9);
        if ($affordable->isEmpty()) {
            throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
        }

        // 只对池子里**实际存在**的稀有度掷点(否则低时代城市会有大半掷点落空要重掷)
        $weights = [];
        foreach (NpcCode::RARITIES as $rarity) {
            if ($affordable->contains(fn ($d) => $d->rarity === $rarity)) {
                $weights[$rarity] = (float) GameSetting::get(self::rarityWeightKey($rarity));
            }
        }

        // 权重全被后台调成 0 时回退到池子里最低的稀有度(Fail Safe:不让配置把招募变成 500)
        $rarity = NpcRandom::weightedKey($weights) ?? array_key_first($weights);

        $candidates = $affordable->filter(fn ($d) => $d->rarity === $rarity)->values();

        return $candidates[NpcRandom::int(0, $candidates->count() - 1)];
    }

    // A7:招募资金 = wage_per_min × 工资系数 × 稀有度系数(三个数全部后台可调)
    public static function recruitPrice(object $def): float
    {
        $rarityCoef = (float) GameSetting::get(self::rarityPriceKey($def->rarity));
        $wageMultiplier = (float) GameSetting::get(GameSetting::NPC_RECRUIT_PRICE_WAGE_MULTIPLIER);

        return round((float) $def->wage_per_min * $wageMultiplier * $rarityCoef, 2);
    }

    private static function rarityPriceKey(string $rarity): string
    {
        return match ($rarity) {
            NpcCode::RARITY_UNCOMMON  => GameSetting::NPC_RECRUIT_PRICE_RARITY_UNCOMMON,
            NpcCode::RARITY_RARE      => GameSetting::NPC_RECRUIT_PRICE_RARITY_RARE,
            NpcCode::RARITY_EPIC      => GameSetting::NPC_RECRUIT_PRICE_RARITY_EPIC,
            NpcCode::RARITY_LEGENDARY => GameSetting::NPC_RECRUIT_PRICE_RARITY_LEGENDARY,
            default                   => GameSetting::NPC_RECRUIT_PRICE_RARITY_COMMON,
        };
    }

    private static function rarityWeightKey(string $rarity): string
    {
        return match ($rarity) {
            NpcCode::RARITY_UNCOMMON  => GameSetting::NPC_RECRUIT_WEIGHT_UNCOMMON,
            NpcCode::RARITY_RARE      => GameSetting::NPC_RECRUIT_WEIGHT_RARE,
            NpcCode::RARITY_EPIC      => GameSetting::NPC_RECRUIT_WEIGHT_EPIC,
            NpcCode::RARITY_LEGENDARY => GameSetting::NPC_RECRUIT_WEIGHT_LEGENDARY,
            default                   => GameSetting::NPC_RECRUIT_WEIGHT_COMMON,
        };
    }

    // 建一行运行时 NPC(招募 / 自然增长 / 将来的事件发放共用),返回新行 id
    public static function insertNpc(int $cityId, object $def, string $source): int
    {
        $now = now();

        return (int) DB::table('city_npcs')->insertGetId([
            'city_id'              => $cityId,
            'npc_id'               => $def->npc_id,
            'skill_level'          => (int) $def->initial_skill_level,
            'xp'                   => 0,
            'skill_value'          => (int) $def->initial_skill_value,
            'morale'               => (float) GameSetting::get(GameSetting::NPC_MORALE_INITIAL),
            'status'               => NpcCode::STATUS_IDLE,
            'assigned_instance_id' => null,
            'acquired_source'      => $source,
            'acquired_at'          => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
    }

    // ---------- 派驻 ----------

    // 互斥(§52 / §67):一个 NPC 同时只能在一个岗位上。这里由**表形状**兜底 ——
    // 派驻关系就是 city_npcs 上的一列,一行放不下两个岗位;想换岗必须先撤下。
    // 槽位上限(A5,后台可调)不是唯一约束能表达的,所以在城市行锁内 count 判定。
    public static function assign(City $city, int $cityNpcId, int $buildingInstanceId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        $requestHash = Idempotency::hash(AuditAction::NPC_ASSIGN, [
            'cityNpcId' => $cityNpcId, 'buildingInstanceId' => $buildingInstanceId,
        ]);

        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::NPC_ASSIGN, $requestHash) !== null) {
            return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
        }

        return DB::transaction(function () use ($city, $cityNpcId, $buildingInstanceId, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::NPC_ASSIGN, $requestHash) !== null) {
                return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先结算:派驻会改变这栋楼的 npc 乘区,不先结清就等于把新加成追溯到过去
            SimulationService::applyLocked($locked, now());

            $npc = self::lockedNpc((int) $city->id, $cityNpcId);
            if ($npc->status === NpcCode::STATUS_LEFT) {
                throw new GameRuleException(ErrorCode::NPC_NOT_AVAILABLE, 422);
            }
            if ($npc->assigned_instance_id !== null) {
                throw new GameRuleException(ErrorCode::NPC_ALREADY_ASSIGNED, 409);
            }

            // 目标建筑:必须属于本城、且已建成(constructing / upgrading 的楼不生产,派人进去没有意义)
            $instance = DB::table('city_building_instances')
                ->where('id', $buildingInstanceId)->where('city_id', $city->id)->first();
            if (! $instance || $instance->status !== 'active') {
                throw new GameRuleException(ErrorCode::NPC_NOT_AVAILABLE, 422);
            }

            $used = DB::table('city_npcs')->where('assigned_instance_id', $buildingInstanceId)->count();
            if ($used >= self::slotsFor((int) $instance->level)) {
                throw new GameRuleException(ErrorCode::NPC_SLOT_FULL, 422);
            }

            DB::table('city_npcs')->where('id', $cityNpcId)->update([
                'status'               => NpcCode::STATUS_ASSIGNED,
                'assigned_instance_id' => $buildingInstanceId,
                'updated_at'           => now(),
            ]);

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::NPC_ASSIGN, $requestHash);
            }

            AuditLogger::record(AuditAction::NPC_ASSIGN, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'city_npc', 'entity_id' => (string) $cityNpcId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'before_json' => ['assigned_instance_id' => null, 'status' => $npc->status],
                'after_json'  => ['assigned_instance_id' => $buildingInstanceId, 'status' => NpcCode::STATUS_ASSIGNED],
                // delta:这个 NPC 给这栋楼带来的百分比加成(§6.4 单 NPC 口径,已夹 1.60)。
                // 「派了人产量没变」是最常见的投诉,审计里必须留下当时算出来的数
                'delta_json' => [
                    'building_instance_id' => $buildingInstanceId,
                    'npc_bonus_pct'        => self::npcBonusPct($npc, $instance),
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
        });
    }

    // ---------- 撤下 ----------

    public static function unassign(City $city, int $cityNpcId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        $requestHash = Idempotency::hash(AuditAction::NPC_UNASSIGN, ['cityNpcId' => $cityNpcId]);

        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::NPC_UNASSIGN, $requestHash) !== null) {
            return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
        }

        return DB::transaction(function () use ($city, $cityNpcId, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::NPC_UNASSIGN, $requestHash) !== null) {
                return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先结算:撤人之前那段时间的加成是真实发生过的,必须先结清再撤
            SimulationService::applyLocked($locked, now());

            $npc = self::lockedNpc((int) $city->id, $cityNpcId);
            if ($npc->assigned_instance_id === null) {
                // 已经是空闲状态:当成一次成功的无操作,不 +revision、不写审计。
                // 重复点「撤下」不该报错,也不该在审计里刷出一堆没有实际变化的行
                return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
            }

            $instance = DB::table('city_building_instances')->where('id', $npc->assigned_instance_id)->first();
            $previousInstanceId = (int) $npc->assigned_instance_id;

            DB::table('city_npcs')->where('id', $cityNpcId)->update([
                'status'               => NpcCode::STATUS_IDLE,
                'assigned_instance_id' => null,
                'updated_at'           => now(),
            ]);

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::NPC_UNASSIGN, $requestHash);
            }

            AuditLogger::record(AuditAction::NPC_UNASSIGN, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'city_npc', 'entity_id' => (string) $cityNpcId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'before_json' => ['assigned_instance_id' => $previousInstanceId, 'status' => $npc->status],
                'after_json'  => ['assigned_instance_id' => null, 'status' => NpcCode::STATUS_IDLE],
                'delta_json'  => [
                    'building_instance_id' => $previousInstanceId,
                    'npc_bonus_pct'        => $instance ? -self::npcBonusPct($npc, $instance) : 0.0,
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
        });
    }

    // ---------- 辞退 ----------

    // §6 没写辞退,但 backlog §3.2 明确要这个端点:工资对 idle 的 NPC 照收,
    // 没有辞退就等于「招进来的人这辈子都得养着」,士气/离职之外玩家没有任何主动的成本控制手段。
    public static function dismiss(City $city, int $cityNpcId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        $requestHash = Idempotency::hash(AuditAction::NPC_DISMISS, ['cityNpcId' => $cityNpcId]);

        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::NPC_DISMISS, $requestHash) !== null) {
            return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
        }

        return DB::transaction(function () use ($city, $cityNpcId, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::NPC_DISMISS, $requestHash) !== null) {
                return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先结算:辞退之前的工资照付,不先结清等于让玩家在扣款前一秒把人踢掉逃工资
            SimulationService::applyLocked($locked, now());

            $npc = self::lockedNpc((int) $city->id, $cityNpcId);
            if ($npc->status === NpcCode::STATUS_LEFT) {
                throw new GameRuleException(ErrorCode::NPC_NOT_AVAILABLE, 422);
            }

            $def = DB::table('npc_definition')->where('npc_id', $npc->npc_id)->first();

            DB::table('city_npcs')->where('id', $cityNpcId)->update([
                'status'               => NpcCode::STATUS_LEFT,
                'assigned_instance_id' => null,
                'updated_at'           => now(),
            ]);

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::NPC_DISMISS, $requestHash);
            }

            AuditLogger::record(AuditAction::NPC_DISMISS, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'city_npc', 'entity_id' => (string) $cityNpcId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'before_json' => ['status' => $npc->status, 'assigned_instance_id' => $npc->assigned_instance_id],
                'after_json'  => ['status' => NpcCode::STATUS_LEFT, 'assigned_instance_id' => null],
                // delta:释放掉的常态开销速率(辞退唯一的经济意义)
                'delta_json'  => [
                    'wage_money_per_min' => -(float) ($def->wage_per_min ?? 0),
                    'food_per_min'       => -(float) ($def->food_per_min ?? 0),
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            return self::diff($city->fresh(), [], self::npcRow($cityNpcId));
        });
    }

    // ---------- 共用 ----------

    // A5 槽位数:L3 建筑多一个槽。两个数都后台可调
    public static function slotsFor(int $level): int
    {
        $key = $level >= 3 ? GameSetting::NPC_SLOTS_PER_BUILDING_L3 : GameSetting::NPC_SLOTS_PER_BUILDING;

        return (int) GameSetting::get($key);
    }

    // 锁到本城的某个 NPC 行。所有权在 Controller 已确权,这里再按 city_id 过滤一次 ——
    // 服务层不假设调用方一定做过校验(Fail Closed),多一个 where 的成本可以忽略
    private static function lockedNpc(int $cityId, int $cityNpcId): object
    {
        $npc = DB::table('city_npcs')->where('id', $cityNpcId)->where('city_id', $cityId)
            ->lockForUpdate()->first();

        if (! $npc) {
            throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
        }

        return $npc;
    }

    // 单个 NPC 对某栋楼的百分比加成(审计 delta 用),已按 §6.4 的单 NPC 帽夹过
    private static function npcBonusPct(object $npc, object $instance): float
    {
        $def = DB::table('npc_definition')->where('npc_id', $npc->npc_id)->first();
        $building = DB::table('building_definition')->where('building_id', $instance->building_id)->first();
        $curve = DB::table('npc_skill_level_curve')->pluck('primary_bonus', 'level')
            ->map(fn ($b) => (float) $b)->all();

        // 产出资源集合(资源作用域的特性要用):按该实例当前等级的 output_json
        $outputs = [];
        $level = DB::table('building_level_definition')
            ->where('building_id', $instance->building_id)->where('level', $instance->level)->first();
        foreach (json_decode($level->output_json ?? '[]', true) ?: [] as $o) {
            $outputs[$o['resource']] = true;
        }

        $factor = NpcBonus::forNpc([
            'primary_skill_id' => $def->primary_skill_id ?? null,
            'skill_level'      => (int) $npc->skill_level,
            'specs'            => NpcBonus::specsFromJson($def->trait_json ?? null),
        ], [
            'category'    => $building->category ?? null,
            'series_key'  => $building->series_key ?? null,
            'instance_id' => (int) $instance->id,
            'outputs'     => $outputs,
        ], $curve);

        return round(($factor - 1.0) * 100, 2);
    }

    // 单行 NPC 的契约表示(snake_case,与快照里的 list 元素同一形状)
    public static function npcRow(int $cityNpcId): ?array
    {
        $row = DB::table('city_npcs as cn')
            ->join('npc_definition as nd', 'cn.npc_id', '=', 'nd.npc_id')
            ->where('cn.id', $cityNpcId)
            ->first([
                'cn.id', 'cn.npc_id', 'cn.skill_level', 'cn.xp', 'cn.skill_value', 'cn.morale',
                'cn.status', 'cn.assigned_instance_id', 'cn.acquired_source',
                'nd.name_key', 'nd.name_zh', 'nd.category', 'nd.rarity', 'nd.primary_skill_id',
                'nd.wage_per_min', 'nd.food_per_min',
            ]);

        return $row ? self::toContract($row) : null;
    }

    private static function toContract(object $r): array
    {
        return [
            'id'                   => (int) $r->id,
            'npc_id'               => $r->npc_id,
            'name_key'             => $r->name_key,
            // 中文名(§6.3 的 150 条扩充带入)。N001~N030 暂为 null —— 前端遇到 null 回落 name_key,
            // 不在服务端编一个占位名字(拟名待批,编出来的名字会被当成正式名传播出去)
            'name_zh'              => $r->name_zh,
            'category'             => $r->category,
            'rarity'               => $r->rarity,
            'primary_skill_id'     => $r->primary_skill_id,
            'skill_level'          => (int) $r->skill_level,
            'skill_value'          => (int) $r->skill_value,
            'xp'                   => (int) $r->xp,
            'morale'               => (float) $r->morale,
            'status'               => $r->status,
            'assigned_instance_id' => $r->assigned_instance_id === null ? null : (int) $r->assigned_instance_id,
            'acquired_source'      => $r->acquired_source,
            'wage_per_min'         => (float) $r->wage_per_min,
            'food_per_min'         => (float) $r->food_per_min,
        ];
    }

    // 城市快照的 npcs 区块(CityController 的 M3-NPC 锚点)。
    // 一次联查取全:NPC 数量是个位到几十的量级,不做分页;派驻关系另给一张
    // building_instance_id => [city_npc_id…] 的表,前端画建筑详情的「NPC 槽位」区块直接用
    public static function snapshot(int $cityId): array
    {
        $rows = DB::table('city_npcs as cn')
            ->join('npc_definition as nd', 'cn.npc_id', '=', 'nd.npc_id')
            ->where('cn.city_id', $cityId)
            ->whereIn('cn.status', NpcCode::ACTIVE_STATUSES)
            ->orderBy('cn.id')
            ->get([
                'cn.id', 'cn.npc_id', 'cn.skill_level', 'cn.xp', 'cn.skill_value', 'cn.morale',
                'cn.status', 'cn.assigned_instance_id', 'cn.acquired_source',
                'nd.name_key', 'nd.name_zh', 'nd.category', 'nd.rarity', 'nd.primary_skill_id',
                'nd.wage_per_min', 'nd.food_per_min',
            ]);

        $list = [];
        $assignments = [];
        $wage = 0.0;
        $food = 0.0;
        $idle = 0;

        foreach ($rows as $r) {
            $list[] = self::toContract($r);
            $wage += (float) $r->wage_per_min;
            $food += (float) $r->food_per_min;

            if ($r->assigned_instance_id !== null) {
                $assignments[(int) $r->assigned_instance_id][] = (int) $r->id;
            } else {
                $idle++;
            }
        }

        return [
            'total'    => count($list),
            // 未分配徽标(§11 的 npc_unassigned):前端拿它在底部导航打红点
            'idle'     => $idle,
            'assigned' => count($list) - $idle,
            // 常态开销速率:与内核那唯一一个消费点取的是同一批行,口径一致
            'wage_money_per_min' => round($wage, 4),
            'food_per_min'       => round($food, 4),
            // 槽位规则(后台可调),前端据此画「x / y 槽」
            'slots_per_building'    => self::slotsFor(1),
            'slots_per_building_l3' => self::slotsFor(3),
            // 离职阈值(A4,后台可调):士气低于本值的 NPC 开始有离职风险。
            // W7 补下发 —— 在此之前前端把 30 硬编码在面板里(见 backlog 的契约缺口清单),
            // 后台一改设定就成了两套真相。阈值属于数值规格,只该有 game_settings 这一份口径
            'morale_leave_threshold' => (float) GameSetting::get(GameSetting::NPC_MORALE_LEAVE_THRESHOLD),
            'list'        => $list,
            // map 型:building_instance_id => [city_npc_id…]。
            // 必须过 ApiResponse::map —— 没派任何人时 PHP 会把空关联数组编成 `[]` 而不是 `{}`,
            // 前端就得为空态另写一条分支(理由见该方法的注释)
            'assignments' => ApiResponse::map($assignments),
        ];
    }

    // 资源/revision 简要 diff(与 BuildService::snapshotDiff 同一形状,前端一套解析代码走天下)
    private static function diff(City $city, array $delta = [], ?array $npc = null): array
    {
        $diff = [
            'revision'  => (int) $city->revision,
            // map 型(键为资源 code)一律过 ApiResponse::map:空时也要是 `{}` 不是 `[]`。
            // 派驻 / 解除派驻这类不动资源的操作 delta 恒为空,最容易在这里退化成 `[]`
            'resources' => ApiResponse::map(DB::table('city_resources')->where('city_id', $city->id)
                ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all()),
            'money'     => (float) $city->money,
            'delta'     => ApiResponse::map($delta),
        ];

        if ($npc !== null) {
            $diff['npc'] = $npc;
        }

        return $diff;
    }
}
