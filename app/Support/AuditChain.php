<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// 审计 Hash Chain(CLAUDE §58 / SECURITY「Audit HMAC Hash Chain」/ v3.2 §12.2,阶段 M3)。
//
// ── 链的形状 ───────────────────────────────────────────────────────────────
// 按 city_id 分域:每个城市一条链,city_id 为 NULL 的事件(登录 / 限流 / 后台配置 / 越权拒绝)
// 走一条「全局链」。域内第 N 条的 previous_hash = 第 N-1 条的 event_hash,域内第一条用全零串。
//
//   event_hash = HMAC-SHA256(canonical_payload + previous_hash, AUDIT_HMAC_SECRET)
//
// 为什么分域而不是一条全局链:审计写绝大多数发生在 cities 行锁事务内,单条全局链会让所有城市的
// 写互相争抢「最后一条 hash」,把本来可以并行的城市串行化。分域后每条链只在自己域内争抢。
//
// ── 并发安全依据(链尾指针为什么不放在 audit_logs 里)──────────────────────
// 曾经的写法是 `SELECT ... WHERE city_id <=> ? ORDER BY id DESC LIMIT 1 FOR UPDATE`,靠
// idx_audit_chain (city_id, id) 锁住该域的链尾。同域串行、跨域并行都成立,但它有一个致命洞:
//
//   域内一行都没有时(新城的第一条审计),这条锁定读锁的是「该域本应插入的那段 gap」。
//   多个空域(city 7、8、9…)在索引里落到同一段物理 gap,而 gap 锁彼此兼容 —— 两个事务都拿得到锁;
//   随后两条 INSERT 各自申请 insert intention lock,分别被对方持有的 gap 锁挡住,成环 → 1213 Deadlock。
//
// 实测必现(两个新城并发写第一条审计),生产对应场景是「两个玩家同时注册建城」→ CITY.CREATE 死锁 →
// 其中一个注册 500。gap 锁 vs insert intention lock 这一层,是「cities 行锁 → 审计锁」的跨表顺序
// 论证覆盖不到的。
//
// 现在的写法把链尾指针挪进 audit_chain_heads(主键 = 域名字符串),同一事务内四步:
//   ① INSERT ... ON DUPLICATE KEY UPDATE domain = domain —— 确保该域的行存在。
//      行不存在时是纯 INSERT(insert intention lock 之间互相兼容,不成环);
//      行已存在时按重复键取该行的排他记录锁,正是我们要的。
//   ② SELECT last_event_hash ... WHERE domain = ? FOR UPDATE —— 主键等值命中已存在的行,
//      InnoDB 退化成纯记录锁,**不带 gap**;不同域是不同记录,天然不争抢,同域串行。
//   ③ INSERT audit_logs —— 此时没有任何人在 audit_logs 上持 gap 锁,insert intention 永不被挡。
//   ④ UPDATE audit_chain_heads 推进链尾 —— 锁在②已经拿到,不再新增锁资源。
// 调用方没有事务时 AuditLogger 会开一个短事务,保证②③④与提交是同一个原子区间。
//
// 锁顺序:业务路径一律是 cities 行锁 → 本域链头行;只写审计不锁城市的路径(如快照里的科技懒解锁)
// 只取后者。不存在反向持锁,故无环。唯一要守的纪律:同一个事务内不要先写 A 域审计再写 B 域审计
// (目前没有这种调用点),否则两个事务用相反顺序取两域的链头行才可能成环。
//
// audit_chain_heads 只是「取上一条 hash」的指针,不是链本身:链完整性永远以 audit_logs 每行的
// previous_hash / event_hash 为准,audit:verify-chain 逐行重算、不信任该表,并额外校验
// 「链头表的值 == 该域实际链尾」(不一致 = 有人绕过 AuditLogger 直写,报 HEAD_MISMATCH)。
//
// ── 跨库可移植性 ──────────────────────────────────────────────────────────
// canonical_payload 不能直接用「插入时的 JSON 字符串」:线上 MySQL 5.7 的原生 JSON 列会重排 key、
// 压掉空白,读回来的字节跟写进去的不一样(本地 MariaDB 是 LONGTEXT,原样保存),那样 verify 会在
// 生产整链报断而本地全绿。所以一律先 decode 再规范化(递归 ksort + 标量一律转字符串),
// 让 hash 只依赖「语义值」,不依赖任何一种数据库的 JSON 存储表示。
// 标量转字符串同时也吃掉了「写入时是 int 5 / 读回来是字符串 '5'」「1.0 与 1」这类往返差异。
final class AuditChain
{
    // 域内第一条的 previous_hash(64 个 0)
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    // 进 canonical_payload 的字段清单(固定顺序:下面会 ksort,这里按字母序写只为便于人眼核对)。
    // 改这份清单等于换算法 —— 老行的 event_hash 会全部对不上,只能在「重置链」的前提下做。
    // 未入列的只有:id(自增,插入前拿不到)、created_at(数据库默认值,与 occurred_at 冗余)、
    // previous_hash / event_hash(前者按公式单独拼接,后者是结果本身)。
    public const FIELDS = [
        'action',
        'actor_id',
        'actor_type',
        'after_json',
        'before_json',
        'city_id',
        'city_revision_after',
        'city_revision_before',
        'delta_json',
        'entity_id',
        'entity_type',
        'game_data_version',
        'idempotency_key',
        'ip_address',
        'metadata_json',
        'occurred_at',
        'reason_code',
        'request_id',
        'status',
        'trace_id',
        'user_agent_hash',
        'user_id',
    ];

    // 上面哪些是 JSON 列(需要 decode 后规范化,而不是当普通字符串)
    private const JSON_FIELDS = ['after_json', 'before_json', 'delta_json', 'metadata_json'];

    // 缺 secret 的告警每进程只打一次,避免刷爆日志
    private static bool $warned = false;

    // 取 HMAC secret。
    // 1) 有 AUDIT_HMAC_SECRET → 用它(生产唯一正确姿势);
    // 2) 没有但有 APP_KEY → 从 APP_KEY 派生并 warning(本地开发方便,生产不该走到这);
    // 3) 两个都没有 → 返回 null:审计照写,但不挂链(previous_hash / event_hash 留 NULL)。
    //    审计本身是安全底线,绝不能因为链的配置缺失而写不进去。
    public static function secret(): ?string
    {
        $secret = config('audit.hmac_secret');
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        $appKey = config('app.key');
        if (is_string($appKey) && $appKey !== '') {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('AUDIT_HMAC_SECRET 未配置,审计链暂用 APP_KEY 派生的密钥;生产环境必须显式配置(CLAUDE §58/§75)');
            }

            return hash_hmac('sha256', 'apg-audit-chain-v1', $appKey);
        }

        if (! self::$warned) {
            self::$warned = true;
            Log::warning('AUDIT_HMAC_SECRET 与 APP_KEY 均缺失,审计链本次不挂链(previous_hash / event_hash 留 NULL)');
        }

        return null;
    }

    // 测试用:重置「已告警」标记,让降级告警可被重复观察
    public static function resetWarningState(): void
    {
        self::$warned = false;
    }

    // 把一行审计(列名 => 值,写入时的数组或从库里读回来的行都行)压成规范串。
    public static function canonicalPayload(array $row): string
    {
        $canonical = [];
        foreach (self::FIELDS as $field) {
            $value = $row[$field] ?? null;
            $canonical[$field] = in_array($field, self::JSON_FIELDS, true)
                ? self::normalizeJson($value)
                : self::normalizeScalar($value);
        }

        ksort($canonical, SORT_STRING);

        return (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // event_hash = HMAC-SHA256(canonical_payload + previous_hash, secret)
    public static function eventHash(string $canonicalPayload, string $previousHash, string $secret): string
    {
        return hash_hmac('sha256', $canonicalPayload . $previousHash, $secret);
    }

    // 链头表的主键:全局域用 'global',城市域用 'city:<id>'(主键不能为 NULL,故不复用 city_id)
    public static function domainKey(?int $cityId): string
    {
        return $cityId === null ? 'global' : 'city:' . $cityId;
    }

    // 反解:'city:7' → 7,'global' → null。verify 拿链头表里的域名回推域用
    public static function parseDomainKey(string $domain): ?int
    {
        return str_starts_with($domain, 'city:') ? (int) substr($domain, 5) : null;
    }

    // 锁住该域的链头行并取出上一条的 event_hash(必须在事务内调用)。
    // 返回 null 表示该域还没挂过链(全新域,或链尾还是补列前的历史行)→ 调用方用 GENESIS。
    //
    // 先确保行存在再 FOR UPDATE:主键等值命中已存在的行时 InnoDB 只加记录锁不加 gap 锁,
    // 这正是绕开「空域 gap 锁互斥成环」的关键(见本类顶部)。
    public static function lockHead(?int $cityId): ?string
    {
        $domain = self::domainKey($cityId);

        // ON DUPLICATE KEY UPDATE domain = domain:已存在时不改任何值,只为拿那一行的排他锁。
        // 不用 INSERT IGNORE —— 它在重复键时取的是共享锁,两个同域写入者可能互相升级不上而成环。
        DB::statement(
            'insert into `audit_chain_heads` (`domain`, `last_event_hash`, `updated_at`) values (?, null, ?)'
            . ' on duplicate key update `domain` = `domain`',
            [$domain, now()]
        );

        $row = DB::table('audit_chain_heads')->where('domain', $domain)
            ->lockForUpdate()->first(['last_event_hash']);

        return $row?->last_event_hash;
    }

    // 推进链头(锁在 lockHead 已拿到,这里不新增锁资源)
    public static function advanceHead(?int $cityId, string $eventHash): void
    {
        DB::table('audit_chain_heads')->where('domain', self::domainKey($cityId))
            ->update(['last_event_hash' => $eventHash, 'updated_at' => now()]);
    }

    // 链头表里记录的该域链尾(verify 用来比对,不加锁)
    public static function storedHead(?int $cityId): ?string
    {
        return DB::table('audit_chain_heads')->where('domain', self::domainKey($cityId))
            ->value('last_event_hash');
    }

    // 标量列:null 保持 null,其余一律转字符串(吃掉 int/string 往返差异);bool 转 true/false
    private static function normalizeScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    // JSON 列:decode 后递归规范化(key 排序 + 标量转字符串),抹掉存储层的 JSON 表示差异
    private static function normalizeJson(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            // 解析不出来(理论上不该发生)时退回原始字符串,至少保证可复现
            if ($decoded === null && strtolower(trim($value)) !== 'null') {
                return $value;
            }
            $value = $decoded;
        }

        return self::normalizeStructure($value);
    }

    private static function normalizeStructure(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[(string) $key] = self::normalizeStructure($item);
            }
            ksort($out, SORT_STRING);

            return $out;
        }

        return self::normalizeScalar($value);
    }
}
