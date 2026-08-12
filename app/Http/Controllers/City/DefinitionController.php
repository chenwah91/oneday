<?php

namespace App\Http\Controllers\City;

use App\Game\City\EraService;
use App\Game\Item\ItemDefinition;
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

    // NPC 定义(M3-W7,v3.2 §6.1 / §6.2 / §6.3):150 个原型 + 12 条技能 + 10 级曲线。
    // 招募池预览用 —— 招募本身**不接受 npc_id**(抽到谁由服务器掷点,§30 / §66),
    // 本端点只回答「这一版数值里都有些什么人、升级要多少 XP」。
    //
    // ══ 为什么**不**下发 trait_json 的 specs 结构 ═══════════════════════════════════
    // specs 是内核用来投稿 modifier 的内部表达(target / scope / op / value)。它下发出去有两害:
    //   ① 客户端不可信(§31 / §66):前端拿着 specs 只能自己再算一遍加成,而那份计算永远
    //      不可能与服务端的乘区 / 消费点口径完全一致 —— 于是「面板显示 +25%、实际产量没动」;
    //   ② 它是**内部结构**,target 名单随波次增删(W7 就动了两条),下发等于把它变成对外契约,
    //      以后改一个 target 名字就要发前端版本。
    // 展示用的中文描述(trait_desc_zh)由定义表逐行写好,信息量对玩家足够,且改文案不改契约。
    //
    // min_era_order 一并给出(与 buildings / technologies 两个端点同一条理由,§13):
    // 前端要拿它与快照的 city.era.era_order 比,把「还没到时代」的 NPC 置灰,
    // 否则前端得自己维护一张「时代 → 序号」表 —— 序号只在 era 表里有一份。
    public function npcs(): JsonResponse
    {
        $npcs = DB::table('npc_definition as nd')
            ->join('era as e', 'nd.min_era', '=', 'e.era_key')
            ->orderBy('nd.npc_id')
            ->get(['nd.npc_id', 'nd.name_key', 'nd.name_zh', 'nd.category', 'nd.rarity',
                'nd.min_era', 'e.era_order', 'nd.primary_skill_id',
                'nd.initial_skill_level', 'nd.initial_skill_value', 'nd.max_level',
                'nd.wage_per_min', 'nd.food_per_min',
                'nd.recruit_source', 'nd.recruit_desc_zh', 'nd.trait_desc_zh'])
            ->map(fn ($r) => [
                'npc_id'   => $r->npc_id,
                'name_key' => $r->name_key,
                // 中文名:N001~N030 仍为 null(拟名待用户拍板),前端遇到 null 回落 name_key。
                // 不在服务端编一个占位名 —— 编出来的名字会被当成正式名传播出去
                'name_zh'             => $r->name_zh,
                'category'            => $r->category,
                'rarity'              => $r->rarity,
                'min_era'             => $r->min_era,
                'min_era_order'       => (int) $r->era_order,
                'primary_skill_id'    => $r->primary_skill_id,
                'initial_skill_level' => (int) $r->initial_skill_level,
                'initial_skill_value' => (int) $r->initial_skill_value,
                'max_level'           => (int) $r->max_level,
                'wage_per_min'        => (float) $r->wage_per_min,
                'food_per_min'        => (float) $r->food_per_min,
                'recruit_source'      => $r->recruit_source,
                'recruit_desc_zh'     => $r->recruit_desc_zh,
                'trait_desc_zh'       => $r->trait_desc_zh,
            ])->all();

        // 技能表:primary_skill_id 是个 code,中文含义只存在 npc_skill_definition 这一处
        //(与 /api/definitions/resources 存在的理由逐字相同:code → 显示名的唯一来源)
        $skills = DB::table('npc_skill_definition')
            ->orderBy('skill_id')
            ->get(['skill_id', 'name_key', 'effect_desc_zh'])
            ->map(fn ($r) => [
                'skill_id'       => $r->skill_id,
                'name_key'       => $r->name_key,
                'effect_desc_zh' => $r->effect_desc_zh,
            ])->all();

        // 等级曲线(§6.2):**全局定义**,不随 NPC 变,所以放在这里而不是逐个 NPC 重复 10 行。
        // xp_to_next 是**增量**不是累计(10 级为 0 = 满级);前端的经验条分母就取它,
        // 不下发的话前端只能硬编码一份曲线 —— 后台改了数值就会两套真相
        $curve = DB::table('npc_skill_level_curve')
            ->orderBy('level')
            ->get(['level', 'xp_to_next', 'primary_bonus', 'maintenance_reduction_cap'])
            ->map(fn ($r) => [
                'level'                    => (int) $r->level,
                'xp_to_next'               => (int) $r->xp_to_next,
                'primary_bonus'            => (float) $r->primary_bonus,
                'maintenance_reduction_cap' => (float) $r->maintenance_reduction_cap,
            ])->all();

        return ApiResponse::ok(['data' => [
            'npcs'        => $npcs,
            'skills'      => $skills,
            'level_curve' => $curve,
        ]]);
    }

    // 工具定义(R1-B,v3.2 §7 的 24 行):玩家侧制作目录。
    //
    // 补的是与 npcs 同一类的硬缺口:制作必须提交 item_id(§7 是配方合成不是抽奖),
    // 而「有哪些工具 / 材料成本多少 / 要什么时代与制作建筑」三样只在 item_definition 里,
    // 玩家侧一个都读不到(/api/admin/definitions/items 是 edit_definition 权限的后台端点)。
    // 前端因此只能把制作区降级成一句缺口提示 —— 本端点点亮它。
    //
    // ══ 为什么**不**下发 effect_json 的 specs 结构 ═══════════════════════════════
    // 与 npcs() 的 trait_json 逐字同一条理由:specs 是内核投稿 modifier 的内部表达
    //(target / scope / op / value / scope_key),下发出去 ① 客户端拿它自己算加成,
    // 永远算不出与服务端乘区口径一致的数;② 它是内部结构,target 名单随波次增删,
    // 下发等于把它变成对外契约。展示信息由 effect_code + effect_value + unit 三列给足
    //(§7 原文三列,前端 enum-names.js 把 code 翻成中文,数值一律用服务器下发的)。
    // 同理不下发 unmapped_zh / crafting_unmapped_zh:那两列是「本波没映射上」的内部排查记录。
    //
    // trade_value 也不下发:B5 已批 M3 不做工具交易,这一列当前只是将来「拆解返还」的基数,
    // 下发就等于对玩家承诺一个不存在的卖出价。
    //
    // min_era_order 一并给出(与 buildings / technologies / npcs 三个端点同一条理由,§13):
    // 前端要拿它与快照的 city.era.era_order 比,把「还没到时代」的工具置灰,
    // 否则前端得自己维护一张「时代 → 序号」表 —— 序号只在 era 表里有一份。
    //
    // 读表走 ItemDefinition::all():该类的文件头写死了「全项目只有这里读 item_definition」,
    // 这里再自己拼一次 SQL 就会让 craft_cost_json 的解析口径出现第二份
    //(它会丢掉 ≤0 的脏项,与制作路径判「定义损坏」的口径必须一致)
    public function items(): JsonResponse
    {
        $orders = EraService::orders();

        $items = array_values(array_map(fn ($def) => [
            'item_id'  => $def['item_id'],
            'name_key' => $def['name_key'],
            // §7 里工具**没有中文名**,只有 name_key 与 equip_target_desc_zh(伐木工 / 猎人…)。
            // 后者是工具唯一的中文显示成分,前端的显示名 = 类别(装备对象);
            // 下发它就是为了让前端删掉临时补位的 ITEM_EQUIP_TARGET_NAMES 小表(该表注释已写明)
            'equip_target_desc_zh' => $def['equip_target_desc_zh'],
            // §7 明文「单建筑同类加成只取最高值」就是按 category 分组的,前端要拿它提示玩家
            'category'      => $def['category'],
            'min_era'       => $def['min_era'],
            'min_era_order' => (int) ($orders[$def['min_era']] ?? 0),
            // 耐久上限(点);运行时的剩余耐久在快照的 items 块,不在定义里
            'durability'      => (int) $def['durability'],
            'durability_tier' => $def['durability_tier'],
            // work_minutes / uses:medical_item 是一次性消耗品,玩家要在做之前就看得出来
            'durability_mode' => $def['durability_mode'],
            'effect_code'     => $def['effect_code'],
            'effect_value'    => (float) $def['effect_value'],
            'unit'            => $def['unit'],
            // 材料成本 {资源 code => 数量}(与建筑 level1.cost 同构:资源 code 的中文名
            // 一律由 /api/definitions/resources 翻,不在这里重复一份显示名)
            'craft_cost' => $def['craft_cost'],
            // 制作来源:building_id 有值才是真闸门(ItemService::craft 只校验这一列);
            // 为空的两类(手工制作 / §7 点名的建筑不在 94 栋内)不设建筑门槛,
            // 前端照 desc 原文显示即可 —— 两者的区别对玩家没有意义,对玩家只有「要不要先建楼」
            'crafting_source_desc_zh' => $def['crafting_source_desc_zh'],
            'crafting_building_id'    => $def['crafting_building_id'],
            // §7 的 note_zh:一句中文补充说明(「消耗型道具」「终局专属」),仅供显示
            'note' => $def['note'],
        ], ItemDefinition::all()));

        return ApiResponse::ok(['data' => ['items' => $items]]);
    }
}
