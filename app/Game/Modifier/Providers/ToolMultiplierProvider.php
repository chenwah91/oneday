<?php

namespace App\Game\Modifier\Providers;

use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;

// tool 乘区 —— **占位,恒 1.0**(= 工具接入前的历史行为)。
//
// 认领波次:W3-A「D2 工具 / 道具」。
//
// 接入方式(§7):
//   prepare() 一次性取本城已装备的道具(按 building_instance_id 归组,含 durability_left);
//   multiplierFor() **同一建筑内同 category 只取最高值**(§7 明文,防止堆低级工具),不同 category 相乘。
// 耐久按「建筑实际工作的分钟」扣,那是分段结算里的动作,不在本 Provider 里做
// (本 Provider 只负责返回乘数,不写库)。
// 非产量类的 effect_code(construction_speed_pct / maintenance_cost_reduction_pct / …)不进本格,
// 走 ModifierTarget::CONSUMPTION_POINTS 登记的各自消费点。
//
// **接入时只改本文件,不要碰 SimulationService**(backlog §10.2 纪律)。
final class ToolMultiplierProvider extends MultiplierProvider
{
    public function slot(): string
    {
        return ModifierTarget::SLOT_TOOL;
    }

    public function multiplierFor(array $unit): float
    {
        return 1.0;
    }
}
