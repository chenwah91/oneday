<?php

namespace App\Game\Modifier\Providers;

use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;

// event 乘区 —— **占位,恒 1.0**(= 事件接入前的历史行为)。
//
// 认领波次:W3-B「D4 随机事件引擎」。
//
// 接入方式(§9):
//   prepare() 从 `city_active_modifiers` 取本城生效中的产量类修正(按 scope 归组);
//   multiplierFor() 按 scope(全城 / 某类 / 某栋)合成该实例的事件乘数。
// 事件对**幸福 / 治安**的冲击不走本格,走 D0.2 的 flat 通道
// (ModifierTarget::HAPPINESS_FLAT / SECURITY_FLAT):
//   duration = 0 的瞬时型改当前值(事件系统自己结算时改,不经总线);
//   duration > 0 的持续型改目标值,由 §10.2 的快落慢升自然收敛。
// 负面事件的资源直接损失(EVT_GRANARY_PEST 粮食 -8%~15%)在分段结算的**段起**一次性扣,
// 也不走本格 —— 那是一次性 delta 不是速率乘数。
//
// **接入时只改本文件,不要碰 SimulationService**(backlog §10.2 纪律)。
final class EventMultiplierProvider extends MultiplierProvider
{
    public function slot(): string
    {
        return ModifierTarget::SLOT_EVENT;
    }

    public function multiplierFor(array $unit): float
    {
        return 1.0;
    }
}
