<?php

namespace App\Http\Controllers\Admin;

use App\Game\Definition\GameDataVersion;
use App\Game\Market\MarketDefinition;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 后台调整建筑等级数值:allowlist 字段 + 强制 reason + 审计 + 版本递增
class AdminDefinitionController extends Controller
{
    // 可编辑字段 allowlist。
    // happiness_bonus / governance_bonus / defense_score 三项已于 V3.2.1 从表里物理删除
    // (与 output_json 双口径且不参与结算,后台改了等于没改),不得再加回来:
    // 幸福 / 治理 / 国防的真实数值一律走 output_json。
    private const EDITABLE = [
        'duration_seconds', 'worker_required',
        'maintenance_money_per_min', 'maintenance_food_per_min', 'maintenance_fuel_per_min', 'power_per_min',
        'capacity',
    ];

    // NPC 定义的可编辑字段(M3-D1,v3.2 §6.3)。
    //
    // 只放**数值**列:工资 / 口粮 / 初始技能值 / 初始等级 / 上限等级。
    // 刻意不放 rarity / category / primary_skill_id / recruit_source / trait_json ——
    // 那几列是「结构」不是「数值」:改 rarity 会同时改掉招募掷点权重与价格档位,
    // 改 primary_skill_id 会让岗位匹配整体换一套,改 trait_json 要重新过 ModifierSpec 的三重 allowlist。
    // 结构性调整走 Seed + 迁移(有 diff、可回滚),不给后台一个能一键改坏经济的入口。
    private const NPC_EDITABLE = [
        'wage_per_min', 'food_per_min',
        'initial_skill_value', 'initial_skill_level', 'max_level',
    ];

    public function buildingLevels(Request $request): JsonResponse
    {
        $buildingId = (string) $request->query('buildingId', '');
        $rows = DB::table('building_level_definition')->where('building_id', $buildingId)->orderBy('level')
            ->get(array_merge(['building_id', 'level'], self::EDITABLE));
        return ApiResponse::ok(['data' => ['levels' => $rows]]);
    }

    public function editBuildingLevel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'buildingId' => ['required', 'string', 'max:16'],
            'level'      => ['required', 'integer', 'between:1,3'],
            'field'      => ['required', 'string'],
            // value 不允许负数:所有 EDITABLE 字段(耗时/工人/维护/幸福度/治理/防御/容量)按设计均为非负,
            // 若放行负数,负的 maintenance_money_per_min 会让 SimulationService 的 max(0, money - rate*minutes) 变成无上限生钱
            'value'      => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80),避免超长原因导致写入审计时报错、事务回滚且不留痕
            'reason'     => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('building_level_definition')->where('building_id', $data['buildingId'])->where('level', $data['level'])->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $before = $row->{$data['field']};
            DB::table('building_level_definition')->where('building_id', $data['buildingId'])->where('level', $data['level'])
                ->update([$data['field'] => $data['value']]);

            $version = GameDataVersion::bump(
                "调整 {$data['buildingId']} L{$data['level']} {$data['field']}: {$before} → {$data['value']}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'building_level_definition',
                'entity_id' => $data['buildingId'] . ':' . $data['level'],
                'reason_code' => $data['reason'],
                'before_json' => [$data['field'] => $before],
                'after_json' => [$data['field'] => $data['value']],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            return ['before' => $before, 'after' => $data['value'], 'version' => $version];
        });

        if ($result === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        return ApiResponse::ok(['data' => $result]);
    }

    // ---- NPC 定义(M3-D1)----

    // 列表:30 行原型的可编辑数值 + 只读的结构列(稀有度 / 来源 / 技能),供后台先看后改。
    // 30 行不分页:一屏看完才比较得出「这一档工资是不是偏了」
    public function npcs(): JsonResponse
    {
        $rows = DB::table('npc_definition')->orderBy('npc_id')->get(array_merge(
            ['npc_id', 'name_key', 'category', 'min_era', 'primary_skill_id', 'rarity', 'recruit_source', 'trait_desc_zh'],
            self::NPC_EDITABLE
        ));

        return ApiResponse::ok(['data' => ['npcs' => $rows, 'editable' => self::NPC_EDITABLE]]);
    }

    // 调整:与 editBuildingLevel 逐条同款(allowlist 字段 + 非负 + 强制 reason + 行锁 + 审计 + 版本递增)。
    // 改 NPC 数值同样会改变全服产出(工资进结算的支出通道、初始等级直接决定乘区),
    // 所以必须 bump game_data_version —— 否则半年后回查「他当时的工资为什么是 8」会查不出来(§64/§65)
    public function editNpc(Request $request): JsonResponse
    {
        $data = $request->validate([
            'npc_id' => ['required', 'string', 'max:16'],
            'field'  => ['required', 'string'],
            // 与建筑等级同一条理由:allowlist 里的五个字段按设计都是非负数,
            // 放行负数会让 wage_per_min 变成「雇一个人反而每分钟生钱」
            'value'  => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::NPC_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        // 等级类字段必须是 1~10 的整数(§6.2 曲线只有 10 级):
        // 填 0 或 3.5 会让 NpcBonus 查曲线时落空,静默变成「这个 NPC 没有加成」
        if (in_array($data['field'], ['initial_skill_level', 'max_level'], true)) {
            $level = (float) $data['value'];
            if ($level < 1 || $level > 10 || floor($level) !== $level) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                    'errors' => ['value' => ['等级必须是 1~10 的整数']],
                ]);
            }
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('npc_definition')->where('npc_id', $data['npc_id'])->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            $before = $row->{$data['field']};
            DB::table('npc_definition')->where('npc_id', $data['npc_id'])->update([$data['field'] => $data['value']]);

            $version = GameDataVersion::bump(
                "调整 {$data['npc_id']} {$data['field']}: {$before} → {$data['value']}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'npc_definition',
                'entity_id' => $data['npc_id'],
                'reason_code' => $data['reason'],
                'before_json' => [$data['field'] => $before],
                'after_json' => [$data['field'] => $data['value']],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            return ['before' => $before, 'after' => $data['value'], 'version' => $version];
        });

        if ($result === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // ---- 市场定义(M3-D3,v3.2 §8)----

    // 可编辑的**数值**列。
    //
    // 刻意不放 trade_mode / rs_code / market_category / first_era:
    // 改 trade_mode 等于「上市 / 退市」一种资源 —— 那是结构性变更(会让时代 X 的建筑瞬间可建或全锁),
    // 必须走 Seed + 迁移(有 diff、可回滚),不给后台一个能一键改坏经济的开关。
    // 全市场级的调节(手续费倍率 / 滑点系数 / 成交量上限 / 停市)在 game_settings 里,那边改一处影响全场;
    // 这里改的是**单个资源**的基准数值。两套入口互不重叠,同一个数不会有两个来源。
    private const MARKET_EDITABLE = [
        'base_price', 'min_price', 'max_price',
        'volatility', 'elasticity', 'fee_rate', 'base_liquidity',
    ];

    // 逐字段的合理上限。下限统一是 0(validate 里的 min:0),这里补的是上限 ——
    // 每一条都对应一种「填错就打穿经济」的具体后果,注释写清楚,免得以后有人当成随手拍的数字放宽
    private const MARKET_FIELD_MAX = [
        // 基础价:1e6 已经远超终局的 980,再高会让单笔成交额溢出 DECIMAL(18,4) 的金额列
        'base_price'     => 1000000,
        'min_price'      => 1000000,
        'max_price'      => 1000000,
        // 波动率 1.0 = ±100%:再高会让目标价乘出负数(夹取虽然兜得住,但价格会长期贴着边界抖)
        'volatility'     => 1,
        // 弹性 10:§8 原值 0.75,给 10 倍余量做实验;再高会让轻微供需失衡就把价格顶到夹取边界
        'elasticity'     => 10,
        // 费率 0.9:≥1 时卖出会变成倒贴钱,那不是手续费而是没收
        'fee_rate'       => 0.9,
        // 流动性 1e9:它同时是滑点分母与成交量上限的基数
        'base_liquidity' => 1000000000,
    ];

    // 列表:26 行的全部数值 + 只读结构列,供后台先看后改。
    // 26 行不分页 —— 市场调价必须横向比较(「铁比铜贵这么多合理吗」),一屏看完才比得出来
    public function marketDefinitions(): JsonResponse
    {
        $rows = DB::table('market_definition')->orderBy('rs_code')->get(array_merge(
            ['resource_id', 'rs_code', 'market_category', 'first_era', 'trade_mode', 'note'],
            self::MARKET_EDITABLE
        ));

        return ApiResponse::ok(['data' => ['market' => $rows, 'editable' => self::MARKET_EDITABLE]]);
    }

    // 调整:与 editBuildingLevel / editNpc 逐条同款
    //(allowlist 字段 + 范围校验 + 强制 reason + 行锁 + 审计 + 版本递增)。
    //
    // 必须 bump game_data_version:market_definition 在 CHECKSUM_TABLES 里,
    // 改一行基础价就等于改了全服价格,半年后要能回答「那时的铁为什么是这个价」(§64 / §65)。
    public function editMarketDefinition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resource_code' => ['required', 'string', 'max:32'],
            'field'         => ['required', 'string'],
            // 与建筑等级 / NPC 同一条理由:allowlist 里的七个字段按设计都是非负数。
            // 负的 base_price 会让「买入」变成给玩家发钱,负的 fee_rate 会让往返套利立刻转正
            'value'         => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason'        => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::MARKET_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        $value = (float) $data['value'];
        if ($value > self::MARKET_FIELD_MAX[$data['field']]) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['超出该字段允许的上限 ' . self::MARKET_FIELD_MAX[$data['field']]]],
            ]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('market_definition')->where('resource_id', $data['resource_code'])->lockForUpdate()->first();
            if (! $row) {
                return 'not_found';
            }

            $before = $row->{$data['field']};

            // 改完之后整行必须仍然自洽 —— 逐字段单独校验拦不住「跨字段」的坏组合:
            //   min_price > max_price   → 夹取区间为空,价格会跳到一个说不清的值;
            //   base_price = 0(现货)  → 成交额恒为 0,等于免费无限领取该资源。
            // 所以在同一个事务里先算出改后的整行,自洽才提交
            $after = (array) $row;
            $after[$data['field']] = $value;

            if ((float) $after['min_price'] > (float) $after['max_price']) {
                return 'min_over_max';
            }
            if ($after['trade_mode'] === MarketDefinition::TRADE_MODE_SPOT && (float) $after['base_price'] <= 0) {
                return 'zero_base_price';
            }

            DB::table('market_definition')->where('resource_id', $data['resource_code'])->update([$data['field'] => $value]);

            $version = GameDataVersion::bump(
                "调整市场 {$data['resource_code']} {$data['field']}: {$before} → {$value}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'market_definition',
                'entity_id' => $data['resource_code'],
                'reason_code' => $data['reason'],
                'before_json' => [$data['field'] => $before],
                'after_json' => [$data['field'] => $value],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            return ['before' => $before, 'after' => $value, 'version' => $version];
        });

        if ($result === 'not_found') {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        if ($result === 'min_over_max') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['改动会让 min_price 超过 max_price,价格夹取区间将为空']],
            ]);
        }
        if ($result === 'zero_base_price') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['现货资源的 base_price 必须大于 0,否则该资源会变成免费']],
            ]);
        }

        // 定义有请求级缓存,改完必须失效 —— 否则同一请求后续的定价还在用旧数值
        MarketDefinition::flush();

        return ApiResponse::ok(['data' => $result]);
    }
}
