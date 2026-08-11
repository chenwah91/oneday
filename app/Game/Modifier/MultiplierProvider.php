<?php

namespace App\Game\Modifier;

// M3-D0.1:Provider 抽象基类 —— 只提供两个默认实现,省掉每个子类的样板。
//
//   prepare()   默认空实现:不需要取数的 Provider(如四个占位槽)直接继承;
//   flatSpecs() 默认空数组:不产出 flat 效果的 Provider 直接继承。
//
// 子类必须实现 slot() 与 multiplierFor()。
abstract class MultiplierProvider implements ProviderInterface
{
    public function prepare(ModifierContext $context, array $units): void
    {
        // 默认不取数
    }

    public function flatSpecs(): array
    {
        return [];
    }

    // M3-D4 追加:**带时间区间**的 flat 投稿。
    //
    // flatSpecs() 是「整段窗口都成立」的投稿(NPC 工资就是这种:一段结算里人数不变);
    // 事件不一样 —— 一条持续 15 分钟的幸福冲击,可能只覆盖 30 分钟结算段里的前半截。
    // 所以总线在取 flat 时会把区间传下来($fromOffset / $toOffset 是「相对结算窗口起点的分钟偏移」,
    // 与 applyLocked 里 foodZeroOffset 等同一套口径),由 Provider 自己按区间求交后返回。
    //
    // 默认空数组:不产出时间相关 flat 的 Provider 什么都不用做,行为与 D0 落地时完全一致。
    public function timedFlatSpecs(float $fromOffset, float $toOffset): array
    {
        return [];
    }
}
