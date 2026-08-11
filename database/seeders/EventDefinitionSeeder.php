<?php

namespace Database\Seeders;

use App\Game\Definition\EnumCode;
use App\Game\Event\EventCode;
use App\Game\Modifier\ModifierSpec;
use App\Game\Resource\ResourceCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// 事件定义层 Seeder(v3.2 §9.2 的 30 行)。
//
// 数据在 database/data/events.json,这里只做「JSON 行 → 表行」的转换 + 守门。
// 守门不是可选项:条件 metric 写错、效果 kind 写错、target 未登记,在运行时全都表现为
// **静默不生效**(事件照常触发,只是什么都没发生)—— 那比 seed 失败难查一万倍。
// 所以下面每一条校验失败都直接抛,绝不静默兜底。
//
// 特别守的三条(它们是本系统最容易被后人改坏的地方):
//   ① 正向事件不许出现 value>0 的 event 乘区 spec —— §13 帽修正方向要求正向一律「直接发资源」;
//   ② event 乘区 spec 的 value 必须 ≤ 0 —— 乘区里只放惩罚(<1.0),加成走 grant;
//   ③ 选项专用 kind(loss_scale / end_now / …)不许出现在自动效果里 —— 那时还没有「已发生的效果」可改。
class EventDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        // upsert 而不是 insert:2026_08_11_700001 迁移已经在建表时把同一批数据灌过一遍
        // (让「只跑迁移不跑 seed」的库也能直接用),这里再跑一次必须是无害的重刷。
        // 更新列 = 除主键外的全部列:重跑 seed 的语义就是「把定义拉回 events.json 的样子」
        DB::table('event_definition')->upsert(
            self::rows(),
            ['event_id'],
            [
                'name_zh', 'name_key', 'category', 'event_type', 'min_era',
                'base_weight', 'cooldown_minutes', 'duration_minutes', 'effect_multiplier',
                'enabled', 'disabled_reason',
                'condition_desc_zh', 'auto_effect_desc_zh',
                'option_a_desc_zh', 'option_b_desc_zh', 'option_c_desc_zh',
                'condition_json', 'auto_effect_json', 'options_json',
            ]
        );
    }

    // 30 行表行(迁移与 Seeder 共用同一份构造 + 同一套守门)
    public static function rows(): array
    {
        $data = json_decode(file_get_contents(database_path('data/events.json')), true);

        return array_map(fn ($e) => self::row($e), $data['events']);
    }

    private static function row(array $e): array
    {
        $id = (string) ($e['event_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('events.json:存在没有 event_id 的行');
        }

        if (! in_array($e['event_type'], EventCode::TYPES, true)) {
            throw new RuntimeException("events.json:{$id} 的 event_type「{$e['event_type']}」不是 positive / negative");
        }
        // 停用必须有理由、启用不许带理由:两边都守死,后台列表里才不会出现
        // 「灰着但没人知道为什么」或者「亮着却挂着一条吓人的停用说明」
        if (! $e['enabled'] && ($e['disabled_reason'] ?? null) === null) {
            throw new RuntimeException("events.json:{$id} 被停用但没写 disabled_reason");
        }
        if ($e['enabled'] && ($e['disabled_reason'] ?? null) !== null) {
            throw new RuntimeException("events.json:{$id} 是启用状态却带着 disabled_reason");
        }

        self::assertCondition($id, $e['condition_json'] ?? []);
        self::assertEffects($id, 'auto', $e['event_type'], $e['auto_effect_json'] ?? [], false);

        foreach (EventCode::OPTIONS as $key) {
            $option = $e['options_json'][$key] ?? null;
            if ($option === null) {
                continue;
            }
            self::assertEffects($id, "option {$key}", $e['event_type'], $option, true);
        }

        return [
            'event_id'   => $id,
            'name_zh'    => $e['name_zh'],
            'name_key'   => $e['name_key'],
            'category'   => $e['category'],
            'event_type' => $e['event_type'],
            'min_era'    => $e['min_era'],
            'base_weight'       => $e['base_weight'],
            'cooldown_minutes'  => $e['cooldown_minutes'],
            'duration_minutes'  => $e['duration_minutes'],
            'effect_multiplier' => 1,
            'enabled'           => $e['enabled'],
            'disabled_reason'   => $e['disabled_reason'] ?? null,
            'condition_desc_zh'   => $e['condition_desc_zh'],
            'auto_effect_desc_zh' => $e['auto_effect_desc_zh'],
            'option_a_desc_zh'    => $e['option_a_desc_zh'] ?? null,
            'option_b_desc_zh'    => $e['option_b_desc_zh'] ?? null,
            'option_c_desc_zh'    => $e['option_c_desc_zh'] ?? null,
            'condition_json'   => json_encode($e['condition_json'] ?? ['all' => [], 'unmapped_zh' => []], JSON_UNESCAPED_UNICODE),
            'auto_effect_json' => json_encode($e['auto_effect_json'] ?? ['effects' => [], 'unmapped_zh' => []], JSON_UNESCAPED_UNICODE),
            'options_json'     => json_encode($e['options_json'] ?? [], JSON_UNESCAPED_UNICODE),
        ];
    }

    // ---------- 条件守门 ----------

    private static function assertCondition(string $id, array $condition): void
    {
        foreach ($condition['all'] ?? [] as $c) {
            $metric = (string) ($c['metric'] ?? '');
            if (! in_array($metric, EventCode::CONDITION_METRICS, true)) {
                throw new RuntimeException("events.json:{$id} 的条件 metric「{$metric}」未在 EventCode::CONDITION_METRICS 登记");
            }
            if (! in_array((string) ($c['op'] ?? ''), EventCode::OPS, true)) {
                throw new RuntimeException("events.json:{$id} 的条件 op「{$c['op']}」不是合法比较运算符");
            }
            if (! is_int($c['value'] ?? null) && ! is_float($c['value'] ?? null)) {
                throw new RuntimeException("events.json:{$id} 的条件 value 必须是数字");
            }

            if (in_array($metric, [EventCode::METRIC_BUILDING_COUNT, EventCode::METRIC_ASSIGNED_WORKERS], true)) {
                self::assertBuildingScope($id, $c['scope'] ?? null, $c['keys'] ?? []);
            }
            if ($metric === EventCode::METRIC_RESOURCE_STOCK) {
                self::assertResource($id, $c['resource'] ?? null);
            }
        }
    }

    private static function assertBuildingScope(string $id, ?string $scope, array $keys): void
    {
        if (! in_array((string) $scope, EventCode::BUILDING_SCOPES, true)) {
            throw new RuntimeException("events.json:{$id} 的建筑过滤 scope「{$scope}」不是 category / series");
        }
        if ($keys === []) {
            throw new RuntimeException("events.json:{$id} 的建筑过滤没有给 keys");
        }

        $allowed = $scope === EventCode::SCOPE_CATEGORY
            ? EnumCode::BUILDING_CATEGORIES
            : EnumCode::BUILDING_SERIES;

        foreach ($keys as $key) {
            if (! isset($allowed[$key])) {
                throw new RuntimeException("events.json:{$id} 的建筑过滤 key「{$key}」不在 EnumCode 的 {$scope} 名单里");
            }
        }
    }

    private static function assertResource(string $id, ?string $code): void
    {
        if (! isset(ResourceCode::CHINESE_NAMES[(string) $code]) || ResourceCode::isCapacity((string) $code)) {
            throw new RuntimeException("events.json:{$id} 的资源 code「{$code}」不是已登记的库存资源");
        }
    }

    // ---------- 效果守门 ----------

    private static function assertEffects(string $id, string $where, string $type, array $block, bool $isOption): void
    {
        foreach ($block['effects'] ?? [] as $effect) {
            $kind = (string) ($effect['kind'] ?? '');
            if (! in_array($kind, EventCode::EFFECT_KINDS, true)) {
                throw new RuntimeException("events.json:{$id} 的 {$where} 效果 kind「{$kind}」未在 EventCode::EFFECT_KINDS 登记");
            }
            if (! $isOption && in_array($kind, EventCode::OPTION_ONLY_KINDS, true)) {
                throw new RuntimeException("events.json:{$id} 的自动效果里出现了选项专用 kind「{$kind}」(那时还没有已发生的效果可改)");
            }

            match ($kind) {
                EventCode::EFFECT_RESOURCE_DELTA,
                EventCode::EFFECT_RESOURCE_PCT_OF_STOCK => self::assertResource($id, $effect['resource'] ?? null),
                EventCode::EFFECT_GRANT_PRODUCTION_PCT  => self::assertGrant($id, $where, $type, $effect),
                EventCode::EFFECT_MODIFIER              => self::assertModifier($id, $where, $type, $effect),
                default                                 => null,
            };
        }
    }

    // grant = §13 帽修正方向的落点:只有正向事件才允许「直接发资源」
    private static function assertGrant(string $id, string $where, string $type, array $effect): void
    {
        if ($type !== EventCode::TYPE_POSITIVE) {
            throw new RuntimeException("events.json:{$id} 的 {$where} 在负向事件里用了 grant_production_pct(直接发资源只属于正向事件)");
        }
        if ((float) ($effect['value'] ?? 0) <= 0) {
            throw new RuntimeException("events.json:{$id} 的 {$where} grant 比例必须为正");
        }

        foreach ($effect['resources'] ?? [] as $code) {
            self::assertResource($id, $code);
        }
        if (! isset($effect['resources']) && ! isset($effect['resource_group'])) {
            throw new RuntimeException("events.json:{$id} 的 {$where} grant 必须给 resources 或 resource_group");
        }
    }

    // modifier = 持续型效果。三重 allowlist 由 ModifierSpec 的构造函数完成(target / scope / op),
    // 这里另加一条本系统自己的规矩:event 乘区里只放惩罚
    private static function assertModifier(string $id, string $where, string $type, array $effect): void
    {
        $value = (float) ($effect['value'] ?? 0);
        $scope = (string) ($effect['scope'] ?? '');
        $target = (string) ($effect['target'] ?? '');

        if ($target === 'event' && $value > 0) {
            throw new RuntimeException(
                "events.json:{$id} 的 {$where} 往 event 乘区放了正向加成({$value})。" .
                '§13 帽修正方向:正向效果一律走 grant_production_pct 直接发资源,乘区里只放 <1.0 的惩罚'
            );
        }
        if ($target === 'event' && $type === EventCode::TYPE_POSITIVE) {
            throw new RuntimeException("events.json:{$id} 的 {$where} 在正向事件里用了 event 乘区");
        }

        // scope_keys(随机二选一)与 pick(随机挑一栋建筑)在触发时才定得下具体的 scope_key,
        // 用一个占位键先过一遍 ModifierSpec 的构造校验:target / op / scope 三项现在就能验
        $scopeKey = $effect['scope_key'] ?? ($scope === ModifierSpec::SCOPE_CITY ? null : '__placeholder__');

        try {
            new ModifierSpec($target, $scope, 'pct', $value, $scopeKey);
        } catch (\InvalidArgumentException $ex) {
            throw new RuntimeException("events.json:{$id} 的 {$where} modifier 非法 —— {$ex->getMessage()}");
        }

        if ($scope === ModifierSpec::SCOPE_BUILDING_CATEGORY) {
            foreach ($effect['scope_keys'] ?? [$effect['scope_key'] ?? null] as $key) {
                if (! isset(EnumCode::BUILDING_CATEGORIES[(string) $key])) {
                    throw new RuntimeException("events.json:{$id} 的 {$where} building_category「{$key}」不在 EnumCode 名单里");
                }
            }
        }
        if ($scope === ModifierSpec::SCOPE_RESOURCE) {
            self::assertResource($id, $effect['scope_key'] ?? null);
        }
        if ($scope === ModifierSpec::SCOPE_BUILDING_INSTANCE) {
            // 具体实例在触发时随机挑,这里只验挑选规则本身合法
            self::assertBuildingScope($id, $effect['pick']['scope'] ?? null, $effect['pick']['keys'] ?? []);
        }
    }
}
