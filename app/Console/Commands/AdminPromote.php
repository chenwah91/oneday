<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Role;
use Illuminate\Console\Command;

// 设置用户角色:php artisan admin:promote <username> [role]
// role 省略时默认 admin;可选值见 App\Support\Role(player/support/game_master/admin/super_admin)
class AdminPromote extends Command
{
    protected $signature = 'admin:promote {username} {role=admin}';
    protected $description = '设置指定用户名的角色(player/support/game_master/admin/super_admin)';

    public function handle(): int
    {
        $role = (string) $this->argument('role');

        // 白名单校验放在查用户之前:非法角色一律拒绝,绝不写入数据库(Fail Closed)
        if (! Role::isValid($role)) {
            $this->error('角色非法:' . $role . ',可选值:' . implode(' / ', Role::all()));
            return 1;
        }

        $user = User::where('username', $this->argument('username'))->first();
        if (! $user) {
            $this->error('用户不存在');
            return 1;
        }

        $before = $user->role;
        // ⚠️ 安全红线:role 绝不能加入 User::$fillable ——
        // 一旦可批量赋值,注册/更新接口带个 role 字段就是自助提权(M1 已修复过该漏洞)。
        // forceFill 是唯一被允许的写 role 路径,且只出现在这个受控的 CLI 命令里。
        $user->forceFill(['role' => $role])->save();

        $this->info("已将 {$user->username} 的角色由 {$before} 设为 {$role}");
        return 0;
    }
}
