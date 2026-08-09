<?php

namespace App\Game\Building;

use RuntimeException;

// 游戏规则/安全校验失败:带稳定错误码与 HTTP 状态,供控制器统一转 ApiResponse
class GameRuleException extends RuntimeException
{
    public function __construct(public string $errorCode, public int $status = 422)
    {
        parent::__construct($errorCode);
    }
}
