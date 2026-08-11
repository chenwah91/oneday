<?php

namespace App\Game\Item;

use App\Game\Building\ConstructionService;
use App\Game\City\EraService;
use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\GameSetting;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

// 工具的三个玩家动作:制作 / 装备 / 卸下(M3-D2,v3.2 §7 + backlog §4.2)。
//
// 安全链一律照 BuildService / NpcService 的模板逐步走(CLAUDE §42):
//   幂等(锁前 + 锁后各一次,关掉 TOCTOU)→ Revision → 锁城市行 → 锁内先跑 Time Delta 结算
//   → 耐久懒结算 → 规则校验 → 扣费 / 改状态 → 不变量 → 审计(带 delta)→ revision + 1。
// 所有权校验在 Controller 层完成(要用 request 写 Security Log),这里只接已确权的实体。
//
// 「锁内先结算」不是可选项:
//   不结算就扣材料,玩家可以用离线期间早就被吃掉的旧余额制作;
//   不结算就装备,新工具的加成会追溯到装上之前的时段;
//   不先跑耐久结算就卸下,玩家可以在扣耐久前一秒把工具摘下来,等于永久免耐久。
final class ItemService
{
    // ---------- 制作 ----------

    // §7 的获取方式:每一件都有 crafting_source(制作建筑)与材料成本。
    // 与招募不同,**制作要指定做哪一件** —— 它不是抽奖,是配方合成:
    // 客户端提交的只有 item_id,成本 / 耐久 / 效果全部由服务器从定义表读(CLAUDE §45)。
    public static function craft(City $city, string $itemId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        $requestHash = Idempotency::hash(AuditAction::ITEM_CRAFT, ['itemId' => $itemId]);

        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::ITEM_CRAFT, $requestHash) !== null) {
            return self::diff($city->fresh());
        }

        if (GameSetting::get(GameSetting::ITEM_CRAFT_ENABLED) !== true) {
            throw new GameRuleException(ErrorCode::ITEM_CRAFT_DISABLED, 422);
        }

        $def = ItemDefinition::find($itemId);
        if ($def === null) {
            throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
        }
        if ($def['craft_cost'] === []) {
            // 定义损坏(craft_cost_json 解析不出任何一项):Fail Closed,绝不当成「免费制作」
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        return DB::transaction(function () use ($city, $def, $itemId, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::ITEM_CRAFT, $requestHash) !== null) {
                return self::diff($city->fresh());
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            $sim = SimulationService::applyLocked($locked, now());
            ItemRuntimeService::settleLocked($locked, $sim, now());

            // 时代闸门(§7 的 min_era):判定一律读 cities.era_order,与建造 / 招募同一口径
            $orders = EraService::orders();
            if ((int) ($orders[$def['min_era']] ?? PHP_INT_MAX) > (int) $locked->era_order) {
                throw new GameRuleException(ErrorCode::ERA_REQUIRED, 422);
            }

            // 制作建筑闸门(§7 的 crafting_source):只有映射到具体建筑的工具才校验。
            // crafting_building_id 为空的两类(手工制作 / 来源建筑在 94 栋里不存在)不设建筑门槛 ——
            // 详见 item_definition.crafting_unmapped_zh 的列注释与交付汇报的对照表
            if ($def['crafting_building_id'] !== null) {
                $hasBuilding = DB::table('city_building_instances')
                    ->where('city_id', $city->id)
                    ->where('building_id', $def['crafting_building_id'])
                    ->where('status', ConstructionService::STATUS_ACTIVE)
                    ->exists();
                if (! $hasBuilding) {
                    throw new GameRuleException(ErrorCode::CRAFTING_BUILDING_MISSING, 422);
                }
            }

            // 资源足额:一律用结算后的最新余额(资金 money 单列在 cities.money)
            foreach ($def['craft_cost'] as $res => $amount) {
                $have = $res === ResourceCode::MONEY
                    ? (float) $sim['money']
                    : (float) ($sim['resources'][$res] ?? 0);
                if ($have < $amount) {
                    throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
                }
            }

            $delta = [];
            foreach ($def['craft_cost'] as $res => $amount) {
                if ($res === ResourceCode::MONEY) {
                    DB::table('cities')->where('id', $city->id)->decrement('money', $amount);
                } else {
                    DB::table('city_resources')->where('city_id', $city->id)
                        ->where('resource_id', $res)->decrement('amount', $amount);
                }
                $delta[$res] = -$amount;
            }

            $now = now();
            $cityItemId = (int) DB::table('city_items')->insertGetId([
                'city_id'              => $city->id,
                'item_id'              => $itemId,
                // 新造的工具满耐久;上限回定义表读,不冗余(后台调高耐久上限只影响此后新造的)
                'durability_left'      => $def['durability'],
                'status'               => ItemCode::STATUS_STORED,
                'equipped_instance_id' => null,
                'acquired_source'      => ItemCode::SOURCE_CRAFT,
                'acquired_at'          => $now,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);

            // 不变量(CLAUDE §52):扣费后资源与资金都不得为负。扣前已按结算后余额校验过,这里是双保险
            $negative = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($negative > 0 || (float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::ITEM_CRAFT, $requestHash);
            }

            AuditLogger::record(AuditAction::ITEM_CRAFT, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'city_item', 'entity_id' => (string) $cityItemId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'delta_json' => $delta,
                'idempotency_key' => $idempotencyKey,
                'metadata_json' => [
                    'item_id'              => $itemId,
                    'category'             => $def['category'],
                    'durability'           => $def['durability'],
                    'durability_tier'      => $def['durability_tier'],
                    'crafting_building_id' => $def['crafting_building_id'],
                ],
            ]);

            return self::diff($city->fresh(), $delta, self::itemRow($cityItemId));
        });
    }

    // ---------- 装备 ----------

    // 互斥(与 §52 的 NPC 同一条纪律):一件工具同时只能装在一栋楼上。这里由**表形状**兜底 ——
    // 装备关系就是 city_items 上的一列,一行放不下两个建筑;想换楼必须先卸下。
    // 槽位上限(B2,后台可调)不是唯一约束能表达的,所以在城市行锁内 count 判定。
    public static function equip(City $city, int $cityItemId, int $buildingInstanceId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        $requestHash = Idempotency::hash(AuditAction::ITEM_EQUIP, [
            'cityItemId' => $cityItemId, 'buildingInstanceId' => $buildingInstanceId,
        ]);

        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::ITEM_EQUIP, $requestHash) !== null) {
            return self::diff($city->fresh(), [], self::itemRow($cityItemId));
        }

        return DB::transaction(function () use ($city, $cityItemId, $buildingInstanceId, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::ITEM_EQUIP, $requestHash) !== null) {
                return self::diff($city->fresh(), [], self::itemRow($cityItemId));
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先结算:装备会改变这栋楼的 tool 乘区,不先结清就等于把新加成追溯到过去
            $sim = SimulationService::applyLocked($locked, now());
            ItemRuntimeService::settleLocked($locked, $sim, now());

            $item = self::lockedItem((int) $city->id, $cityItemId);
            if ($item->status === ItemCode::STATUS_BROKEN || (float) $item->durability_left <= 0) {
                throw new GameRuleException(ErrorCode::ITEM_BROKEN, 422);
            }
            if ($item->equipped_instance_id !== null) {
                throw new GameRuleException(ErrorCode::ITEM_ALREADY_EQUIPPED, 409);
            }

            // 目标建筑:必须属于本城、且已建成(constructing / upgrading 的楼不生产,装上去没有意义)
            $instance = DB::table('city_building_instances')
                ->where('id', $buildingInstanceId)->where('city_id', $city->id)->first();
            if (! $instance || $instance->status !== ConstructionService::STATUS_ACTIVE) {
                throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
            }

            $used = DB::table('city_items')
                ->where('equipped_instance_id', $buildingInstanceId)
                ->where('status', ItemCode::STATUS_EQUIPPED)
                ->count();
            if ($used >= self::slotsPerBuilding()) {
                throw new GameRuleException(ErrorCode::ITEM_SLOT_FULL, 422);
            }

            DB::table('city_items')->where('id', $cityItemId)->update([
                'status'               => ItemCode::STATUS_EQUIPPED,
                'equipped_instance_id' => $buildingInstanceId,
                'updated_at'           => now(),
            ]);

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::ITEM_EQUIP, $requestHash);
            }

            AuditLogger::record(AuditAction::ITEM_EQUIP, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'city_item', 'entity_id' => (string) $cityItemId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'before_json' => ['equipped_instance_id' => null, 'status' => $item->status],
                'after_json'  => ['equipped_instance_id' => $buildingInstanceId, 'status' => ItemCode::STATUS_EQUIPPED],
                // delta:这件工具给这栋楼带来的百分比加成(§7 口径,已按「同类取最高」判定)。
                // 「装了工具产量没变」是最常见的投诉,审计里必须留下当时算出来的数
                'delta_json' => [
                    'building_instance_id' => $buildingInstanceId,
                    'tool_bonus_pct'       => self::toolBonusPct((string) $item->item_id, $instance),
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            return self::diff($city->fresh(), [], self::itemRow($cityItemId));
        });
    }

    // ---------- 卸下 ----------

    // 耐久保留(backlog §4.2 明文):卸下不是销毁,剩余耐久原样留在这一行上
    public static function unequip(City $city, int $cityItemId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        $requestHash = Idempotency::hash(AuditAction::ITEM_UNEQUIP, ['cityItemId' => $cityItemId]);

        if ($idempotencyKey !== null
            && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::ITEM_UNEQUIP, $requestHash) !== null) {
            return self::diff($city->fresh(), [], self::itemRow($cityItemId));
        }

        return DB::transaction(function () use ($city, $cityItemId, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            if ($idempotencyKey !== null
                && Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::ITEM_UNEQUIP, $requestHash) !== null) {
                return self::diff($city->fresh(), [], self::itemRow($cityItemId));
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先结算 + 先扣耐久:卸下之前那段工作时间的耐久是真实消耗过的,
            // 不先结清就等于给玩家一个「快到点就摘下来」的免耐久漏洞
            $sim = SimulationService::applyLocked($locked, now());
            ItemRuntimeService::settleLocked($locked, $sim, now());

            $item = self::lockedItem((int) $city->id, $cityItemId);
            if ($item->equipped_instance_id === null) {
                // 已经是未装备状态:当成一次成功的无操作,不 +revision、不写审计
                //(重复点「卸下」不该报错,也不该在审计里刷出一堆没有实际变化的行 —— 与 NPC unassign 同款)
                return self::diff($city->fresh(), [], self::itemRow($cityItemId));
            }

            $previousInstanceId = (int) $item->equipped_instance_id;
            $instance = DB::table('city_building_instances')->where('id', $previousInstanceId)->first();

            DB::table('city_items')->where('id', $cityItemId)->update([
                'status'               => ItemCode::STATUS_STORED,
                'equipped_instance_id' => null,
                'updated_at'           => now(),
            ]);

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::ITEM_UNEQUIP, $requestHash);
            }

            AuditLogger::record(AuditAction::ITEM_UNEQUIP, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'city_item', 'entity_id' => (string) $cityItemId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'before_json' => ['equipped_instance_id' => $previousInstanceId, 'status' => $item->status],
                'after_json'  => ['equipped_instance_id' => null, 'status' => ItemCode::STATUS_STORED],
                'delta_json'  => [
                    'building_instance_id' => $previousInstanceId,
                    'tool_bonus_pct'       => $instance ? -self::toolBonusPct((string) $item->item_id, $instance) : 0.0,
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            return self::diff($city->fresh(), [], self::itemRow($cityItemId));
        });
    }

    // ---------- 共用 ----------

    // B2 单栋建筑装备槽位数(后台可调)
    public static function slotsPerBuilding(): int
    {
        return (int) GameSetting::get(GameSetting::ITEM_SLOTS_PER_BUILDING);
    }

    // 锁到本城的某件工具。所有权在 Controller 已确权,这里再按 city_id 过滤一次(Fail Closed:
    // 服务层不假设调用方一定做过校验,多一个 where 的成本可以忽略)
    private static function lockedItem(int $cityId, int $cityItemId): object
    {
        $item = DB::table('city_items')->where('id', $cityItemId)->where('city_id', $cityId)
            ->lockForUpdate()->first();

        if (! $item) {
            throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
        }

        return $item;
    }

    // 单件工具对某栋楼的百分比加成(审计 delta 用)。
    // 只算这一件自己的贡献,不含同楼其他工具 —— 审计要回答的是「这一次操作带来了多少」
    private static function toolBonusPct(string $itemId, object $instance): float
    {
        $def = ItemDefinition::find($itemId);
        if ($def === null) {
            return 0.0;
        }

        $building = DB::table('building_definition')->where('building_id', $instance->building_id)->first();

        // 产出资源集合(资源作用域的效果要用):按该实例当前等级的 output_json
        $level = DB::table('building_level_definition')
            ->where('building_id', $instance->building_id)->where('level', $instance->level)->first();
        $outputs = [];
        foreach (json_decode($level->output_json ?? '[]', true) ?: [] as $output) {
            $outputs[$output['resource']] = true;
        }

        $contribution = ItemBonus::contribution($def['specs'], [
            'category'    => $building->category ?? null,
            'instance_id' => (int) $instance->id,
            'outputs'     => $outputs,
        ]);

        return round($contribution * 100, 2);
    }

    // 单行工具的契约表示(snake_case,与快照里的 list 元素同一形状)
    public static function itemRow(int $cityItemId): ?array
    {
        $row = DB::table('city_items')->where('id', $cityItemId)->first();

        return $row ? self::toContract($row) : null;
    }

    private static function toContract(object $row): array
    {
        $def = ItemDefinition::find((string) $row->item_id);
        $durability = (int) ($def['durability'] ?? 0);
        $left = (float) $row->durability_left;

        return [
            'id'                   => (int) $row->id,
            'item_id'              => (string) $row->item_id,
            'name_key'             => $def['name_key'] ?? null,
            'category'             => $def['category'] ?? null,
            'status'               => (string) $row->status,
            'equipped_instance_id' => $row->equipped_instance_id === null ? null : (int) $row->equipped_instance_id,
            'durability_left'      => $left,
            'durability_max'       => $durability,
            'durability_tier'      => $def['durability_tier'] ?? null,
            'durability_mode'      => $def['durability_mode'] ?? null,
            // 预警标记(B4「归零前 20% 发预警」):阈值后台可调。
            // 通知系统属 D4/F 波次,本波次只把标记放进契约,前端与将来的通知都从这一个口径取
            'durability_warning'   => $durability > 0
                && $left > 0
                && $left / $durability <= (float) GameSetting::get(GameSetting::ITEM_DURABILITY_WARNING_PCT),
            'effect_code'          => $def['effect_code'] ?? null,
            'effect_value'         => $def['effect_value'] ?? null,
            'unit'                 => $def['unit'] ?? null,
            'acquired_source'      => (string) $row->acquired_source,
        ];
    }

    // 城市快照的 items 区块(CityController 的 M3-ITEM 锚点)。
    //
    // 顺带在这里触发耐久懒结算:CityController 只允许在自己的锚点内插行(backlog §10.2 的
    // 共享文件纪律),而耐久结算必须发生在读 city_items 之前,否则玩家会看到一份「还没扣」的耐久。
    // 结算只写 city_items 与 cities.item_settled_at,不动资源 / 资金 / revision,
    // 因此放在快照已经取完资源之后也不会让响应里的数字自相矛盾。
    //
    // 一次联查取全:工具数量是个位到几十的量级(每件都要材料 + 建筑前置),不做分页;
    // 装备关系另给一张 building_instance_id => [city_item_id…] 的表,
    // 前端画建筑详情的「装备」区块直接用
    public static function snapshot(City $city, array $sim): array
    {
        ItemRuntimeService::settle($city, $sim);

        $rows = DB::table('city_items')
            ->where('city_id', $city->id)
            ->whereIn('status', ItemCode::ACTIVE_STATUSES)
            ->orderBy('id')
            ->get();

        $list = [];
        $equipment = [];
        $stored = 0;
        $warning = 0;

        foreach ($rows as $row) {
            $contract = self::toContract($row);
            $list[] = $contract;

            if ($contract['equipped_instance_id'] !== null) {
                $equipment[$contract['equipped_instance_id']][] = $contract['id'];
            } else {
                $stored++;
            }
            if ($contract['durability_warning']) {
                $warning++;
            }
        }

        return [
            'total'    => count($list),
            'stored'   => $stored,
            'equipped' => count($list) - $stored,
            // 已损毁的不在 list 里(行保留只为可追溯),但给一个计数:
            // 玩家要能看出「我的工具是被用坏了,不是凭空消失」
            'broken'   => DB::table('city_items')->where('city_id', $city->id)
                ->where('status', ItemCode::STATUS_BROKEN)->count(),
            // 耐久预警数量(B4):前端拿它在底部导航打红点
            'durability_warning' => $warning,
            // 槽位规则(后台可调),前端据此画「x / y 槽」
            'slots_per_building' => self::slotsPerBuilding(),
            'list'       => $list,
            'equipment'  => $equipment,
        ];
    }

    // 资源/revision 简要 diff(与 BuildService::snapshotDiff / NpcService::diff 同一形状,
    // 前端一套解析代码走天下)
    private static function diff(City $city, array $delta = [], ?array $item = null): array
    {
        $diff = [
            'revision'  => (int) $city->revision,
            'resources' => DB::table('city_resources')->where('city_id', $city->id)
                ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
            'money'     => (float) $city->money,
            'delta'     => $delta,
        ];

        if ($item !== null) {
            $diff['item'] = $item;
        }

        return $diff;
    }
}
