<?php

namespace App\Http\Controllers\City;

use App\Game\Resource\ResourceCode;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// 定义只读:可建建筑列表(带 L1 成本/产出)、资源定义(code → 中文显示名)、科技定义
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
            // era_order 一并返回(M2-B6):前端建造面板要拿它与快照的 city.era.era_order 比较,
            // 把超时代的建筑提前置灰。否则前端得自己维护一张「时代 → 序号」表(§13:序号只在 era 表里有一份)
            ->join('era as e', 'bd.era_key', '=', 'e.era_key')
            ->select('bd.building_id', 'bd.name', 'bd.category', 'bd.era_key', 'e.era_order', 'bd.max_count',
                'bd.footprint_w', 'bd.footprint_h', 'bl.cost_json', 'bl.output_json')
            ->orderBy('bd.building_id')
            ->get()
            ->map(fn ($r) => [
                'building_id' => $r->building_id,
                'name'        => $r->name,
                'category'    => $r->category,
                'era'         => $r->era_key,
                'era_order'   => (int) $r->era_order,
                'max_count'   => (int) $r->max_count,
                'footprint'   => ['w' => (int) $r->footprint_w, 'h' => (int) $r->footprint_h],
                'level1'      => [
                    'cost'   => json_decode($r->cost_json, true),
                    'output' => json_decode($r->output_json, true),
                ],
            ])->all();

        return ApiResponse::ok(['data' => ['buildings' => $defs]]);
    }

    // 科技定义(M2-B1):50 个节点。name 是中文显示名(科技表本身就存中文名,不像资源那样只有 code)。
    // era_order 一并返回:前端要拿它与快照的 max_research_era_order 比较,判断节点是否被时代锁住,
    // 否则前端得自己再维护一张「时代 → 序号」表(§13 数据驱动:序号只在 era 表里有一份)
    public function technologies(): JsonResponse
    {
        $rows = DB::table('technology_definition as t')
            ->join('era as e', 't.era_key', '=', 'e.era_key')
            ->orderBy('e.era_order')
            ->orderBy('t.tech_id')
            ->get(['t.tech_id', 't.name', 't.branch', 't.era_key', 'e.era_order',
                't.knowledge_cost', 't.research_minutes', 't.prerequisite_tech_ids', 't.unlock_building_ids'])
            ->map(fn ($r) => [
                'tech_id'             => $r->tech_id,
                'name'                => $r->name,
                'branch'              => $r->branch,
                'era'                 => $r->era_key,
                'era_order'           => (int) $r->era_order,
                // cost 与建筑定义同构(资源 code => 数量),将来科技加别的成本资源不用改前端
                'cost'                => [ResourceCode::KNOWLEDGE => (int) $r->knowledge_cost],
                'duration_minutes'    => (float) $r->research_minutes,
                'prerequisites'       => json_decode($r->prerequisite_tech_ids ?: '[]', true) ?: [],
                'unlock_building_ids' => json_decode($r->unlock_building_ids ?: '[]', true) ?: [],
            ])->all();

        return ApiResponse::ok(['data' => ['technologies' => $rows]]);
    }
}
