<?php

namespace App\Game\Item;

use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;

// §7 工具加成计算 —— 全项目唯一一份实现(与 NpcBonus 对称)。
//
// v3.2 §7 原文里与计算相关的两条:
//   「单建筑同类加成只取最高值,避免玩家堆叠大量低级工具」
//   「工具加成与 NPC 加成属于不同乘区」
// backlog §4.3 把第一条落成两句:**同一建筑内同 category 只取最高值;不同 category 相乘**。
//
// 「最高」按**对这栋建筑的实际贡献**取,而不是按定义表的 effect_value 取。
// 差别在这里会真实发生:矿业工具装在农田上贡献是 0(specs 的 resource / category 都不命中),
// 若按 effect_value 取最高,一件装错地方的高级矿镐会把同类里真正生效的低级工具**顶掉**,
// 表现成「装了工具产量反而没变」—— 这正是 §7 那句话想防的反面。
//
// 封顶纪律(承接 M2「封顶只落在一处」):
//   §7 没有给工具侧的总帽,所以这里**一个帽都不夹**;
//   §13 的 2.75 总帽仍然只由 SimulationService::multiplierProduct() 夹一次。
final class ItemBonus
{
    // 一栋建筑上全部已装备工具合成后的 tool 乘区值。
    //
    // $items    [['category' => string, 'specs' => ModifierSpec[]], …](该建筑上已装备且耐久 > 0 的)
    // $building ['category' => ?string, 'outputs' => [资源 code => …], 'instance_id' => ?int]
    public static function forBuilding(array $items, array $building): float
    {
        // 同 category 只留贡献最高的一件(§7)
        $bestByCategory = [];
        foreach ($items as $item) {
            $contribution = self::contribution($item['specs'] ?? [], $building);
            $category = (string) ($item['category'] ?? '');

            if (! isset($bestByCategory[$category]) || $contribution > $bestByCategory[$category]) {
                $bestByCategory[$category] = $contribution;
            }
        }

        // 不同 category 相乘(backlog §4.3)
        $product = 1.0;
        foreach ($bestByCategory as $contribution) {
            $product *= 1.0 + $contribution;
        }

        // 下限夹 0:后台把效果值调成大负数也不该出现负产量(与 NpcBonus::forNpc 同一条兜底)
        return max(0.0, $product);
    }

    // 单件工具对某一栋建筑的百分比贡献(0.18 = +18%)。
    //
    // 只认 target = tool 那一格:建造速度 / 维护成本 / 治理容量这些非产量效果各有自己的消费点
    // (ModifierTarget::CONSUMPTION_POINTS),由对应波次接线,绝不混进产量乘区(一条产量管线接不住)。
    // op 只认 pct:flat 在乘区里没有意义(乘区是比例),flat 效果一律留给 flat 通道 / 消费点。
    public static function contribution(array $specs, array $building): float
    {
        $sum = 0.0;

        foreach ($specs as $spec) {
            if (! $spec instanceof ModifierSpec) {
                continue;
            }
            if ($spec->target !== ModifierTarget::SLOT_TOOL || $spec->op !== ModifierSpec::OP_PCT) {
                continue;
            }
            if (self::specApplies($spec, $building)) {
                $sum += $spec->value;
            }
        }

        return $sum;
    }

    // spec 的 scope 是否命中这栋建筑(与 NpcBonus::specApplies 同一套口径,刻意逐字对齐):
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

    // effect_json 的 specs 段 → ModifierSpec[]。
    // 解析失败的单条静默跳过(Seeder 已经在入库前逐条守门过,这里是运行时的第二道保险:
    // 一条脏效果不该让整座城市的结算炸掉)
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
