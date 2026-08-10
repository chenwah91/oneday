<?php

namespace App\Http\Controllers\City;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// 定义只读:可建建筑列表(带 L1 成本/产出)、资源定义(code → 中文显示名)
// 契约字段一律 snake_case 全小写(用户 2026-08-10 拍板)
class DefinitionController extends Controller
{
    // 资源定义:前端把 resource code 翻成中文显示名的唯一来源
    // (资源主键是英文 code,中文名只存在 resource_definition.name,见 docs/templates/resource-code-map.md)
    public function resources(): JsonResponse
    {
        $rows = DB::table('resource_definition')
            ->select('resource_id', 'name', 'rs_code', 'category', 'first_era')
            ->orderBy('resource_id')
            ->get()
            ->map(fn ($r) => [
                'code'     => $r->resource_id,
                'name'     => $r->name,
                'rs_code'  => $r->rs_code,
                'category' => $r->category,
                'era'      => $r->first_era,
            ])->all();

        return ApiResponse::ok(['data' => ['resources' => $rows]]);
    }

    public function buildings(): JsonResponse
    {
        $defs = DB::table('building_definition as bd')
            ->join('building_level_definition as bl', function ($j) {
                $j->on('bd.building_id', '=', 'bl.building_id')->where('bl.level', '=', 1);
            })
            ->select('bd.building_id', 'bd.name', 'bd.category', 'bd.era_key', 'bd.max_count',
                'bd.footprint_w', 'bd.footprint_h', 'bl.cost_json', 'bl.output_json')
            ->orderBy('bd.building_id')
            ->get()
            ->map(fn ($r) => [
                'building_id' => $r->building_id,
                'name'        => $r->name,
                'category'    => $r->category,
                'era'         => $r->era_key,
                'max_count'   => (int) $r->max_count,
                'footprint'   => ['w' => (int) $r->footprint_w, 'h' => (int) $r->footprint_h],
                'level1'      => [
                    'cost'   => json_decode($r->cost_json, true),
                    'output' => json_decode($r->output_json, true),
                ],
            ])->all();

        return ApiResponse::ok(['data' => ['buildings' => $defs]]);
    }
}
