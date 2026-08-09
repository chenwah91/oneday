<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

// 授予管理员角色:php artisan admin:promote <username>
class AdminPromote extends Command
{
    protected $signature = 'admin:promote {username}';
    protected $description = '把指定用户名设为管理员';

    public function handle(): int
    {
        $user = User::where('username', $this->argument('username'))->first();
        if (! $user) {
            $this->error('用户不存在');
            return 1;
        }
        // role 已从 $fillable 移除,不能再走批量赋值,这里用 forceFill 显式绕过
        $user->forceFill(['role' => 'admin'])->save();
        $this->info("已将 {$user->username} 设为管理员");
        return 0;
    }
}
