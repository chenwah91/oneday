<?php

namespace App\Game\Energy;

use App\Support\GameSetting;

// M.1 电力系统的口径中心(v3.2 §3.3 energyFactor + §8 RS017 capacity_contract + backlog 9.F4)。
//
// ══ 电力是**流量**不是库存(9.F4 已批准)═══════════════════════════════════════
// §8 把 RS017 electricity 的 trade_mode 写成 `capacity_contract`(产能合约),
// 9.F4 据此裁决:`powerFactor = min(1, 全城发电 / 全城耗电)`,电力**不进 city_resources**。
// 落地成三条,三条都在结算内核里:
//   ① 发电 = 建筑 output_json 里的 `electricity` 速率 —— 与仓储 / 人口 / 治理 / 运输容量
//      同一条「容量类产出」通道提取成全城值,不进 grossOut → 不入库;
//   ② 耗电 = building_level_definition 的 `power_per_min` 那一列(**唯一口径**)。
//      input_json 里的 `electricity` 是 V2 遗留的同一件事的第二种写法(36 行里 33 行两值完全相等,
//      F08 / F09 / F10 三栋的 power_per_min 反而更高),两处都读就是双计 → 内核只认 power_per_min;
//   ③ 满足率按 §3.3 折算成 power 乘区,只作用在**真正耗电的建筑**上。
//
// ══ 曲线为什么不照抄物流的分档 ═══════════════════════════════════════════════
// §3.3 给了电力**专属**公式:`energyFactor = hasPowerDemand ? clamp(powerReceived / powerDemand, 0, 1) : 1`
// —— 纯线性、下限 0、没有分档。它与物流(§10.7 的 0.80 / 1.00 / 1.25 三个拐点 + 0.25 下限)
// 刻意不同:§15 回归表明文要求「耗电建筑获取电力为 0 → 对应建筑实际产出为 0」,
// 物流那种 0.25 的兜底会让这条测试永远绿不了。
// 有专属曲线就照抄专属曲线(v3.2 优先),不套物流的形状 —— 但拐点 / 下限 / 起算时代三个参数
// 仍然做成后台可调(GameSetting 的 power_* 四条),默认值精确等于 §3.3 的裸口径。
final class PowerService
{
    // 电力覆盖率 = 可用发电 / 耗电需求。
    // 需求为 0 → 1.0(§3.3 的 `hasPowerDemand ? … : 1`:不耗电的城市不该被判成「缺电」)
    public static function coverage(float $available, float $demand): float
    {
        if ($demand <= 0) {
            return 1.0;
        }

        return max(0.0, $available) / $demand;
    }

    // §3.3 的 energyFactor。$fullRatio / $min 省略时读后台设定(默认 1.00 / 0.00 = §3.3 裸口径)。
    //
    // $fullRatio 是「满供拐点」:覆盖率 ≥ 它即视为满供不打折。默认 1.00 等于没有宽限档;
    // 调到 0.95 就得到一条「轻微缺电不降产」的分档曲线(与 §10.7 物流 0.80~1.00 那一档同形)。
    public static function factor(float $available, float $demand, ?float $fullRatio = null, ?float $min = null): float
    {
        $coverage = self::coverage($available, $demand);

        $fullRatio = $fullRatio ?? (float) GameSetting::get(GameSetting::POWER_FULL_SUPPLY_RATIO);
        $min = $min ?? (float) GameSetting::get(GameSetting::POWER_FACTOR_MIN);

        if ($coverage >= $fullRatio) {
            return 1.0;
        }

        return max($min, min(1.0, $coverage));
    }

    // 电力使用率(§9.2 EVT_BLACKOUT 的条件「电力使用率>85%」)。
    //
    // 分母取**名义装机容量**而不是「事件减益后的可用发电」:使用率是玩家的经营指标
    // (我的电网还剩多少余量),不该被大停电本身推高 —— 否则一次断电会把使用率顶到天上,
    // 连锁拉高后续能源类事件的权重。
    // 分母的 max(1, …) 与 §10.7 transportLoad 是同一套写法:装机为 0 时不炸除零,
    // 而是让「有耗电建筑却一台发电机都没有」表现成使用率 > 1(确实是最缺电的状态)。
    public static function usageRate(float $demand, float $capacity): float
    {
        return max(0.0, $demand) / max(1.0, $capacity);
    }
}
