<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

// 统一 JSON 响应:成功 {success:true,...data};失败 {success:false,error,request_id,...extra}
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板),前端按同名读取
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
            'success'    => false,
            'error'      => $error,
            'request_id' => Context::get('request_id'),
        ] + $extra, $status);
    }
}
