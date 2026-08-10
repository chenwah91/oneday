<?php

namespace App\Support;

use RuntimeException;

// 游戏规则/安全校验失败:带稳定错误码与 HTTP 状态。
// 由 bootstrap/app.php 的全局异常 render 统一转 ApiResponse,业务层只管抛,不用每个 Controller 写 try/catch。
class GameRuleException extends RuntimeException
{
    // $details:可选的结构化补充说明,原样并进错误响应的 details 字段(键名一律 snake_case)。
    // 用途是「拒绝理由需要逐项列清单」的场景 —— 如时代升级 ERA_REQUIRED 要告诉前端
    // 每一维的需求值/当前值/是否满足。默认空数组 = 响应结构与从前完全一致,不影响既有错误码。
    // 注意:details 会直接发给客户端,只放玩家本来就能看到的游戏状态,绝不放内部实现细节。
    public function __construct(public string $errorCode, public int $status = 422, public array $details = [])
    {
        parent::__construct($errorCode);
    }
}
