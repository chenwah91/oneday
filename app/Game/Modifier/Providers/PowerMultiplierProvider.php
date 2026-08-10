<?php

namespace App\Game\Modifier\Providers;

use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;

// power 乘区 —— **占位,恒 1.0**(= 电力接入前的历史行为)。
//
// 认领波次:W4-A「M.1 电力系统」。M2 从头到尾没认领过这一格:
// v3.2 §19 的 M2-C1~C6 清单里没有电力这一节,`power_per_min` seed 了 57 行至今零读取。
//
// 接入方式(F4 裁决「电力做流量不做库存」):
//   prepare() 里聚合全城发电与耗电 → powerFactor = min(1, 发电 / 耗电);
//   multiplierFor() 按建筑是否耗电返回 powerFactor 或 1.0。
//   §15 回归表要求的「耗电建筑获取电力为 0 → 实际产出为 0」那条测试,落点就在这里。
//
// **接入时只改本文件,不要碰 SimulationService**(backlog §10.2 纪律)。
final class PowerMultiplierProvider extends MultiplierProvider
{
    public function slot(): string
    {
        return ModifierTarget::SLOT_POWER;
    }

    public function multiplierFor(array $unit): float
    {
        return 1.0;
    }
}
