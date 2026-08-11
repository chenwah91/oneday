<?php

namespace App\Game\Market;

use App\Support\GameSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// 市场定价内核(v3.2 §8.1 + backlog 5.2 + 9.C 区批准口径)。
//
// ══ 设计的一句话 ══════════════════════════════════════════════════════════════
// 「不落窗、不跑 cron:价格是 epoch 的**纯函数**,任何时刻按 epoch 重算都得到同一个数。」
//
// ── 为什么不跑 cron(与 backlog 5.2 的差异,已获用户 2026-08-11 拍板)──────────
// backlog 原方案是「cron 每分钟补算 window,落 market_price_windows 表」。共享主机上这条路有三个坑:
// cron 会漏跑 / 会延迟 / 会并发,而价格一旦漏了一窗,后面的窗就再也算不回来(§8.1 是递归式)。
// 懒求值方案把价格变成 f(epoch),漏跑这件事根本不存在 —— 没人访问的窗口不需要存在。
// 代价是放弃 §8.1 的 AR(1) 平滑项(见下),收益是零运维、零补算、零并发。
//
// ── 与 §8.1 原文的逐条对照 ────────────────────────────────────────────────────
//   §8.1: imbalance   = (demand − supply) / max(1, demand + supply)          → 原样保留
//   §8.1: targetPrice = basePrice × (1 + elasticity × imbalance) × eventMul  → 原样保留
//   §8.1: noise       = random(−volatility, +volatility)                     → 原样保留,但改用
//                       HMAC(secret, resource|epoch) 派生的**确定性**伪随机(见 noise())
//   §8.1: nextPrice   = clamp(currentPrice × 0.80 + targetPrice × (0.20 + noise), min, max)
//                     → **唯一的实现偏离**:改为 clamp(targetPrice × (1 + noise), …)。
//                       `currentPrice × 0.80` 是对「上一窗价格」的递归引用,而懒求值下不存在
//                       「上一窗价格」这个已落库的量,硬要递归就得从开服第一窗算起(不可行)。
//                       平滑本身没有丢:§8.1 引入 AR(1) 是为了让价格不要每窗跳,而这里的
//                       targetPrice 已经建立在「最近 N 窗供需的移动平均」之上 —— 平滑落在了
//                       供需侧而不是价格侧,§13「移动平均必须存在」这一条照样满足。
//   §8.1: buyPrice / sellPrice = nextPrice × (1 ± feeRate)                   → 在 TradeService,
//                       并按 9.C4 再叠一层滑点(§8.1 缺、§13 要求)
//
// ── 服务器权威(CLAUDE §30 / §66 / §88)────────────────────────────────────────
// 扰动来自 HMAC(服务器密钥, 资源|epoch)。玩家就算把这份代码逐行读完,没有密钥也算不出下一窗的价:
// 密钥只在 env(config/market.php),不进库、不进 Git、不下发前端。
// 反过来,服务器任何进程、任何时刻重算 epoch E 的价格都得到同一个数,不需要任何共享状态。
//
// ── 「同一 epoch 内价格恒定」为什么成立(反刷的关键)──────────────────────────
// 移动平均只取 **[E−N, E−1]** 这 N 个**已经结束**的窗口,**不含当前窗 E**。
// 当前窗还在成交,若把它算进去,同一个 epoch 内价格会随别人下单而变 —— 那既破坏确定性,
// 也给了「先小单探价、再大单套利」的口子。取闭区间到 E−1 之后,epoch E 一开始价格就已经定死。
final class PriceEngine
{
    // 事件乘数(§8.1 的 eventMultiplier)。
    //
    // ══ W5 裁决:这一位**恒为 1.0,不接城市事件**(结论写死在这里,别再"接一次")══════
    // §9.2 的两条价格事件(EVT_OIL_SHOCK「石油和燃料价格+40%」、EVT_SPECULATION「随机战略资源
    // 价格+25%~50%」)在本项目里是 **city_events 的城市级实例**,而本类算的价格是**全服共享**的:
    //   ① 让一座城市的事件去改 targetPrice,等于让这名玩家的随机事件改**所有人**的行情;
    //   ② 价格是 f(资源, epoch) 的纯函数、无共享状态,这正是「同一 epoch 内价格恒定」
    //      与「任何进程重算都得到同一个数」两条反刷前提的来源。塞一个按城市变化的乘数进来,
    //      这两条当场失效(同一 epoch 里 A 城看到的价与 B 城不同,且价格开始依赖数据库状态)。
    // 所以价格冲击落在**城市侧的成交价**上,消费点是 TradeService(ModifierTarget::MARKET_PRICE_PCT),
    // 且只作用于买入侧 —— 完整理由见那里的注释。本常量保留为 1.0 的显式占位:
    // 将来若真出现**全服级**事件(所有城市共享的世界事件),它才是正确的接入点。
    public const EVENT_MULTIPLIER_DEFAULT = 1.0;

    // 派生确定性伪随机时取的十六进制位数(13 位 = 52 bit,恰好在 float 能精确表示的整数范围内,
    // 也远低于 64 位平台 int 上限,不会溢出成负数)
    private const NOISE_HEX_DIGITS = 13;

    // 2^52 − 1:上面 13 位十六进制的最大值,用作归一化分母
    private const NOISE_MAX = 4503599627370495;

    // 已就密钥缺失告过警(每进程一次,避免刷屏)
    private static bool $warned = false;

    // ---------- EPOCH(价格窗口)----------

    // 窗口秒数(后台可调,9.C2 批准 60 秒)
    public static function windowSeconds(): int
    {
        return max(1, (int) GameSetting::get(GameSetting::MARKET_WINDOW_SECONDS));
    }

    // 某一时刻所属的 epoch。原点固定取 Unix 纪元 0:
    // 换成任何「开服时间」都要多存一个配置,而配置一改历史 window_index 的含义就变了;
    // 用 0 做原点,window_index 永远可以反算回墙钟时间,跨环境也对得上
    public static function epochAt(CarbonInterface $at): int
    {
        return intdiv($at->getTimestamp(), self::windowSeconds());
    }

    // 当前 epoch
    public static function currentEpoch(): int
    {
        return self::epochAt(now());
    }

    // 某个 epoch 的起始时刻
    public static function epochStartsAt(int $epoch): Carbon
    {
        return Carbon::createFromTimestamp($epoch * self::windowSeconds());
    }

    // 某个 epoch 的结束时刻(= 下一个 epoch 的起点)
    public static function epochEndsAt(int $epoch): Carbon
    {
        return self::epochStartsAt($epoch + 1);
    }

    // ---------- 定价 ----------

    // 单个资源在某 epoch 的服务器基准价(未含手续费与滑点)。
    // $epoch 省略时取当前 epoch。资源未登记时返回 null(Fail Closed:不猜价)
    public static function price(string $resourceId, ?int $epoch = null): ?float
    {
        $def = MarketDefinition::find($resourceId);
        if ($def === null) {
            return null;
        }

        return self::priceFor($def, $epoch ?? self::currentEpoch());
    }

    // 已拿到定义时的定价(交易路径用这个,省一次 find)
    public static function priceFor(array $def, int $epoch): float
    {
        // 不可交易资源没有市场价:knowledge 的 base_price 本来就是 0,money 恒为 1(它是计价单位)
        if ($def['trade_mode'] === MarketDefinition::TRADE_MODE_NON_TRADEABLE) {
            return round($def['base_price'], 4);
        }

        [$demand, $supply] = self::movingAverage($def, $epoch);

        // §8.1 原式:imbalance = (demand − supply) / max(1, demand + supply)
        $imbalance = ($demand - $supply) / max(1.0, $demand + $supply);

        // §8.1 原式:targetPrice = basePrice × (1 + elasticity × imbalance) × eventMultiplier
        $target = $def['base_price'] * (1.0 + $def['elasticity'] * $imbalance) * self::EVENT_MULTIPLIER_DEFAULT;

        // 确定性扰动:noise ∈ [−volatility, +volatility]
        $price = $target * (1.0 + self::noise($def['resource_id'], $epoch, $def['volatility']));

        [$low, $high] = MarketDefinition::priceBounds($def);

        // 落到 4 位小数后再夹:与 DECIMAL(14,4) 的存储精度一致,
        // 保证「算出来的价」与「存进流水的价」是同一个数,也保证跨次重算的浮点尾差不会外泄
        return round(max($low, min($high, $price)), 4);
    }

    // 全部资源在某 epoch 的价目表(GET /api/market/prices 用)。
    // 移动平均是一次批量查询(见 volumes),26 个资源只查一次库
    public static function priceTable(?int $epoch = null): array
    {
        $epoch ??= self::currentEpoch();
        $table = [];

        foreach (MarketDefinition::all() as $resourceId => $def) {
            $table[$resourceId] = self::priceFor($def, $epoch);
        }

        return $table;
    }

    // ---------- 确定性伪随机 ----------

    // noise ∈ [−volatility, +volatility],由 HMAC-SHA256(密钥, "资源|epoch") 派生。
    //
    // 为什么不用 random_int():§30 要求服务器权威,而这里更强的要求是**可重算**——
    // 同一 epoch 被算 100 次必须得到同一个价,否则「买入时报价 A、扣款时价 B」就是可套利的裂缝。
    // 真随机做不到可重算,除非把每窗价格落库(= 回到 cron 方案)。
    //
    // 密钥缺失时返回 0.0(价格退化为「基础价 × 供需漂移」,完全没有随机波动)。
    // 这是刻意的 Fail Safe 方向:与其用一个公开可推导的兜底密钥(= 玩家能预测全部未来价 = 无风险套利),
    // 不如干脆不波动 —— 不波动的市场很无聊,但一分钱也刷不出来。
    public static function noise(string $resourceId, int $epoch, float $volatility): float
    {
        if ($volatility <= 0.0) {
            return 0.0;
        }

        $secret = self::secret();
        if ($secret === null) {
            return 0.0;
        }

        $mac = hash_hmac('sha256', $resourceId . '|' . $epoch, $secret);
        $unit = hexdec(substr($mac, 0, self::NOISE_HEX_DIGITS)) / self::NOISE_MAX; // [0, 1]

        return ($unit * 2.0 - 1.0) * $volatility;
    }

    // 取定价密钥。与 AuditChain::secret 同一套降级策略:
    //   1) 有 MARKET_PRICE_SECRET → 用它(生产唯一正确姿势);
    //   2) 没有但有 APP_KEY → 从 APP_KEY 派生并 warning(本地开发方便,生产不该走到这);
    //   3) 两个都没有 → null,noise 恒 0(见上面的 Fail Safe 说明)。
    public static function secret(): ?string
    {
        $secret = config('market.price_secret');
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        $appKey = config('app.key');
        if (is_string($appKey) && $appKey !== '') {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('MARKET_PRICE_SECRET 未配置,市场定价暂用 APP_KEY 派生的密钥;生产环境必须显式配置(CLAUDE §75)');
            }

            return hash_hmac('sha256', 'apg-market-price-v1', $appKey);
        }

        if (! self::$warned) {
            self::$warned = true;
            Log::warning('MARKET_PRICE_SECRET 与 APP_KEY 均缺失,市场价格本次不加随机波动(noise 恒 0)');
        }

        return null;
    }

    // 测试用:重置「已告警」标记,让降级告警可被重复观察
    public static function resetWarningState(): void
    {
        self::$warned = false;
    }

    // 清空供需聚合的请求级缓存。
    // 正常请求里用不到(一次请求内成交流水不会被外力改写);测试直接往流水表插历史成交后需要调它
    public static function flushVolumes(): void
    {
        foreach (array_keys(Context::all()) as $key) {
            if (str_starts_with((string) $key, 'market_volumes:')) {
                Context::forget((string) $key);
            }
        }
    }

    // ---------- 供需移动平均(9.C3)----------

    // 返回 [demand, supply]:最近 N 个**已结束**窗口的全服**每窗平均**成交量,各加一份系统底噪。
    //
    // 9.C3 批准口径:
    //   demand = 全服该窗买入量 + 系统底噪(= base_liquidity × 5%)
    //   supply = 全服卖出量     + 同额底噪
    // 底噪的作用:新服 / 空服时买卖量都是 0,(0−0)/max(1,0) 虽然不会除零,但任何一笔小单都会
    // 把 imbalance 直接推到 ±1(价格瞬间打到夹取边界)。加了底噪之后,单人要撼动价格必须
    // 拿出与流动性同量级的成交量,而那又被成交量上限挡着 —— 两条规则在这里合围。
    public static function movingAverage(array $def, int $epoch): array
    {
        $windows = max(1, (int) GameSetting::get(GameSetting::MARKET_MA_WINDOWS));
        $volumes = self::volumes($epoch, $windows);
        $row = $volumes[$def['resource_id']] ?? ['buy' => 0.0, 'sell' => 0.0];

        $floor = MarketDefinition::effectiveLiquidity($def) * (float) GameSetting::get(GameSetting::MARKET_NOISE_FLOOR_PCT);

        return [
            $row['buy'] / $windows + $floor,
            $row['sell'] / $windows + $floor,
        ];
    }

    // 全服成交量批量聚合:一次查回 [resource_id => ['buy' => 量, 'sell' => 量]]。
    //
    // 窗口区间 **[epoch − N, epoch − 1]**,刻意不含当前窗(理由见类顶部「同一 epoch 内价格恒定」)。
    // MySQL 5.7 兼容:纯 GROUP BY,不用 OVER() / CTE(线上 5.7 会直接语法错误)。
    // 请求级缓存:一次请求里价目表 + 交易可能反复问同一个 epoch,只查一次库
    private static function volumes(int $epoch, int $windows): array
    {
        $cacheKey = 'market_volumes:' . $epoch . ':' . $windows;
        if (Context::has($cacheKey)) {
            return Context::get($cacheKey);
        }

        $rows = DB::table('city_market_orders')
            ->where('window_index', '>=', $epoch - $windows)
            ->where('window_index', '<=', $epoch - 1)
            ->groupBy('resource_id', 'side')
            ->get([
                'resource_id',
                'side',
                DB::raw('SUM(quantity) as qty'),
            ]);

        $volumes = [];
        foreach ($rows as $row) {
            $resourceId = (string) $row->resource_id;
            $volumes[$resourceId] ??= ['buy' => 0.0, 'sell' => 0.0];
            $side = (string) $row->side === TradeService::SIDE_BUY ? 'buy' : 'sell';
            $volumes[$resourceId][$side] = (float) $row->qty;
        }

        Context::add($cacheKey, $volumes);

        return $volumes;
    }
}
