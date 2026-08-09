<?php

namespace App\Support;

use RuntimeException;

// 游戏规则/安全校验失败:带稳定错误码与 HTTP 状态。
// 由 bootstrap/app.php 的全局异常 render 统一转 ApiResponse,业务层只管抛,不用每个 Controller 写 try/catch。
class GameRuleException extends RuntimeException
{
    public function __construct(public string $errorCode, public int $status = 422)
    {
        parent::__construct($errorCode);
    }
}
