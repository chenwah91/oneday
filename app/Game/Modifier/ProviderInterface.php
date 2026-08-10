<?php

namespace App\Game\Modifier;

// M3-D0.1:Provider 契约(注册制总线的唯一接口)。
//
// 一个 Provider = 一个乘区槽的全部逻辑:它自己知道去哪张表取数、怎么算出这一格的乘数。
// D1~D5 各系统只新增自己的 Provider 类,**不改 SimulationService**
// (backlog §10.2 纪律:整个 M3 只有 W1-A 可以改结算内核)。
//
// 三段式生命周期,每次 applyLocked 走一轮:
//   slot()          声明认领哪一格(七乘区之一,ModifierTarget::SLOT_*);
//   prepare()       准备段一次性取数 —— **锁内、分段循环之外**,查库只能在这里;
//   multiplierFor() 逐建筑实例返回该格乘数 —— **纯函数,循环内零查库**。
//
// 另外 flatSpecs() 供 D0.2 的 flat 通道(事件 / NPC 特性对幸福、治安的直接冲击)投稿,
// 不产出 flat 效果的 Provider 返回空数组即可(MultiplierProvider 已给默认实现)。
interface ProviderInterface
{
    // 认领的乘区槽,必须是 ModifierTarget::SLOTS 之一;一个槽只能有一个 Provider
    public function slot(): string;

    // 准备段:一次性把本 Provider 需要的数据取齐。
    // $units 是本次结算的建筑实例中间结构(此时七乘区尚未填充,grossIn / grossOut / maint* 已就位),
    // 需要跨实例聚合的 Provider(如物流按全城运输需求)在这里做聚合。
    public function prepare(ModifierContext $context, array $units): void;

    // 逐实例的乘数。$unit 就是 prepare() 里那个中间结构的一行;
    // 实现里不许查库、不许读全局状态,只能用 prepare() 阶段缓存下来的值
    public function multiplierFor(array $unit): float;

    // 本 Provider 对 flat 通道的投稿(ModifierSpec[]),没有就返回空数组。
    // 与 multiplierFor() 同样的纪律:prepare() 之后才可调用,内部不许查库
    public function flatSpecs(): array;
}
