<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\AuditAction;
use App\Support\AuditChain;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// M3-M.9 审计 Hash Chain(CLAUDE §58):链的生成、分域、篡改检测与降级行为。
//
// audit_logs.city_id 没有外键约束,所以这里直接用虚构的城市 id 造域,
// 免得为了「验一条链」把整个建城 / 结算链路拖进来(也避开并行改结算内核的同伴)。
class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    // 取某一域(city_id 为 null 即全局链)的全部审计行,按 id 升序
    private function domainRows(?int $cityId)
    {
        $query = DB::table('audit_logs');
        $cityId === null ? $query->whereNull('city_id') : $query->where('city_id', $cityId);

        return $query->orderBy('id')->get();
    }

    private function write(string $action, array $attrs = []): void
    {
        AuditLogger::record($action, 'success', $attrs);
    }

    // 跑 audit:verify-chain,返回 [退出码, 完整输出]。
    // 不用 artisan()->expectsOutputToContain:它底层是 Mockery 逐个 doWrite 调用匹配,
    // 同一行里的第二个期望串永远匹配不上(第一个期望先把这次调用吃掉了)。
    private function verify(array $args = []): array
    {
        $code = Artisan::call('audit:verify-chain', $args);

        return [$code, Artisan::output()];
    }

    public function test_chain_links_consecutive_rows_in_same_domain(): void
    {
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 101, 'delta_json' => ['wood' => -50]]);
        $this->write(AuditAction::BUILDING_UPGRADE, ['city_id' => 101, 'delta_json' => ['stone' => -20]]);
        $this->write(AuditAction::BUILDING_DEMOLISH, ['city_id' => 101]);

        $rows = $this->domainRows(101);
        $this->assertCount(3, $rows);

        // 域内第一条挂全零串
        $this->assertSame(AuditChain::GENESIS, $rows[0]->previous_hash);

        foreach ($rows as $i => $row) {
            $this->assertNotNull($row->event_hash, "第 {$i} 条应有 event_hash");
            $this->assertSame(64, strlen($row->event_hash));
            if ($i > 0) {
                $this->assertSame($rows[$i - 1]->event_hash, $row->previous_hash, "第 {$i} 条应接上一条");
            }
        }

        // event_hash 必须真的是 HMAC(canonical + previous, secret),不是随手塞的值
        $secret = AuditChain::secret();
        $first = (array) $rows[0];
        $this->assertSame(
            AuditChain::eventHash(AuditChain::canonicalPayload($first), $rows[0]->previous_hash, $secret),
            $rows[0]->event_hash
        );
    }

    public function test_city_domains_and_global_domain_do_not_interleave(): void
    {
        // 交错写:城市 A → 全局 → 城市 B → 城市 A → 全局 → 城市 B
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 201]);
        $this->write(AuditAction::AUTH_LOGIN_SUCCESS);
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 202]);
        $this->write(AuditAction::BUILDING_UPGRADE, ['city_id' => 201]);
        $this->write(AuditAction::AUTH_LOGOUT);
        $this->write(AuditAction::BUILDING_UPGRADE, ['city_id' => 202]);

        foreach ([201, 202, null] as $domain) {
            $rows = $this->domainRows($domain);
            $this->assertCount(2, $rows);
            $this->assertSame(AuditChain::GENESIS, $rows[0]->previous_hash);
            // 第二条只接自己域的第一条 —— 不会串到别的域
            $this->assertSame($rows[0]->event_hash, $rows[1]->previous_hash);
        }

        // 反向确认:三条链的 event_hash 互不相同(没有共用同一个链尾)
        $heads = collect([201, 202, null])->map(fn ($d) => $this->domainRows($d)[1]->event_hash);
        $this->assertCount(3, $heads->unique());
    }

    // 锁形状回归:链尾锁必须落在 audit_chain_heads 的主键行上,**绝不能**回到 audit_logs。
    //
    // 老写法 `audit_logs WHERE city_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE` 在域内空(新城第一条)
    // 时锁的是 idx_audit_chain 里的一段 gap;多个空域落在同一段物理 gap 上,gap 锁互相兼容 →
    // 两个事务都拿到锁 → 各自 INSERT 的 insert intention lock 被对方挡住 → 1213 Deadlock。
    // 双进程实测必现(两个玩家同时注册建城)。这条用例就是防止有人把那个写法改回去。
    public function test_chain_lock_is_on_head_table_not_audit_logs(): void
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = strtolower($q->sql);
        });

        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 301]);

        $headLock = collect($queries)->first(
            fn ($sql) => str_contains($sql, 'audit_chain_heads') && str_contains($sql, 'for update')
        );
        $this->assertNotNull($headLock, '取链尾必须在 audit_chain_heads 上加行锁,否则同域并发写会分叉');
        $this->assertStringContainsString('`domain` = ?', $headLock, '必须是主键等值命中,才只加记录锁不加 gap 锁');

        $logsLock = collect($queries)->first(
            fn ($sql) => str_contains($sql, 'audit_logs') && str_contains($sql, 'for update')
        );
        $this->assertNull($logsLock, 'audit_logs 上不能再有锁定读:空域 gap 锁会让两个新城并发写第一条时死锁');
    }

    // 多个「审计域为空」的新城各写第一条:每条都从全零串起链,各自建自己的链头行。
    // 真并发在 PHPUnit 里跑不出来(RefreshDatabase 把整轮包在一个事务里),
    // 死锁本身由双进程探针实测覆盖;这条守的是逻辑侧:空域之间绝不串链、链头行按域各建一行。
    public function test_multiple_empty_domains_each_start_from_genesis(): void
    {
        foreach ([311, 312, 313] as $cityId) {
            $this->write(AuditAction::CITY_CREATE, ['city_id' => $cityId]);
        }

        foreach ([311, 312, 313] as $cityId) {
            $rows = $this->domainRows($cityId);
            $this->assertCount(1, $rows);
            $this->assertSame(AuditChain::GENESIS, $rows[0]->previous_hash);
            $this->assertSame(
                $rows[0]->event_hash,
                DB::table('audit_chain_heads')->where('domain', 'city:' . $cityId)->value('last_event_hash')
            );
        }

        // 三条链的起点相同(全零串)但结果互不相同 —— 没有串域
        $heads = DB::table('audit_chain_heads')->whereIn('domain', ['city:311', 'city:312', 'city:313'])
            ->pluck('last_event_hash');
        $this->assertCount(3, $heads->unique());
    }

    // 链头表必须始终等于该域 audit_logs 的实际链尾
    public function test_chain_head_table_tracks_domain_tail(): void
    {
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 321]);
        $this->write(AuditAction::AUTH_LOGIN_SUCCESS);
        $this->write(AuditAction::BUILDING_UPGRADE, ['city_id' => 321]);

        $cityTail = $this->domainRows(321)->last()->event_hash;
        $globalTail = $this->domainRows(null)->last()->event_hash;

        $this->assertSame($cityTail, DB::table('audit_chain_heads')->where('domain', 'city:321')->value('last_event_hash'));
        $this->assertSame($globalTail, DB::table('audit_chain_heads')->where('domain', 'global')->value('last_event_hash'));

        [$code, $output] = $this->verify();
        $this->assertSame(0, $code, $output);
    }

    // 有人绕过 AuditLogger 直写 audit_logs(链头表没跟着走)→ 必须报 HEAD_MISMATCH
    public function test_verify_chain_detects_head_mismatch(): void
    {
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 331]);

        DB::table('audit_chain_heads')->where('domain', 'city:331')
            ->update(['last_event_hash' => str_repeat('f', 64)]);

        [$code, $output] = $this->verify(['--city' => 331]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('HEAD_MISMATCH', $output);
    }

    // 整域审计被删光:只看 audit_logs 的话这个域根本不会被遍历到,
    // 域清单并上链头表才抓得住(链头还留着,实际链尾却空了)
    public function test_verify_chain_detects_whole_domain_wipe(): void
    {
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 341]);
        $this->write(AuditAction::BUILDING_UPGRADE, ['city_id' => 341]);

        DB::table('audit_logs')->where('city_id', 341)->delete();

        [$code, $output] = $this->verify();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('HEAD_MISMATCH', $output);
        $this->assertStringContainsString('city=341', $output);
    }

    public function test_verify_chain_passes_on_clean_chain(): void
    {
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 401, 'delta_json' => ['wood' => -50, 'money' => -100]]);
        $this->write(AuditAction::BUILDING_UPGRADE, ['city_id' => 401, 'before_json' => ['level' => 1], 'after_json' => ['level' => 2]]);
        $this->write(AuditAction::AUTH_LOGIN_SUCCESS);

        [$code, $output] = $this->verify();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('验证 3 条 / 跳过历史 0 条 / 断链 0 处', $output);
    }

    public function test_verify_chain_detects_tampered_row(): void
    {
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 501, 'delta_json' => ['wood' => -50]]);
        $this->write(AuditAction::BUILDING_UPGRADE, ['city_id' => 501]);
        $this->write(AuditAction::BUILDING_DEMOLISH, ['city_id' => 501]);

        // 篡改中间那条的资源变化(典型作弊:把扣掉的木头抹平)
        $target = $this->domainRows(501)[1];
        DB::table('audit_logs')->where('id', $target->id)
            ->update(['delta_json' => json_encode(['wood' => 999999])]);

        [$code, $output] = $this->verify(['--city' => 501]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('CONTENT_TAMPERED', $output);
        $this->assertStringContainsString('id=' . $target->id, $output);
        $this->assertStringContainsString('断链 1 处', $output);
        // 只报被改的那一行,不级联把后面的行也判成断链
        $this->assertStringContainsString('验证 3 条 / 跳过历史 0 条 / 断链 1 处', $output);
    }

    public function test_verify_chain_detects_tampered_actor(): void
    {
        $this->write(AuditAction::ADMIN_COMPENSATION, ['city_id' => 502, 'actor_type' => 'admin', 'actor_id' => 7]);

        // 换个人背锅:把操作者改掉
        $target = $this->domainRows(502)[0];
        DB::table('audit_logs')->where('id', $target->id)->update(['actor_id' => 8]);

        [$code, $output] = $this->verify(['--city' => 502]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('CONTENT_TAMPERED', $output);
        $this->assertStringContainsString('id=' . $target->id, $output);
    }

    public function test_verify_chain_detects_deleted_row(): void
    {
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 601]);
        $this->write(AuditAction::BUILDING_UPGRADE, ['city_id' => 601]);
        $this->write(AuditAction::BUILDING_DEMOLISH, ['city_id' => 601]);

        // 抹掉中间一条:后一条的 previous_hash 就接不上了
        $target = $this->domainRows(601)[1];
        DB::table('audit_logs')->where('id', $target->id)->delete();

        [$code, $output] = $this->verify(['--city' => 601]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('PREVIOUS_MISMATCH', $output);
        $this->assertStringContainsString('断链 1 处', $output);
    }

    public function test_historical_rows_without_hash_are_skipped(): void
    {
        // 模拟补列前写入的历史行(两列都是 NULL,append-only 纪律下不回填)
        foreach ([AuditAction::BUILDING_BUILD, AuditAction::BUILDING_UPGRADE] as $action) {
            DB::table('audit_logs')->insert([
                'occurred_at' => now()->format('Y-m-d H:i:s.u'), 'request_id' => (string) Str::uuid(),
                'actor_type' => 'player', 'action' => $action, 'status' => 'success',
                'city_id' => 701, 'created_at' => now(),
            ]);
        }

        // 部署之后的新行:链从这里开始,previous_hash 是全零串而不是「上一条历史行」
        $this->write(AuditAction::BUILDING_DEMOLISH, ['city_id' => 701]);

        $rows = $this->domainRows(701);
        $this->assertSame(AuditChain::GENESIS, $rows[2]->previous_hash);

        [$code, $output] = $this->verify(['--city' => 701]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('验证 1 条 / 跳过历史 2 条 / 断链 0 处', $output);
    }

    public function test_chain_continues_across_separate_requests(): void
    {
        User::create([
            'username' => 'chainuser', 'name' => 'chainuser',
            'email' => 'chain@example.com', 'password' => 'password123',
        ]);

        // 两个独立请求,各写一条全局域审计(登录成功 / 登出)
        $this->postJson('/api/auth/login', ['username' => 'chainuser', 'password' => 'password123'])->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();

        $rows = $this->domainRows(null);
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertSame(AuditAction::AUTH_LOGIN_SUCCESS, $rows[0]->action);
        $this->assertSame(AuditAction::AUTH_LOGOUT, $rows[1]->action);

        // 第二个请求写的行接得上第一个请求写的行 —— 链不随请求边界断开
        $this->assertSame(AuditChain::GENESIS, $rows[0]->previous_hash);
        $this->assertSame($rows[0]->event_hash, $rows[1]->previous_hash);

        [$code, $output] = $this->verify(['--city' => 'global']);
        $this->assertSame(0, $code, $output);
    }

    public function test_missing_secret_degrades_without_blocking_audit(): void
    {
        config(['audit.hmac_secret' => null, 'app.key' => null]);
        AuditChain::resetWarningState();

        // 审计是安全底线:密钥缺失只允许「不挂链」,不允许写不进去
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 801]);

        $rows = $this->domainRows(801);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->previous_hash);
        $this->assertNull($rows[0]->event_hash);

        AuditChain::resetWarningState();
    }

    public function test_derives_secret_from_app_key_when_env_missing(): void
    {
        config(['audit.hmac_secret' => null]);
        AuditChain::resetWarningState();

        $derived = AuditChain::secret();
        $this->assertNotNull($derived);
        $this->assertSame(hash_hmac('sha256', 'apg-audit-chain-v1', config('app.key')), $derived);

        // 显式配置优先于派生
        config(['audit.hmac_secret' => 'explicit-secret']);
        $this->assertSame('explicit-secret', AuditChain::secret());

        AuditChain::resetWarningState();
    }

    public function test_canonical_payload_ignores_json_key_order_and_scalar_type(): void
    {
        // 同一条记录,一份是「写入时的 PHP 值」,一份是「从库里读回来的字符串 + 重排过 key 的 JSON」
        // (线上 MySQL 5.7 的原生 JSON 列就会重排 key),两者的 canonical 必须一致
        $written = [
            'action' => 'BUILDING.BUILD', 'city_id' => 5, 'user_id' => 9, 'status' => 'success',
            'occurred_at' => '2026-08-10 10:00:00.123456',
            'delta_json' => '{"wood":-50,"money":-100}',
            'metadata_json' => '{"b":1,"a":{"y":2,"x":1.0}}',
        ];
        $readBack = [
            'action' => 'BUILDING.BUILD', 'city_id' => '5', 'user_id' => '9', 'status' => 'success',
            'occurred_at' => '2026-08-10 10:00:00.123456',
            'delta_json' => '{"money": -100, "wood": -50}',
            'metadata_json' => '{"a": {"x": 1, "y": 2}, "b": 1}',
        ];

        $this->assertSame(
            AuditChain::canonicalPayload($written),
            AuditChain::canonicalPayload($readBack)
        );

        // 但真正的内容变化必须改变 canonical
        $tampered = $readBack;
        $tampered['delta_json'] = '{"money": -100, "wood": -49}';
        $this->assertNotSame(
            AuditChain::canonicalPayload($readBack),
            AuditChain::canonicalPayload($tampered)
        );
    }

    public function test_verify_chain_rejects_unknown_city_option(): void
    {
        [$code, $output] = $this->verify(['--city' => 'abc']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--city 只接受城市 id 或 global', $output);
    }

    // 缺 secret 期间写入的行会在链上留个洞:链已开始之后又出现未挂链的行,
    // verify 必须把它报成 CHAIN_HOLE,而不是当历史行悄悄跳过
    public function test_unchained_row_after_chain_started_is_reported_as_hole(): void
    {
        $this->write(AuditAction::BUILDING_BUILD, ['city_id' => 901]);

        config(['audit.hmac_secret' => null, 'app.key' => null]);
        AuditChain::resetWarningState();
        $this->write(AuditAction::BUILDING_UPGRADE, ['city_id' => 901]);

        config(['audit.hmac_secret' => 'testing-audit-hmac-secret']);
        AuditChain::resetWarningState();

        [$code, $output] = $this->verify(['--city' => 901]);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('CHAIN_HOLE', $output);
    }
}
