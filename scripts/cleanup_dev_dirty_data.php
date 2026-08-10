<?php

/**
 * 开发库脏数据清理(一次性脚本,留仓库供追溯)。
 *
 * 背景:docs/STATUS.md「待用户拍板 §6 开发库脏数据清理」,用户 2026-08-10 授权执行。
 *
 * 清理范围(**只动开发库 apg,不动测试库、更不动线上**):
 *   1. 四个实测账号 uitest0810a / techsmoke / m2e2e0810 / m2admin0810,
 *      连同它们的城市与运行时关联行(city_technologies / city_building_instances /
 *      city_resources / cities / idempotency_keys / users);
 *   2. city 4(chenwah91)的 governance_capacity 脏行 —— 治理容量是**容量类产出**,
 *      按设计根本不该出现在 city_resources(库存资源表),是早期手工调试塞进去的。
 *
 * 明确不动的东西:
 *   - audit_logs:append-only(CLAUDE §58),历史就是历史,账号删了审计照样留着;
 *   - F02 的 worker_required = 6(4 → 6 的手改):用户指示保留不动;
 *   - sessions / cache 等框架表:只统计不删,避免超出授权范围。
 *
 * 安全纪律:
 *   - 每一条 DELETE 都带 WHERE 精确圈定(CLAUDE 红线:禁止无 WHERE 的 DELETE);
 *   - 先 SELECT 打印将要删除的行,再删,并逐步打印影响行数;
 *   - 整体包在一个事务里,任何一步抛异常就整体回滚;
 *   - 库名不是 apg 直接拒绝执行(防止手滑连到测试库/线上库)。
 *
 * 用法:
 *   C:/xampp/php/php.exe scripts/cleanup_dev_dirty_data.php          # 只预览(dry-run),不删
 *   C:/xampp/php/php.exe scripts/cleanup_dev_dirty_data.php --apply  # 真正执行
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ---- 参数与安全闸门 ----

$apply = in_array('--apply', $argv ?? [], true);
$database = (string) DB::connection()->getDatabaseName();

// 只允许开发库
const ALLOWED_DATABASE = 'apg';

// 要清理的实测账号
const DIRTY_USERNAMES = ['uitest0810a', 'techsmoke', 'm2e2e0810', 'm2admin0810'];

// 要清理的脏资源行:city 4 的治理容量(容量类产出不该进 city_resources)
const DIRTY_RESOURCE_CITY_ID = 4;
const DIRTY_RESOURCE_ID = 'governance_capacity';

echo "== 开发库脏数据清理 ==\n";
echo '当前库:' . $database . ($apply ? '  模式:执行(--apply)' : '  模式:预览(dry-run)') . "\n\n";

if ($database !== ALLOWED_DATABASE) {
    fwrite(STDERR, "拒绝执行:本脚本只允许在开发库 `" . ALLOWED_DATABASE . "` 上运行,当前连的是 `{$database}`。\n");
    exit(1);
}

// ---- 第 0 步:先看清楚要删什么 ----

$users = DB::table('users')->whereIn('username', DIRTY_USERNAMES)->get(['id', 'username', 'role']);
$userIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();

$cities = $userIds
    ? DB::table('cities')->whereIn('user_id', $userIds)->get(['id', 'user_id', 'name'])
    : collect();
$cityIds = $cities->pluck('id')->map(fn ($id) => (int) $id)->all();

echo "[0] 命中的账号(" . count($userIds) . " 个):\n";
foreach ($users as $u) {
    echo "    users#{$u->id}  {$u->username}  role={$u->role}\n";
}
echo "[0] 命中的城市(" . count($cityIds) . " 座):\n";
foreach ($cities as $c) {
    echo "    cities#{$c->id}  user_id={$c->user_id}\n";
}

$dirtyRow = DB::table('city_resources')
    ->where('city_id', DIRTY_RESOURCE_CITY_ID)
    ->where('resource_id', DIRTY_RESOURCE_ID)
    ->first();
echo '[0] city ' . DIRTY_RESOURCE_CITY_ID . ' 的 ' . DIRTY_RESOURCE_ID . ' 脏行:'
    . ($dirtyRow ? 'amount=' . $dirtyRow->amount : '不存在(已清理过)') . "\n";

// 审计只统计不删,让运行者看见「历史确实保留了」
$auditKept = $userIds ? DB::table('audit_logs')->whereIn('user_id', $userIds)->count() : 0;
echo "[0] 这些账号的 audit_logs 行数:{$auditKept}(append-only,保留不动)\n";

// 框架表里的残留:只报告,不在本次授权范围内
$sessionRows = $userIds ? DB::table('sessions')->whereIn('user_id', $userIds)->count() : 0;
echo "[0] 这些账号的 sessions 行数:{$sessionRows}(不在本次授权范围,保留不动)\n\n";

if (! $apply) {
    echo "预览结束,未执行任何删除。确认无误后加 --apply 重跑。\n";
    exit(0);
}

// ---- 执行:全部包在一个事务里,任何一步失败整体回滚 ----

$affected = [];

DB::transaction(function () use ($userIds, $cityIds, &$affected) {
    // 顺序 = 外键依赖的反序:先删子表(city_* → cities),再删 users
    if ($cityIds) {
        $affected['city_technologies'] = DB::table('city_technologies')->whereIn('city_id', $cityIds)->delete();
        $affected['city_building_instances'] = DB::table('city_building_instances')->whereIn('city_id', $cityIds)->delete();
        $affected['city_resources'] = DB::table('city_resources')->whereIn('city_id', $cityIds)->delete();
        $affected['cities'] = DB::table('cities')->whereIn('id', $cityIds)->delete();
    } else {
        $affected['city_technologies'] = 0;
        $affected['city_building_instances'] = 0;
        $affected['city_resources'] = 0;
        $affected['cities'] = 0;
    }

    // idempotency_keys 按 user_id 圈定(该表没有外键,删城不会级联)
    $affected['idempotency_keys'] = $userIds
        ? DB::table('idempotency_keys')->whereIn('user_id', $userIds)->delete()
        : 0;

    $affected['users'] = $userIds
        ? DB::table('users')->whereIn('id', $userIds)->delete()
        : 0;

    // city 4 的治理容量脏行:双条件精确圈定,不会波及其它城市 / 其它资源
    $affected['city_resources(governance_capacity)'] = DB::table('city_resources')
        ->where('city_id', DIRTY_RESOURCE_CITY_ID)
        ->where('resource_id', DIRTY_RESOURCE_ID)
        ->delete();
});

$step = 1;
foreach ($affected as $table => $rows) {
    echo "[{$step}] {$table}:删除 {$rows} 行\n";
    $step++;
}

// ---- 收尾复核 ----

$leftUsers = DB::table('users')->whereIn('username', DIRTY_USERNAMES)->count();
$leftDirty = DB::table('city_resources')
    ->where('city_id', DIRTY_RESOURCE_CITY_ID)->where('resource_id', DIRTY_RESOURCE_ID)->count();
$orphanResources = DB::table('city_resources')
    ->whereNotIn('city_id', DB::table('cities')->select('id'))->count();

echo "\n[复核] 残留实测账号:{$leftUsers}(应为 0)\n";
echo "[复核] 残留 " . DIRTY_RESOURCE_ID . " 脏行:{$leftDirty}(应为 0)\n";
echo "[复核] 孤儿 city_resources 行:{$orphanResources}(应为 0)\n";
echo "[复核] audit_logs 总行数:" . DB::table('audit_logs')->count() . "(未被本脚本改动)\n";
echo "[复核] F02 L1 worker_required:"
    . DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 1)->value('worker_required')
    . "(用户指示保留手改值,不回改)\n";

echo "\n完成。\n";
