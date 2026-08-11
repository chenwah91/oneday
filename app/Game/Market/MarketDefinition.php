<?php

namespace App\Game\Market;

use App\Support\GameSetting;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

// 市场定义层读取入口(market_definition 表 = v3.2 §8 的 26 行)。
//
// 三条纪律(与 GameSetting 同源):
//   1. 全项目只有这里读 market_definition,业务代码不许再自己查表 —— 否则「有效流动性 / 有效费率 /
//      价格夹取区间」这类「定义值 × 全局设定」的折算会散落各处,改一处漏一处;
//   2. 请求级缓存:一次请求内整表只查一次(26 行),之后走 Context 缓存
//      (用 Context 而非类内 static:跟随请求生命周期,测试里每个用例重建 Application 会自动清空);
//   3. 缺表 / 缺行一律当作「该资源不在市场上」→ 交易被拒,而不是 fallback 出一个凭空的价格。
//      定价失败必须 Fail Closed(CLAUDE §41),绝不能猜。
final class MarketDefinition
{
    // 现货:可买卖(§8 的 24 行)
    public const TRADE_MODE_SPOT = 'spot';

    // 产能合约:§8 明文「electricity uses capacity-contract / instant settlement and is not stored as
    // normal inventory」。电力不是库存资源,现货买卖对它没有意义(买 100 电存哪儿?),
    // M3-D3 只做现货 → 它有价格、进价目表,但买卖一律拒绝。产能合约本身留给电力系统(W4-A)
    public const TRADE_MODE_CAPACITY_CONTRACT = 'capacity_contract';

    // 不可交易:§8 明文的 knowledge / money。两者仍然入表(定义层要与 §8 逐行一致,
    // 而且后台得看得见「它们为什么不能交易」),只是标记为不可交易
    public const TRADE_MODE_NON_TRADEABLE = 'non_tradeable';

    private const CACHE_KEY = 'market_definitions';

    // 整表(resource_id => 定义数组)。表不存在 / 未 seed 时返回空数组
    public static function all(): array
    {
        if (Context::has(self::CACHE_KEY)) {
            return Context::get(self::CACHE_KEY);
        }

        $rows = [];
        foreach (DB::table('market_definition')->orderBy('rs_code')->get() as $row) {
            $rows[(string) $row->resource_id] = [
                'resource_id'     => (string) $row->resource_id,
                'rs_code'         => (string) $row->rs_code,
                'market_category' => (string) $row->market_category,
                'first_era'       => (string) $row->first_era,
                'trade_mode'      => (string) $row->trade_mode,
                'base_price'      => (float) $row->base_price,
                'min_price'       => (float) $row->min_price,
                'max_price'       => (float) $row->max_price,
                'volatility'      => (float) $row->volatility,
                'elasticity'      => (float) $row->elasticity,
                'fee_rate'        => (float) $row->fee_rate,
                'base_liquidity'  => (float) $row->base_liquidity,
                'note'            => $row->note === null ? null : (string) $row->note,
            ];
        }

        Context::add(self::CACHE_KEY, $rows);

        return $rows;
    }

    // 单个资源的定义;未登记返回 null(调用方一律按「不可交易」处理)
    public static function find(string $resourceId): ?array
    {
        return self::all()[$resourceId] ?? null;
    }

    // 现货可交易?未登记 / capacity_contract / non_tradeable 一律 false
    public static function isTradeable(?array $def): bool
    {
        return $def !== null && $def['trade_mode'] === self::TRADE_MODE_SPOT;
    }

    // 有效流动性 = 定义表 base_liquidity × 全局倍率。
    // 下限 1:它同时是滑点公式与成交量上限的分母,为 0 会让滑点变成除零、上限变成 0(整个市场卡死)。
    // 不可交易资源的 base_liquidity 是 0,但它们根本走不到交易路径,这里的下限只是兜底
    public static function effectiveLiquidity(array $def): float
    {
        $multiplier = (float) GameSetting::get(GameSetting::MARKET_LIQUIDITY_MULTIPLIER);

        return max(1.0, $def['base_liquidity'] * $multiplier);
    }

    // 有效手续费率 = 定义表 fee_rate × 全局倍率。
    // 夹在 [0, 0.9]:费率 ≥1 会让卖出变成「倒贴钱」,那不是手续费而是没收
    public static function effectiveFeeRate(array $def): float
    {
        $multiplier = (float) GameSetting::get(GameSetting::MARKET_FEE_RATE_MULTIPLIER);

        return max(0.0, min(0.9, $def['fee_rate'] * $multiplier));
    }

    // 价格夹取区间 = 定义表 [min_price, max_price] 与 全局 [基础价×下限倍率, 基础价×上限倍率] 的**交集**。
    //
    // 为什么取交集而不是二选一(§8 与本任务口径的冲突处理):
    //   §8 逐行给了 min_price / max_price(比例 0.45~0.55 / 2.4~3.2,逐资源不同),这是权威数值;
    //   任务要求「夹取倍率进设定」,是为了运营能一次性全局收紧。
    //   取交集 = 两边都不失效:全局倍率默认取 §8 全表最宽档(0.45 / 3.2),此时**逐行的 §8 值说了算**
    //   (完全等价于「以 §8 为准」);运营把倍率调窄时,收紧对全市场立即生效,不必逐行改 26 行定义。
    // 返回 [下限, 上限];异常数据(下限 > 上限)时退化为定义表原值,绝不返回空区间
    public static function priceBounds(array $def): array
    {
        $base = $def['base_price'];
        $low = max($def['min_price'], $base * (float) GameSetting::get(GameSetting::MARKET_PRICE_MIN_MULTIPLE));
        $high = min($def['max_price'], $base * (float) GameSetting::get(GameSetting::MARKET_PRICE_MAX_MULTIPLE));

        if ($low > $high) {
            return [$def['min_price'], $def['max_price']];
        }

        return [$low, $high];
    }

    // 单城单窗成交量上限 = 有效流动性 × 比例(§8.1「不超过该资源市场流动性的 10%」)
    public static function windowQuota(array $def): float
    {
        return self::effectiveLiquidity($def) * (float) GameSetting::get(GameSetting::MARKET_QUOTA_WINDOW_PCT);
    }

    // 单城每小时成交量上限 = 单窗上限 × 倍数(9.C7 批准 20 倍)
    public static function hourlyQuota(array $def): float
    {
        return self::windowQuota($def) * (float) GameSetting::get(GameSetting::MARKET_QUOTA_HOURLY_MULTIPLE);
    }

    // 清空请求级缓存(测试里改库后调用)
    public static function flush(): void
    {
        Context::forget(self::CACHE_KEY);
    }
}
