<?php

namespace App\Game\Modifier\Providers;

use App\Game\Energy\PowerService;
use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;
use App\Support\GameSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// power 乘区(M.1 电力系统 W4-A 接线,v3.2 §3.3 / §8 RS017 / backlog 9.F4)。
//
// M2 从头到尾没认领过这一格:§19 的 M2-C1~C6 清单里没有电力这一节,`power_per_min` seed 了 57 行零读取。
// 本波次把它接上,口径与公式全在 App\Game\Energy\PowerService(不在这里抄第二份)。
//
// 三段式生命周期(ProviderInterface 的纪律):
//   prepare()       锁内、分段循环之外,**一条查询**(事件减益)取齐全部输入,算出全城 powerFactor;
//   multiplierFor() 纯函数:按 §3.3 的 `hasPowerDemand ? energyFactor : 1` 逐实例返回;
//   flatSpecs()     不投稿(电力不是「资金 / 口粮」类常态开销,它是产能合约不是支出)。
//
// ══ 取数从哪来 ═══════════════════════════════════════════════════════════════
//   发电(装机)= 内核在提取容量类产出时聚合好的 ModifierContext::CAP_POWER
//                 (= 建筑 output_json 里 electricity 的速率之和);
//   耗电(需求)= 中间结构里的 `powerPerMin`(= building_level_definition.power_per_min 那一列)。
// 两者都是**名义速率**,与 LogisticsMultiplierProvider 取名义输入输出当运输需求是同一条口径 ——
// 理由也一样:拿折算后的速率当分子分母会自己吃自己(power 本身就是七乘区之一,收敛都不保证)。
//
// 由此产生一条必须写清楚的后果:**电站不派工 / 没煤也照发电**。
// 这不是漏判,而是「容量类产出不受乘区与满足率影响」这条既有口径的延伸
// (仓储建筑不派工也给仓容、道路不派工也给运力,§8 又把电力定义成 capacity_contract)。
// 若要改成「按电站的实际产能发电」,需要在 D0 总线上引入两阶段 prepare,属后续波次。
//
// ══ EVT_BLACKOUT 的接线点就在这里 ════════════════════════════════════════════
// 「全城电力可用量-40%」写成一行 city_active_modifiers(target=power / scope=city / op=pct / value=-0.40),
// 本 Provider 按**与结算窗口的交集比例**折算后作用在**发电侧**(可用发电 = 装机 × (1 + Σpct))。
// 折算方式与 EventMultiplierProvider 逐字对齐:到期之后覆盖比例自然归 0,数值会自己恢复,
// 不需要任何清理任务。作用在发电侧而不是直接乘产量,是为了让「减益降为-10%」这类选项
// 与电网余量产生真实互动 —— 电力充裕的城市砍 40% 装机可能仍然不缺电,这正是设计意图。
//
// **接入时不新增乘区、不改 ModifierBus**(backlog §10.2 纪律)。
final class PowerMultiplierProvider extends MultiplierProvider
{
    // 名义装机容量(资源/分钟):建筑 output_json 里 electricity 的速率之和
    private float $capacityPerMin = 0.0;

    // 事件减益后的可用发电
    private float $availablePerMin = 0.0;

    // 全城耗电需求(power_per_min 之和)
    private float $demandPerMin = 0.0;

    private float $factor = 1.0;

    // 本次结算窗口内生效的 power 事件减益合计(≤ 0,已按覆盖比例折算)
    private float $eventPct = 0.0;

    // 闸门:总开关关掉 / 时代未到 → 不计需求,乘区恒 1.0(= 接入前的历史行为)
    private bool $gated = false;

    public function slot(): string
    {
        return ModifierTarget::SLOT_POWER;
    }

    public function prepare(ModifierContext $context, array $units): void
    {
        $this->capacityPerMin = max(0.0, $context->capacity(ModifierContext::CAP_POWER));

        $demand = 0.0;
        foreach ($units as $u) {
            $demand += max(0.0, (float) ($u['powerPerMin'] ?? 0));
        }

        // 时代闸门(与 SimConstants::LOGISTICS_MIN_ERA_ORDER 同款,只是做成了后台可调):
        // 全表最早的发电建筑 E03 与最早的耗电建筑 F08 / P07 / P08 都在时代 VIII,
        // 所以默认 8 在现有数据下是**冗余的第二道保险**(时代 VIII 之前需求天然为 0);
        // 它存在的意义是:将来往低时代补耗电建筑时,不至于让那批城市开局即断电且无法自救。
        $this->gated = GameSetting::get(GameSetting::POWER_GATE_ENABLED) !== true
            || $context->eraOrder < (int) GameSetting::get(GameSetting::POWER_MIN_ERA_ORDER);

        $this->demandPerMin = $this->gated ? 0.0 : $demand;
        $this->eventPct = $this->readEventPct($context);
        $this->availablePerMin = max(0.0, $this->capacityPerMin * (1.0 + $this->eventPct));
        $this->factor = $this->gated
            ? 1.0
            : PowerService::factor($this->availablePerMin, $this->demandPerMin);
    }

    // §3.3:`energyFactor = hasPowerDemand ? clamp(powerReceived / powerDemand, 0, 1) : 1`。
    // 不耗电的建筑(住宅 / 道路 / 时代 I~VII 的绝大多数)恒 1.0 —— 断电不该殃及它们
    public function multiplierFor(array $unit): float
    {
        return ((float) ($unit['powerPerMin'] ?? 0)) > 0 ? $this->factor : 1.0;
    }

    // ---- 事件减益(EVT_BLACKOUT 的唯一接线点)----

    // 与本次结算窗口有交集的 target=power 行,按交集比例折算后求和(恒 ≤ 0)。
    // 只认 scope=city:「全城电力可用量-40%」作用于电网整体;
    // 逐栋 / 逐类的限电将来若要做,应另立一条 target,不在这里静默扩语义
    private function readEventPct(ModifierContext $context): float
    {
        // 表可能还不存在(事件迁移未跑的库):缺表 = 无减益,而不是让整个结算内核炸掉
        if (! DB::getSchemaBuilder()->hasTable('city_active_modifiers')) {
            return 0.0;
        }

        $totalMinutes = max(0.0, $context->totalMinutes);
        $windowStart = Carbon::parse($context->now)->copy()->subSeconds((int) round($totalMinutes * 60));

        $rows = DB::table('city_active_modifiers')
            ->where('city_id', $context->cityId)
            ->where('target', ModifierTarget::SLOT_POWER)
            ->where('op', ModifierSpec::OP_PCT)
            ->where('scope', ModifierSpec::SCOPE_CITY)
            ->where('ends_at', '>', $windowStart)
            ->where('starts_at', '<', $context->now)
            ->get(['value', 'starts_at', 'ends_at']);

        $sum = 0.0;
        foreach ($rows as $row) {
            $startTs = max(Carbon::parse($row->starts_at)->getTimestamp(), $windowStart->getTimestamp());
            $endTs = min(Carbon::parse($row->ends_at)->getTimestamp(), $context->now->getTimestamp());
            // elapsed=0 的快照:没有区间可分摊,按「此刻生效」全额显示(与 EventMultiplierProvider 一致)
            $coverage = $totalMinutes > 0
                ? max(0.0, min(1.0, ($endTs - $startTs) / 60.0 / $totalMinutes))
                : 1.0;

            $sum += (float) $row->value * $coverage;
        }

        return $sum;
    }

    // ---- 读数:内核取回去放进返回值给前端显示,不参与结算 ----

    public function capacityPerMin(): float
    {
        return $this->capacityPerMin;
    }

    public function availablePerMin(): float
    {
        return $this->availablePerMin;
    }

    public function demandPerMin(): float
    {
        return $this->demandPerMin;
    }

    public function factor(): float
    {
        return $this->factor;
    }

    public function eventPct(): float
    {
        return $this->eventPct;
    }

    // 电力余量(可用发电 − 需求,不为负)。§4 的 `power_spare_per_min>=N` 特殊前置
    // (P10 15 / M02 18 / C04 20 / K04 25)将来接闸门时读的就是它 —— 见交付汇报的 POWER_SHORTAGE 一节
    public function sparePerMin(): float
    {
        return max(0.0, $this->availablePerMin - $this->demandPerMin);
    }

    // 电力使用率(EVT_BLACKOUT 的条件 metric)
    public function usageRate(): float
    {
        return PowerService::usageRate($this->demandPerMin, $this->capacityPerMin);
    }

    // 缺电警报(与物流的 congestion 对称):有需求且 factor < 1
    public function shortage(): bool
    {
        return $this->demandPerMin > 0 && $this->factor < 1.0;
    }
}
