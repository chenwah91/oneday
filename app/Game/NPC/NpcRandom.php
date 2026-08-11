<?php

namespace App\Game\NPC;

use Closure;

// 服务器权威随机(CLAUDE §30 / §66,backlog §11.3)。
//
// 生产路径**只用 random_int()**(CSPRNG):§11.3 明文禁止 mt_rand,更禁止任何客户端参与。
// 招募稀有度、自然增长掷点、离职掷点全部经过这里,不许在业务代码里直接调 random_int ——
// 集中在一处才有唯一的审计面,也才有下面这个测试接缝。
//
// 测试接缝(照 Laravel 的 Str::createRandomStringsUsing 同款做法):
//   NpcRandom::createUsing(fn ($min, $max) => …)  换成确定性序列,断言「种子固定 → 结果固定」;
//   NpcRandom::createNormally()                    恢复 CSPRNG。
// 只有测试会调这两个方法;业务代码一行都不碰它们,所以生产环境的随机源不可能被换掉。
final class NpcRandom
{
    private static ?Closure $factory = null;

    // 闭区间 [$min, $max] 的整数
    public static function int(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }

        if (self::$factory !== null) {
            return (int) (self::$factory)($min, $max);
        }

        return random_int($min, $max);
    }

    // 按权重挑一个键。$weights = [键 => 权重],权重全为 0 / 数组为空时返回 null。
    // 权重之和不必等于 100:掷点用 [1, 总权重] 的整数,按累加区间落袋
    public static function weightedKey(array $weights): int|string|null
    {
        // 浮点权重(后台可以填 0.5)统一放大成整数再掷点:CSPRNG 只能给整数,
        // 放大 10000 倍后 0.0001 的权重差异仍然可分辨,足够运营调参
        $scaled = [];
        $total = 0;
        foreach ($weights as $key => $weight) {
            $w = (int) round(max(0.0, (float) $weight) * 10000);
            if ($w > 0) {
                $scaled[$key] = $w;
                $total += $w;
            }
        }

        if ($total <= 0) {
            return null;
        }

        $roll = self::int(1, $total);
        foreach ($scaled as $key => $w) {
            $roll -= $w;
            if ($roll <= 0) {
                return $key;
            }
        }

        // 理论上到不了这里(累加必然覆盖 [1, total]);兜底返回最后一个键而不是 null,
        // 免得一个浮点/取整的边角把招募变成 500
        return array_key_last($scaled);
    }

    // 概率掷点:$chance ∈ [0,1]。精度到万分之一(与 weightedKey 同一放大倍数)
    public static function chance(float $chance): bool
    {
        if ($chance <= 0) {
            return false;
        }
        if ($chance >= 1) {
            return true;
        }

        return self::int(1, 10000) <= (int) round($chance * 10000);
    }

    // ---- 测试接缝(业务代码不得调用)----

    public static function createUsing(Closure $factory): void
    {
        self::$factory = $factory;
    }

    public static function createNormally(): void
    {
        self::$factory = null;
    }
}
