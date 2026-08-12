<?php

namespace App\Game\Modifier\Providers;

use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;
use App\Game\NPC\NpcBonus;
use App\Game\NPC\NpcCode;
use App\Game\NPC\NpcTraitScale;
use Illuminate\Support\Facades\DB;

// npc 乘区(M3-D1 W2-A 接线,v3.2 §6.4)。
//
// 三段式生命周期(ProviderInterface 的纪律):
//   prepare()       锁内、分段循环之外,**两条查询**取齐本城 NPC 与建筑分类,算出「实例 → 乘数」表;
//   multiplierFor() 纯函数查表,循环内零查库;
//   flatSpecs()     把全城 NPC 的工资 / 口粮投稿到总线的两条通用支出 target。
//
// 帽的分工(承接 M2「封顶只落在一处」的纪律):
//   §6.4 的单 NPC 帽 1.60 与 NPC 侧总帽 1.50 是 **NPC 系统内部的合成规则**,在 NpcBonus 里夹完;
//   §13 的 2.75 总帽是**全部乘区**的帽,仍然只由 SimulationService::multiplierProduct() 夹一次。
//   本 Provider 不碰 §13 的帽,也不在这里做第二次夹取。
//
// 工资与口粮为什么对 idle 的 NPC 也收:§6.3 的 wage_per_min 是「雇着就要发」的钱,
// 不是「上班才发」。这也是 POST /api/city/npc/dismiss(辞退)存在的唯一意义 ——
// 只在派驻时收钱的话,玩家把人闲置就等于零成本囤 NPC,士气与离职体系(§16.5)也就没有压力来源。
final class NpcMultiplierProvider extends MultiplierProvider
{
    // building_instance_id => 该栋建筑的 npc 乘区值(已夹 §6.4 两层帽)
    private array $byInstance = [];

    // 全城 NPC 的工资 / 口粮速率(每分钟),投稿给总线的通用支出通道
    private float $wageMoneyPerMin = 0.0;

    private float $foodPerMin = 0.0;

    public function slot(): string
    {
        return ModifierTarget::SLOT_NPC;
    }

    public function prepare(ModifierContext $context, array $units): void
    {
        $this->byInstance = [];
        $this->wageMoneyPerMin = 0.0;
        $this->foodPerMin = 0.0;

        // ① 本城仍在编的 NPC(idle + assigned)+ 各自的定义行。
        //    left 的行留在表里只为可追溯,一律不参与结算,也不再收工资
        $npcs = DB::table('city_npcs as cn')
            ->join('npc_definition as nd', 'cn.npc_id', '=', 'nd.npc_id')
            ->where('cn.city_id', $context->cityId)
            ->whereIn('cn.status', NpcCode::ACTIVE_STATUSES)
            ->get([
                'cn.id', 'cn.status', 'cn.skill_level', 'cn.assigned_instance_id',
                // trait_multiplier:NPC 特性强度倍率(W11-B),下面解析 specs 时逐条乘上去
                'nd.npc_id', 'nd.primary_skill_id', 'nd.wage_per_min', 'nd.food_per_min',
                'nd.trait_json', 'nd.trait_multiplier',
            ]);

        if ($npcs->isEmpty()) {
            return; // 没有 NPC = 与接入前的历史行为完全一致(乘区恒 1.0、零支出)
        }

        foreach ($npcs as $n) {
            $this->wageMoneyPerMin += (float) $n->wage_per_min;
            $this->foodPerMin += (float) $n->food_per_min;
        }

        // 已派驻的按建筑实例归组;一个都没派驻就不必再查曲线与建筑分类
        $assigned = $npcs->filter(
            fn ($n) => $n->status === NpcCode::STATUS_ASSIGNED && $n->assigned_instance_id !== null
        );
        if ($assigned->isEmpty()) {
            return;
        }

        // ② §6.2 等级曲线 + 建筑分类(A3 岗位匹配要 category / series_key)。
        //    两张都是定义表、行数固定(10 行 / 94 行),一次取全比逐实例查便宜得多
        $curve = DB::table('npc_skill_level_curve')->pluck('primary_bonus', 'level')
            ->map(fn ($b) => (float) $b)->all();
        $buildings = DB::table('building_definition')
            ->whereIn('building_id', $context->buildingIds ?: [''])
            ->get(['building_id', 'category', 'series_key'])
            ->keyBy('building_id');

        $grouped = [];
        foreach ($assigned as $n) {
            $grouped[(int) $n->assigned_instance_id][] = [
                'primary_skill_id' => $n->primary_skill_id,
                'skill_level'      => (int) $n->skill_level,
                // 特性 specs 已乘过该 NPC 的强度倍率(W11-B);§6.4 的两层帽仍由 NpcBonus 夹,
                // 倍率作用在**投稿值**上而不是帽上 —— 调强一位 NPC 不会顶穿单人 1.60 / NPC 侧 1.50
                'specs'            => NpcTraitScale::specs($n->trait_json, $n->trait_multiplier),
            ];
        }

        foreach ($units as $u) {
            $instanceId = (int) ($u['instanceId'] ?? 0);
            if (! isset($grouped[$instanceId])) {
                continue;
            }

            $def = $buildings[$u['buildingId']] ?? null;
            $this->byInstance[$instanceId] = NpcBonus::forBuilding($grouped[$instanceId], [
                'category'    => $def->category ?? null,
                'series_key'  => $def->series_key ?? null,
                'instance_id' => $instanceId,
                // 资源作用域的特性(「木材产量 +8%」)按「这栋楼产不产这个资源」判定
                'outputs'     => $u['grossOut'] ?? [],
            ], $curve);
        }
    }

    public function multiplierFor(array $unit): float
    {
        return $this->byInstance[(int) ($unit['instanceId'] ?? 0)] ?? 1.0;
    }

    // 工资 / 口粮走总线的通用支出通道(ModifierTarget::EXPENSE_*),
    // 由 SimulationService 那唯一一个消费点消费 —— 本 Provider 不自己扣钱、不写库
    public function flatSpecs(): array
    {
        $specs = [];

        if ($this->wageMoneyPerMin > 0) {
            $specs[] = ModifierSpec::flat(ModifierTarget::EXPENSE_MONEY_PER_MIN, $this->wageMoneyPerMin);
        }
        if ($this->foodPerMin > 0) {
            $specs[] = ModifierSpec::flat(ModifierTarget::EXPENSE_FOOD_PER_MIN, $this->foodPerMin);
        }

        return $specs;
    }

    // 读数(供测试与调试;结算侧已经通过乘区与支出通道生效,不要在别处二次使用)
    public function wageMoneyPerMin(): float
    {
        return $this->wageMoneyPerMin;
    }

    public function foodPerMin(): float
    {
        return $this->foodPerMin;
    }
}
