<?php

namespace App\Http\Controllers\Admin;

use App\Game\City\EraService;
use App\Game\Definition\GameDataVersion;
use App\Game\Event\EventDefinition;
use App\Game\Item\ItemDefinition;
use App\Game\Market\MarketDefinition;
use App\Game\Resource\ResourceCode;
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

    // 逐字段的合理上限(W11-B 补漏:这七列此前只有 min:0,上限完全裸奔)。
    // 下限统一是 0(validate 里的 min:0),这里补的是上限 —— 每一条都对应一种「填错就打穿」的具体后果
    private const BUILDING_FIELD_MAX = [
        // 建造耗时 7 天(604800 秒):§3 最长的一栋是 4 小时量级,留足实验余量;
        // 再长等于「这栋楼永远建不完」,而在建实例会一直占着地块与队列
        'duration_seconds'          => 604800,
        // 工人 10000:再多会让单栋楼吃光全城劳动力,「工人不足」从此不再是可解的约束
        'worker_required'           => 10000,
        // 三项维护费 1e6/分钟:与 base_price 同量级的上限,防止 DECIMAL(14,4) 的金额列溢出;
        // 真到这个量级时每分钟的支出已经超过全服总产出,城市只会瞬间破产
        'maintenance_money_per_min' => 1000000,
        'maintenance_food_per_min'  => 1000000,
        'maintenance_fuel_per_min'  => 1000000,
        // 耗电 1e6/分钟:同上,再高会让一栋楼吃掉全城装机容量,全城直接进限电
        'power_per_min'             => 1000000,
        // 容量 1e9:它是仓储 / 人口的上限基数,DECIMAL(14,2) 装得下,再高会溢出
        'capacity'                  => 1000000000,
    ];

    // building_level_definition 的三个 JSON 列(条目级编辑,见 editBuildingLevelJson 顶部说明)
    private const BUILDING_JSON_COLUMNS = ['output_json', 'input_json', 'cost_json'];

    // 逐列的数值上限。下限统一 0
    private const BUILDING_JSON_MAX = [
        // 产出 / 投入速率 1e6/分钟:§3 最高是两位数量级,留足实验余量;
        // 再高会让一栋楼一分钟填满仓库,产量帽(§13)与仓储上限双双失去意义
        'output_json' => 1000000,
        'input_json'  => 1000000,
        // 建造成本 1e7:§3 最贵的一栋是十万量级,留 100 倍余量;再高会顶爆资源列的量纲
        'cost_json'   => 10000000,
    ];

    // NPC 定义的可编辑字段(M3-D1,v3.2 §6.3)。
    //
    // 只放**数值**列:工资 / 口粮 / 初始技能值 / 初始等级 / 上限等级。
    // 刻意不放 rarity / category / primary_skill_id / recruit_source / trait_json ——
    // 那几列是「结构」不是「数值」:改 rarity 会同时改掉招募掷点权重与价格档位,
    // 改 primary_skill_id 会让岗位匹配整体换一套,改 trait_json 要重新过 ModifierSpec 的三重 allowlist。
    // 结构性调整走 Seed + 迁移(有 diff、可回滚),不给后台一个能一键改坏经济的入口。
    //
    // W11-B 追加 trait_multiplier(特性强度倍率):它正好补上「trait_json 不可编辑」留下的那个洞 ——
    // 运营真正想调的是「这位 NPC 的特性是不是太强」,而不是特性的结构。倍率满足前者,
    // 结构仍然锁在 Seed + 迁移那条有 diff、可回滚的路上(见 NpcTraitScale 顶部的口径说明)。
    private const NPC_EDITABLE = [
        'wage_per_min', 'food_per_min',
        'initial_skill_value', 'initial_skill_level', 'max_level',
        'trait_multiplier',
    ];

    // 逐字段的合理上限(下限统一 0)。
    // 目前只登记 trait_multiplier —— 另外五列的上限是既有缺口,不在本波次范围内(已在交付汇报点名)
    private const NPC_FIELD_MAX = [
        // 强度 10 倍:再高会让单个 NPC 的一条特性直接顶爆 §6.4 的单人帽 1.60 与 §13 的 2.75 总帽,
        // 顶爆之后倍率再怎么调都不会有任何变化 —— 运营会看到「改了没反应」,那是最难解释的一类问题。
        // 与 event_definition.effect_multiplier 同一个上限、同一条理由
        'trait_multiplier' => 10,
    ];

    public function buildingLevels(Request $request): JsonResponse
    {
        $buildingId = (string) $request->query('buildingId', '');
        // W11-2 补漏:①下发三个 JSON 列现值(building-level-json 条目编辑器要显示当前产量/配方/造价,
        // 此前后台只能借游戏侧端点看 L1 的两列);②与其余 8 个定义 GET 对齐,下发 editable 数组,
        // 前端不再用「行键名 − 主键列」兜底推导。JSON 列 decode 后下发(空列退化 {} 由前端处理)
        $rows = DB::table('building_level_definition')->where('building_id', $buildingId)->orderBy('level')
            ->get(array_merge(['building_id', 'level'], self::EDITABLE, ['output_json', 'input_json', 'cost_json']))
            ->map(function ($r) {
                foreach (['output_json', 'input_json', 'cost_json'] as $col) {
                    $r->{$col} = json_decode($r->{$col} ?? 'null', true);
                }
                return $r;
            });
        return ApiResponse::ok(['data' => ['levels' => $rows, 'editable' => self::EDITABLE]]);
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

        $value = (float) $data['value'];
        if ($value > self::BUILDING_FIELD_MAX[$data['field']]) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['超出该字段允许的上限 ' . self::BUILDING_FIELD_MAX[$data['field']]]],
            ]);
        }

        // 两个 int 列必须收整数:12.5 秒的工期 / 3.5 个工人写进 int 列会被静默截断,
        // 后台显示 12.5 而库里是 12 —— 与事件的「分钟类必须整数」同一条理由
        if (in_array($data['field'], ['duration_seconds', 'worker_required'], true) && floor($value) !== $value) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['该字段必须是整数']],
            ]);
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

    // 建筑等级三个 JSON 列的**条目级**编辑(W11-B 任务1)。
    //
    // 为什么必须开这个入口:一栋楼的产出速率(output_json 的 rate_per_min)、投入速率、建造成本
    // 才是「这栋楼强不强」的真正数值 —— 而它们全在 JSON 列里,后台此前一个都改不了,
    // 能改的七列(工期 / 工人 / 维护 / 容量)反而都是外围。数值平衡的主战场没有入口,
    // 运营只能提工单等发版,这正是 §64「不要直接在生产库 UPDATE」最容易被违反的场景。
    //
    // 为什么只改**已存在条目的数值标量**,不许增删条目、不许改 resource 键:
    //   增删条目 = 改这栋楼产什么 / 吃什么,那是**结构性**变更(会让 §16.1 的资源来源链断链、
    //   让某个资源变成无源);改 resource 键等于同时做一次删除加一次新增。
    //   结构性调整走 Seed + 迁移(有 diff、可回滚),与 trade_mode / effect_json 同一条纪律。
    //
    // 写法照抄 editItem 的 rescaleEffectJson:decode → 只替换那一个数 → encode 回写,
    // **解析失败原样退出不写 null** —— 宁可这次改动不生效,也不能把一列结构化数据写成 null。
    public function editBuildingLevelJson(Request $request): JsonResponse
    {
        $data = $request->validate([
            'building_id' => ['required', 'string', 'max:16'],
            'level'       => ['required', 'integer', 'between:1,3'],
            'column'      => ['required', 'string'],
            // 条目定位键:output/input 是 specs 里的 resource 字段,cost 是 map 的键
            'resource'    => ['required', 'string', 'max:32'],
            // 与其它定义同一条理由:三列的数值按设计都是非负数。
            // 负的 rate_per_min 会让一栋楼「生产负木材」(= 凭空销毁库存),
            // 负的 cost 会让建造变成领资源
            'value'       => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason'      => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['column'], self::BUILDING_JSON_COLUMNS, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['column' => ['该列不可编辑']]]);
        }

        // resource 必须是登记在册的资源 code:allowlist 之外的键写进去就是一条**永远读不到**的配置
        //(结算内核按 ResourceCode 查表,查不到就静默跳过),与 modifier target 拼错是同一类坑
        if (! array_key_exists($data['resource'], ResourceCode::CHINESE_NAMES)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['resource' => ['未登记的资源 code']],
            ]);
        }

        $value = (float) $data['value'];
        if ($value > self::BUILDING_JSON_MAX[$data['column']]) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['超出该列允许的上限 ' . self::BUILDING_JSON_MAX[$data['column']]]],
            ]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('building_level_definition')
                ->where('building_id', $data['building_id'])->where('level', $data['level'])
                ->lockForUpdate()->first();
            if (! $row) {
                return 'not_found';
            }

            $rewritten = self::rewriteBuildingJsonEntry($row->{$data['column']}, $data['column'], $data['resource'], $value);
            if ($rewritten['status'] !== 'ok') {
                return $rewritten['status'];
            }

            DB::table('building_level_definition')
                ->where('building_id', $data['building_id'])->where('level', $data['level'])
                ->update([$data['column'] => $rewritten['json']]);

            // 审计的 before/after 用 `列.资源` 定位到**具体那一格**:
            // 只写 ['output_json' => 整段 JSON] 的话,半年后回查得靠人眼 diff 两段 JSON 找出改了哪个数
            $auditKey = $data['column'] . '.' . $data['resource'];

            $version = GameDataVersion::bump(
                "调整 {$data['building_id']} L{$data['level']} {$auditKey}: {$rewritten['before']} → {$value}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'building_level_definition',
                'entity_id' => $data['building_id'] . ':' . $data['level'],
                'reason_code' => $data['reason'],
                'before_json' => [$auditKey => $rewritten['before']],
                'after_json' => [$auditKey => $value],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            return ['before' => $rewritten['before'], 'after' => $value, 'version' => $version];
        });

        if ($result === 'not_found') {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        if ($result === 'entry_not_found') {
            // 与 404 分开:行是存在的,只是这一列里没有该资源的条目 ——
            // 新增条目是结构性变更,必须走迁移(见方法顶部说明)
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['resource' => ['该列中不存在此资源的条目;新增条目属结构性变更,请走迁移']],
            ]);
        }
        if ($result === 'invalid_json') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['column' => ['该列的 JSON 无法解析,已原样保留未做改动']],
            ]);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // 三个 JSON 列的条目改写(见 editBuildingLevelJson 里的调用点注释)。
    //
    // 两种形状各自处理,**只换那一个数**,其余字段与条目顺序原样保留:
    //   output_json / input_json = [{"resource":"wood","rate_per_min":8}, …]  → 换 rate_per_min
    //   cost_json                = {"wood":10,"money":6}                      → 换该键的值
    //
    // 返回 ['status' => ok|entry_not_found|invalid_json, 'before' => 旧值, 'json' => 新 JSON 串]。
    // 解析不出来 / 编码不出来时一律 invalid_json 且不返回 json:调用方据此原样退出,绝不写 null
    private static function rewriteBuildingJsonEntry(?string $json, string $column, string $resource, float $value): array
    {
        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return ['status' => 'invalid_json'];
        }

        if ($column === 'cost_json') {
            if (! array_key_exists($resource, $decoded)) {
                return ['status' => 'entry_not_found'];
            }
            $before = (float) $decoded[$resource];
            $decoded[$resource] = $value;
        } else {
            $index = null;
            foreach ($decoded as $i => $entry) {
                if (is_array($entry) && ($entry['resource'] ?? null) === $resource) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                return ['status' => 'entry_not_found'];
            }
            $before = (float) ($decoded[$index]['rate_per_min'] ?? 0);
            $decoded[$index]['rate_per_min'] = $value;
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return ['status' => 'invalid_json'];
        }

        return ['status' => 'ok', 'before' => $before, 'json' => $encoded];
    }

    // ---- NPC 定义(M3-D1)----

    // 列表:150 行原型的可编辑数值 + 只读的结构列(中文名 / 稀有度 / 来源 / 技能),供后台先看后改。
    // 不分页:全表一屏拉下来才比较得出「这一档工资是不是偏了」;
    // name_zh 一起给出来 —— 150 行里只靠 N087 这样的 code 认人太难(150 行现已逐条有名)
    public function npcs(): JsonResponse
    {
        $rows = DB::table('npc_definition')->orderBy('npc_id')->get(array_merge(
            ['npc_id', 'name_key', 'name_zh', 'category', 'min_era', 'primary_skill_id', 'rarity', 'recruit_source', 'trait_desc_zh'],
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

        if (isset(self::NPC_FIELD_MAX[$data['field']]) && (float) $data['value'] > self::NPC_FIELD_MAX[$data['field']]) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['超出该字段允许的上限 ' . self::NPC_FIELD_MAX[$data['field']]]],
            ]);
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

    // 可编辑列:七个**数值**列 + trade_mode(唯一一个字符串列,W11-B 追加,见下)。
    //
    // 刻意不放 rs_code / market_category / first_era:那三列是「这个资源在 §8 里的身份」,
    // 改了等于换一个资源,必须走 Seed + 迁移(有 diff、可回滚)。
    //
    // trade_mode 为什么破例开放、又为什么只开一半:
    //   运营的真实需求是**单资源停市 / 复市**(某个资源被刷崩了,先摘下来止血,查清再挂回去)——
    //   这件事此前只能改代码发版,而全市场停市开关(game_settings)又太钝:为一种资源停掉整个市场。
    //   所以只放行 spot ↔ non_tradeable 这一对**可逆**互切;
    //   涉及 capacity_contract 的一律 422 —— 产能合约(电力)不是库存资源,把它切成现货等于问
    //   「买 100 度电存哪儿」,整条交易路径都没有承接它的语义(见 MarketDefinition 的常量注释)。
    //   把现货切成产能合约同理:那是**结构性**改动,得先有产能合约的交易实现。
    //
    // 全市场级的调节(手续费倍率 / 滑点系数 / 成交量上限 / 全场停市)在 game_settings 里,那边改一处影响全场;
    // 这里改的是**单个资源**。两套入口互不重叠,同一个数不会有两个来源。
    private const MARKET_EDITABLE = [
        'base_price', 'min_price', 'max_price',
        'volatility', 'elasticity', 'fee_rate', 'base_liquidity',
        'trade_mode',
    ];

    // trade_mode 允许互切的两个取值(capacity_contract 刻意不在其中,见 MARKET_EDITABLE 的说明)
    private const MARKET_TRADE_MODE_SWITCHABLE = [
        MarketDefinition::TRADE_MODE_SPOT,
        MarketDefinition::TRADE_MODE_NON_TRADEABLE,
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
        // trade_mode 不在只读列里 —— 它已是可编辑字段(W11-B),由 MARKET_EDITABLE 带出,
        // 两处都列会让 SELECT 出现重复列名
        $rows = DB::table('market_definition')->orderBy('rs_code')->get(array_merge(
            ['resource_id', 'rs_code', 'market_category', 'first_era', 'note'],
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
        // trade_mode 是 allowlist 里唯一的**字符串**字段,校验规则按字段分流:
        // 其余七个数值字段仍是 numeric + min:0(负的 base_price 会让「买入」变成给玩家发钱,
        // 负的 fee_rate 会让往返套利立刻转正)
        $isTradeMode = $request->input('field') === 'trade_mode';

        $data = $request->validate([
            'resource_code' => ['required', 'string', 'max:32'],
            'field'         => ['required', 'string'],
            'value'         => $isTradeMode
                ? ['required', 'string', 'max:32']
                : ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason'        => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::MARKET_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        if ($isTradeMode) {
            // 目标值只收 spot / non_tradeable(capacity_contract 一律拒,见 MARKET_EDITABLE 的说明)
            if (! in_array($data['value'], self::MARKET_TRADE_MODE_SWITCHABLE, true)) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                    'errors' => ['value' => ['trade_mode 只允许在 spot 与 non_tradeable 之间互切']],
                ]);
            }
            $value = (string) $data['value'];
        } else {
            $value = (float) $data['value'];
            if ($value > self::MARKET_FIELD_MAX[$data['field']]) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                    'errors' => ['value' => ['超出该字段允许的上限 ' . self::MARKET_FIELD_MAX[$data['field']]]],
                ]);
            }
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('market_definition')->where('resource_id', $data['resource_code'])->lockForUpdate()->first();
            if (! $row) {
                return 'not_found';
            }

            // 现状是产能合约的资源不许改 trade_mode:电力不是库存资源,切成现货会让
            // 「买 100 度电」走进一条没有承接语义的路径。锁内判定 —— 判定依据是**库里的当前值**
            if ($data['field'] === 'trade_mode' && $row->trade_mode === MarketDefinition::TRADE_MODE_CAPACITY_CONTRACT) {
                return 'capacity_contract';
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
        if ($result === 'capacity_contract') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['产能合约资源(电力)不可切换 trade_mode:它不是库存资源,现货买卖对它没有语义']],
            ]);
        }

        // 定义有请求级缓存,改完必须失效 —— 否则同一请求后续的定价还在用旧数值
        MarketDefinition::flush();

        return ApiResponse::ok(['data' => $result]);
    }

    // ---- 工具 / 道具定义(M3-D2,v3.2 §7)----

    // 可编辑的**数值**列。
    //
    // 刻意不放 category / min_era / durability_tier / durability_mode / effect_code / effect_json /
    // craft_cost_json / crafting_building_id:那几列是「结构」不是「数值」——
    // 改 category 会改变「同类只取最高」的分组(§7),改 durability_tier 会整档换掉耐久速度,
    // 改 effect_json 要重新过 ModifierSpec 的三重 allowlist,改 craft_cost 是改配方。
    // 结构性调整走 Seed + 迁移(有 diff、可回滚),不给后台一个能一键改坏经济的入口。
    // 全局规则(槽位数 / 每档分钟数 / 预警阈值 / 两个开关)在 /api/admin/settings,两套入口互不重叠。
    private const ITEM_EDITABLE = ['durability', 'effect_value', 'trade_value'];

    // 逐字段的合理上限。下限统一是 0(validate 里的 min:0),这里补的是上限
    private const ITEM_FIELD_MAX = [
        // 耐久 1e6 点:按 10 分钟 1 点算已经是 19 年工龄,再高等于「永不损耗」,B4 的损毁机制形同虚设
        'durability'   => 1000000,
        // 效果值 1000:§7 最高是 35(percent)。留 30 倍余量做实验,再高会让单件工具直接顶爆 §13 的帽
        'effect_value' => 1000,
        // 拆解基数 1e6:§7 最高 1500,与 base_price 同量级的上限,防止金额列溢出
        'trade_value'  => 1000000,
    ];

    // 列表:24 行的可编辑数值 + 只读结构列,供后台先看后改。
    // 24 行不分页 —— 工具调数值必须横向比较(「这一档耐久是不是偏低」),一屏看完才比得出来
    public function items(): JsonResponse
    {
        // W11-B 补两列只读:
        //   equip_target_desc_zh —— §7 的「装备对象」原文(这件工具装在哪种岗位 / 建筑上)。
        //     没有它,后台看到 effect_code=production_pct 也说不出「这 8% 加在谁头上」;
        //   effect_json         —— 真正进乘区 / 消费点的那一份 specs(只读)。
        //     effect_value 只是 §7 的展示值,两者由 editItem 的 rescaleEffectJson 同步 ——
        //     把 specs 摆出来,运营才能自己核对「改完 effect_value 后 specs 到底变成什么了」,
        //     也一眼看得出哪几件的效果压根没结构化(specs 为空 = 装上去没有任何作用)。
        //     仍然**只读**:手写 specs 要重新过 ModifierSpec 的三重 allowlist,拼错只会静默不生效
        $rows = DB::table('item_definition')->orderBy('item_id')->get(array_merge(
            ['item_id', 'name_key', 'category', 'min_era', 'equip_target_desc_zh',
                'durability_tier', 'durability_mode',
                'effect_code', 'unit', 'effect_json', 'crafting_source_desc_zh', 'crafting_building_id',
                'crafting_unmapped_zh', 'craft_cost_json', 'note'],
            self::ITEM_EDITABLE
        ));

        return ApiResponse::ok(['data' => ['items' => $rows, 'editable' => self::ITEM_EDITABLE]]);
    }

    // 调整:与 editBuildingLevel / editNpc / editMarketDefinition 逐条同款
    //(allowlist 字段 + 范围校验 + 强制 reason + 行锁 + 审计 + 版本递增)。
    //
    // 必须 bump game_data_version:item_definition 在 CHECKSUM_TABLES 里,
    // 改一行 effect_value 就等于改了全服的产量上限,半年后要能回答
    // 「他那件工具当时为什么加 18%」(§64 / §65)。
    public function editItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'string', 'max:16'],
            'field'   => ['required', 'string'],
            // 与建筑等级 / NPC / 市场同一条理由:allowlist 里的三个字段按设计都是非负数。
            // 负的 durability 会让工具装上去当场损毁,负的 effect_value 会让工具变成减产
            'value'   => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason'  => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::ITEM_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        $value = (float) $data['value'];
        if ($value > self::ITEM_FIELD_MAX[$data['field']]) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['超出该字段允许的上限 ' . self::ITEM_FIELD_MAX[$data['field']]]],
            ]);
        }

        // 耐久必须是正整数:0 耐久的工具做出来就是废的,小数耐久上限没有意义
        //(剩余耐久才是小数 —— 它按「工作分钟 / 每点分钟数」递减)
        if ($data['field'] === 'durability' && ($value < 1 || floor($value) !== $value)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['耐久必须是 ≥1 的整数']],
            ]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('item_definition')->where('item_id', $data['item_id'])->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            $before = $row->{$data['field']};
            $update = [$data['field'] => $value];

            // effect_value 与 effect_json.specs 是**同一个数的两种写法**(§7 原文 8 = specs 里的 0.08),
            // 真正进乘区 / 消费点的是 specs。只改 effect_value 就会变成
            // 「后台改了没反应」—— 与 M.3 的 governance_bonus 双口径是同一个坑,V3.2.1 刚把那三列删掉。
            // 所以这里同步重写 specs 的数值:**保留每条 spec 的符号与其余字段**,只换量级
            // (IT016 的减免类效果 specs 是 -0.08,改成 12 之后仍然是 -0.12,不会被改成加成)。
            if ($data['field'] === 'effect_value') {
                $update['effect_json'] = self::rescaleEffectJson($row->effect_json, (string) $row->unit, $value);
            }

            DB::table('item_definition')->where('item_id', $data['item_id'])->update($update);

            $version = GameDataVersion::bump(
                "调整工具 {$data['item_id']} {$data['field']}: {$before} → {$value}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'item_definition',
                'entity_id' => $data['item_id'],
                'reason_code' => $data['reason'],
                'before_json' => [$data['field'] => $before],
                'after_json' => [$data['field'] => $value],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            return ['before' => $before, 'after' => $value, 'version' => $version];
        });

        if ($result === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        // 定义有请求级缓存,改完必须失效 —— 否则同一请求后续的乘区还在用旧数值。
        // 注意 effect_value 只是 §7 原文的展示值,真正进乘区的是 effect_json 里的 specs:
        // 两者的同步在交付汇报里点名为已知限制(effect_json 是结构列,后台不可编辑)
        ItemDefinition::flush();

        return ApiResponse::ok(['data' => $result]);
    }

    // effect_json 的 specs 按新的 §7 效果值重算(见 editItem 里的调用点注释)。
    //   percent → spec 值 = 新值 / 100;flat → spec 值 = 新值。
    // 每条 spec 的符号沿用原值(减免类是负数),其余字段(target / scope / op / scope_key)原样保留;
    // unmapped_zh 一并原样保留 —— 它是「为什么这条效果没生效」的说明,与数值无关。
    // 解析不出来时原样返回:宁可这一次同步不生效,也不能把一列结构化数据写成 null
    private static function rescaleEffectJson(?string $json, string $unit, float $newValue): ?string
    {
        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded) || ! is_array($decoded['specs'] ?? null)) {
            return $json;
        }

        $magnitude = $unit === 'percent' ? $newValue / 100.0 : $newValue;

        foreach ($decoded['specs'] as $index => $spec) {
            $sign = ((float) ($spec['value'] ?? 0)) < 0 ? -1.0 : 1.0;
            $decoded['specs'][$index]['value'] = $sign * $magnitude;
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    // ---- 随机事件定义(M3-D4,v3.2 §9.2)----
    //
    // 用户 2026-08-10 拍板③点名:「**所有事件必须在管理员后台可设定**(权重/效果/开关)」。
    // 这里就是那条硬约束的落点,五个可编辑项一一对应:
    //   enabled           开关(逐事件启用 / 停用)
    //   base_weight       权重(§9.1 权重公式的基数)
    //   cooldown_minutes  冷却
    //   duration_minutes  持续时间
    //   effect_multiplier **效果数值**的强度倍率(所有效果的数值统一乘它)
    //
    // 为什么效果数值走「倍率」而不是让后台直接编辑 effect JSON:
    // 手写 JSON 迟早写出一条 target 不存在 / scope_key 拼错的配置,而那种配置在运行时
    // 只会「静默不生效」——事件照常触发、通知照常弹、什么都没发生,是最难查的一类线上问题。
    // 倍率把「调强调弱」这个真实需求满足到位,同时把结构性改动挡在 Seed + 迁移那条有 diff、可回滚的路上。
    private const EVENT_EDITABLE = ['enabled', 'base_weight', 'cooldown_minutes', 'duration_minutes', 'effect_multiplier'];

    // 逐字段的合理区间(下限统一 0,这里补上限)。每一条都对应一种「填错就出事」的具体后果
    private const EVENT_FIELD_MAX = [
        // 开关:只收 0 / 1
        'enabled'           => 1,
        // 权重 10000:§9.2 最高 12,留足实验余量;再高会让一条事件在候选池里吃掉全部概率
        'base_weight'       => 10000,
        // 冷却 / 持续 一周(10080 分钟):再长就等于「这辈子只触发一次」,不如直接停用
        'cooldown_minutes'  => 10080,
        'duration_minutes'  => 10080,
        // 效果强度 10 倍:再高会让「损失 15% 粮食」变成一次清仓
        'effect_multiplier' => 10,
    ];

    // 列表:30 行的可编辑数值 + 只读的结构列(条件 / 效果原文 + DSL 落地情况),供后台先看后改。
    // 30 行不分页 —— 事件调权重必须横向比较(「干旱比洪水多这么多合理吗」),一屏看完才比得出来
    public function events(): JsonResponse
    {
        $rows = DB::table('event_definition')->orderBy('event_id')->get(array_merge(
            ['event_id', 'name_zh', 'category', 'event_type', 'min_era', 'disabled_reason',
                'condition_desc_zh', 'auto_effect_desc_zh',
                'option_a_desc_zh', 'option_b_desc_zh', 'option_c_desc_zh',
                'condition_json', 'auto_effect_json', 'options_json'],
            self::EVENT_EDITABLE
        ));

        // 给后台一栏「这条事件的效果到底落地了几条」:
        // mapped = 能执行的效果条数,unmapped = 原样保留但当前无法承接的文案条数。
        // 运营看到 mapped=0 就知道「开了它也不会有任何后果」,不必去翻代码
        $rows = $rows->map(function ($row) {
            $auto = json_decode((string) $row->auto_effect_json, true) ?: [];
            $options = json_decode((string) $row->options_json, true) ?: [];

            $mapped = count($auto['effects'] ?? []);
            $unmapped = count($auto['unmapped_zh'] ?? []);
            foreach ($options as $option) {
                if ($option === null) {
                    continue;
                }
                $mapped += count($option['effects'] ?? []);
                $unmapped += count($option['unmapped_zh'] ?? []);
            }

            $row->mapped_effect_count = $mapped;
            $row->unmapped_effect_count = $unmapped;

            return $row;
        });

        return ApiResponse::ok(['data' => ['events' => $rows, 'editable' => self::EVENT_EDITABLE]]);
    }

    // 调整:与 editBuildingLevel / editNpc / editMarketDefinition / editItem 逐条同款
    //(allowlist 字段 + 范围校验 + 强制 reason + 行锁 + 审计 + 版本递增)。
    //
    // 两处本系统特有的处理:
    //   ① 改完必须 EventDefinition::flush() —— 任务硬约束「后台改动必须即刻影响后续触发」,
    //      定义有请求级缓存,不失效的话同一请求里后续的触发判定还在用旧值;
    //   ② 启用一条「效果全是 unmapped」的事件时回一条 warning:
    //      不拦(依赖落地后运营就该能开),但要让他知道现在开了也不会有任何后果。
    public function editEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'string', 'max:32'],
            'field'    => ['required', 'string'],
            // 与其它定义同一条理由:allowlist 里的五个字段按设计都是非负数。
            // 负的 base_weight 会让权重掷点的累加区间错乱,负的 effect_multiplier 会让
            // 「损失粮食」变成「凭空发粮食」
            'value'    => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason'   => ['required', 'string', 'min:2', 'max:80'],
            // 停用原因(W11-B 补漏):停用时必填,列宽对齐 event_definition.disabled_reason 的 VARCHAR(255)。
            // 与 reason 的分工 —— reason 进审计(「这次操作为什么做」,一次性),
            // disabled_reason 进定义表并随后台列表下发(「这条事件为什么是灰的」,长期显示给下一个人看)
            'disabled_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (! in_array($data['field'], self::EVENT_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        $value = (float) $data['value'];
        if ($value > self::EVENT_FIELD_MAX[$data['field']]) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['超出该字段允许的上限 ' . self::EVENT_FIELD_MAX[$data['field']]]],
            ]);
        }

        // 三个「分钟 / 开关」类字段必须是整数:12.5 分钟的冷却写进 unsignedInteger 列会被静默截断
        if (in_array($data['field'], ['enabled', 'cooldown_minutes', 'duration_minutes'], true)
            && floor($value) !== $value) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['该字段必须是整数']],
            ]);
        }

        // 停用必须留下原因:后台列表把 disabled_reason 直接显示在灰行上。
        // 没有它,下一个人看到一条灰着的事件只有两种猜测 ——「依赖还没落地」还是「谁手滑关的」,
        // 而这两种的处理方式完全相反(等 / 立刻开回来)。这正是 event_definition 当初就建了这一列的原因,
        // 只是此前没有任何入口能写它
        if ($data['field'] === 'enabled' && $value == 0 && trim((string) ($data['disabled_reason'] ?? '')) === '') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['disabled_reason' => ['停用事件必须填写停用原因(后台列表要显示它)']],
            ]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('event_definition')->where('event_id', $data['event_id'])->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            $before = $row->{$data['field']};
            $update = [$data['field'] => $value];

            // 停用原因与开关**同事务同审计**:分两次写会出现「已经停用但原因还没落库」的中间态,
            // 后台恰好在这一刻刷新就会看到一条没有理由的灰行
            $beforeAudit = [$data['field'] => $before];
            $afterAudit = [$data['field'] => $value];
            if ($data['field'] === 'enabled') {
                // 停用 → 写入原因;启用 → 自动清成 NULL(理由已经不成立了,留着只会误导下一个人)
                $reasonAfter = $value == 0 ? (string) $data['disabled_reason'] : null;
                $update['disabled_reason'] = $reasonAfter;
                $beforeAudit['disabled_reason'] = $row->disabled_reason;
                $afterAudit['disabled_reason'] = $reasonAfter;
            }

            DB::table('event_definition')->where('event_id', $data['event_id'])->update($update);

            $version = GameDataVersion::bump(
                "调整事件 {$data['event_id']} {$data['field']}: {$before} → {$value}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'event_definition',
                'entity_id' => $data['event_id'],
                'reason_code' => $data['reason'],
                'before_json' => $beforeAudit,
                'after_json' => $afterAudit,
                'metadata_json' => ['game_data_version' => $version],
            ]);

            $warning = null;
            if ($data['field'] === 'enabled' && $value == 1) {
                $auto = json_decode((string) $row->auto_effect_json, true) ?: [];
                if (($auto['effects'] ?? []) === []) {
                    $warning = '该事件的自动效果当前全部无法承接(见 disabled_reason),启用后会触发但不会产生任何后果';
                }
            }

            return ['before' => $before, 'after' => $value, 'version' => $version, 'warning' => $warning];
        });

        if ($result === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        // 定义有请求级缓存,改完必须失效 —— 否则同一请求后续的触发判定还在用旧数值
        EventDefinition::flush();

        return ApiResponse::ok(['data' => $result]);
    }

    // ---- 科技定义(W11-B,v3.2 §4)----

    // 可编辑的**数值**列:研究要花多少知识、要花多久。
    //
    // 刻意不放 prerequisite_tech_ids / unlock_building_ids / era_key / branch ——
    // 那四列是**科技树的拓扑**,不是数值:
    //   改 prerequisite 会当场造出环(A 依赖 B、B 依赖 A → 两条都永远研究不了),
    //     或者让一条已解锁的科技变成"前置未满足"(玩家已经建出来的楼从此非法);
    //   改 unlock_building_ids 会让一栋建筑失去解锁来源(永远建不出来)或凭空提前;
    //   改 era_key 会同时改研究闸门与它解锁的那批建筑的可建时代。
    // 拓扑性调整走 Seed + 迁移(有 diff、可回滚,而且能在测试里跑一遍环检测),
    // 不给后台一个能一键把科技树改成死锁的入口。
    private const TECH_EDITABLE = ['knowledge_cost', 'research_minutes'];

    // 逐字段的合理上限。下限统一是 0(validate 里的 min:0)
    private const TECH_FIELD_MAX = [
        // 知识成本 1e7:§4 最贵的一条是十万量级,留 100 倍余量;
        // 再高会超出玩家一辈子能攒出的知识总量,等于把这条科技永久锁死(不如直接不放出来)
        'knowledge_cost'   => 10000000,
        // 研究时长一周(10080 分钟):再长等于「这条科技研究不完」,
        // 而研究槽位被它一直占着 —— 与事件的冷却 / 持续时间同一个上限、同一条理由
        'research_minutes' => 10080,
    ];

    // 列表:50 行的可编辑数值 + 只读的拓扑列,供后台先看后改。
    // 50 行不分页 —— 调科技成本必须沿着树横向比较(「这一代的三条分支价差合理吗」),一屏看完才比得出来。
    // 前置 / 解锁两列一并给出(只读):运营得看得见「改贵了会卡住后面哪几条」
    public function technologies(): JsonResponse
    {
        $rows = DB::table('technology_definition')->orderBy('tech_id')->get(array_merge(
            ['tech_id', 'era_key', 'branch', 'name', 'prerequisite_tech_ids', 'unlock_building_ids'],
            self::TECH_EDITABLE
        ));

        return ApiResponse::ok(['data' => ['technologies' => $rows, 'editable' => self::TECH_EDITABLE]]);
    }

    // 调整:与前面五个编辑器逐条同款
    //(allowlist 字段 + 范围校验 + 强制 reason + 行锁 + 审计 + 版本递增)。
    //
    // 必须 bump game_data_version:technology_definition 在 CHECKSUM_TABLES 里,
    // 改一行知识成本就等于改了全服的科技节奏,半年后要能回答
    // 「他当时研究冶金为什么只花了 300 知识」(§64 / §65)。
    public function editTechnology(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tech_id' => ['required', 'string', 'max:32'],
            'field'   => ['required', 'string'],
            // 与其它定义同一条理由:两个字段按设计都是非负数。
            // 负的 knowledge_cost 会让研究变成「产知识」,负的 research_minutes 会让完成时间早于开始时间
            'value'   => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason'  => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::TECH_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        $value = (float) $data['value'];
        if ($value > self::TECH_FIELD_MAX[$data['field']]) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['超出该字段允许的上限 ' . self::TECH_FIELD_MAX[$data['field']]]],
            ]);
        }

        // 知识成本是 int 列:300.5 会被静默截断成 300(后台显示 300.5 而库里是 300)。
        // research_minutes 是 DECIMAL(10,2),小数是合法的(§4 里就有 x.5 分钟的条目),不作整数要求
        if ($data['field'] === 'knowledge_cost' && floor($value) !== $value) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['知识成本必须是整数']],
            ]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('technology_definition')->where('tech_id', $data['tech_id'])->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            $before = $row->{$data['field']};
            DB::table('technology_definition')->where('tech_id', $data['tech_id'])->update([$data['field'] => $value]);

            $version = GameDataVersion::bump(
                "调整科技 {$data['tech_id']} {$data['field']}: {$before} → {$value}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'technology_definition',
                'entity_id' => $data['tech_id'],
                'reason_code' => $data['reason'],
                'before_json' => [$data['field'] => $before],
                'after_json' => [$data['field'] => $value],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            return ['before' => $before, 'after' => $value, 'version' => $version];
        });

        if ($result === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // ---- 建筑定义(W11-B,v3.2 §3)----

    // 可编辑列只有一个:同类建筑的建造上限。
    //
    // footprint_w / footprint_h **绝不开放**:占地是已建成实例在地图上的实际尺寸,
    // 改大一格会让所有存量建筑瞬间互相重叠(而重叠检测只在建造时跑,存量不会被重新校验)——
    // 那不是「数值失衡」,是地图数据当场自相矛盾。
    //
    // ⚠️ 另有六列**零引用死列**,同样不进 allowlist,但理由不同 ——
    //   population_min / governance_ratio_min / happiness_min / base_workers /
    //   base_build_seconds / upgrade_to_building_id
    // 它们在表里有值,但全项目没有任何代码读它们(建造闸门读的是等级表与科技,
    // 工人数读 building_level_definition.worker_required,工期读 duration_seconds)。
    // 开放编辑等于给运营一个「改了完全没反应」的假旋钮 —— 比不开放更糟。
    // 这六列是补实现还是删列,待用户裁决(见交付汇报),裁决之前一律保持只读。
    private const BUILDING_DEF_EDITABLE = ['max_count'];

    // 列表:94 行的可编辑上限 + 只读结构列。
    // footprint 两列一并给出(只读):运营调 max_count 时得知道「这栋楼一个占几格」,
    // 才判断得出「上限 20 会不会把地图塞满」
    public function buildings(): JsonResponse
    {
        $rows = DB::table('building_definition')->orderBy('building_id')->get(array_merge(
            ['building_id', 'name', 'era_key', 'category', 'footprint_w', 'footprint_h'],
            self::BUILDING_DEF_EDITABLE
        ));

        return ApiResponse::ok(['data' => ['buildings' => $rows, 'editable' => self::BUILDING_DEF_EDITABLE]]);
    }

    // 调整:与前面几个编辑器逐条同款
    //(allowlist 字段 + 范围校验 + 强制 reason + 行锁 + 审计 + 版本递增)。
    public function editBuilding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'building_id' => ['required', 'string', 'max:16'],
            'field'       => ['required', 'string'],
            'value'       => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason'      => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::BUILDING_DEF_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        // 下限是 **1** 而不是 0(所以不能只靠 validate 的 min:0):
        // max_count = 0 会让**已经建成**的那些实例当场变成非法(数量 0 > 上限 0 不成立),
        // 而存量实例不会被重新校验 —— 城市里于是留着一堆"本不该存在"的楼,
        // 玩家一拆就再也建不回来。想彻底停售一栋楼请走迁移(连同它的解锁科技一起处理)。
        // 上限 10000:再高等于没有上限,而 BUILDING_LIMIT_REACHED 这条闸门本身就是布局压力的来源
        $value = (float) $data['value'];
        if ($value < 1 || $value > 10000 || floor($value) !== $value) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['建造上限必须是 1~10000 的整数(0 会让已建成的建筑变成非法)']],
            ]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('building_definition')->where('building_id', $data['building_id'])->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            $before = $row->{$data['field']};
            DB::table('building_definition')->where('building_id', $data['building_id'])->update([$data['field'] => $value]);

            $version = GameDataVersion::bump(
                "调整建筑 {$data['building_id']} {$data['field']}: {$before} → {$value}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'building_definition',
                'entity_id' => $data['building_id'],
                'reason_code' => $data['reason'],
                'before_json' => [$data['field'] => $before],
                'after_json' => [$data['field'] => $value],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            return ['before' => $before, 'after' => $value, 'version' => $version];
        });

        if ($result === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // ---- NPC 等级曲线(W11-B,v3.2 §6.2)----

    // 可编辑的**数值**列。主键 level **绝不开放** —— 改它等于把某一级整个搬走:
    // city_npcs.skill_level 指向的那一级会查不到(NpcBonus 查曲线落空 = 该 NPC 静默失去全部加成),
    // 或者两行撞成同一级。10 级的档位结构是 §6.2 定死的,加减档位走迁移。
    private const CURVE_EDITABLE = ['xp_to_next', 'primary_bonus', 'maintenance_reduction_cap'];

    // 逐字段的合理上限。下限统一是 0
    private const CURVE_FIELD_MAX = [
        // 升级所需经验 1e7:§6.2 最高是四位数,留足实验余量;
        // 再高等于「这一级永远升不上去」,而等级是 NPC 全部加成的乘区来源
        'xp_to_next'                => 10000000,
        // 主技能加成 0.9(= +90%):§6.2 满级是 0.35。
        // 再高会让**单个** NPC 顶爆 §6.4 的单人帽 1.60 —— 顶爆之后曲线的高低完全不再影响结果,
        // 运营会看到「改了 0.9 和改了 5.0 一模一样」,那是最难解释的一类"没反应"
        'primary_bonus'             => 0.9,
        // 维护费减免上限 0.9(= 最多免 90%):1.0 会让维护费归零,
        // 而维护费是 §10 支出侧的主要压力来源,归零等于抽掉整条经济约束
        'maintenance_reduction_cap' => 0.9,
    ];

    // 列表:10 行整表(§6.2 只有 10 级),不分页 —— 调曲线必须逐级纵向比较,一屏看完才看得出斜率
    public function npcSkillCurve(): JsonResponse
    {
        $rows = DB::table('npc_skill_level_curve')->orderBy('level')->get(array_merge(
            ['level'],
            self::CURVE_EDITABLE
        ));

        return ApiResponse::ok(['data' => ['curve' => $rows, 'editable' => self::CURVE_EDITABLE]]);
    }

    // 调整:与前面几个编辑器逐条同款
    //(allowlist 字段 + 范围校验 + 强制 reason + 行锁 + 审计 + 版本递增)。
    //
    // 必须 bump game_data_version:npc_skill_level_curve 在 CHECKSUM_TABLES 里,
    // 改一行等于改了全服所有该等级 NPC 的加成(§64 / §65)。
    public function editNpcSkillCurve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level'  => ['required', 'integer', 'between:1,10'],
            'field'  => ['required', 'string'],
            // 与其它定义同一条理由:三个字段按设计都是非负数。
            // 负的 primary_bonus 会让升级变成减产,负的 xp_to_next 会让经验条倒着走
            'value'  => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::CURVE_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        $value = (float) $data['value'];
        if ($value > self::CURVE_FIELD_MAX[$data['field']]) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['超出该字段允许的上限 ' . self::CURVE_FIELD_MAX[$data['field']]]],
            ]);
        }

        // 经验是 unsignedInteger 列:小数会被静默截断
        if ($data['field'] === 'xp_to_next' && floor($value) !== $value) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['该字段必须是整数']],
            ]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('npc_skill_level_curve')->where('level', $data['level'])->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            $before = $row->{$data['field']};
            DB::table('npc_skill_level_curve')->where('level', $data['level'])->update([$data['field'] => $value]);

            $version = GameDataVersion::bump(
                "调整 NPC 等级曲线 L{$data['level']} {$data['field']}: {$before} → {$value}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'npc_skill_level_curve',
                'entity_id' => (string) $data['level'],
                'reason_code' => $data['reason'],
                'before_json' => [$data['field'] => $before],
                'after_json' => [$data['field'] => $value],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            return ['before' => $before, 'after' => $value, 'version' => $version];
        });

        if ($result === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // ---- 时代升级门槛(W11-B,v3.2 §5.1)----

    // 可编辑的七个**数值**门槛。
    //
    // buildings_json **绝不开放**:必须建筑清单是**升级路径的拓扑**,不是数值。
    // 填一栋目标时代的建筑就是死锁 ——「要升级先建它,要建它先升级」,而且这个死锁
    // 在后台看不出来(那一行只是多了一个建筑 id)。VII→VIII 那一档的注释就记着这个坑:
    // §5.1 原文的「钢铁厂规划 / 铁路规划」指向 P07 / T08,两者都是时代 VIII 建筑,
    // 当成前提会让所有人卡在时代 VII。改建筑清单一律走迁移(测试里有一条覆盖全档的死锁检测)。
    private const ERA_REQ_EDITABLE = [
        'population', 'knowledge', 'food', 'money', 'governance', 'happiness', 'defense',
    ];

    // 逐字段的合理上限:按**现值**留 10 倍余量(现值 = §5.1 最高一档,即 IX→X)。
    // 10 倍够运营做任何一次再平衡,又拦得住「多打一个零」这种最常见的手滑
    private const ERA_REQ_FIELD_MAX = [
        // 现值 200000(IX→X)
        'population' => 2000000,
        // 现值 100000
        'knowledge'  => 1000000,
        // 现值 1500000
        'food'       => 15000000,
        // 现值 800000
        'money'      => 8000000,
        // 现值 120000
        'governance' => 1200000,
        // 幸福度是 0~100 的百分制,不吃 10 倍余量:填 101 等于「这一档永远升不上去」,
        // 而幸福度的计算上限就是 100(§11)—— 那不是难度调节,是把升级通道焊死
        'happiness'  => 100,
        // 现值 8000。⚠️ 这一列同时是**国防威胁需求**的来源(见 EraService::defenseRequirement)
        'defense'    => 80000,
    ];

    // 列表:9 行整表(§5.1 的 I→II … IX→X),不分页 —— 调门槛必须逐档纵向比较曲线陡不陡。
    // buildings_json 一并给出(只读):运营得看得见「这一档还要求哪几栋楼」,
    // 否则只看七个数字会以为门槛就这些
    public function eraRequirements(): JsonResponse
    {
        $rows = DB::table('era_upgrade_requirement')->orderBy('era_order')->get(array_merge(
            ['era_order', 'buildings_json'],
            self::ERA_REQ_EDITABLE
        ));

        return ApiResponse::ok(['data' => ['requirements' => $rows, 'editable' => self::ERA_REQ_EDITABLE]]);
    }

    // 调整:与前面几个编辑器逐条同款
    //(allowlist 字段 + 范围校验 + 强制 reason + 行锁 + 审计 + 版本递增)。
    //
    // 两处本表特有的处理:
    //   ① 改完必须 EraService::flushRequirements() —— 门槛有请求级缓存,
    //      不失效的话同一请求里后续的升代判定 / 威胁需求还在用旧值;
    //   ② 改 defense 一律回一条 warning:这一列**同时**是国防威胁需求的来源
    //      (处在时代 N 的城市,威胁需求 = 升出时代 N 所需的国防最低)。
    //      不拦(单一来源正是设计意图),但要让运营知道「我只是想让升代难一点」
    //      会连带把全服的威胁等级判定一起改掉 —— 否则这就是一次看不见的副作用。
    public function editEraRequirement(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 主键 = 目标时代(2 表示 I→II 那一档);era 表最高 10
            'era_order' => ['required', 'integer', 'between:2,10'],
            'field'     => ['required', 'string'],
            // 七个门槛按设计都是非负数:负门槛等于「这一维不设限」,
            // 而那应该用 0 明确表达(0 = 不要求),不该靠负数隐式表达
            'value'     => ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason'    => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::ERA_REQ_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        $value = (float) $data['value'];
        if ($value > self::ERA_REQ_FIELD_MAX[$data['field']]) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['超出该字段允许的上限 ' . self::ERA_REQ_FIELD_MAX[$data['field']]]],
            ]);
        }

        // 七列都是 unsignedInteger:§5.1 的门槛全是整数,小数会被静默截断
        //(后台显示 50.5 而库里是 50,而门槛判定用的是库里那个值)
        if (floor($value) !== $value) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['时代门槛必须是整数']],
            ]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('era_upgrade_requirement')->where('era_order', $data['era_order'])->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            $before = $row->{$data['field']};
            DB::table('era_upgrade_requirement')->where('era_order', $data['era_order'])->update([$data['field'] => $value]);

            $version = GameDataVersion::bump(
                "调整时代门槛 →{$data['era_order']} {$data['field']}: {$before} → {$value}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'era_upgrade_requirement',
                'entity_id' => (string) $data['era_order'],
                'reason_code' => $data['reason'],
                'before_json' => [$data['field'] => $before],
                'after_json' => [$data['field'] => $value],
                'metadata_json' => ['game_data_version' => $version],
            ]);

            $warning = null;
            if ($data['field'] === 'defense') {
                $warning = '本行同时改变国防威胁需求:处在时代 ' . ((int) $data['era_order'] - 1)
                    . ' 的城市,其威胁需求 = 本行的国防最低,改动会立即影响全服该时代城市的威胁等级判定';
            }

            return ['before' => $before, 'after' => $value, 'version' => $version, 'warning' => $warning];
        });

        if ($result === null) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }

        // 门槛有请求级缓存,改完必须失效 —— 否则同一请求后续的升代判定 / 威胁需求还在用旧数值
        EraService::flushRequirements();

        return ApiResponse::ok(['data' => $result]);
    }
}
