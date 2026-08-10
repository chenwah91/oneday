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
}
