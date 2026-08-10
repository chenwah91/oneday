<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M3-M.9 审计 Hash Chain 的链头指针表。
//
// 为什么要单独一张表(而不是每次去 audit_logs 里 ORDER BY id DESC LIMIT 1 FOR UPDATE):
// 在 audit_logs 上取链尾时,若该域一行都没有(新城的第一条审计),InnoDB 锁的是
// idx_audit_chain (city_id, id) 里「该域本应插入的那段 gap」。多个空域(city 7、8、9…)
// 在索引里落到同一段物理 gap,gap 锁彼此兼容 → 两个事务都拿得到锁;随后各自的 INSERT
// 申请 insert intention lock,被对方的 gap 锁挡住 → 成环,报 1213 Deadlock。
// 实测:两个新城并发写第一条审计必现(生产场景 = 两个玩家同时注册建城,CITY.CREATE 死锁)。
//
// 本表把「链尾指针」挪出 audit_logs:域名是主键,等值命中已存在的行时 InnoDB 退化成
// 纯记录锁(不带 gap),不同域天然不争抢,同域串行 —— 环没了。
// 详见 app/Support/AuditChain.php 顶部的锁形状说明。
//
// domain 取值:'global'(city_id 为 NULL 的事件)/ 'city:<id>'。
// 用字符串而不是可空的 city_id,是因为主键不能为 NULL,全局域没法用 NULL 表达。
//
// 本表只是「取上一条 hash」的快速指针,不是链本身:链完整性永远以 audit_logs 里
// 每行的 previous_hash / event_hash 为准,audit:verify-chain 逐行重算,不信任本表。
return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->string('domain', 32)->primary();
            // 该域最后一条已挂链审计的 event_hash;NULL = 该域还没挂过链(新域,或链尾还是历史行)
            $table->char('last_event_hash', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::backfillHeads();
    }

    // 回填:把 audit_logs 里已经挂过链的域,其当前链尾登记进链头表。
    //
    // 正常上线路径(本迁移与 900001 同一次发布)跑到这里时一条挂链行都没有,这段是空操作。
    // 需要它是为了「库里已经有挂链行、链头表却是空的」这种过渡态 —— 例如开发库跑过中间版本。
    // 不回填的话 audit:verify-chain 会把它报成 HEAD_MISMATCH(链头表说空、实际却有链尾),
    // 那是假警报,会稀释真警报的可信度。
    //
    // 只读 audit_logs、只写新表,一行审计都不改(append-only 纪律)。
    // 链的完整性不依赖本表:每行的 previous_hash / event_hash 由 verify 独立重算,
    // 回填只是把「指针」对齐到既有事实,不会掩盖任何已经发生的篡改。
    private static function backfillHeads(): void
    {
        // 先探一下有没有挂过链的行:绝大多数环境(含生产首发)到这里直接返回,不做无谓扫描
        $hasChained = DB::table('audit_logs')->whereNotNull('event_hash')->limit(1)->exists();
        if (! $hasChained) {
            return;
        }

        $domains = DB::table('audit_logs')->distinct()->pluck('city_id');

        foreach ($domains as $cityId) {
            $query = DB::table('audit_logs')->whereNotNull('event_hash');
            $cityId === null ? $query->whereNull('city_id') : $query->where('city_id', $cityId);

            $tail = $query->orderByDesc('id')->value('event_hash');
            if ($tail === null) {
                continue;
            }

            DB::table('audit_chain_heads')->insert([
                'domain'          => $cityId === null ? 'global' : 'city:' . (int) $cityId,
                'last_event_hash' => $tail,
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
    }
};
