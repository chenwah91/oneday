<?php
// API 单入口:路由经 ?r= 参数分发;所有异常写日志并返回统一错误
require dirname(__DIR__, 2) . '/app/core/bootstrap.php';

$route  = $_GET['r'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($method . ' ' . $route) {
        case 'POST auth/register':
            $res = AuthService::register(
                (string)($body['username'] ?? ''),
                (string)($body['password'] ?? ''),
                isset($body['email']) ? (string)$body['email'] : null
            );
            if (isset($res['error'])) {
                Response::fail($res['error'], ErrorText::of($res['error']));
            }
            Response::ok($res);
            // Response 内部 exit,不会落到下一 case

        case 'POST auth/login':
            $res = AuthService::login(
                (string)($body['username'] ?? ''),
                (string)($body['password'] ?? ''),
                $_SERVER['REMOTE_ADDR'] ?? ''
            );
            if (isset($res['error'])) {
                $status = ($res['error'] === 'TOO_MANY_ATTEMPTS') ? 429 : 401;
                Response::fail($res['error'], ErrorText::of($res['error']), $status);
            }
            Response::ok($res);

        case 'GET me':
            $pid = Auth::requirePlayerId();
            Response::ok(['player_id' => $pid]);

        default:
            Response::fail('NOT_FOUND', ErrorText::of('NOT_FOUND'), 404);
    }
} catch (Throwable $e) {
    Logger::error($e->getMessage(), ['route' => $route, 'file' => $e->getFile(), 'line' => $e->getLine()]);
    if (App::config()['app_debug']) {
        Response::fail('SERVER_ERROR', $e->getMessage(), 500);
    }
    Response::fail('SERVER_ERROR', ErrorText::of('SERVER_ERROR'), 500);
}
