<?php

namespace Database\Seeders;

use App\Game\Item\ItemCode;
use App\Game\Modifier\ModifierSpec;
use App\Game\Resource\ResourceCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// 工具 / 道具定义层 Seeder(v3.2 §7 的 24 行)。
//
// 数据在 database/data/items.json,这里只做「JSON 行 → 表行」的转换 + 守门。
// 守门不是可选项(承接 NpcDefinitionSeeder 的同一条纪律):M2 的 upgrade_to 断链就是
// 「解析不到就静默变 NULL」造成的,36 条链丢了很久没人发现。所以这里对
// durability_tier / durability_mode / craft_cost 的资源 code / effect_json 的 target
// 一律**校验失败即抛**,不静默兜底。
class ItemDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        // upsert 而不是 insert:建表迁移(2026_08_11_600001)已经把 24 行灌过一遍
        //(让「只跑迁移不跑 seed」的库也能直接用),全新库上「迁移 + Seeder」会连着跑两次。
        // 更新列 = 除主键外的全部列:重跑 seed 的语义就是「把定义拉回 items.json 的样子」
        DB::table('item_definition')->upsert(
            self::rows(),
            ['item_id'],
            ['name_key', 'category', 'min_era', 'equip_target_desc_zh', 'durability', 'durability_tier',
                'durability_mode', 'effect_code', 'effect_value', 'unit', 'effect_json', 'craft_cost_json',
                'crafting_source_desc_zh', 'crafting_building_id', 'crafting_unmapped_zh', 'trade_value', 'note']
        );
    }

    // JSON → 数据库行的映射。做成 public static 是为了让建表迁移也能用同一份映射 ——
    // 迁移里再抄一遍列名,早晚会和这里对不上(与 MarketDefinitionSeeder::rows 同一做法)
    public static function rows(): array
    {
        $data = json_decode(file_get_contents(database_path('data/items.json')), true);

        return array_map(function ($i) {
            if (! in_array($i['durability_tier'], ItemCode::TIERS, true)) {
                throw new RuntimeException("items.json:{$i['item_id']} 的 durability_tier「{$i['durability_tier']}」不是合法档位");
            }
            if (! in_array($i['durability_mode'], ItemCode::DURABILITY_MODES, true)) {
                throw new RuntimeException("items.json:{$i['item_id']} 的 durability_mode「{$i['durability_mode']}」不是合法口径");
            }
            if ((int) $i['durability'] <= 0) {
                throw new RuntimeException("items.json:{$i['item_id']} 的 durability 必须为正 —— 0 耐久的工具装上去当场损毁");
            }

            self::assertCraftCost($i['item_id'], $i['craft_cost'] ?? []);
            self::assertEffectJson($i['item_id'], $i['effect_json'] ?? null);

            return [
                'item_id'                 => $i['item_id'],
                'name_key'                => $i['name_key'],
                'category'                => $i['category'],
                'min_era'                 => $i['min_era'],
                'equip_target_desc_zh'    => $i['equip_target_desc_zh'],
                'durability'              => $i['durability'],
                'durability_tier'         => $i['durability_tier'],
                'durability_mode'         => $i['durability_mode'],
                'effect_code'             => $i['effect_code'],
                'effect_value'            => $i['effect_value'],
                'unit'                    => $i['unit'],
                'effect_json'             => json_encode($i['effect_json'] ?? ['specs' => [], 'unmapped_zh' => []], JSON_UNESCAPED_UNICODE),
                'craft_cost_json'         => json_encode($i['craft_cost'] ?? [], JSON_UNESCAPED_UNICODE),
                'crafting_source_desc_zh' => $i['crafting_source_desc_zh'],
                'crafting_building_id'    => $i['crafting_building_id'] ?? null,
                'crafting_unmapped_zh'    => $i['crafting_unmapped_zh'] ?? null,
                'trade_value'             => $i['trade_value'],
                'note'                    => $i['note'] ?? null,
            ];
        }, $data['items']);
    }

    // 制作成本守门:键必须是登记过的**库存**资源(容量类不进 city_resources,扣不了),值必须是正数。
    // 一个拼错的资源 code 在运行时只会「静默不扣这一项」= 白送材料,比 seed 失败难查一万倍
    private static function assertCraftCost(string $itemId, array $cost): void
    {
        if ($cost === []) {
            throw new RuntimeException("items.json:{$itemId} 的 craft_cost 为空 —— §7 每一件都有成本,空成本等于免费无限制作");
        }

        foreach ($cost as $code => $amount) {
            if (! isset(ResourceCode::CHINESE_NAMES[$code]) || ResourceCode::isCapacity($code)) {
                throw new RuntimeException("items.json:{$itemId} 的 craft_cost 含非库存资源「{$code}」");
            }
            if (! is_int($amount) && ! is_float($amount)) {
                throw new RuntimeException("items.json:{$itemId} 的 craft_cost「{$code}」不是数字");
            }
            if ($amount <= 0) {
                throw new RuntimeException("items.json:{$itemId} 的 craft_cost「{$code}」必须为正");
            }
        }
    }

    // 效果守门:specs 里的每一条都必须能构造成一个合法 ModifierSpec(target/scope/op 三重 allowlist)。
    // 构造失败直接抛 —— 一条 target 写错的效果在运行时只会「静默不生效」,
    // 而玩家花了材料做出来的工具「没有任何数字变化」正是 backlog §11.1 点名最容易被投诉的一类 bug。
    //
    // 另一条:specs 与 unmapped_zh **不能同时为空**。
    // 两者都空 = 这件工具既没有效果也没说明为什么没效果,那就是抄漏了一行
    private static function assertEffectJson(string $itemId, ?array $effect): void
    {
        $specs = $effect['specs'] ?? [];
        $unmapped = $effect['unmapped_zh'] ?? [];

        if ($specs === [] && $unmapped === []) {
            throw new RuntimeException("items.json:{$itemId} 的 effect_json 既没有 specs 也没有 unmapped_zh —— 效果抄漏了");
        }

        foreach ($specs as $spec) {
            try {
                new ModifierSpec(
                    (string) $spec['target'],
                    (string) $spec['scope'],
                    (string) $spec['op'],
                    (float) $spec['value'],
                    $spec['scope_key'] ?? null,
                );
            } catch (\InvalidArgumentException $e) {
                throw new RuntimeException("items.json:{$itemId} 的 effect_json 非法 —— {$e->getMessage()}");
            }
        }
    }
}
