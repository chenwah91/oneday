<?php

namespace Database\Seeders;

use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// NPC 定义层 Seeder(v3.2 §6.1 / §6.2 / §6.3)。
//
// 数据在 database/data/npcs.json,这里只做「JSON 行 → 表行」的转换 + 守门。
// 守门不是可选项:M2 的 upgrade_to 断链就是因为「解析不到就静默变 NULL」,36 条链丢了很久没人发现。
// 所以这里对 rarity / recruit_source / trait_json 的 target 一律**校验失败即抛**,不静默兜底。
class NpcDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('data/npcs.json')), true);

        // upsert 而不是 insert:2026_08_11_400001 迁移已经在建表时把同一批数据灌过一遍
        // (让「只跑迁移不跑 seed」的库也能直接用),这里再跑一次必须是无害的重刷。
        // 更新列 = 除主键外的全部列:重跑 seed 的语义就是「把定义拉回 npcs.json 的样子」
        DB::table('npc_skill_definition')->upsert(
            self::skillRows($data['skills']),
            ['skill_id'],
            ['name_key', 'effect_target', 'effect_desc_zh']
        );
        DB::table('npc_skill_level_curve')->upsert(
            self::curveRows($data['level_curve']),
            ['level'],
            ['xp_to_next', 'primary_bonus', 'maintenance_reduction_cap']
        );
        DB::table('npc_definition')->upsert(
            self::npcRows($data['npcs'], $data['skills']),
            ['npc_id'],
            ['name_key', 'category', 'min_era', 'primary_skill_id', 'initial_skill_value',
                'initial_skill_level', 'max_level', 'wage_per_min', 'food_per_min', 'rarity',
                'recruit_source', 'recruit_desc_zh', 'trait_desc_zh', 'trait_json']
        );
    }

    // §6.1 的 12 条技能。effect_target 必须是 ModifierTarget 已登记的 target 或 NULL
    public static function skillRows(array $skills): array
    {
        return array_map(function ($s) {
            $target = $s['effect_target'] ?? null;
            if ($target !== null && ! in_array($target, ModifierTarget::all(), true)) {
                throw new RuntimeException("npcs.json:{$s['skill_id']} 的 effect_target「{$target}」未在 ModifierTarget 登记");
            }

            return [
                'skill_id'       => $s['skill_id'],
                'name_key'       => $s['name_key'],
                'effect_target'  => $target,
                'effect_desc_zh' => $s['effect_desc_zh'],
            ];
        }, $skills);
    }

    // §6.2 的 10 行等级曲线
    public static function curveRows(array $curve): array
    {
        return array_map(fn ($c) => [
            'level'                     => $c['level'],
            'xp_to_next'                => $c['xp_to_next'],
            'primary_bonus'             => $c['primary_bonus'],
            'maintenance_reduction_cap' => $c['maintenance_reduction_cap'],
        ], $curve);
    }

    // §6.3 的 30 行原型
    public static function npcRows(array $npcs, array $skills): array
    {
        $skillIds = array_flip(array_column($skills, 'skill_id'));

        return array_map(function ($n) use ($skillIds) {
            if (! isset($skillIds[$n['primary_skill_id']])) {
                throw new RuntimeException("npcs.json:{$n['npc_id']} 的 primary_skill_id「{$n['primary_skill_id']}」不在 §6.1 的技能表里");
            }
            if (! in_array($n['rarity'], NpcCode::RARITIES, true)) {
                throw new RuntimeException("npcs.json:{$n['npc_id']} 的 rarity「{$n['rarity']}」不是合法稀有度");
            }
            if (! in_array($n['recruit_source'], NpcCode::SOURCES, true)) {
                throw new RuntimeException("npcs.json:{$n['npc_id']} 的 recruit_source「{$n['recruit_source']}」不是合法来源 code");
            }

            self::assertTraitJson($n['npc_id'], $n['trait_json'] ?? null);

            return [
                'npc_id'              => $n['npc_id'],
                'name_key'            => $n['name_key'],
                'category'            => $n['category'],
                'min_era'             => $n['min_era'],
                'primary_skill_id'    => $n['primary_skill_id'],
                'initial_skill_value' => $n['initial_skill_value'],
                'initial_skill_level' => $n['initial_skill_level'],
                'max_level'           => $n['max_level'],
                'wage_per_min'        => $n['wage_per_min'],
                'food_per_min'        => $n['food_per_min'],
                'rarity'              => $n['rarity'],
                'recruit_source'      => $n['recruit_source'],
                'recruit_desc_zh'     => $n['recruit_desc_zh'],
                'trait_desc_zh'       => $n['trait_desc_zh'],
                'trait_json'          => json_encode($n['trait_json'] ?? ['specs' => [], 'unmapped_zh' => []], JSON_UNESCAPED_UNICODE),
            ];
        }, $npcs);
    }

    // 特性守门:specs 里的每一条都必须能构造成一个合法 ModifierSpec(target/scope/op 三重 allowlist)。
    // 构造失败直接抛 —— 一条 target 写错的特性在运行时只会「静默不生效」,那比 seed 失败难查一万倍
    private static function assertTraitJson(string $npcId, ?array $trait): void
    {
        foreach ($trait['specs'] ?? [] as $spec) {
            try {
                new ModifierSpec(
                    (string) $spec['target'],
                    (string) $spec['scope'],
                    (string) $spec['op'],
                    (float) $spec['value'],
                    $spec['scope_key'] ?? null,
                );
            } catch (\InvalidArgumentException $e) {
                throw new RuntimeException("npcs.json:{$npcId} 的 trait_json 非法 —— {$e->getMessage()}");
            }
        }
    }
}
