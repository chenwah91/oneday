<?php
// PDO 连接工厂(单例)。测试框架在首次调用前设 $GLOBALS['__db_cfg_key']='db_test' 切换到测试库
final class Db {
    private static ?PDO $pdo = null;

    public static function get(): PDO {
        if (self::$pdo === null) {
            $key = $GLOBALS['__db_cfg_key'] ?? 'db';
            $cfg = App::config()[$key];
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => true,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$pdo;
    }
}
