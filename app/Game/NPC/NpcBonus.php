<?php

namespace App\Game\NPC;

use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\Simulation\SimConstants;
use App\Support\GameSetting;

// §6.4 NPC 加成计算 —— 全项目唯一一份实现。
//
// v3.2 §6.4 原文:
//   主技能加成 = skillLevelTable[level].主技能效率加成
//   岗位不匹配  = 主技能加成 × 0.25
//   副技能加成  = 对应副技能加成 × 0.50
//   NPC最终倍率 = 1 + 主技能加成 + 副技能加成 + NPC特性
//   单NPC对单建筑效率建议封顶 1.60;多个NPC叠加后总NPC倍率建议封顶 1.90
//
// 两处与原文的差异,都是有据的:
//   ① **副技能恒为 0**:§6.3 的 30 行原型每人只有一个 primary_skill_id,表里没有副技能列。
//      公式里的 ×0.50 通道照样实现(常量 + 入参),等哪天定义表补了副技能就能直接用,
//      但在 v3.2 现有数据下它永远是 0 —— 不是漏做,是数据里就没有。
//   ② **总帽 1.90 → 1.50**:用户 2026-08-11 拍板(依据 backlog §11.1 方向①:
//      tech 1.20 × npc 1.90 × tool 1.18 = 2.69 已经吃掉 §13 的 2.75 硬帽,
//      正向事件对强城市 100% 失效;把 NPC 收紧到 1.50 后乘积降到 2.12,给事件留出 1.30 的余量)。
//
// 封顶纪律:这里夹的是 **NPC 系统内部**的两层帽(单 NPC 1.60 / 本格 1.50),
// §13 的 2.75 总帽仍然只由 SimulationService::multiplierProduct() 夹,本类绝不碰它。
final class NpcBonus
{
    // 单个 NPC 对某一栋建筑的倍率(已夹 §6.4 的单 NPC 帽 1.60)。
    //
    // $npc      ['primary_skill_id' => ?string, 'skill_level' => int, 'specs' => ModifierSpec[]]
    // $building ['category' => ?string, 'series_key' => ?string, 'outputs' => [资源 code => true], 'instance_id' => ?int]
    // $curve    [level => primary_bonus]:§6.2 的曲线,调用方一次查库后传进来(循环内零查库)
    public static function forNpc(array $npc, array $building, array $curve): float
    {
        $level = (int) ($npc['skill_level'] ?? 1);
        $primaryBonus = (float) ($curve[$level] ?? 0.0);

        // 岗位匹配:建筑的对口技能(A3 映射)与 NPC 主技能是否一致。
        // 不匹配不是 0,是 ×0.25(§6.4 明文)—— 派错岗位是效率问题,不是「白养」
        $required = NpcCode::requiredSkill($building['category'] ?? null, $building['series_key'] ?? null);
        $matched = $required !== null && $required === ($npc['primary_skill_id'] ?? null);
        $primary = $matched
            ? $primaryBonus
            : $primaryBonus * (float) GameSetting::get(GameSetting::NPC_JOB_MISMATCH_RATE);

        // 副技能通道(§6.4 的 ×0.50):v3.2 §6.3 没有副技能列 → 恒 0,见类注释①
        $secondary = (float) ($npc['secondary_bonus'] ?? 0.0) * SimConstants::NPC_SECONDARY_SKILL_RATE;

        $factor = 1.0 + $primary + $secondary + self::traitBonus($npc['specs'] ?? [], $building);

        // 单 NPC 对单建筑封顶(§6.4)。下限夹 0:后台把特性调成大负数也不该出现负产量
        return max(0.0, min($factor, SimConstants::NPC_SINGLE_BUILDING_CAP));
    }

    // 一栋建筑上全部 NPC 合成后的 npc 乘区值(已夹 NPC 侧总帽 1.50)。
    // 多 NPC 用**连乘**:§6.4 说的是「多个 NPC 叠加后总 NPC 倍率」,倍率的叠加就是相乘;
    // 无论相乘还是相加,在 1.50 的帽下第二个人的边际收益都会被迅速吃掉 —— 这正是帽的用意
    public static function forBuilding(array $npcs, array $building, array $curve): float
    {
        $product = 1.0;
        foreach ($npcs as $npc) {
            $product *= self::forNpc($npc, $building, $curve);
        }

        return min($product, (float) GameSetting::get(GameSetting::NPC_TOTAL_CAP));
    }

    // 特性对**产量乘区**的贡献(§6.3 的 trait_json)。
    //
    // 只认 target = npc 那一格:治理容量 / 建造速度 / 维护成本这些非产量特性各有自己的消费点
    // (ModifierTarget::CONSUMPTION_POINTS),由对应波次接线,绝不混进产量乘区(一条产量管线接不住)。
    // op 只认 pct:flat 在乘区里没有意义(乘区是比例),flat 特性一律留给 flat 通道 / 消费点。
    private static function traitBonus(array $specs, array $building): float
    {
        $sum = 0.0;

        foreach ($specs as $spec) {
            if (! $spec instanceof ModifierSpec) {
                continue;
            }
            if ($spec->target !== ModifierTarget::SLOT_NPC || $spec->op !== ModifierSpec::OP_PCT) {
                continue;
            }
            if (self::specApplies($spec, $building)) {
                $sum += $spec->value;
            }
        }

        return $sum;
    }

    // spec 的 scope 是否命中这栋建筑:
    //   city              全城,恒命中
    //   building_category 建筑 category 相同
    //   building_instance 建筑实例 id 相同
    //   resource          这栋建筑**产出**该资源(「木材产量 +8%」落到所有产木材的建筑上)
    private static function specApplies(ModifierSpec $spec, array $building): bool
    {
        return match ($spec->scope) {
            ModifierSpec::SCOPE_CITY              => true,
            ModifierSpec::SCOPE_BUILDING_CATEGORY => $spec->scopeKey === ($building['category'] ?? null),
            ModifierSpec::SCOPE_BUILDING_INSTANCE => $spec->scopeKey === (string) ($building['instance_id'] ?? ''),
            ModifierSpec::SCOPE_RESOURCE          => isset($building['outputs'][$spec->scopeKey]),
            default                               => false,
        };
    }

    // trait_json 的 specs 段 → ModifierSpec[]。
    // 解析失败的单条静默跳过(Seeder 已经在入库前逐条守门过,这里是运行时的第二道保险:
    // 一条脏特性不该让整座城市的结算炸掉)
    public static function specsFromJson(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $specs = [];
        foreach ($decoded['specs'] ?? [] as $row) {
            try {
                $specs[] = new ModifierSpec(
                    (string) ($row['target'] ?? ''),
                    (string) ($row['scope'] ?? ''),
                    (string) ($row['op'] ?? ''),
                    (float) ($row['value'] ?? 0),
                    $row['scope_key'] ?? null,
                );
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $specs;
    }
}
