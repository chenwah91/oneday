<?php

namespace App\Game\Item;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

// 工具定义层读取入口(item_definition 表 = v3.2 §7 的 24 行)。
//
// 三条纪律(与 MarketDefinition / GameSetting 同源):
//   1. 全项目只有这里读 item_definition,业务代码不许再自己查表 —— 否则「制作成本 / 效果 specs /
//      耐久档位」的解析会散落各处,改一处漏一处;
//   2. 请求级缓存:一次请求内整表只查一次(24 行),之后走 Context 缓存
//      (用 Context 而非类内 static:跟随请求生命周期,测试里每个用例重建 Application 会自动清空);
//   3. 缺表 / 缺行一律当作「没有这件工具」→ 制作与装备被拒,而不是 fallback 出一个凭空的默认值。
//      定义读不出来必须 Fail Closed(CLAUDE §41),绝不能猜。
final class ItemDefinition
{
    private const CACHE_KEY = 'item_definitions';

    // 整表(item_id => 定义数组)。表不存在 / 未 seed 时返回空数组
    public static function all(): array
    {
        if (Context::has(self::CACHE_KEY)) {
            return Context::get(self::CACHE_KEY);
        }

        $rows = [];

        foreach (DB::table('item_definition')->orderBy('item_id')->get() as $row) {
            $rows[(string) $row->item_id] = [
                'item_id'                 => (string) $row->item_id,
                'name_key'                => (string) $row->name_key,
                'category'                => (string) $row->category,
                'min_era'                 => (string) $row->min_era,
                'equip_target_desc_zh'    => (string) $row->equip_target_desc_zh,
                'durability'              => (int) $row->durability,
                'durability_tier'         => (string) $row->durability_tier,
                'durability_mode'         => (string) $row->durability_mode,
                'effect_code'             => (string) $row->effect_code,
                'effect_value'            => (float) $row->effect_value,
                'unit'                    => (string) $row->unit,
                // specs 在这里就解析成 ModifierSpec[](每请求一次),乘区准备段直接拿来用
                'specs'                   => ItemBonus::specsFromJson($row->effect_json),
                'unmapped_zh'             => self::unmappedFromJson($row->effect_json),
                'craft_cost'              => self::costFromJson($row->craft_cost_json),
                'crafting_source_desc_zh' => (string) $row->crafting_source_desc_zh,
                'crafting_building_id'    => $row->crafting_building_id === null ? null : (string) $row->crafting_building_id,
                'crafting_unmapped_zh'    => $row->crafting_unmapped_zh === null ? null : (string) $row->crafting_unmapped_zh,
                'trade_value'             => (float) $row->trade_value,
                'note'                    => $row->note === null ? null : (string) $row->note,
            ];
        }

        Context::add(self::CACHE_KEY, $rows);

        return $rows;
    }

    // 单件工具的定义;未登记返回 null(调用方一律按「没有这件工具」处理)
    public static function find(string $itemId): ?array
    {
        return self::all()[$itemId] ?? null;
    }

    // 清空请求级缓存(后台改完定义、测试里改库后调用)
    public static function flush(): void
    {
        Context::forget(self::CACHE_KEY);
    }

    // craft_cost_json → [资源 code => 数量]。脏数据返回空数组:
    // 制作路径拿到空成本会被 ItemService 当成「定义损坏」拒绝,不会变成免费制作
    private static function costFromJson(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $cost = [];
        foreach ($decoded as $code => $amount) {
            if ((is_int($amount) || is_float($amount)) && $amount > 0) {
                $cost[(string) $code] = (float) $amount;
            }
        }

        return $cost;
    }

    // effect_json 里没能映射成 spec 的效果原文(backlog §9 B3 的 unmapped 口径)。
    // 只作显示 / 排查用,不参与任何计算
    private static function unmappedFromJson(?string $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) && is_array($decoded['unmapped_zh'] ?? null)
            ? array_values($decoded['unmapped_zh'])
            : [];
    }
}
