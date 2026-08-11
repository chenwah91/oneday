<?php

namespace Database\Seeders;

use App\Game\Definition\EnumCode;
use App\Game\Event\EventCode;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
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
                EventCode::EFFECT_RESOURCE_DELTA        => self::assertResource($id, $effect['resource'] ?? null),
                EventCode::EFFECT_RESOURCE_PCT_OF_STOCK => self::assertStockLoss($id, $where, $type, $effect),
                EventCode::EFFECT_GRANT_PRODUCTION_PCT  => self::assertGrant($id, $where, $type, $effect),
                EventCode::EFFECT_MODIFIER              => self::assertModifier($id, $where, $type, $effect),
                EventCode::EFFECT_MODIFIER_SET_VALUE    => self::assertModifierSetValue($id, $where, $effect),
                EventCode::EFFECT_MODIFIER_SCALE        => self::assertModifierScale($id, $where, $effect),
                EventCode::EFFECT_THREAT_LOSS_PCT       => self::assertThreatLoss($id, $where, $type, $effect),
                EventCode::EFFECT_NPC_LEAVE             => self::assertNpcLeave($id, $where, $type, $effect),
                default                                 => null,
            };
        }
    }

    // resource_pct_of_stock = 按库存百分比增减。两种写法:
    //   ① 点名资源(§9.2 EVT_GRANARY_PEST 的粮食、EVT_OIL_SHOCK 选项 A 的石油);
    //   ② **不点名**(W5:§9.2 EVT_CRIME 的「随机库存损失」)—— 触发时从「当前有库存的非资金资源」里随机挑一种。
    // 不点名时必须给 min/max 区间:一条既不点名资源、又不给区间的效果,只会静默扣一个说不清的数。
    // 随机挑选**只允许负向**(损失):「随机送你一种资源」不在 §9.2 的任何一条里,别让它从这个口子溜进来
    private static function assertStockLoss(string $id, string $where, string $type, array $effect): void
    {
        if (isset($effect['resource'])) {
            self::assertResource($id, $effect['resource']);

            return;
        }

        if (! isset($effect['min'], $effect['max'])) {
            throw new RuntimeException("events.json:{$id} 的 {$where} resource_pct_of_stock 没点名资源时必须给 min/max 区间");
        }
        if ((float) $effect['min'] > (float) $effect['max']) {
            throw new RuntimeException("events.json:{$id} 的 {$where} resource_pct_of_stock 区间 min > max");
        }
        if ((float) $effect['max'] >= 0 || $type !== EventCode::TYPE_NEGATIVE) {
            throw new RuntimeException("events.json:{$id} 的 {$where} 随机库存变化只允许负向事件的**损失**(区间必须整体为负)");
        }
    }

    // threat_loss_pct = 按威胁等级计算的损失(M3-D5)。**只属于负向事件**:
    // 「按国防缺口扣库存」在正向事件里没有任何语义,写错了只会表现成一条莫名其妙的扣资源。
    // 比例由 DefenseService 在运行时算,所以这里不校验 value(events.json 里根本不该写它);
    // 带 resource 时(赎金的资金)照常走资源 allowlist
    private static function assertThreatLoss(string $id, string $where, string $type, array $effect): void
    {
        if ($type !== EventCode::TYPE_NEGATIVE) {
            throw new RuntimeException("events.json:{$id} 的 {$where} 在正向事件里用了 threat_loss_pct(按国防缺口扣库存只属于负向事件)");
        }
        if (isset($effect['value'])) {
            throw new RuntimeException(
                "events.json:{$id} 的 {$where} threat_loss_pct 不接受 value —— " .
                '损失比例由 DefenseService::raidLossPct(国防缺口 × 威胁档)在运行时算,写死在定义里会变成第二份口径'
            );
        }
        if (isset($effect['resource'])) {
            self::assertResource($id, $effect['resource']);
        }
    }

    // npc_leave = 随机流失一名在编 NPC(§9.2 EVT_BRAIN_DRAIN)。守两条:
    //   ① 只属于负向事件 —— 「随机走掉一个人」在正向事件里没有任何语义;
    //   ② 不接受任何参数 —— 恒 1 名(§9.2 原文没给数量);写了 value / count 说明作者以为它可配置,
    //      而运行时会**静默忽略**那个数,那正是本 Seeder 存在的意义:宁可 seed 失败也不静默不生效。
    private static function assertNpcLeave(string $id, string $where, string $type, array $effect): void
    {
        if ($type !== EventCode::TYPE_NEGATIVE) {
            throw new RuntimeException("events.json:{$id} 的 {$where} 在正向事件里用了 npc_leave(随机流失 NPC 只属于负向事件)");
        }
        foreach (['value', 'count'] as $key) {
            if (isset($effect[$key])) {
                throw new RuntimeException(
                    "events.json:{$id} 的 {$where} npc_leave 不接受 {$key} —— 恒流失 1 名(§9.2 原文没给数量),写了也不会生效"
                );
            }
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

    // 选项里的「减益降为 -X%」:只允许点名七乘区,且同样不许把减益改成加成。
    // M.1 起 target 可省略(默认 event 乘区);EVT_BLACKOUT 的选项 A 点名 power
    private static function assertModifierSetValue(string $id, string $where, array $effect): void
    {
        $target = (string) ($effect['target'] ?? ModifierTarget::SLOT_EVENT);

        if (! ModifierTarget::isSlot($target)) {
            throw new RuntimeException("events.json:{$id} 的 {$where} modifier_set_value 的 target「{$target}」不是七乘区之一");
        }
        if ((float) ($effect['value'] ?? 0) > 0) {
            throw new RuntimeException("events.json:{$id} 的 {$where} modifier_set_value 把乘区改成了加成(值必须 ≤ 0)");
        }
    }

    // 选项里的「减益减半 / 取消」(W5):系数必须落在 [0, 1],target 必须已登记且不是 flat 通道。
    //
    // 为什么系数不许 > 1:这条 kind 只出现在**补救类选项**里(紧急维护 / 释放储备 / 市场干预),
    // 系数 > 1 会让「补救」反而加重惩罚 —— 那不是数值调优,那是选项文案对不上效果。
    // 为什么排除 flat 通道:幸福 / 治安有自己的 flat_set,两条路都能改同一行就会有人改两次。
    private static function assertModifierScale(string $id, string $where, array $effect): void
    {
        $target = (string) ($effect['target'] ?? ModifierTarget::SLOT_EVENT);
        $value = (float) ($effect['value'] ?? -1);

        if (! in_array($target, ModifierTarget::all(), true)) {
            throw new RuntimeException("events.json:{$id} 的 {$where} modifier_scale 的 target「{$target}」未在 ModifierTarget 登记");
        }
        if (in_array($target, ModifierTarget::FLAT_TARGETS, true)) {
            throw new RuntimeException("events.json:{$id} 的 {$where} modifier_scale 不接受 flat 通道 target「{$target}」(请用 flat_set)");
        }
        if ($value < 0 || $value > 1) {
            throw new RuntimeException("events.json:{$id} 的 {$where} modifier_scale 的系数必须落在 [0, 1](选项只能把减益变小)");
        }
    }

    // modifier = 持续型效果。三重 allowlist 由 ModifierSpec 的构造函数完成(target / scope / op),
    // 这里另加一条本系统自己的规矩:**任何七乘区**里都只放惩罚。
    //
    // M.1 之前这条规矩只写给 event 一格(那时事件也只会往 event 乘区投稿);
    // EVT_BLACKOUT 复活后事件多了一个合法落点(power 乘区的「全城电力可用量-40%」),
    // 所以把判定从「target === 'event'」放宽成「target 是七乘区之一」——
    // 规矩本身没变、还更严了一点:将来谁想往 npc / tool / tech 任何一格塞加成都会当场 seed 失败
    private static function assertModifier(string $id, string $where, string $type, array $effect): void
    {
        // 值可以是定值,也可以是 min/max 区间(W5:EVT_SPECULATION 的「价格+25%~50%」)。
        // 校验时取「幅度最大的那一端」—— 区间掷点掷不出比它更极端的数,验它就等于验了整个区间
        $value = isset($effect['min'], $effect['max'])
            ? (abs((float) $effect['max']) >= abs((float) $effect['min']) ? (float) $effect['max'] : (float) $effect['min'])
            : (float) ($effect['value'] ?? 0);
        $scope = (string) ($effect['scope'] ?? '');
        $target = (string) ($effect['target'] ?? '');

        if (isset($effect['min'], $effect['max']) && (float) $effect['min'] > (float) $effect['max']) {
            throw new RuntimeException("events.json:{$id} 的 {$where} modifier 区间 min > max");
        }

        if (ModifierTarget::isSlot($target) && $value > 0) {
            throw new RuntimeException(
                "events.json:{$id} 的 {$where} 往 {$target} 乘区放了正向加成({$value})。" .
                '§13 帽修正方向:正向效果一律走 grant_production_pct 直接发资源,乘区里只放 <1.0 的惩罚'
            );
        }
        if (ModifierTarget::isSlot($target) && $type === EventCode::TYPE_POSITIVE) {
            throw new RuntimeException("events.json:{$id} 的 {$where} 在正向事件里用了 {$target} 乘区");
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
            // 与 building_category 同一写法:scope_keys(触发时随机挑一个)时逐个验,
            // 否则验单个 scope_key。少了这一支,EVT_SPECULATION 的「随机战略资源」会带着
            // 一个没验过的资源清单进库(写错的 code 只会表现成事件静默不生效)
            foreach ($effect['scope_keys'] ?? [$effect['scope_key'] ?? null] as $key) {
                self::assertResource($id, $key);
            }
        }
        if ($scope === ModifierSpec::SCOPE_BUILDING_INSTANCE) {
            // 具体实例在触发时随机挑,这里只验挑选规则本身合法
            self::assertBuildingScope($id, $effect['pick']['scope'] ?? null, $effect['pick']['keys'] ?? []);
        }
    }
}
