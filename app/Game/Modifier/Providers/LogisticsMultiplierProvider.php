<?php

namespace App\Game\Modifier\Providers;

use App\Game\Modifier\ModifierContext;
use App\Game\Modifier\ModifierTarget;
use App\Game\Modifier\MultiplierProvider;
use App\Game\Simulation\SimConstants;
use App\Game\Simulation\SimulationService;
use App\Support\GameSetting;

// logistics 乘区(v3.2 §10.7,M2-C4 迁入)。
//
// transportDemand = Σ(各生产建筑每分钟输入 + 输出) × distanceFactor
//
// 需求取「该级定义的基础输入/输出速率」而不是乘区折算后的速率,两个理由:
//   ① logistics 本身就是七乘区之一,拿折算后的速率当分母会自己吃自己(收敛都不保证);
//   ② §10.7 的字面口径就是建筑的每分钟输入 / 输出,即名义吞吐。
// 容量类产出(人口/仓储/治理/运输/医疗/国防)在内核建中间结构时已被提走,不在 grossIn / grossOut 里,
// 所以住宅、仓库、行政所、道路本身天然不占运力 —— 这正是「生产建筑」这个限定词的落地方式。
//
// 时代闸门(M2-C4 的补充假设,依据见 SimConstants::LOGISTICS_MIN_ERA_ORDER):
// 时代 I 没有任何建筑能产出 transport_capacity(全表最早的运输建筑是时代 II 的 T02),
// 若时代 I 照样计需求,所有时代 I 城市开局即重度拥堵、且无任何手段自救。
//
// 负载 → 物流率的两条曲线仍留在 SimulationService::transportLoad / logisticsFactor:
// 它们是纯函数、且是 §10.7 分档口径的唯一落点(测试直接打这两个静态方法),
// 本 Provider 只负责取数与填格,绝不抄第二份公式。
//
// 全城一个值:M2 distanceFactor 恒 1.0 且没有分区路网,
// M3 大地图再改成按建筑到路网的距离逐栋算(那时把 multiplierFor 改成读 $unit 即可)。
final class LogisticsMultiplierProvider extends MultiplierProvider
{
    private float $demandPerMin = 0.0;
    private float $load = 0.0;
    private float $factor = SimConstants::LOGISTICS_FACTOR_MAX;
    private bool $congestion = false;

    public function slot(): string
    {
        return ModifierTarget::SLOT_LOGISTICS;
    }

    public function prepare(ModifierContext $context, array $units): void
    {
        $demand = 0.0;
        foreach ($units as $u) {
            $demand += array_sum($u['grossIn']) + array_sum($u['grossOut']);
        }
        // distanceFactor:M2 恒 1.0(§10.7「M2:distanceFactor = 1.0」),地图距离惩罚留 M3 大地图
        $demand *= SimConstants::LOGISTICS_DISTANCE_FACTOR;

        // era_order 由时代升级(M2-B6)维护;列缺失 / 为空由内核统一按时代 I 兜底后传进来。
        // 起算时代改成后台设定(默认 2,与迁移前的 SimConstants::LOGISTICS_MIN_ERA_ORDER 同值)
        if ($context->eraOrder < (int) GameSetting::get(GameSetting::LOGISTICS_MIN_ERA_ORDER)) {
            $demand = 0.0;
        }

        $this->demandPerMin = $demand;
        $this->load = SimulationService::transportLoad($demand, $context->capacity(ModifierContext::CAP_TRANSPORT));
        // 物流总开关(运营救急):关掉之后乘区恒 1.0,但需求 / 负载 / 拥堵警报的读数照常算 ——
        // 止血的同时还看得见「到底堵成什么样」,不至于关了开关就两眼一抹黑
        $this->factor = GameSetting::get(GameSetting::LOGISTICS_GATE_ENABLED) === true
            ? SimulationService::logisticsFactor($this->load)
            : SimConstants::LOGISTICS_FACTOR_MAX;
        // 拥堵警报(§10.7「> 1.25 → 产生拥堵警报」/ §15 回归表「出现拥堵警报」)
        $this->congestion = $this->load > (float) GameSetting::get(GameSetting::TRANSPORT_LOAD_OVER);
    }

    public function multiplierFor(array $unit): float
    {
        return $this->factor;
    }

    // ---- 读数:内核取回去放进返回值给前端显示,不参与结算 ----

    public function demandPerMin(): float
    {
        return $this->demandPerMin;
    }

    public function load(): float
    {
        return $this->load;
    }

    public function factor(): float
    {
        return $this->factor;
    }

    public function congestion(): bool
    {
        return $this->congestion;
    }
}
