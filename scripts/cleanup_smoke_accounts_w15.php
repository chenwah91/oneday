<?php

/**
 * 冒烟账号清理(一次性脚本,留仓库供追溯)。
 *
 * 背景:W11~W16 几波开发在开发库里留下 5 个端到端实测账号,docs/STATUS.md 一直挂着
 *       「等清理授权」。用户 2026-08-16 授权执行。
 *
 * 清理范围(**只动开发库 apg,不动测试库、更不动线上**):
 *   w11smoke_ban / w11smoke_sup  W11 封禁与权限分级实测
 *   w12smoke                     W12 玩家界面波实测(含一栋 H01 与补偿审计)
 *   w16smoke                     W16 建造面板三态实测(含 A01+H01)
 *   w15admin                     W15 后台独立会话实测用的临时管理员
 *
 * 明确不动的东西:
 *   - audit_logs:append-only(CLAUDE §58),账号删了审计照样留着 —— 这正是审计的意义;
 *   - 用户自己的账号(chenwah91 / chenwah_admin / chengzhu001 等)一律不碰;
 *   - 开发库里 W13~W16 冒烟留下的**定义数据**(H01 的 L4 定义 / TECH_I_SMOKE / iron_tools)
 *     不在本次范围 —— 那是定义表,删了会动 GDV,要单独裁决。
 *
 * 安全纪律(与 scripts/cleanup_dev_dirty_data.php 同一套):
 *   - 每一条 DELETE 都带 WHERE 精确圈定(CLAUDE 红线:禁止无 WHERE 的 DELETE);
 *   - 先 SELECT 打印将要删除的行,再删,并逐步打印影响行数;
 *   - 整体包在一个事务里,任何一步抛异常就整体回滚;
 *   - 库名不是 apg 直接拒绝执行(防止手滑连到测试库/线上库);
 *   - 默认 dry-run,必须显式 --apply 才真的删。
 *
 * 用法:
 *   C:/xampp/php/php.exe scripts/cleanup_smoke_accounts_w15.php          # 只预览(dry-run),不删
 *   C:/xampp/php/php.exe scripts/cleanup_smoke_accounts_w15.php --apply  # 真正执行
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ---- 参数与安全闸门 ----

$apply = in_array('--apply', $argv ?? [], true);
$database = (string) DB::connection()->getDatabaseName();

const ALLOWED_DATABASE = 'apg';

// 要清理的冒烟账号(按用户名圈定,不写死 id —— id 会因重建库而变)
const SMOKE_USERNAMES = ['w11smoke_ban', 'w11smoke_sup', 'w12smoke', 'w16smoke', 'w15admin'];

echo "== 冒烟账号清理(W11~W16)==\n";
echo '当前库:' . $database . ($apply ? '  模式:执行(--apply)' : '  模式:预览(dry-run)') . "\n\n";

if ($database !== ALLOWED_DATABASE) {
    fwrite(STDERR, "拒绝执行:本脚本只允许在开发库 `" . ALLOWED_DATABASE . "` 上运行,当前连的是 `{$database}`。\n");
    exit(1);
}

// ---- 第 0 步:先看清楚要删什么 ----

$users = DB::table('users')->whereIn('username', SMOKE_USERNAMES)->get(['id', 'username', 'role']);
$userIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();

$cities = $userIds
    ? DB::table('cities')->whereIn('user_id', $userIds)->get(['id', 'user_id', 'name'])
    : collect();
$cityIds = $cities->pluck('id')->map(fn ($id) => (int) $id)->all();

echo '[0] 命中的账号(' . count($userIds) . " 个):\n";
foreach ($users as $u) {
    echo "    users#{$u->id}  {$u->username}  role=" . ($u->role ?: '(player)') . "\n";
}
echo '[0] 命中的城市(' . count($cityIds) . " 座):\n";
foreach ($cities as $c) {
    echo "    cities#{$c->id}  user_id={$c->user_id}  name={$c->name}\n";
}

// 安全断言:命中的账号必须全部在白名单里(防止 WHERE 写错波及别人)
$hit = $users->pluck('username')->all();
$unexpected = array_diff($hit, SMOKE_USERNAMES);
if ($unexpected) {
    fwrite(STDERR, "拒绝执行:命中了白名单之外的账号:" . implode(', ', $unexpected) . "\n");
    exit(1);
}

// 审计只统计不删,让运行者看见「历史确实保留了」
$auditKept = $userIds ? DB::table('audit_logs')->whereIn('user_id', $userIds)->count() : 0;
echo "[0] 这些账号的 audit_logs 行数:{$auditKept}(append-only,保留不动)\n\n";

if (! $apply) {
    echo "预览结束,未执行任何删除。确认无误后加 --apply 重跑。\n";
    exit(0);
}

if (! $userIds) {
    echo "没有命中任何账号,无需清理。\n";
    exit(0);
}

// ---- 执行:全部包在一个事务里,任何一步失败整体回滚 ----

$affected = [];

DB::transaction(function () use ($userIds, $cityIds, &$affected) {
    // 顺序 = 外键依赖的反序:先删所有挂在 city 上的子表,再删 cities,最后删 users。
    // 这 10 张 city_* 是按 information_schema 的外键清单逐一核对出来的(2026-08-16),
    // 新增模块若再挂表到 cities,这里要跟着补 —— 漏一张就会撞外键约束整体回滚(Fail Closed,不会删半截)
    $cityTables = [
        'city_active_modifiers',
        'city_building_instances',
        'city_events',
        'city_event_cooldowns',
        'city_items',
        'city_market_orders',
        'city_market_quota',
        'city_npcs',
        'city_resources',
        'city_technologies',
    ];

    foreach ($cityTables as $table) {
        $affected[$table] = $cityIds
            ? DB::table($table)->whereIn('city_id', $cityIds)->delete()
            : 0;
    }

    $affected['cities'] = $cityIds ? DB::table('cities')->whereIn('id', $cityIds)->delete() : 0;

    // 无外键、按 user_id 存的两张表(删城不会级联到它们)
    $affected['idempotency_keys'] = DB::table('idempotency_keys')->whereIn('user_id', $userIds)->delete();
    $affected['sessions'] = DB::table('sessions')->whereIn('user_id', $userIds)->delete();

    $affected['users'] = DB::table('users')->whereIn('id', $userIds)->delete();
});

$step = 1;
foreach ($affected as $table => $rows) {
    echo "[{$step}] {$table}:删除 {$rows} 行\n";
    $step++;
}

// ---- 收尾复核 ----

$leftUsers = DB::table('users')->whereIn('username', SMOKE_USERNAMES)->count();
$orphanResources = DB::table('city_resources')->whereNotIn('city_id', DB::table('cities')->select('id'))->count();
$orphanBuildings = DB::table('city_building_instances')->whereNotIn('city_id', DB::table('cities')->select('id'))->count();

echo "\n[复核] 残留冒烟账号:{$leftUsers}(应为 0)\n";
echo "[复核] 孤儿 city_resources 行:{$orphanResources}(应为 0)\n";
echo "[复核] 孤儿 city_building_instances 行:{$orphanBuildings}(应为 0)\n";
echo '[复核] audit_logs 总行数:' . DB::table('audit_logs')->count() . "(未被本脚本改动)\n";
echo '[复核] 剩余账号数:' . DB::table('users')->count() . "\n";

echo "\n完成。\n";
