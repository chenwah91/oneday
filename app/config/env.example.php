<?php
// 环境配置样板:复制为 env.php 并按环境修改;env.php 不入 git
return [
    'env' => 'test',                    // test | prod
    'app_debug' => true,                // prod 必须 false
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'apg_dev',
        'user' => 'root',
        'pass' => '',
    ],
    'db_test' => [                      // 单元测试专用库,每次跑测试会清空重建!
        'host' => '127.0.0.1',
        'name' => 'apg_test',
        'user' => 'root',
        'pass' => '',
    ],
    'token_ttl_days' => 30,
];
