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

    // map 型契约字段的统一包装:**空时也必须序列化成 `{}` 而不是 `[]`**(M3-W7)。
    //
    // 病根是 PHP 的 json_encode:关联数组在有键时编成对象、在**空**时编成数组 ——
    // 同一个字段于是有了两种 JSON 形状(`{"3":[7]}` vs `[]`),前端每个读它的地方都得写一次
    // 「先判断是不是数组」的兼容分支(public/game/js/ui/npc-panel.js 里就有这么一处),
    // 漏写一处就是「没派人时面板直接报错」这种只在空态复现的 bug。
    //
    // 纪律:**只包 map 型**(键有业务含义:资源 code / 建筑实例 id / 资源 id → 值),
    // **列表型一律保持数组**(buildings / list / active 这类有序集合,空时就该是 `[]`)。
    // 包错方向同样有害:把列表变成对象会让前端的 `.map()` 当场失效。
    public static function map(array $map): object
    {
        return (object) $map;
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
