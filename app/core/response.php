<?php
// 统一 JSON 响应格式:{ok:true,data} / {ok:false,error_code,message}
final class Response {
    public static function ok($data = null): never {
        self::send(['ok' => true, 'data' => $data]);
    }

    public static function fail(string $errorCode, string $message, int $status = 400): never {
        http_response_code($status);
        self::send(['ok' => false, 'error_code' => $errorCode, 'message' => $message]);
    }

    private static function send(array $payload): never {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
