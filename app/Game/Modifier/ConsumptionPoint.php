<?php

namespace App\Game\Modifier;

use App\Game\Item\ItemBonus;
use App\Game\Item\ItemCode;
use App\Game\NPC\NpcCode;
use App\Game\NPC\NpcTraitScale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// D0.3 非产量 target 的**取数**工具:把一条 `*_pct` target 在本城的全部投稿汇总成一个比例。
//
// 与 ModifierTarget::CONSUMPTION_POINTS 登记表的关系:
//   登记表回答「这条 target 由谁消费」(每条 target 只有一个消费点,这条纪律不变);
//   本类回答「消费的时候到哪几张表把投稿捞出来」—— 三个来源的取数逻辑只写一份,
//   免得每个消费点各抄一遍「读 modifier + 读 NPC 特性 + 读已装备工具」的三段查询。
//
// 三个投稿来源(与 §6.3 特性 / §7 工具 / §9 事件三张表一一对应):
//   ① city_active_modifiers 里 target 匹配、当前生效中的行(事件写的持续型效果);
//   ② 在编 NPC(idle + assigned)的 trait_json,**每条 spec 乘该 NPC 的 trait_multiplier**(W11-B);
//   ③ 已装备且耐久 > 0 的工具的 effect_json。
//
// 只认 **scope=city**:这类 target 描述的都是全城性的规则修正(工期 / 维护费 / 手续费 / 治理容量),
// 没有「只对某一栋楼的维护费打折」的语义。逐栋的效果一律走七乘区,不走这里。
//
// op 的口径:pct() / pctMany() 只收 op=pct(名字里的 pct 就是这个意思);
// 需要同时拿 flat 与 pct 的消费点(治理容量 W6)走 sumsMany(),它按 op 分桶、两侧分开返回。
// **两侧一律不混**:op 与 target 口径不符的行整条跳过,而不是猜一个语义 ——
// governance 死 target 的病根就是「flat 投稿塞进 pct target」被静默吞掉。
//
// 缺表一律按「没有投稿」处理(Fail Safe):NPC / 工具 / 事件迁移没跑的库仍应照常结算。
//
// 性能纪律:调用点必须在**分段循环之外 / 事务内的准备段**取一次值,不许在循环里逐次调用
// (与 D0 Provider 的 prepare 同一条纪律)。
final class ConsumptionPoint
{
    // 某条 target 在本城的合计比例(0.08 = +8%;-0.05 = −5%)。
    // 不夹取:上下限由各消费点自己按业务语义决定(维护费夹到 ≥0、工期夹到 ≥0.1 倍速…),
    // 在这里统一夹反而会让「哪一处夹的」变得说不清
    public static function pct(string $target, int $cityId, ?Carbon $now = null): float
    {
        $now ??= now();

        return self::fromModifiers($target, $cityId, $now)
            + self::fromNpcTraits($target, $cityId)
            + self::fromEquippedItems($target, $cityId);
    }

    // 一次取回**多条** target 的合计比例:返回 [target => 比例],入参里的 target 一个都不会缺(没投稿就是 0.0)。
    //
    // 与逐条调 pct() 的差别只在查询次数:pct() 是「每条 target 各查三张表」,
    // 本方法是「三张表各查一次、按 target 分桶」。同一个消费点要读四五条 target 时(结算内核就是),
    // 前者会在每次快照上多打十几条查询 —— 口径完全一致,省的纯粹是往返。
    //
    // 纪律不变:仍然只认 op=pct + scope=city,仍然必须在**分段循环之外**调用。
    public static function pctMany(array $targets, int $cityId, ?Carbon $now = null): array
    {
        // pct 侧就是 sumsMany 结果的一半:两个方法只写一份取数,免得口径分叉
        return array_map(
            static fn (array $sums): float => $sums[ModifierSpec::OP_PCT],
            self::sumsMany($targets, $cityId, $now)
        );
    }

    // 一次取回多条 target 的 **pct 与 flat 两侧**合计:返回 [target => ['pct' => …, 'flat' => …]],
    // 入参里的 target 一个都不会缺(没投稿就是 0.0)。
    //
    // 为什么要有这一条而不是「pctMany + flatMany 各调一次」:治理容量(W6)与国防(W4-B)一样,
    // 同一个消费点要在**同一次读取**里同时拿到 flat 与 pct 才能按固定顺序合成
    //((建筑口径 + Σflat) × (1 + Σpct))。分两趟取等于把三张表各查两遍 ——
    // 口径完全一样,多出来的纯粹是往返(与 pctMany 相对逐条 pct() 省的是同一种东西)。
    //
    // 与 DefenseService::bonuses() 的关系:那一处是**读取侧**的三条国防 target 专用聚合,
    // 挂在每次快照上;本方法是通用入口,内核的消费点用它。两处的判定口径逐字一致 ——
    // 只认 scope=city,flat 通道只收 op=flat、pct 通道只收 op=pct,**口径不符的行整条跳过**
    //(不猜语义:猜错在运行时只表现为数值悄悄不对,那正是 governance 死 target 的病根)。
    public static function sumsMany(array $targets, int $cityId, ?Carbon $now = null): array
    {
        $now ??= now();

        $totals = array_fill_keys($targets, [ModifierSpec::OP_PCT => 0.0, ModifierSpec::OP_FLAT => 0.0]);
        if ($targets === []) {
            return $totals;
        }

        // ① 事件写下的持续型 modifier
        if (DB::getSchemaBuilder()->hasTable('city_active_modifiers')) {
            $rows = DB::table('city_active_modifiers')
                ->where('city_id', $cityId)
                ->whereIn('target', $targets)
                ->whereIn('op', ModifierSpec::OPS)
                ->where('scope', ModifierSpec::SCOPE_CITY)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>', $now)
                ->get(['target', 'op', 'value']);

            foreach ($rows as $row) {
                $totals[(string) $row->target][(string) $row->op] += (float) $row->value;
            }
        }

        // ② 在编 NPC 的特性 / ③ 已装备且耐久 > 0 的工具:两处的 specs 走同一条累加
        foreach (self::citySpecs($cityId) as $spec) {
            if (array_key_exists($spec->target, $totals) && $spec->scope === ModifierSpec::SCOPE_CITY) {
                $totals[$spec->target][$spec->op] += $spec->value;
            }
        }

        return $totals;
    }

    // 某条 target 对**某一种资源**的合计比例 = 全城作用域(scope=city)+ 该资源作用域(scope=resource)。
    //
    // 两者相加而不是二选一:「全市场价格 +10%」与「石油价格 +40%」是可以同时存在的两件事
    // (§9.2 的 EVT_GLOBAL_CRISIS 与 EVT_OIL_SHOCK 就是这种关系)。
    // 目前唯一的调用方是 TradeService 的 market_price_pct 消费点
    public static function pctForResource(string $target, int $cityId, string $resourceId, ?Carbon $now = null): float
    {
        $now ??= now();

        $total = self::pct($target, $cityId, $now);

        if (! DB::getSchemaBuilder()->hasTable('city_active_modifiers')) {
            return $total;
        }

        return $total + (float) DB::table('city_active_modifiers')
            ->where('city_id', $cityId)
            ->where('target', $target)
            ->where('op', ModifierSpec::OP_PCT)
            ->where('scope', ModifierSpec::SCOPE_RESOURCE)
            ->where('scope_key', $resourceId)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->sum('value');
    }

    // 某条 target 对**一批资源**的合计比例:返回 [资源 => 比例],入参里的资源一个都不会缺(没投稿就是 0.0)。
    //
    // 每一项的口径与 pctForResource 逐字一致(全城作用域 + 该资源作用域相加),差别只在查询次数:
    //   pctForResource 是「问一个资源查一次」(三张表 + 一次资源作用域查询);
    //   本方法是「三张表查一次 + 资源作用域一次分组查询」。
    // GET /api/market/prices 要给 28 个资源逐个附上价格冲击,逐个调 pctForResource 会打出
    // 上百条查询(§38 明文反 N+1),而口径完全一样 —— 省的纯粹是往返。
    //
    // 只读用途:唯一调用方是价目表端点的 buy_price_pct(玩家看到的「本城买入侧要贵多少」)。
    // **成交仍然走 pctForResource**(TradeService 的消费点),因为成交必须在城市行锁内当场取值,
    // 不能用一份可能已经过期的批量快照 —— 两处口径一致但取值时机不同,这一条不许混用。
    public static function pctByResource(string $target, int $cityId, array $resourceIds, ?Carbon $now = null): array
    {
        $now ??= now();

        $cityWide = self::pct($target, $cityId, $now);
        $totals = array_fill_keys($resourceIds, $cityWide);

        if ($resourceIds === [] || ! DB::getSchemaBuilder()->hasTable('city_active_modifiers')) {
            return $totals;
        }

        // MySQL 5.7 兼容:纯 GROUP BY,不用窗口函数 / CTE
        $rows = DB::table('city_active_modifiers')
            ->where('city_id', $cityId)
            ->where('target', $target)
            ->where('op', ModifierSpec::OP_PCT)
            ->where('scope', ModifierSpec::SCOPE_RESOURCE)
            ->whereIn('scope_key', $resourceIds)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->groupBy('scope_key')
            ->get(['scope_key', DB::raw('SUM(value) as total')]);

        foreach ($rows as $row) {
            $totals[(string) $row->scope_key] += (float) $row->total;
        }

        return $totals;
    }

    // 在编 NPC 特性 + 已装备工具的全部 specs(pctMany 用;逐条口径与 fromNpcTraits / fromEquippedItems 一致)
    private static function citySpecs(int $cityId): array
    {
        $specs = [];

        if (DB::getSchemaBuilder()->hasTable('city_npcs')) {
            // trait_multiplier 一并取出:NPC 特性的强度倍率(W11-B),每条 spec 的值统一乘它。
            // **只乘 NPC 这一路** —— 下面的工具 specs 原样不动(见 NpcTraitScale 顶部的口径说明)
            $traits = DB::table('city_npcs as cn')
                ->join('npc_definition as nd', 'cn.npc_id', '=', 'nd.npc_id')
                ->where('cn.city_id', $cityId)
                ->whereIn('cn.status', NpcCode::ACTIVE_STATUSES)
                ->get(['nd.trait_json', 'nd.trait_multiplier']);

            foreach ($traits as $row) {
                foreach (NpcTraitScale::specs($row->trait_json, $row->trait_multiplier) as $spec) {
                    $specs[] = $spec;
                }
            }
        }

        if (DB::getSchemaBuilder()->hasTable('city_items')) {
            $effects = DB::table('city_items as ci')
                ->join('item_definition as it', 'ci.item_id', '=', 'it.item_id')
                ->where('ci.city_id', $cityId)
                ->where('ci.status', ItemCode::STATUS_EQUIPPED)
                ->whereNotNull('ci.equipped_instance_id')
                ->where('ci.durability_left', '>', 0)
                ->pluck('it.effect_json');

            foreach ($effects as $json) {
                foreach (ItemBonus::specsFromJson($json) as $spec) {
                    $specs[] = $spec;
                }
            }
        }

        return array_values(array_filter($specs, fn ($s) => $s instanceof ModifierSpec));
    }

    // ① 事件等写下的持续型 modifier
    private static function fromModifiers(string $target, int $cityId, Carbon $now): float
    {
        if (! DB::getSchemaBuilder()->hasTable('city_active_modifiers')) {
            return 0.0;
        }

        return (float) DB::table('city_active_modifiers')
            ->where('city_id', $cityId)
            ->where('target', $target)
            ->where('op', ModifierSpec::OP_PCT)
            ->where('scope', ModifierSpec::SCOPE_CITY)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->sum('value');
    }

    // ② 在编 NPC 的特性(§6.3 trait_json 已由 D1 结构化成 specs)
    private static function fromNpcTraits(string $target, int $cityId): float
    {
        if (! DB::getSchemaBuilder()->hasTable('city_npcs')) {
            return 0.0;
        }

        $total = 0.0;
        // trait_multiplier 一并取出(W11-B):与 citySpecs 逐字同口径,两处不许分叉
        $traits = DB::table('city_npcs as cn')
            ->join('npc_definition as nd', 'cn.npc_id', '=', 'nd.npc_id')
            ->where('cn.city_id', $cityId)
            ->whereIn('cn.status', NpcCode::ACTIVE_STATUSES)
            ->get(['nd.trait_json', 'nd.trait_multiplier']);

        foreach ($traits as $row) {
            $total += self::sumCitySpecs(NpcTraitScale::specs($row->trait_json, $row->trait_multiplier), $target);
        }

        return $total;
    }

    // ③ 已装备且耐久 > 0 的工具(§7 effect_json 已由 D2 结构化成 specs)。
    // stored(躺仓库)与 broken(已损毁)不参与 —— 与 ToolMultiplierProvider 同一条口径
    private static function fromEquippedItems(string $target, int $cityId): float
    {
        if (! DB::getSchemaBuilder()->hasTable('city_items')) {
            return 0.0;
        }

        $total = 0.0;
        $effects = DB::table('city_items as ci')
            ->join('item_definition as it', 'ci.item_id', '=', 'it.item_id')
            ->where('ci.city_id', $cityId)
            ->where('ci.status', ItemCode::STATUS_EQUIPPED)
            ->whereNotNull('ci.equipped_instance_id')
            ->where('ci.durability_left', '>', 0)
            ->pluck('it.effect_json');

        foreach ($effects as $json) {
            $total += self::sumCitySpecs(ItemBonus::specsFromJson($json), $target);
        }

        return $total;
    }

    private static function sumCitySpecs(array $specs, string $target): float
    {
        $sum = 0.0;
        foreach ($specs as $spec) {
            if ($spec instanceof ModifierSpec
                && $spec->target === $target
                && $spec->op === ModifierSpec::OP_PCT
                && $spec->scope === ModifierSpec::SCOPE_CITY) {
                $sum += $spec->value;
            }
        }

        return $sum;
    }
}
