<?php

namespace App\Game\Modifier\Providers;

use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// event 乘区 + 事件的 flat 通道(M3-D4 W3-B 接线,v3.2 §9)。
//
// 数据源只有一张表:city_active_modifiers(source_type = 'event')。
// 事件系统写它、本 Provider 读它,内核照旧只认总线的三个动作 —— SimulationService 一个字未改。
//
// ══ 只放惩罚,不放加成(§13 帽修正方向)══════════════════════════════════════
// 表里 target=event 的行恒为负值(Seeder 在入库前就把「正向事件用 event 乘区」挡掉了):
// 正向事件一律**直接发资源**(EventEffect::grantProduction),不占 §13 的 2.75 加成帽。
// 所以本格的输出恒 ≤ 1.0,乘进 multiplierProduct() 之后只会压低乘积,永远不会顶到帽。
//
// ══ 按覆盖比例折算(跨段生效的落地方式)════════════════════════════════════
// 七乘区在 applyLocked 里是**整段窗口**取一次值(prepare 在分段循环之外),
// 而事件可能只覆盖窗口的一部分(触发于中途、或中途到期)。
// 所以这里把「-35% 持续 8 分钟」在 30 分钟的结算窗口里折算成 -35% × (8/30):
// 结果与「前 8 分钟按 0.65 倍、后 22 分钟按 1.0 倍」的分段积分在一阶上一致,
// 而且到期之后覆盖比例自然归 0 —— 数值会自己恢复,不需要任何清理任务。
//
// flat 通道(幸福 / 治安)则更精细:内核**逐段**调用 flat(target, 段起, 段止),
// 本 Provider 按每一段与事件生效区间的交集折算,跨段到期能精确落在正确的那一段。
final class EventMultiplierProvider extends MultiplierProvider
{
    // 结算窗口的起点与总分钟数(prepare 阶段算好,后面全靠它换算偏移量)
    private ?Carbon $windowStart = null;

    private float $totalMinutes = 0.0;

    // target=event 的行(已折算好覆盖比例):['scope','scope_key','value']
    private array $productionSpecs = [];

    // flat 通道的行(保留原始区间,取值时再按调用方给的段求交):
    // ['target','value','from','to'](from/to = 相对窗口起点的分钟偏移)
    private array $flatRows = [];

    // building_id => category。中间结构 $unit 里只有 building_id,
    // 而 building_category 作用域要按分类命中 —— 与 NpcMultiplierProvider 同样在准备段查一次
    private array $categoryByBuilding = [];

    public function slot(): string
    {
        return ModifierTarget::SLOT_EVENT;
    }

    public function prepare(ModifierContext $context, array $units): void
    {
        $this->productionSpecs = [];
        $this->flatRows = [];
        $this->categoryByBuilding = [];
        $this->totalMinutes = max(0.0, $context->totalMinutes);
        $this->windowStart = Carbon::parse($context->now)->copy()->subSeconds((int) round($this->totalMinutes * 60));

        // 表可能还不存在(事件迁移未跑的库):缺表 = 与接入前完全一致的历史行为(恒 1.0),
        // 而不是让整个结算内核炸掉
        if (! DB::getSchemaBuilder()->hasTable('city_active_modifiers')) {
            return;
        }

        // 与本次结算窗口**有交集**的行:ends_at > 窗口起点 且 starts_at < 窗口终点。
        // 一次查完(索引 idx_active_mod_city_ends),循环内零查库
        $rows = DB::table('city_active_modifiers')
            ->where('city_id', $context->cityId)
            ->where('ends_at', '>', $this->windowStart)
            ->where('starts_at', '<', $context->now)
            ->get(['target', 'scope', 'scope_key', 'op', 'value', 'starts_at', 'ends_at']);

        foreach ($rows as $row) {
            [$from, $to] = $this->offsets($row, $context);
            $target = (string) $row->target;

            if ($target === ModifierTarget::SLOT_EVENT && $row->op === ModifierSpec::OP_PCT) {
                $coverage = $this->totalMinutes > 0
                    ? max(0.0, min(1.0, ($to - $from) / $this->totalMinutes))
                    : 1.0; // elapsed=0 的快照:没有区间可分摊,按「此刻生效」全额显示

                $this->productionSpecs[] = [
                    'scope'     => (string) $row->scope,
                    'scope_key' => $row->scope_key === null ? null : (string) $row->scope_key,
                    'value'     => (float) $row->value * $coverage,
                ];

                continue;
            }

            if (in_array($target, ModifierTarget::FLAT_TARGETS, true) && $row->op === ModifierSpec::OP_FLAT) {
                $this->flatRows[] = [
                    'target' => $target,
                    'value'  => (float) $row->value,
                    'from'   => $from,
                    'to'     => $to,
                ];
            }

            // 其余 target(event_loss_reduction_pct 等非产量项)不归本格管:
            // 它们各有自己的唯一消费点(D0.3 的登记表),在那里直接读表,不经总线的乘区
        }

        // 只有真的出现了 building_category 作用域才查建筑分类(绝大多数结算里这张表根本不用查)
        $needsCategory = false;
        foreach ($this->productionSpecs as $spec) {
            $needsCategory = $needsCategory || $spec['scope'] === ModifierSpec::SCOPE_BUILDING_CATEGORY;
        }

        if ($needsCategory) {
            $this->categoryByBuilding = DB::table('building_definition')
                ->whereIn('building_id', $context->buildingIds ?: [''])
                ->pluck('category', 'building_id')->all();
        }
    }

    // 一行 modifier 与本次结算窗口的交集,换算成「相对窗口起点的分钟偏移」
    private function offsets(object $row, ModifierContext $context): array
    {
        $startTs = max(Carbon::parse($row->starts_at)->getTimestamp(), $this->windowStart->getTimestamp());
        $endTs = min(Carbon::parse($row->ends_at)->getTimestamp(), $context->now->getTimestamp());

        $from = ($startTs - $this->windowStart->getTimestamp()) / 60.0;
        $to = ($endTs - $this->windowStart->getTimestamp()) / 60.0;

        return [$from, max($from, $to)];
    }

    // 逐实例的 event 乘区值。多条并存时**连乘**:两个 -30% 的减益叠加是 0.7 × 0.7,
    // 不是 1 − 0.6 —— 相加会在三条减益同时生效时把产量打成负数
    public function multiplierFor(array $unit): float
    {
        $factor = 1.0;

        foreach ($this->productionSpecs as $spec) {
            if ($this->applies($spec, $unit)) {
                $factor *= max(0.0, 1.0 + $spec['value']);
            }
        }

        return max(0.0, $factor);
    }

    // scope 命中判定(与 NpcBonus::specApplies 同一套口径,免得两个系统对「作用范围」有两种理解):
    //   city              全城,恒命中
    //   building_category 建筑 category 相同
    //   building_instance 建筑实例 id 相同
    //   resource          这栋建筑**产出**该资源(「木材产量-30%」落到所有产木材的建筑上)
    private function applies(array $spec, array $unit): bool
    {
        return match ($spec['scope']) {
            ModifierSpec::SCOPE_CITY              => true,
            ModifierSpec::SCOPE_BUILDING_CATEGORY => $spec['scope_key'] === ($this->categoryByBuilding[$unit['buildingId'] ?? ''] ?? null),
            ModifierSpec::SCOPE_BUILDING_INSTANCE => $spec['scope_key'] === (string) ($unit['instanceId'] ?? ''),
            ModifierSpec::SCOPE_RESOURCE          => isset($unit['grossOut'][$spec['scope_key']]),
            default                               => false,
        };
    }

    // flat 通道(D0.2):内核逐段调用,这里按「本段 ∩ 事件生效区间」的比例折算。
    // 完全覆盖本段 → 全额;覆盖一半 → 半额;不相交 → 0。
    // 段长为 0(elapsed=0 的快照)时退化成「该时刻是否生效」,生效即全额
    public function timedFlatSpecs(float $fromOffset, float $toOffset): array
    {
        $span = $toOffset - $fromOffset;
        $specs = [];

        foreach ($this->flatRows as $row) {
            $overlap = min($toOffset, $row['to']) - max($fromOffset, $row['from']);

            if ($span <= 0) {
                // 时点判定:[from, to] 是闭开区间,起点算生效、终点不算
                if ($row['from'] <= $fromOffset && $row['to'] > $fromOffset) {
                    $specs[] = ModifierSpec::flat($row['target'], $row['value']);
                }

                continue;
            }

            if ($overlap > 0) {
                $specs[] = ModifierSpec::flat($row['target'], $row['value'] * min(1.0, $overlap / $span));
            }
        }

        return $specs;
    }

    // 读数(测试与调试用:结算侧已经通过乘区与 flat 通道生效,不要在别处二次使用)
    public function productionSpecs(): array
    {
        return $this->productionSpecs;
    }
}
