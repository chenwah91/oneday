<?php

namespace App\Game\Modifier;

use InvalidArgumentException;

// M3-D0.1:一条 modifier 的规格。三要素 = target / scope / op(+ 值与作用对象键)。
//
//   target  作用到什么(ModifierTarget 的三类名单之一,allowlist 校验,未登记一律拒绝);
//   scope   作用范围:city(全城)/ building_instance(某栋)/ building_category(某类)/ resource(某资源);
//   op      运算方式:pct(百分比,0.10 = +10%)/ flat(绝对值加减)。
//
// 为什么要有这个值对象:M3 的 §6.3 特性、§7 工具 effect_code、§9 事件效果三张表加起来有 80+ 条效果,
// 全是自然语言。逐条结构化成 ModifierSpec 之后,「谁作用于什么」才有唯一表达,
// 也才能在测试里逐条对账(A2 / B3 / D1 三条数值缺口的落点都是它)。
//
// W1-A 只落地这个契约本身:七个乘区 Provider 目前都直接返回乘数,不产出 Spec;
// flat 通道虽然已接进内核,但没有任何 Provider 投稿 → 总和恒 0.0。
final class ModifierSpec
{
    // ---- scope:作用范围 ----
    public const SCOPE_CITY = 'city';                            // 全城(scopeKey 为 null)
    public const SCOPE_BUILDING_INSTANCE = 'building_instance';  // 单栋实例(scopeKey = city_building_instances.id)
    public const SCOPE_BUILDING_CATEGORY = 'building_category';  // 一类建筑(scopeKey = building_definition.category)
    public const SCOPE_RESOURCE = 'resource';                    // 单一资源(scopeKey = ResourceCode)

    public const SCOPES = [
        self::SCOPE_CITY,
        self::SCOPE_BUILDING_INSTANCE,
        self::SCOPE_BUILDING_CATEGORY,
        self::SCOPE_RESOURCE,
    ];

    // ---- op:运算方式 ----
    public const OP_PCT = 'pct';    // 百分比:value = 0.10 表示 +10%
    public const OP_FLAT = 'flat';  // 绝对值:value = -6 表示直接 -6

    public const OPS = [self::OP_PCT, self::OP_FLAT];

    public function __construct(
        public readonly string $target,
        public readonly string $scope,
        public readonly string $op,
        public readonly float $value,
        // scope 为 city 时必须为 null;其余 scope 必须给出作用对象的键
        public readonly ?string $scopeKey = null,
    ) {
        if (! in_array($target, ModifierTarget::all(), true)) {
            throw new InvalidArgumentException("未登记的 modifier target:{$target}");
        }
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException("未登记的 modifier scope:{$scope}");
        }
        if (! in_array($op, self::OPS, true)) {
            throw new InvalidArgumentException("未登记的 modifier op:{$op}");
        }
        if ($scope === self::SCOPE_CITY && $scopeKey !== null) {
            throw new InvalidArgumentException('scope=city 不接受 scopeKey');
        }
        if ($scope !== self::SCOPE_CITY && ($scopeKey === null || $scopeKey === '')) {
            throw new InvalidArgumentException("scope={$scope} 必须给出 scopeKey");
        }
    }

    // 便捷构造:全城 flat(事件对幸福 / 治安的冲击最常见的形态)
    public static function flat(string $target, float $value, string $scope = self::SCOPE_CITY, ?string $scopeKey = null): self
    {
        return new self($target, $scope, self::OP_FLAT, $value, $scopeKey);
    }

    // 便捷构造:百分比
    public static function pct(string $target, float $value, string $scope = self::SCOPE_CITY, ?string $scopeKey = null): self
    {
        return new self($target, $scope, self::OP_PCT, $value, $scopeKey);
    }

    // 是否作用于给定对象。scope=city 的 spec 对任何对象都成立;
    // 其余 scope 要求 scope 与 scopeKey 都对得上
    public function appliesTo(string $scope, ?string $scopeKey = null): bool
    {
        if ($this->scope === self::SCOPE_CITY) {
            return true;
        }

        return $this->scope === $scope && $this->scopeKey === $scopeKey;
    }
}
