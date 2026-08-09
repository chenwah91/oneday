<?php
// 环境冒烟检查:配置可读、数据库可连
require dirname(__DIR__) . '/app/core/bootstrap.php';
$cfg = App::config();
echo 'env=' . $cfg['env'] . PHP_EOL;
echo 'db=' . Db::get()->query('SELECT VERSION()')->fetchColumn() . PHP_EOL;
echo 'OK' . PHP_EOL;
