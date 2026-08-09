<?php

namespace App\Http\Controllers\City;

use App\Game\Building\BuildService;
use App\Game\Building\GameRuleException;
use App\Game\City\CityFactory;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 建造入口:校验意图 → BuildService → 统一响应
class BuildController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'buildingId'       => ['required', 'string', 'max:16'],
            'x'                => ['required', 'integer', 'min:0', 'max:999'],
            'y'                => ['required', 'integer', 'min:0', 'max:999'],
            'idempotencyKey'   => ['nullable', 'string', 'max:100'],
            'expectedRevision' => ['nullable', 'integer'],
        ]);

        $city = CityFactory::createForUser($request->user());

        try {
            $diff = BuildService::build(
                $city, $data['buildingId'], (int) $data['x'], (int) $data['y'],
                $data['idempotencyKey'] ?? null,
                isset($data['expectedRevision']) ? (int) $data['expectedRevision'] : null
            );
        } catch (GameRuleException $e) {
            return ApiResponse::fail($e->errorCode, $e->status);
        }

        return ApiResponse::ok(['data' => $diff]);
    }
}
