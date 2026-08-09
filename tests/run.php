<?php
// 一键跑所有测试:php tests/run.php
require __DIR__ . '/bootstrap.php';
foreach (glob(__DIR__ . '/test_*.php') as $file) {
    echo basename($file) . "\n";
    require $file;
}
printf("\n通过 %d,失败 %d\n", $GLOBALS['__tests_passed'], $GLOBALS['__tests_failed']);
exit($GLOBALS['__tests_failed'] > 0 ? 1 : 0);
