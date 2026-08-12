<?php

namespace App\Game\NPC;

use App\Game\Modifier\ModifierSpec;

// NPC 特性强度倍率(W11-B 任务4):把 npc_definition.trait_multiplier 施加到该 NPC 的每一条 spec 上。
//
// 为什么单独一个类而不是塞进 NpcBonus::specsFromJson:
//   ① specsFromJson 只做「JSON → ModifierSpec[]」这一件事,它的调用方里有**不该被倍率影响**的
//      (工具 / 事件走各自的强度旋钮);把倍率藏进解析函数会让「谁乘了、谁没乘」看不出来;
//   ② NpcBonus 的帽 / mismatch / 等级曲线逻辑是另一条纪律线,不该为了一个乘数去动它。
//
// 施加口径(定死,别再发明第二套):
//   · **pct 与 flat 同乘** —— 倍率描述的是「这位 NPC 的特性有多强」,不区分表达方式:
//     「治理容量 +10%」×2 = +20%,「治理容量 +30」×2 = +60,两者都是「这个人强了一倍」;
//   · 只乘 **NPC 来源**:同一个消费点里的工具投稿(§7 effect_json)与事件投稿
//     (city_active_modifiers)一律原样,它们各有 effect_value / effect_multiplier 可调;
//   · 倍率恒 1.0000 时返回的 specs 与不施加完全一致(全表默认值 → 落地即零行为变化)。
//
// ModifierSpec 是 readonly 值对象,所以是**重建**而不是就地改值 —— 这也保证
// 三重 allowlist(target / scope / op)在重建时再过一遍,倍率不可能把一条非法 spec 洗成合法的。
final class NpcTraitScale
{
    // 某个 NPC 的 trait_json → 已乘过强度倍率的 ModifierSpec[]。
    // $multiplier 直接收数据库列值(string|float|null 都行),null / 非数值一律按 1.0 处理:
    // 倍率读不出来时**不该**让这位 NPC 的特性凭空翻倍或归零
    public static function specs(?string $traitJson, mixed $multiplier): array
    {
        $specs = NpcBonus::specsFromJson($traitJson);

        $factor = is_numeric($multiplier) ? (float) $multiplier : 1.0;
        if ($specs === [] || $factor === 1.0) {
            return $specs;
        }

        $scaled = [];
        foreach ($specs as $spec) {
            $scaled[] = new ModifierSpec(
                $spec->target,
                $spec->scope,
                $spec->op,
                $spec->value * $factor,
                $spec->scopeKey,
            );
        }

        return $scaled;
    }
}
