<?php

namespace App\Game\Modifier\Providers;

use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;

// npc 乘区 —— **占位,恒 1.0**(= NPC 接入前的历史行为)。
//
// 认领波次:W2-A「D1 NPC 全套」。
//
// 接入方式(§6.4):
//   prepare() 一次性取本城的 NPC 实例 + 分配关系 + 技能等级曲线(按 building_instance_id 归组);
//   multiplierFor() 合成「主技能加成(岗位匹配全额 / 不匹配 ×0.25)+ 副技能 ×0.50 + 特性」,
//   **单建筑封顶 1.60、全城总 NPC 倍率封顶 1.90 这两层帽在本 Provider 内部先夹完**,
//   再交出一格给 multiplierProduct() 统一夹 §13 的 2.75× 总帽(承接「封顶只落在一处」的纪律:
//   §6.4 的双层帽是 NPC 系统内部的合成规则,与 §13 的总帽不是同一件事,两者不冲突)。
// 非产量类的 NPC 特性(治理容量 / 研究速度 / 维护减免)不进本格,
// 走 ModifierTarget::CONSUMPTION_POINTS 登记的各自消费点。
//
// **接入时只改本文件,不要碰 SimulationService**(backlog §10.2 纪律)。
final class NpcMultiplierProvider extends MultiplierProvider
{
    public function slot(): string
    {
        return ModifierTarget::SLOT_NPC;
    }

    public function multiplierFor(array $unit): float
    {
        return 1.0;
    }
}
