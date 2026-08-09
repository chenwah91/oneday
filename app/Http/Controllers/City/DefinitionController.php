<?php

namespace App\Http\Controllers\City;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// 定义只读:可建建筑列表(带 L1 成本/产出)
class DefinitionController extends Controller
{
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
                'buildingId' => $r->building_id,
                'name'       => $r->name,
                'category'   => $r->category,
                'era'        => $r->era_key,
                'maxCount'   => (int) $r->max_count,
                'footprint'  => ['w' => (int) $r->footprint_w, 'h' => (int) $r->footprint_h],
                'level1'     => [
                    'cost'   => json_decode($r->cost_json, true),
                    'output' => json_decode($r->output_json, true),
                ],
            ])->all();

        return ApiResponse::ok(['data' => ['buildings' => $defs]]);
    }
}
