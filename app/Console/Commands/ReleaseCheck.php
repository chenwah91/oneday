<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// 发布前自动化检查:php artisan release:check
// 只检查「代码库内可验证」的项(依赖真实生产 .env / 网络环境的项见 docs/ops/release-checklist.md)
class ReleaseCheck extends Command
{
    protected $signature = 'release:check';

    protected $description = '发布前自动化安全/一致性检查';

    public function handle(): int
    {
        $fail = 0;
        $base = base_path();
        // Windows 用 NUL,POSIX(线上 cPanel)用 /dev/null;shell_exec 在 Windows 下
        // 若写死 /dev/null 会导致命令整体失败(找不到路径),必须按系统区分
        $devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        // 1. .env 未被 git 跟踪
        $tracked = trim((string) shell_exec("cd \"$base\" && git ls-files .env 2>$devNull"));
        $this->line($tracked === '' ? '✓ .env 未被 git 跟踪' : '✗ .env 被跟踪!');
        $fail += $tracked === '' ? 0 : 1;

        // 2. .env.example 不含真实密钥(APP_KEY 应为空,不能是真实 base64 密钥)
        $example = @file_get_contents($base . '/.env.example') ?: '';
        $appKeyLeak = (bool) preg_match('/^APP_KEY=base64:.+/m', $example);
        $this->line(! $appKeyLeak ? '✓ .env.example 无真实 APP_KEY' : '✗ .env.example 疑似含真实 APP_KEY');
        $fail += $appKeyLeak ? 1 : 0;

        // 3. 跟踪的 .php blob 无 CRLF、无 BOM(全量抽查,读的是 git blob 而非工作区文件,
        //    避免本地工作区被工具/编辑器改动换行符而没提交时误判)
        $out = (string) shell_exec("cd \"$base\" && git ls-files \"*.php\" 2>$devNull");
        $phpFiles = array_values(array_filter(array_map('trim', explode("\n", $out))));
        $crlf = 0;
        $bom = 0;
        foreach ($phpFiles as $f) {
            $blob = (string) shell_exec("cd \"$base\" && git show HEAD:\"$f\" 2>$devNull");
            if (str_contains($blob, "\r")) {
                $crlf++;
            }
            if (str_starts_with($blob, "\xEF\xBB\xBF")) {
                $bom++;
            }
        }
        $this->line($crlf === 0 ? '✓ 所有 .php git blob 为纯 LF' : "✗ {$crlf} 个 .php 含 CRLF");
        $fail += $crlf === 0 ? 0 : 1;
        $this->line($bom === 0 ? '✓ 所有 .php git blob 无 BOM' : "✗ {$bom} 个 .php 含 BOM");
        $fail += $bom === 0 ? 0 : 1;

        // 4. 迁移文件数量(读代码库文件,不依赖数据库是否已连上/已跑迁移)
        $migrationCount = count(glob(database_path('migrations/*.php')) ?: []);
        $this->line("ℹ 迁移文件数量: {$migrationCount}");

        // 5. 最新 game_data_version(仅报告,不作为失败项;连不上数据库时提示而非报错中断)
        try {
            $ver = DB::table('game_data_versions')->orderByDesc('id')->value('version');
            $this->line('ℹ 最新 game_data_version: '.($ver ?? '(无)'));
        } catch (\Throwable $e) {
            $this->line('ℹ 无法读取 game_data_version(可能未迁移或数据库未连接)');
        }

        $this->newLine();
        if ($fail === 0) {
            $this->info('发布前检查全部通过');

            return 0;
        }
        $this->error("发布前检查有 {$fail} 项失败");

        return 1;
    }
}
