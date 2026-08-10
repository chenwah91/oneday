<?php

namespace App\Console\Commands;

use App\Support\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// 校验审计 Hash Chain:php artisan audit:verify-chain [--city=5|--city=global]
//
// 链按 city_id 分域(每城一条 + city_id 为 NULL 的全局链),逐域按 id 升序走一遍:
//   1) previous_hash 必须等于同域上一条挂链行的 event_hash(域内第一条等于全零串);
//   2) 用库里读回来的字段重算 event_hash,必须与存的一致 —— 不一致说明那一行的内容被改过。
//
// 历史行(补列前写入,previous_hash 为 NULL)一律跳过并计数,不当作断链 —— append-only 纪律下
// 这些行不回填,链从部署时刻开始。
//
// 退出码:0 = 全链完好;1 = 有断链 / 没配 secret。适合挂进定时任务或发布前检查。
class AuditVerifyChain extends Command
{
    protected $signature = 'audit:verify-chain
        {--city= : 只校验一个域:城市 id(如 5),或 global(city_id 为 NULL 的全局链);缺省校验全部}';

    protected $description = '校验 audit_logs 的 Hash Chain,检测审计行是否被篡改或删除';

    public function handle(): int
    {
        $secret = AuditChain::secret();
        if ($secret === null) {
            $this->error('未配置 AUDIT_HMAC_SECRET(且无 APP_KEY 可派生),无法校验审计链');

            return 1;
        }

        $option = $this->option('city');
        $single = $option !== null && $option !== '';

        if ($single) {
            if (in_array(strtolower((string) $option), ['global', 'null'], true)) {
                $domains = [null];
            } elseif (ctype_digit((string) $option)) {
                $domains = [(int) $option];
            } else {
                $this->error('--city 只接受城市 id 或 global');

                return 1;
            }
        } else {
            // 域清单取「audit_logs 里出现过的域」∪「链头表里登记过的域」。
            // 并上链头表这一半是为了抓「某个域的审计行被整域删光」——只看 audit_logs 的话
            // 那个域根本不会被遍历到,删得干干净净反而查不出来。
            $fromLogs = DB::table('audit_logs')->distinct()->pluck('city_id')
                ->map(fn ($id) => $id === null ? null : (int) $id);
            $fromHeads = DB::table('audit_chain_heads')->pluck('domain')
                ->map(fn ($d) => AuditChain::parseDomainKey((string) $d));

            $domains = $fromLogs->merge($fromHeads)->unique(fn ($id) => $id === null ? 'global' : $id)
                ->sortBy(fn ($id) => $id ?? -1)->values()->all();
        }

        $totalVerified = 0;
        $totalSkipped = 0;
        $breaks = [];

        foreach ($domains as $cityId) {
            [$verified, $skipped, $domainBreaks] = $this->verifyDomain($cityId, $secret);

            $totalVerified += $verified;
            $totalSkipped += $skipped;
            $breaks = array_merge($breaks, $domainBreaks);

            // 全库校验时逐域刷屏没意义,只在指定单域或该域有问题时打明细行
            if ($single || $domainBreaks !== []) {
                $this->line(sprintf(
                    '域 %s:校验 %d 条,跳过历史 %d 条,断链 %d 处',
                    $this->domainLabel($cityId), $verified, $skipped, count($domainBreaks)
                ));
            }
        }

        if ($breaks !== []) {
            $this->newLine();
            $this->error('断链明细:');
            foreach ($breaks as $break) {
                $this->line(sprintf(
                    '  id=%s 域=%s 原因=%s %s',
                    $break['id'] === null ? '-' : $break['id'],
                    $this->domainLabel($break['city_id']), $break['reason'], $break['detail']
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '验证 %d 条 / 跳过历史 %d 条 / 断链 %d 处',
            $totalVerified, $totalSkipped, count($breaks)
        ));

        if ($breaks !== []) {
            $this->error('审计链校验失败');

            return 1;
        }

        $this->info('审计链校验通过');

        return 0;
    }

    // 走一个域的链。返回 [已校验条数, 跳过的历史行数, 断链明细]
    private function verifyDomain(?int $cityId, string $secret): array
    {
        $verified = 0;
        $skipped = 0;
        $breaks = [];

        // 链是否已经开始:开始之前遇到的未挂链行算「历史行」,开始之后再遇到就是缺口
        $chainStarted = false;
        $expected = AuditChain::GENESIS;

        $columns = array_merge(['id', 'city_id', 'previous_hash', 'event_hash'], AuditChain::FIELDS);
        $columns = array_values(array_unique($columns));

        $query = DB::table('audit_logs')->select($columns);
        $cityId === null ? $query->whereNull('city_id') : $query->where('city_id', $cityId);

        $query->chunkById(500, function ($rows) use (
            $secret, $cityId, &$verified, &$skipped, &$breaks, &$chainStarted, &$expected
        ) {
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $previous = $row->previous_hash;
                $event = $row->event_hash;

                if ($previous === null && $event === null) {
                    if (! $chainStarted) {
                        $skipped++;

                        continue;
                    }
                    $breaks[] = ['id' => $id, 'city_id' => $cityId, 'reason' => 'CHAIN_HOLE',
                        'detail' => '链已开始后出现未挂链的行(缺 secret 时的降级写入,或该行被替换)'];

                    continue;
                }

                if ($previous === null || $event === null) {
                    $breaks[] = ['id' => $id, 'city_id' => $cityId, 'reason' => 'HALF_LINKED',
                        'detail' => 'previous_hash 与 event_hash 只有一个有值'];

                    continue;
                }

                if (! hash_equals($expected, (string) $previous)) {
                    $breaks[] = ['id' => $id, 'city_id' => $cityId,
                        'reason' => $chainStarted ? 'PREVIOUS_MISMATCH' : 'NOT_GENESIS',
                        'detail' => $chainStarted
                            ? '接不上同域上一条的 event_hash(中间有行被删除或被插入)'
                            : '域内第一条挂链行的 previous_hash 不是全零串'];
                }

                $recomputed = AuditChain::eventHash(
                    AuditChain::canonicalPayload((array) $row),
                    (string) $previous,
                    $secret
                );
                if (! hash_equals((string) $event, $recomputed)) {
                    $breaks[] = ['id' => $id, 'city_id' => $cityId, 'reason' => 'CONTENT_TAMPERED',
                        'detail' => '按当前字段重算的 event_hash 与存储值不一致(该行内容被改过)'];
                }

                $verified++;
                $chainStarted = true;
                // 往下接用「库里存的」而不是重算的:一处篡改只报一处,不级联刷屏
                $expected = (string) $event;
            }
        }, 'id');

        // 链头表校验:audit_chain_heads 记的链尾必须等于本域实际走到的链尾。
        // 不一致 = 有人绕过 AuditLogger 直写 audit_logs,或整域审计被删光(此时实际链尾为 null)。
        // 注意链本身的完整性不依赖这张表 —— 上面每一行都是独立重算的,这里只是多一道交叉验证。
        $expectedHead = $chainStarted ? $expected : null;
        $storedHead = AuditChain::storedHead($cityId);
        if ($storedHead !== $expectedHead) {
            $breaks[] = ['id' => null, 'city_id' => $cityId, 'reason' => 'HEAD_MISMATCH',
                'detail' => sprintf(
                    '链头表记的链尾(%s)与实际链尾(%s)不一致',
                    $storedHead === null ? '空' : substr($storedHead, 0, 12) . '…',
                    $expectedHead === null ? '空' : substr($expectedHead, 0, 12) . '…'
                )];
        }

        return [$verified, $skipped, $breaks];
    }

    private function domainLabel(?int $cityId): string
    {
        return $cityId === null ? 'global' : 'city=' . $cityId;
    }
}
