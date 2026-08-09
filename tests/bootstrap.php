<?php
// 测试引导:切换到测试库(apg_test),提供断言与 schema 重建
$GLOBALS['__db_cfg_key'] = 'db_test'; // 必须在加载 bootstrap 前于 Db 首次使用前设置
require dirname(__DIR__) . '/app/core/bootstrap.php';

$GLOBALS['__tests_passed'] = 0;
$GLOBALS['__tests_failed'] = 0;

function assert_true(bool $cond, string $label): void {
    if ($cond) {
        $GLOBALS['__tests_passed']++;
        echo "  PASS  $label\n";
    } else {
        $GLOBALS['__tests_failed']++;
        echo "  FAIL  $label\n";
    }
}

function assert_eq($expected, $actual, string $label): void {
    $same = ($expected == $actual);
    if (!$same) {
        $label .= sprintf('(期望 %s,实际 %s)', var_export($expected, true), var_export($actual, true));
    }
    assert_true($same, $label);
}

// 清空并按 sql/*.sql 重建测试库 schema(只操作 apg_test!)
function reset_schema(): void {
    $db = Db::get();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $db->exec("DROP TABLE IF EXISTS `$t`");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (glob(dirname(__DIR__) . '/sql/*.sql') as $file) {
        $sqlText = file_get_contents($file);
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sqlText))) as $stmt) {
            $db->exec($stmt);
        }
    }
}
