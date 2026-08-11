<?php

namespace App\Game\Modifier\Providers;

use App\Game\Item\ItemBonus;
use App\Game\Item\ItemCode;
use App\Game\Item\ItemDefinition;
use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;
use Illuminate\Support\Facades\DB;

// tool 乘区(M3-D2 W3-A 接线,v3.2 §7)。
//
// 三段式生命周期(ProviderInterface 的纪律):
//   prepare()       锁内、分段循环之外,**两条查询**取齐本城已装备工具与建筑分类,算出「实例 → 乘数」表;
//   multiplierFor() 纯函数查表,循环内零查库;
//   flatSpecs()     不投稿(工具没有常态开销;耐久是「点数」不是「资金 / 口粮」,不走支出通道)。
//
// 合成规则在 ItemBonus:**同一建筑内同 category 只取最高值(§7),不同 category 相乘**。
// 帽的分工:§7 没有工具侧总帽 → 这里一个都不夹;§13 的 2.75 总帽仍然只由
// SimulationService::multiplierProduct() 夹一次(承接 M2「封顶只落在一处」的纪律)。
//
// 耐久为什么不在这里扣:本 Provider 跑在结算内核的准备段,**只负责返回乘数,不写库**。
// 「按建筑实际工作的分钟扣耐久」是一次写操作,走独立的懒结算时钟(ItemRuntimeService,
// 与 NpcRuntimeService 同一条路径)。这里只做一件相关的事:耐久已经归零的工具不给加成
// —— 万一某次结算跑在耐久结算之前,也不会出现「已经报废还在加成」的一段。
//
// **接入时只改本文件,不要碰 SimulationService**(backlog §10.2 纪律)。
final class ToolMultiplierProvider extends MultiplierProvider
{
    // building_instance_id => 该栋建筑的 tool 乘区值
    private array $byInstance = [];

    public function slot(): string
    {
        return ModifierTarget::SLOT_TOOL;
    }

    public function prepare(ModifierContext $context, array $units): void
    {
        $this->byInstance = [];

        // ① 本城已装备且耐久 > 0 的工具。
        //    stored(躺在仓库)与 broken(已损毁)一律不参与:前者没装上去,后者按 B4 已经报废
        $equipped = DB::table('city_items')
            ->where('city_id', $context->cityId)
            ->where('status', ItemCode::STATUS_EQUIPPED)
            ->whereNotNull('equipped_instance_id')
            ->where('durability_left', '>', 0)
            ->get(['item_id', 'equipped_instance_id']);

        if ($equipped->isEmpty()) {
            return; // 没有工具 = 与接入前的历史行为完全一致(乘区恒 1.0)
        }

        // ② 定义(24 行,走 ItemDefinition 的请求级缓存)+ 建筑分类(A3 之外,
        //    building_category 作用域的效果要 category)。两者都是定义数据、行数固定,
        //    一次取全比逐实例查便宜得多
        $definitions = ItemDefinition::all();
        $buildings = DB::table('building_definition')
            ->whereIn('building_id', $context->buildingIds ?: [''])
            ->get(['building_id', 'category'])
            ->keyBy('building_id');

        $grouped = [];
        foreach ($equipped as $row) {
            $def = $definitions[$row->item_id] ?? null;
            if ($def === null) {
                continue; // 定义缺失:当作没有这件工具(Fail Closed,不猜一个默认加成)
            }

            $grouped[(int) $row->equipped_instance_id][] = [
                'category' => $def['category'],
                'specs'    => $def['specs'],
            ];
        }

        foreach ($units as $u) {
            $instanceId = (int) ($u['instanceId'] ?? 0);
            if (! isset($grouped[$instanceId])) {
                continue;
            }

            $def = $buildings[$u['buildingId']] ?? null;
            $this->byInstance[$instanceId] = ItemBonus::forBuilding($grouped[$instanceId], [
                'category'    => $def->category ?? null,
                'instance_id' => $instanceId,
                // 资源作用域的效果(「木材产量 +8%」)按「这栋楼产不产这个资源」判定
                'outputs'     => $u['grossOut'] ?? [],
            ]);
        }
    }

    public function multiplierFor(array $unit): float
    {
        return $this->byInstance[(int) ($unit['instanceId'] ?? 0)] ?? 1.0;
    }
}
