<?php
// 错误日志:写文件,带时间与上下文;任何环境都不把错误细节直接回给用户
final class Logger {
    public static function error(string $message, array $context = []): void {
        $dir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] %s %s\n",
            gmdate('Y-m-d H:i:s'),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );
        file_put_contents($dir . '/error-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
