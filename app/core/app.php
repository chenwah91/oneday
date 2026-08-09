<?php
// 应用配置:加载 env.php,内部时间统一 UTC(显示层再转 Asia/Kuala_Lumpur)
final class App {
    private static ?array $config = null;

    public static function config(): array {
        if (self::$config === null) {
            self::$config = require dirname(__DIR__) . '/config/env.php';
            date_default_timezone_set('UTC');
        }
        return self::$config;
    }
}
