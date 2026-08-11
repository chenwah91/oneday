<?php

namespace App\Game\Event;

use Illuminate\Support\Facades\Log;

// 事件掷点的服务器权威随机(CLAUDE §30 / §66,backlog §11.3)。
//
// ══ 为什么不是 random_int() ═════════════════════════════════════════════════
// NPC 的掷点用 CSPRNG 就够了(招募是玩家主动点的,当场发生一次)。事件不同:它要**补算离线窗口**。
// 用真随机补算 720 个窗口会留下一条可刷的缝 —— 玩家退出、上线、看到不喜欢的事件、再退出再上线,
// 同一段离线时间会被重新掷一次点(只要上次的结算还没落库,或者时间又往前走了一点)。
// §11.3 因此明文要求:「离线补算的随机数不能依赖 now() 作种子,用 window_index + city_id + 服务端 secret 派生」。
//
// 本类就是那一条:unit(...) = HMAC-SHA256(secret, 'city|window|label') 归一化到 [0,1)。
//   · 同一 (城市, 窗口, 标签) 永远得到同一个数 → 重登录刷不出新结果;
//   · 玩家没有密钥 → 算不出自己下一个窗口会不会触发(与 PriceEngine 同一套思路);
//   · 服务器任何进程、任何时刻都能重算 → 不需要任何共享状态,也不需要 cron 落窗。
//
// 标签(label)的作用:同一个窗口里要掷好几次点(要不要触发 / 抽哪一条 / 损失百分比 / 随机建筑),
// 每次用不同标签派生,互相独立;否则「触发概率」和「抽中谁」会被同一个数捆在一起。
final class EventRandom
{
    // 取十六进制的位数(13 位 = 52 bit,恰在 float 能精确表示的整数范围内,
    // 也远低于 64 位平台 int 上限,不会溢出成负数)。与 PriceEngine::NOISE_HEX_DIGITS 同一口径
    private const HEX_DIGITS = 13;

    // 2^52 − 1:上面 13 位十六进制的最大值,用作归一化分母
    private const MAX = 4503599627370495;

    private static bool $warned = false;

    // [0, 1) 的确定性伪随机数。$parts 任意多段,拼成一条标签
    public static function unit(int|string ...$parts): float
    {
        $secret = self::secret();

        // Fail Safe:两把密钥都没有时退回 CSPRNG。
        // 方向刻意与 PriceEngine 相反(那边没密钥就不波动):价格不波动只是无聊,
        // 而事件「不掷点」等于整个系统停摆;用一个公开可推导的兜底密钥又等于把掷点结果送给玩家。
        // 所以这里选择「继续随机、放弃可重算」——离线补算的防刷性下降,但事件照常发生,且不可预测。
        if ($secret === null) {
            return random_int(0, self::MAX) / (self::MAX + 1);
        }

        $mac = hash_hmac('sha256', implode('|', $parts), $secret);

        return hexdec(substr($mac, 0, self::HEX_DIGITS)) / (self::MAX + 1);
    }

    // 概率掷点:$chance ∈ [0,1]
    public static function chance(float $chance, int|string ...$parts): bool
    {
        if ($chance <= 0.0) {
            return false;
        }
        if ($chance >= 1.0) {
            return true;
        }

        return self::unit(...$parts) < $chance;
    }

    // 闭区间 [$min, $max] 的浮点数(损失百分比、人口增幅这类区间掷点)
    public static function between(float $min, float $max, int|string ...$parts): float
    {
        if ($min >= $max) {
            return $min;
        }

        return $min + self::unit(...$parts) * ($max - $min);
    }

    // [0, $count-1] 的下标(随机挑一栋建筑 / 一种资源 / 一个作用域)
    public static function index(int $count, int|string ...$parts): int
    {
        if ($count <= 1) {
            return 0;
        }

        return min($count - 1, (int) floor(self::unit(...$parts) * $count));
    }

    // 按权重挑一个键:$weights = [键 => 权重]。全为 0 / 空数组返回 null。
    // 权重之和不必等于 1,内部按累加区间落袋(与 NpcRandom::weightedKey 同一算法,
    // 只是随机源换成了确定性派生 —— 事件必须可重算,招募不必)
    public static function weightedKey(array $weights, int|string ...$parts): int|string|null
    {
        $total = 0.0;
        foreach ($weights as $weight) {
            $total += max(0.0, (float) $weight);
        }

        if ($total <= 0.0) {
            return null;
        }

        $roll = self::unit(...$parts) * $total;
        foreach ($weights as $key => $weight) {
            $roll -= max(0.0, (float) $weight);
            if ($roll < 0.0) {
                return $key;
            }
        }

        // 理论上到不了这里(累加必然覆盖 [0, total));兜底返回最后一个键,
        // 免得一个浮点尾差把整次触发变成 500
        return array_key_last($weights);
    }

    // 取密钥。与 PriceEngine::secret / AuditChain::secret 同一套降级策略:
    //   1) 有 EVENT_SECRET → 用它(生产唯一正确姿势);
    //   2) 没有但有 APP_KEY → 从 APP_KEY 派生并 warning(本地开发方便,生产不该走到这);
    //   3) 两个都没有 → null,由 unit() 退回 CSPRNG(见上面的 Fail Safe 说明)。
    public static function secret(): ?string
    {
        $secret = config('event.secret');
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        $appKey = config('app.key');
        if (is_string($appKey) && $appKey !== '') {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('EVENT_SECRET 未配置,事件掷点暂用 APP_KEY 派生的密钥;生产环境必须显式配置(CLAUDE §75)');
            }

            return hash_hmac('sha256', 'apg-event-roll-v1', $appKey);
        }

        if (! self::$warned) {
            self::$warned = true;
            Log::warning('EVENT_SECRET 与 APP_KEY 均缺失,事件掷点退回 CSPRNG(不可重算,离线补算的防刷性下降)');
        }

        return null;
    }

    // 测试用:重置「已告警」标记,让降级告警可被重复观察
    public static function resetWarningState(): void
    {
        self::$warned = false;
    }
}
