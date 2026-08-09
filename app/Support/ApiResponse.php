<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

// 统一 JSON 响应:成功 {success:true,...data};失败 {success:false,error,requestId,...extra}
final class ApiResponse
{
    // 成功响应:data 数组直接并入顶层(如 ['data'=>...]、['revision'=>...])
    public static function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true] + $data, $status);
    }

    // 失败响应:带稳定错误码与请求 ID,便于玩家截图后追查
    public static function fail(string $error, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json([
            'success'   => false,
            'error'     => $error,
            'requestId' => Context::get('request_id'),
        ] + $extra, $status);
    }
}
