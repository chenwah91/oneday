<?php

namespace App\Http\Controllers\Admin;

use App\Game\City\EraService;
use App\Game\Definition\EnumCode;
use App\Game\Definition\GameDataVersion;
use App\Game\Event\EventDefinition;
use App\Game\Item\ItemDefinition;
use App\Game\Market\MarketDefinition;
use App\Game\Modifier\ModifierSpec;
use App\Game\NPC\NpcCode;
use App\Game\Resource\ResourceCode;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\Xlsx\XlsxReader;
use App\Support\Xlsx\XlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    // NPC 定义的可编辑字段(M3-D1 起步,W14-A 扩到全表合理集合)。
    //
    // W14-A 修旧挂账:此前只开放五个数值列且上限裸奔,运营调一个 NPC 的分类 / 稀有度 / 文案
    // 都要提工单等发版。现按三类字段分开校验(见 editNpc):
    //   数值列  逐列过 NPC_FIELD_MAX(旧挂账一并补上,不再裸奔);
    //   枚举列  category / min_era / rarity / recruit_source 对权威来源校验 ——
    //           rarity / recruit_source 的权威是 NpcCode 常量(Seeder 守门用的同一份);
    //           min_era 的权威是 era 表(列上有外键);category 的权威取**库内 distinct**:
    //           EnumCode 没有 NPC category 登记表、Seeder 也不校验它,库内现值就是最严的口径
    //           (Fail Closed:不准借道编辑发明新分类,新分类走迁移);
    //   文本列  name_zh / recruit_desc_zh / trait_desc_zh 按列宽限长。
    //
    // 仍然不可改的列:npc_id / name_key 是主键与派生键;primary_skill_id 改了会让岗位匹配
    // 整体换一套(结构,走迁移);trait_json 是结构列 —— 强度走 trait_multiplier(W11-B),
    // 结构锁在 Seed + 迁移那条有 diff、可回滚的路上(AdminDefinitionExpansionTest 明确守着这一条)。
    private const NPC_EDITABLE = [
        'name_zh', 'category', 'min_era',
        'initial_skill_value', 'initial_skill_level', 'max_level',
        'wage_per_min', 'food_per_min',
        'rarity', 'recruit_source', 'recruit_desc_zh', 'trait_desc_zh',
        'trait_multiplier',
    ];

    // 四个枚举列(权威来源见 NPC_EDITABLE 的说明);三个文本列 => 各自的列宽
    private const NPC_ENUM_EDITABLE = ['category', 'min_era', 'rarity', 'recruit_source'];

    private const NPC_TEXT_MAX = ['name_zh' => 64, 'recruit_desc_zh' => 191, 'trait_desc_zh' => 191];

    // 逐字段的合理上限(下限统一 0)。W14-A 补齐全部数值列 —— 每一条都对应一种「填错就出事」的具体后果
    private const NPC_FIELD_MAX = [
        // 工资 1e6/分钟:与建筑维护费同量级的上限,防 DECIMAL(10,2) 金额列溢出;
        // 真到这个量级,一个 NPC 每分钟的工资已超过全服总产出,雇他等于宣布破产
        'wage_per_min'        => 1000000,
        // 口粮 1e4/分钟:列是 DECIMAL(8,3)(物理上限 99999.999),§6.3 全表最高 1.4,1e4 已留足实验余量
        'food_per_min'        => 10000,
        // 初始技能值是百分制(§6.3 全表最高 99):列是 unsignedSmallInteger,>100 会让技能值失去参照系
        'initial_skill_value' => 100,
        // 等级两列:§6.2 曲线只有 10 级,超出会让 NpcBonus 查曲线落空(静默失去全部加成)
        'initial_skill_level' => 10,
        'max_level'           => 10,
        // 强度 10 倍:再高会让单个 NPC 的一条特性直接顶爆 §6.4 的单人帽 1.60 与 §13 的 2.75 总帽,
        // 顶爆之后倍率再怎么调都不会有任何变化 —— 运营会看到「改了没反应」,那是最难解释的一类问题。
        // 与 event_definition.effect_multiplier 同一个上限、同一条理由
        'trait_multiplier'    => 10,
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
            // 上限 255 = level 列(unsignedTinyInteger)的物理上限,不再写死 3(W13-2 等级无上限):
            // 该级存不存在由下面的行查询判定(查不到 404),这里只拦「列装不下」的值
            'level'      => ['required', 'integer', 'between:1,255'],
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
            // 与 editBuildingLevel 同一条理由(W13-2):等级上限数据驱动,255 是列的物理上限
            'level'       => ['required', 'integer', 'between:1,255'],
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

    // ---- 建筑等级 Excel 导出 / 导入(W13-2)----

    // 导出列顺序 = 导入表头 allowlist。「名称」是只读参考列(join building_definition.name,导入时忽略);
    // 其余列:两列主键 + EDITABLE 的七个数值列 + 三个 JSON 列(JSON 文本原样进单元格)。
    // 常量表达式不能调 array_merge,这里手抄一份 —— 增删 EDITABLE 字段时必须同步这里与导入校验
    private const XLSX_NAME_COLUMN = '名称';

    private const XLSX_COLUMNS = [
        'building_id', self::XLSX_NAME_COLUMN, 'level',
        'duration_seconds', 'worker_required',
        'maintenance_money_per_min', 'maintenance_food_per_min', 'maintenance_fuel_per_min', 'power_per_min',
        'capacity',
        'output_json', 'input_json', 'cost_json',
    ];

    // 导入单文件最多多少行数据(不含表头):94 栋 × 255 级的理论极限远用不到,
    // 这只是防「传错文件」的护栏,与 5MB 的大小上限同一用途
    private const XLSX_MAX_ROWS = 30000;

    // 逐行错误最多回多少条:全表打错时一次回几千条错误没人读得完,前 50 条足够定位问题模式
    private const XLSX_MAX_ERRORS = 50;

    // 审计明细最多记多少格变更(§57:审计不保存大型 Snapshot,超出计数说明)
    private const XLSX_AUDIT_DETAIL_MAX = 100;

    // 导出:全部建筑等级定义,一行一级,按 building_id, level 排序。
    // 只读端点(与 GET /definitions/building-levels 同权限),不写审计、不 bump 版本
    public function exportBuildingLevels(): BinaryFileResponse
    {
        $names = DB::table('building_definition')->pluck('name', 'building_id');
        $rows = DB::table('building_level_definition')->orderBy('building_id')->orderBy('level')->get();

        $data = [self::XLSX_COLUMNS];
        foreach ($rows as $r) {
            $data[] = [
                $r->building_id,
                (string) ($names[$r->building_id] ?? ''),
                (int) $r->level,
                (int) $r->duration_seconds,
                (int) $r->worker_required,
                (float) $r->maintenance_money_per_min,
                (float) $r->maintenance_food_per_min,
                (float) $r->maintenance_fuel_per_min,
                (float) $r->power_per_min,
                (float) $r->capacity,
                $r->output_json,
                $r->input_json,
                $r->cost_json,
            ];
        }

        $path = tempnam(sys_get_temp_dir(), 'blx');
        XlsxWriter::write($path, $data);

        return response()->download($path, 'building_levels_' . now()->format('Ymd_His') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // 导入:11 步铁模板的批量版。
    //   ① 表头 allowlist ② 逐行校验(building_id 必须已存在 —— **不准借道导入新建建筑**;
    //   数值列与 editBuildingLevel 同一套 FIELD_MAX / 整数特判;JSON 列合法且条目过资源 allowlist)
    //   ③ 等级连续性不变量(每栋导入后 1..N 连续,断档 422 —— 否则升级链断裂)
    //   ④ 全过才开事务:行锁 → 与现值 diff → 只写有变化的行 + 新增行,**绝不 DELETE**
    //     (文件里没有的现有行一律不动:删除等级不在本波范围)
    //   ⑤ before/after 摘要进审计、明细进 metadata(超长截断)、GDV bump 一次、缓存无(等级定义无请求级缓存)
    //   ⑥ 响应 {updated, inserted, unchanged, buildings_affected, version}
    public function importBuildingLevels(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 5MB:定义表全量导出不足 100KB,超大文件只可能是传错了东西
            'file'   => ['required', 'file', 'max:5120'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80),与其它编辑器同一条纪律
            'reason' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        try {
            $sheet = XlsxReader::read($data['file']->getRealPath());
        } catch (RuntimeException $e) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['file' => ['文件无法按 xlsx 解析:' . $e->getMessage()]],
            ]);
        }

        if ($sheet === [] || count($sheet) - 1 > self::XLSX_MAX_ROWS) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['file' => [$sheet === [] ? '文件里没有任何数据' : '行数超过上限 ' . self::XLSX_MAX_ROWS]],
            ]);
        }

        // ---- ① 表头 allowlist(未知列 / 重复列 / 缺必需列一律 422)----
        $headerRowNum = array_key_first($sheet);
        $columnAt = [];   // 列位置 => 列名(「名称」列记为 null = 忽略)
        $present = [];
        $headerErrors = [];
        foreach ($sheet[$headerRowNum] as $i => $cell) {
            $name = trim((string) ($cell ?? ''));
            if ($name === '') {
                continue; // 空表头格:该列下方必须也为空(逐行校验时按「无表头的列」拒)
            }
            if (! in_array($name, self::XLSX_COLUMNS, true)) {
                $headerErrors[] = ['row' => $headerRowNum, 'column' => $name, 'reason' => '未知列(不在允许的表头清单里)'];
                continue;
            }
            if (isset($present[$name])) {
                $headerErrors[] = ['row' => $headerRowNum, 'column' => $name, 'reason' => '表头重复出现'];
                continue;
            }
            $present[$name] = true;
            $columnAt[$i] = $name === self::XLSX_NAME_COLUMN ? null : $name;
        }
        foreach (self::XLSX_COLUMNS as $required) {
            if ($required !== self::XLSX_NAME_COLUMN && ! isset($present[$required])) {
                $headerErrors[] = ['row' => $headerRowNum, 'column' => $required, 'reason' => '缺少必需列(请以导出文件为模板)'];
            }
        }
        if ($headerErrors !== []) {
            return self::importFail($headerErrors);
        }

        // ---- ② 逐行校验(错误按 {row, column, reason} 收集,一次性返回)----
        $knownBuildings = array_flip(DB::table('building_definition')->pluck('building_id')->all());
        $errors = [];
        $parsed = []; // building_id => level => ['numeric' => [...], 'json' => [...]]
        foreach ($sheet as $rowNum => $cells) {
            if ($rowNum === $headerRowNum || self::xlsxRowEmpty($cells)) {
                continue;
            }
            $row = self::parseImportRow($rowNum, $cells, $columnAt, $knownBuildings, $errors);
            if ($row === null) {
                continue;
            }
            [$buildingId, $level, $values] = $row;
            if (isset($parsed[$buildingId][$level])) {
                $errors[] = ['row' => $rowNum, 'column' => 'level', 'reason' => "重复行:{$buildingId} L{$level} 在文件里出现了多次"];
                continue;
            }
            $parsed[$buildingId][$level] = $values;
        }

        // ---- ③ 等级连续性不变量(先在锁外收集完整错误清单,锁内还会复核一次)----
        if ($errors === [] && $parsed !== []) {
            self::assertLevelContinuity($parsed, $errors);
        }
        if ($errors !== []) {
            return self::importFail($errors);
        }
        if ($parsed === []) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['file' => ['文件里没有任何数据行']],
            ]);
        }

        $admin = $request->user();
        $reason = $data['reason'];

        // ---- ④⑤ 事务:行锁 → 复核连续性 → diff → update/insert(绝不 DELETE)→ 审计 + GDV bump ----
        $result = DB::transaction(function () use ($parsed, $admin, $reason) {
            // lockForUpdate 锁住涉及建筑的全部现有等级行:并发编辑器改同一行时,diff 的 before 才可信
            $current = [];
            $lockedRows = DB::table('building_level_definition')
                ->whereIn('building_id', array_keys($parsed))
                ->lockForUpdate()->get();
            foreach ($lockedRows as $row) {
                $current[$row->building_id][(int) $row->level] = $row;
            }

            // 锁内复核连续性:锁外检查到开锁之间definition 可能被并发改动(极小概率,但守着不变量不吃亏)
            foreach ($parsed as $buildingId => $levels) {
                $set = array_map('intval', array_unique(array_merge(array_keys($current[$buildingId] ?? []), array_keys($levels))));
                sort($set);
                if ($set !== range(1, count($set))) {
                    return 'gap_conflict';
                }
            }

            $updated = 0;
            $inserted = 0;
            $unchanged = 0;
            $changes = [];       // [{id, field, before, after}](审计明细,截断进 metadata)
            $insertedRows = [];
            $affected = [];

            foreach ($parsed as $buildingId => $levels) {
                ksort($levels); // 低级先写:新增 L4、L5 同时导入时按序插,PK 冲突之外无顺序依赖
                foreach ($levels as $level => $values) {
                    $existing = $current[$buildingId][$level] ?? null;

                    if ($existing === null) {
                        DB::table('building_level_definition')->insert([
                            'building_id'               => $buildingId,
                            'level'                     => $level,
                            // cost_type 是无程序读点的历史描述列(全库唯一读点是 EnumCode 的登记表);
                            // 新等级行沿用最近一档的既有 code,不新增枚举值 ——
                            // 加新 code 要同步 enum-code-map.md / enum-names.js / EnumCodeTest,属结构性变更
                            'cost_type'                 => self::costTypeForLevel($level),
                            'cost_json'                 => $values['json']['cost_json'],
                            'duration_seconds'          => (int) $values['numeric']['duration_seconds'],
                            'worker_required'           => (int) $values['numeric']['worker_required'],
                            'input_json'                => $values['json']['input_json'],
                            'output_json'               => $values['json']['output_json'],
                            'maintenance_money_per_min' => $values['numeric']['maintenance_money_per_min'],
                            'maintenance_food_per_min'  => $values['numeric']['maintenance_food_per_min'],
                            'maintenance_fuel_per_min'  => $values['numeric']['maintenance_fuel_per_min'],
                            'power_per_min'             => $values['numeric']['power_per_min'],
                            'capacity'                  => $values['numeric']['capacity'],
                        ]);
                        $inserted++;
                        $insertedRows[] = $buildingId . ':' . $level;
                        $affected[$buildingId] = true;
                        continue;
                    }

                    // 与现值 diff:只写真正变化的列;整行没变化就一个字节都不写(unchanged)
                    $update = [];
                    foreach ($values['numeric'] as $col => $value) {
                        if (abs((float) $existing->{$col} - $value) > 1e-9) {
                            $update[$col] = $value;
                            $changes[] = ['id' => $buildingId . ':' . $level, 'field' => $col, 'before' => (float) $existing->{$col}, 'after' => $value];
                        }
                    }
                    foreach ($values['json'] as $col => $canonical) {
                        $beforeRaw = $existing->{$col};
                        $before = $beforeRaw === null ? null : json_decode((string) $beforeRaw, true);
                        $after = $canonical === null ? null : json_decode($canonical, true);
                        // 结构宽松比较(8 与 8.0 相等、键序无关):纯格式差异不算改动,不制造假审计
                        if ($before != $after) {
                            $update[$col] = $canonical;
                            $changes[] = ['id' => $buildingId . ':' . $level, 'field' => $col, 'before' => $beforeRaw, 'after' => $canonical];
                        }
                    }

                    if ($update === []) {
                        $unchanged++;
                        continue;
                    }

                    DB::table('building_level_definition')
                        ->where('building_id', $buildingId)->where('level', $level)
                        ->update($update);
                    $updated++;
                    $affected[$buildingId] = true;
                }
            }

            // 全部没变化:不 bump 版本、不写审计(空版本只会稀释 §65 的回查价值),照常返回统计
            if ($updated === 0 && $inserted === 0) {
                return ['updated' => 0, 'inserted' => 0, 'unchanged' => $unchanged, 'buildings_affected' => [], 'version' => null];
            }

            $buildings = array_keys($affected);
            sort($buildings);

            $version = GameDataVersion::bump(
                "Excel 导入建筑等级:更新 {$updated} 行 / 新增 {$inserted} 行(涉及 " . count($buildings) . ' 栋)',
                'admin:' . $admin->username
            );

            // before/after 用「building:level.列」定位到具体格(与单格编辑器同一回查口径);
            // 超过 XLSX_AUDIT_DETAIL_MAX 截断,截断量记进 metadata(§57:审计不装大 Snapshot)
            $detail = array_slice($changes, 0, self::XLSX_AUDIT_DETAIL_MAX);
            $beforeAudit = [];
            $afterAudit = [];
            foreach ($detail as $chg) {
                $beforeAudit[$chg['id'] . '.' . $chg['field']] = $chg['before'];
                $afterAudit[$chg['id'] . '.' . $chg['field']] = $chg['after'];
            }

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'building_level_definition',
                'entity_id'   => 'excel_import',
                'reason_code' => $reason,
                'before_json' => $beforeAudit,
                'after_json'  => $afterAudit,
                'metadata_json' => [
                    'game_data_version'  => $version,
                    'updated'            => $updated,
                    'inserted'           => $inserted,
                    'unchanged'          => $unchanged,
                    'buildings_affected' => array_slice($buildings, 0, self::XLSX_AUDIT_DETAIL_MAX),
                    'inserted_rows'      => array_slice($insertedRows, 0, self::XLSX_AUDIT_DETAIL_MAX),
                    'changes_truncated'  => max(0, count($changes) - self::XLSX_AUDIT_DETAIL_MAX),
                ],
            ]);

            return [
                'updated'            => $updated,
                'inserted'           => $inserted,
                'unchanged'          => $unchanged,
                'buildings_affected' => $buildings,
                'version'            => $version,
            ];
        });

        if ($result === 'gap_conflict') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['file' => ['等级连续性校验未通过(定义在校验后被并发改动),请重新导出后再试']],
            ]);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // 逐行解析 + 校验。通过返回 [building_id, level, ['numeric'=>…, 'json'=>…]],失败往 $errors 里收集并返回 null
    private static function parseImportRow(int $rowNum, array $cells, array $columnAt, array $knownBuildings, array &$errors): ?array
    {
        $raw = [];
        foreach ($cells as $i => $cell) {
            if ($cell === null || $cell === '') {
                continue;
            }
            if (! array_key_exists($i, $columnAt)) {
                $errors[] = ['row' => $rowNum, 'column' => XlsxWriter::columnRef($i), 'reason' => '该列没有表头,不知道这格数据是什么'];
                return null;
            }
            if ($columnAt[$i] === null) {
                continue; // 「名称」参考列:导入时忽略
            }
            $raw[$columnAt[$i]] = $cell;
        }

        $ok = true;
        $fail = function (string $column, string $reason) use (&$errors, &$ok, $rowNum): void {
            $errors[] = ['row' => $rowNum, 'column' => $column, 'reason' => $reason];
            $ok = false;
        };

        // building_id:必须已存在于 building_definition —— 不准借道导入新建建筑(结构性变更走迁移)
        $buildingId = trim((string) ($raw['building_id'] ?? ''));
        if ($buildingId === '' || ! isset($knownBuildings[$buildingId])) {
            $fail('building_id', $buildingId === '' ? 'building_id 不能为空' : "building_id 不存在:{$buildingId}(导入不能新建建筑)");
        }

        // level:1~255 的整数(255 = level 列 unsignedTinyInteger 的物理上限)
        $levelRaw = $raw['level'] ?? null;
        $level = 0;
        if (! is_numeric($levelRaw) || (float) $levelRaw < 1 || (float) $levelRaw > 255 || floor((float) $levelRaw) !== (float) $levelRaw) {
            $fail('level', 'level 必须是 1~255 的整数');
        } else {
            $level = (int) $levelRaw;
        }

        // 七个数值列:与 editBuildingLevel 同一套 FIELD_MAX / 非负 / 整数特判
        $numeric = [];
        foreach (self::EDITABLE as $col) {
            $value = $raw[$col] ?? null;
            if (! is_numeric($value)) {
                $fail($col, $value === null ? '不能为空' : '必须是数字');
                continue;
            }
            $value = (float) $value;
            if ($value < 0) {
                $fail($col, '不能是负数');
                continue;
            }
            if ($value > self::BUILDING_FIELD_MAX[$col]) {
                $fail($col, '超出该字段允许的上限 ' . self::BUILDING_FIELD_MAX[$col]);
                continue;
            }
            if (in_array($col, ['duration_seconds', 'worker_required'], true) && floor($value) !== $value) {
                $fail($col, '该字段必须是整数(int 列,小数会被静默截断)');
                continue;
            }
            $numeric[$col] = $value;
        }

        // 三个 JSON 列:合法 JSON + 条目过资源 allowlist + 数值过 BUILDING_JSON_MAX(与条目编辑器同一套护栏)
        $json = [];
        foreach (self::BUILDING_JSON_COLUMNS as $col) {
            $cell = $raw[$col] ?? null;
            if ($cell === null || trim((string) $cell) === '') {
                if ($col === 'cost_json') {
                    $fail($col, 'cost_json 不能为空(该列 NOT NULL,免费建造/升级请显式写 {} 并三思)');
                } else {
                    $json[$col] = null; // input/output 允许为空(NULL 列)
                }
                continue;
            }
            $decoded = json_decode(trim((string) $cell), true);
            if (! is_array($decoded)) {
                $fail($col, '不是合法的 JSON(必须是数组或对象)');
                continue;
            }
            $shapeError = $col === 'cost_json'
                ? self::validateCostJson($decoded)
                : self::validateRateJson($decoded, self::BUILDING_JSON_MAX[$col]);
            if ($shapeError !== null) {
                $fail($col, $shapeError);
                continue;
            }
            // 规范化落库:cost 的空映射要编成 {} 而不是 [](两列形状不能互相污染)
            $json[$col] = $col === 'cost_json' && $decoded === []
                ? '{}'
                : json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        return $ok ? [$buildingId, $level, ['numeric' => $numeric, 'json' => $json]] : null;
    }

    // output_json / input_json 的形状:[{resource, rate_per_min}, …]。
    // resource 必须是登记在册的资源 code(allowlist 外的键 = 永远读不到的配置,与条目编辑器同一条理由)
    private static function validateRateJson(array $decoded, float $max): ?string
    {
        if ($decoded !== [] && ! array_is_list($decoded)) {
            return '必须是条目列表([{"resource":…,"rate_per_min":…}, …])';
        }
        foreach ($decoded as $i => $entry) {
            if (! is_array($entry) || ! is_string($entry['resource'] ?? null)) {
                return "第 {$i} 条缺少 resource 字段";
            }
            if (! array_key_exists($entry['resource'], ResourceCode::CHINESE_NAMES)) {
                return "第 {$i} 条的资源 code 未登记:{$entry['resource']}";
            }
            $rate = $entry['rate_per_min'] ?? null;
            if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > $max) {
                return "第 {$i} 条的 rate_per_min 必须是 0~{$max} 的数字";
            }
        }

        return null;
    }

    // cost_json 的形状:{资源 code: 数量}
    private static function validateCostJson(array $decoded): ?string
    {
        $max = self::BUILDING_JSON_MAX['cost_json'];
        foreach ($decoded as $code => $amount) {
            if (! is_string($code) || ! array_key_exists($code, ResourceCode::CHINESE_NAMES)) {
                return "资源 code 未登记:{$code}";
            }
            if (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > $max) {
                return "{$code} 的成本必须是 0~{$max} 的数字";
            }
        }

        return null;
    }

    // 等级连续性:每栋建筑「现有等级 ∪ 文件等级」必须恰好是 1..N —— 断档会让升级链在缺口处断裂
    // (UpgradeService 查不到 level+1 即判满级,L5 定义在断档之上等于永远够不着)
    private static function assertLevelContinuity(array $parsed, array &$errors): void
    {
        $existing = DB::table('building_level_definition')
            ->whereIn('building_id', array_keys($parsed))
            ->get(['building_id', 'level']);
        $byBuilding = [];
        foreach ($existing as $row) {
            $byBuilding[$row->building_id][] = (int) $row->level;
        }

        foreach ($parsed as $buildingId => $levels) {
            $set = array_map('intval', array_unique(array_merge($byBuilding[$buildingId] ?? [], array_keys($levels))));
            sort($set);
            if ($set !== range(1, count($set))) {
                $missing = array_values(array_diff(range(1, max($set)), $set));
                $errors[] = [
                    'row'    => 0,
                    'column' => 'level',
                    'reason' => "{$buildingId} 导入后等级断档(缺 L" . implode('、L', $missing) . '):等级必须从 1 连续',
                ];
            }
        }
    }

    // cost_type 是无程序读点的历史描述列:L1 = 建造,L2 = L1→L2,更高级沿用最近一档的既有 code
    // (新增枚举值要同步 enum-code-map.md / enum-names.js / EnumCodeTest 三处,属结构性变更,不在本波)
    private static function costTypeForLevel(int $level): string
    {
        if ($level <= 1) {
            return EnumCode::COST_TYPE_BUILD;
        }
        if ($level === 2) {
            return EnumCode::COST_TYPE_UPGRADE_L1_L2;
        }

        return EnumCode::COST_TYPE_UPGRADE_L2_L3;
    }

    // 一行是否全空(Excel 常在表尾留几行只有格式没有值的空行)
    private static function xlsxRowEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    // 逐行错误的统一 422(上限 XLSX_MAX_ERRORS 条,总数在 errors.file 里说明)
    private static function importFail(array $errors): JsonResponse
    {
        return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
            'errors'     => ['file' => ['导入文件校验未通过,共 ' . count($errors) . ' 处错误(最多列出 ' . self::XLSX_MAX_ERRORS . ' 条)']],
            'row_errors' => array_slice($errors, 0, self::XLSX_MAX_ERRORS),
        ]);
    }

    // ---- NPC 定义(M3-D1)----

    // 列表:150 行原型的可编辑列 + 只读的结构列,供后台先看后改。
    // 不分页:全表一屏拉下来才比较得出「这一档工资是不是偏了」。
    // W14-A:可编辑列扩全后,原先的只读列大多进了 NPC_EDITABLE,这里只留三个真正只读的
    //(主键 / 派生键 / 结构键);trait_json 只读下发 —— 与 items 的 effect_json 同一条理由:
    // 运营得看得见「特性结构到底是什么」才判断得出 trait_multiplier 该调多少,但结构本身不可编辑
    public function npcs(): JsonResponse
    {
        $rows = DB::table('npc_definition')->orderBy('npc_id')->get(array_merge(
            ['npc_id', 'name_key', 'primary_skill_id', 'trait_json'],
            self::NPC_EDITABLE
        ));

        return ApiResponse::ok(['data' => ['npcs' => $rows, 'editable' => self::NPC_EDITABLE]]);
    }

    // 调整:与 editBuildingLevel 逐条同款(allowlist 字段 + 非负 + 强制 reason + 行锁 + 审计 + 版本递增)。
    // 改 NPC 数值同样会改变全服产出(工资进结算的支出通道、初始等级直接决定乘区),
    // 所以必须 bump game_data_version —— 否则半年后回查「他当时的工资为什么是 8」会查不出来(§64/§65)
    public function editNpc(Request $request): JsonResponse
    {
        // W14-A 扩列后字段分三类,value 的形状按 field 分流(与 editMarketDefinition 的 trade_mode 分流同款):
        // 枚举 / 文本列收 string(限长后逐字段再对权威来源校验),数值列收 numeric + min:0
        //(放行负数会让 wage_per_min 变成「雇一个人反而每分钟生钱」)
        $isString = in_array(
            $request->input('field'),
            array_merge(self::NPC_ENUM_EDITABLE, array_keys(self::NPC_TEXT_MAX)),
            true
        );

        $data = $request->validate([
            'npc_id' => ['required', 'string', 'max:16'],
            'field'  => ['required', 'string'],
            'value'  => $isString
                ? ['required', 'string', 'max:191']
                : ['required', 'numeric', 'min:0'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        if (! in_array($data['field'], self::NPC_EDITABLE, true)) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['field' => ['字段不可编辑']]]);
        }

        if ($isString) {
            $value = (string) $data['value'];
            $error = self::validateNpcStringField($data['field'], $value);
            if ($error !== null) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => ['value' => [$error]]]);
            }
        } else {
            $value = (float) $data['value'];
            if ($value > self::NPC_FIELD_MAX[$data['field']]) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                    'errors' => ['value' => ['超出该字段允许的上限 ' . self::NPC_FIELD_MAX[$data['field']]]],
                ]);
            }

            // 等级类字段必须是 1~10 的整数(§6.2 曲线只有 10 级):
            // 填 0 或 3.5 会让 NpcBonus 查曲线时落空,静默变成「这个 NPC 没有加成」
            if (in_array($data['field'], ['initial_skill_level', 'max_level'], true)
                && ($value < 1 || floor($value) !== $value)) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                    'errors' => ['value' => ['等级必须是 1~10 的整数']],
                ]);
            }

            // 技能值是 int 列(W14-A 补漏):62.5 写进去会被静默截断成 62,后台显示与库里从此不一致
            if ($data['field'] === 'initial_skill_value' && floor($value) !== $value) {
                return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                    'errors' => ['value' => ['该字段必须是整数']],
                ]);
            }
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $value, $admin) {
            // lockForUpdate:锁住该行直到事务提交,防止并发编辑时 before/after 审计值出现丢失更新
            $row = DB::table('npc_definition')->where('npc_id', $data['npc_id'])->lockForUpdate()->first();
            if (! $row) {
                return null;
            }

            // 两个等级列改单列时也要与另一列现值合并自洽(W14-A):
            // initial > max 的 NPC 招出来第一天就「超上限」,而升级逻辑再也拉不回来
            if ($data['field'] === 'initial_skill_level' && (float) $value > (float) $row->max_level) {
                return 'level_pair';
            }
            if ($data['field'] === 'max_level' && (float) $value < (float) $row->initial_skill_level) {
                return 'level_pair';
            }

            $before = $row->{$data['field']};
            DB::table('npc_definition')->where('npc_id', $data['npc_id'])->update([$data['field'] => $value]);

            $version = GameDataVersion::bump(
                "调整 {$data['npc_id']} {$data['field']}: {$before} → {$value}",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'npc_definition',
                'entity_id' => $data['npc_id'],
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
        if ($result === 'level_pair') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['initial_skill_level 不得超过 max_level(改单列时与另一列现值合并校验)']],
            ]);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // 枚举 / 文本列的取值校验(editNpc 与 addNpc 共用)。返回 null = 合法,字符串 = 错误信息。
    // Fail Closed:对不上权威来源一律拒 —— 枚举列拼错在运行时只会「静默不生效」,是最难查的一类问题
    private static function validateNpcStringField(string $field, string $value): ?string
    {
        return match (true) {
            $field === 'category'       => DB::table('npc_definition')->where('category', $value)->exists()
                ? null : '未登记的 NPC category(只能用库内已有分类,新增分类走迁移)',
            $field === 'min_era'        => self::eraExists($value) ? null : '不存在的时代 key',
            $field === 'rarity'         => in_array($value, NpcCode::RARITIES, true)
                ? null : '不是合法稀有度 code(见 NpcCode::RARITIES)',
            $field === 'recruit_source' => in_array($value, NpcCode::SOURCES, true)
                ? null : '不是合法获取来源 code(见 NpcCode::SOURCES)',
            // 文本列:非空 + 列宽(validate 已收 191,这里再按各列实际列宽收紧,name_zh 是 64)
            default                     => trim($value) === ''
                ? '不能为空'
                : (mb_strlen($value) <= self::NPC_TEXT_MAX[$field] ? null : '长度超过列宽 ' . self::NPC_TEXT_MAX[$field]),
        };
    }

    // era_key 是否存在于时代表(科技 / NPC 的时代列共用;era 表是这一列的权威来源,列上还有外键兜底)
    private static function eraExists(string $eraKey): bool
    {
        return DB::table('era')->where('era_key', $eraKey)->exists();
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
    //
    // W14-A 扩列核对:对照全列,补上 note(自由文本备注,仅后台显示、不参与计算 —— 上市理由 /
    // 停市原因这类「给下一个人看的话」此前没有任何入口能写)。rs_code / market_category / first_era
    // 三列身份列维持只读(理由见上);至此除身份列外全列可编辑。
    private const MARKET_EDITABLE = [
        'base_price', 'min_price', 'max_price',
        'volatility', 'elasticity', 'fee_rate', 'base_liquidity',
        'trade_mode', 'note',
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
        // trade_mode / note 不在只读列里 —— 两者已是可编辑字段(W11-B / W14-A),由 MARKET_EDITABLE 带出,
        // 两处都列会让 SELECT 出现重复列名
        $rows = DB::table('market_definition')->orderBy('rs_code')->get(array_merge(
            ['resource_id', 'rs_code', 'market_category', 'first_era'],
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
        // 字符串字段(trade_mode / note)与数值字段的校验规则按字段分流:
        // 其余七个数值字段仍是 numeric + min:0(负的 base_price 会让「买入」变成给玩家发钱,
        // 负的 fee_rate 会让往返套利立刻转正)
        $isTradeMode = $request->input('field') === 'trade_mode';
        // note 是自由文本(W14-A 扩列):只限长(对齐列宽 VARCHAR(191)),不做枚举
        $isNote = $request->input('field') === 'note';

        $data = $request->validate([
            'resource_code' => ['required', 'string', 'max:32'],
            'field'         => ['required', 'string'],
            'value'         => $isTradeMode || $isNote
                ? ['required', 'string', 'max:' . ($isNote ? 191 : 32)]
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
        } elseif ($isNote) {
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
            // W14-A 补:价格三元组必须 min ≤ base ≤ max(改单列时与另两列现值合并校验)。
            // base 掉出夹取区间的话,每个 epoch 的目标价一算出来就被夹回边界 —— 价格永远贴边抖,
            // 波动率与弹性两个旋钮从此都「改了没反应」
            if ((float) $after['base_price'] < (float) $after['min_price']
                || (float) $after['base_price'] > (float) $after['max_price']) {
                return 'base_out_of_range';
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
        if ($result === 'base_out_of_range') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['value' => ['改动会让 base_price 掉出 [min_price, max_price] 区间,价格将永远贴着边界']],
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
    // 死列裁决已落地(用户 2026-08-13「按建议删」,迁移 2026_08_13_300001):
    //   population_min / governance_ratio_min / happiness_min / base_workers / base_build_seconds
    //   五个零引用死列已物理删除;upgrade_to_building_id **保留**(跨代升级链数据地基,
    //   有 EnumCodeTest 整套守护)但仍不进 allowlist —— 改升级去向是改进化树拓扑,走迁移。
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

    // ==================== W14-A:定义表「新增」端点 ====================
    //
    // 四个端点走与编辑器同一条 11 步管线(allowlist → 校验 → 强制 reason → 事务 → 审计 → GDV bump →
    // 缓存 flush),差别只在审计的 create 语义:before 为空(此前没有这一行),after 记完整新行,
    // entity 记新 ID。新增绝不做删除;所有 ID 格式 / 唯一性 / 外键存在性 Fail Closed。

    // 新科技 ID 的格式:库内 50 条全部是 TECH_ + 大写字母/数字段(下划线分段,如 TECH_VI_IND)。
    // 放宽到任意大写段而不是死磕「罗马数字_分支缩写」—— 新科技未必落在 5 分支 × 10 时代的网格上,
    // 但前缀与大小写风格必须一致;总长由 validate 按列宽 32 收
    private const TECH_ID_PATTERN = '/^TECH_[A-Z0-9]+(?:_[A-Z0-9]+)*$/';

    // 新 NPC ID 的格式:库内 150 行全部是 N + 三位数字(N001~N150),照抄这个风格
    private const NPC_ID_PATTERN = '/^N\d{3}$/';

    // trait_json 单条 spec 的数值护栏(新增 NPC 用):
    // pct 10(= +1000%)与 trait_multiplier 同上限同理由 —— 再高只会顶爆 §6.4 / §13 的帽;
    // flat 1e6 与金额类字段同量级(库内现值最高 flat +30)
    private const TRAIT_SPEC_PCT_MAX = 10;
    private const TRAIT_SPEC_FLAT_MAX = 1000000;

    // ---- ① 建筑等级加一行 ----
    //
    // level 由服务端算 = 该建筑当前最高级 + 1(客户端不传 level):等级连续性不是「校验出来的」,
    // 是「构造出来的」—— 与 W13-2 导入的连续性不变量同一条底线,断档在这里直接不可能发生。
    // 数值列与三个 JSON 列的校验完全复用导入那一套(FIELD_MAX / 整数特判 / 资源 allowlist / JSON_MAX)。
    public function addBuildingLevel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'building_id' => ['required', 'string', 'max:16'],
            // reason 上限对齐 audit_logs.reason_code 的 VARCHAR(80)
            'reason'      => ['required', 'string', 'min:2', 'max:80'],
            'values'      => ['required', 'array'],
        ]);

        // building_id 必须已存在 —— 不准借道「加等级」新建建筑(结构性变更走迁移,与导入同一条纪律)
        if (! DB::table('building_definition')->where('building_id', $data['building_id'])->exists()) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['building_id' => ['building_id 不存在(新增等级不能新建建筑)']],
            ]);
        }

        $errors = [];
        $parsed = self::parseBuildingLevelValues($data['values'], $errors);
        if ($errors !== []) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => $errors]);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($data, $parsed, $admin) {
            // lockForUpdate 锁住该建筑全部现有等级行,防并发双加:后一个请求在这里排队,
            // 醒来后重算 max 拿到正确的下一级 —— 不会出现两行同级或断档
            $levels = DB::table('building_level_definition')
                ->where('building_id', $data['building_id'])
                ->lockForUpdate()->pluck('level');
            $level = (int) ($levels->max() ?? 0) + 1;

            // level 列是 unsignedTinyInteger,255 是物理上限(与单格编辑器同一条理由)
            if ($level > 255) {
                return 'level_overflow';
            }

            DB::table('building_level_definition')->insert(array_merge([
                'building_id' => $data['building_id'],
                'level'       => $level,
                // cost_type 是无程序读点的历史描述列,沿用最近一档的既有 code(与导入同一口径)
                'cost_type'   => self::costTypeForLevel($level),
            ], $parsed));

            $version = GameDataVersion::bump(
                "新增 {$data['building_id']} L{$level}(建筑等级行)",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'building_level_definition',
                'entity_id' => $data['building_id'] . ':' . $level,
                'reason_code' => $data['reason'],
                'after_json' => $parsed,
                'metadata_json' => ['game_data_version' => $version, 'operation' => 'create'],
            ]);

            return ['building_id' => $data['building_id'], 'level' => $level, 'version' => $version];
        });

        if ($result === 'level_overflow') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['values' => ['该建筑已到 level 列的物理上限 255,不能再加']],
            ]);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // 新增等级行的 values 校验(与 parseImportRow 同一套护栏,错误按「values.列名」收集)。
    // 通过时返回可直接 insert 的列数组(JSON 列已规范化编码;JSON 列接受对象/数组或其 JSON 字符串)
    private static function parseBuildingLevelValues(array $values, array &$errors): array
    {
        $parsed = [];

        // 七个数值列:与 editBuildingLevel / 导入同一套 FIELD_MAX / 非负 / 整数特判
        foreach (self::EDITABLE as $col) {
            $value = $values[$col] ?? null;
            if (! is_numeric($value)) {
                $errors["values.{$col}"] = [$value === null ? '不能为空' : '必须是数字'];
                continue;
            }
            $value = (float) $value;
            if ($value < 0) {
                $errors["values.{$col}"] = ['不能是负数'];
                continue;
            }
            if ($value > self::BUILDING_FIELD_MAX[$col]) {
                $errors["values.{$col}"] = ['超出该字段允许的上限 ' . self::BUILDING_FIELD_MAX[$col]];
                continue;
            }
            if (in_array($col, ['duration_seconds', 'worker_required'], true)) {
                if (floor($value) !== $value) {
                    $errors["values.{$col}"] = ['该字段必须是整数(int 列,小数会被静默截断)'];
                    continue;
                }
                $value = (int) $value;
            }
            $parsed[$col] = $value;
        }

        // 三个 JSON 列:合法 JSON + 条目过资源 allowlist + 数值过 BUILDING_JSON_MAX(与导入同一套)
        foreach (self::BUILDING_JSON_COLUMNS as $col) {
            $raw = $values[$col] ?? null;
            if (is_string($raw) && trim($raw) !== '') {
                $raw = json_decode(trim($raw), true);
                if (! is_array($raw)) {
                    $errors["values.{$col}"] = ['不是合法的 JSON(必须是数组或对象)'];
                    continue;
                }
            }
            if ($raw === null || $raw === '') {
                if ($col === 'cost_json') {
                    $errors["values.{$col}"] = ['cost_json 不能为空(该列 NOT NULL,免费升级请显式传 {} 并三思)'];
                } else {
                    $parsed[$col] = null; // input/output 允许为空(NULL 列)
                }
                continue;
            }
            if (! is_array($raw)) {
                $errors["values.{$col}"] = ['必须是 JSON 对象 / 数组,或其字符串形式'];
                continue;
            }
            $shapeError = $col === 'cost_json'
                ? self::validateCostJson($raw)
                : self::validateRateJson($raw, self::BUILDING_JSON_MAX[$col]);
            if ($shapeError !== null) {
                $errors["values.{$col}"] = [$shapeError];
                continue;
            }
            // 规范化落库:cost 的空映射要编成 {} 而不是 [](与导入同一条理由)
            $parsed[$col] = $col === 'cost_json' && $raw === []
                ? '{}'
                : json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

        return $parsed;
    }

    // ---- ② 新增科技 ----
    //
    // 与 editTechnology 的分工:编辑只开数值(拓扑锁死,改既有节点的前置会造出环);
    // 新增是「加一个新节点」—— 新节点的前置只能指向**已存在**的科技,从构造上就造不出环。
    // branch 的权威来源是 EnumCode::TECH_BRANCHES(EnumCode::COLUMNS 里登记的这一列的登记表);
    // era_key 的权威来源是 era 表(列上有外键);解锁建筑必须已存在(不准借道发明新建筑)。
    public function addTechnology(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason'                         => ['required', 'string', 'min:2', 'max:80'],
            'values'                         => ['required', 'array'],
            'values.tech_id'                 => ['required', 'string', 'max:32'],
            'values.name'                    => ['required', 'string', 'max:96'],
            'values.era_key'                 => ['required', 'string', 'max:4'],
            'values.branch'                  => ['required', 'string', 'max:32'],
            'values.knowledge_cost'          => ['required', 'numeric', 'min:0'],
            'values.research_minutes'        => ['required', 'numeric', 'min:0'],
            // 两个数组列:present 允许空数组(没有前置 / 不解锁建筑都是合法形态);
            // 上限只是护栏 —— 前置超过 20 条 / 解锁超过 50 栋的科技在设计上不存在
            'values.prerequisite_tech_ids'   => ['present', 'array', 'max:20'],
            'values.prerequisite_tech_ids.*' => ['string', 'max:32'],
            'values.unlock_building_ids'     => ['present', 'array', 'max:50'],
            'values.unlock_building_ids.*'   => ['string', 'max:16'],
        ]);
        $values = $data['values'];

        $errors = [];

        if (! preg_match(self::TECH_ID_PATTERN, $values['tech_id'])) {
            $errors['values.tech_id'] = ['tech_id 必须是 TECH_ 开头的大写字母/数字段(例 TECH_VI_IND)'];
        }
        if (! self::eraExists($values['era_key'])) {
            $errors['values.era_key'] = ['不存在的时代 key'];
        }
        if (! array_key_exists($values['branch'], EnumCode::TECH_BRANCHES)) {
            $errors['values.branch'] = ['branch 不在 EnumCode::TECH_BRANCHES 登记表内'];
        }

        // 数值:与 editTechnology 同一套上限;knowledge_cost 是 int 列必须整数
        $cost = (float) $values['knowledge_cost'];
        if ($cost > self::TECH_FIELD_MAX['knowledge_cost'] || floor($cost) !== $cost) {
            $errors['values.knowledge_cost'] = ['知识成本必须是 0~' . self::TECH_FIELD_MAX['knowledge_cost'] . ' 的整数'];
        }
        $minutes = (float) $values['research_minutes'];
        if ($minutes > self::TECH_FIELD_MAX['research_minutes']) {
            $errors['values.research_minutes'] = ['超出该字段允许的上限 ' . self::TECH_FIELD_MAX['research_minutes']];
        }

        // 前置科技:逐个必须已存在(新节点尚不在表里,引用自己同样会被「不存在」拒掉,但单独报清楚)
        $prereqs = array_values($values['prerequisite_tech_ids']);
        if (count($prereqs) !== count(array_unique($prereqs))) {
            $errors['values.prerequisite_tech_ids'] = ['前置科技 ID 重复'];
        } elseif (in_array($values['tech_id'], $prereqs, true)) {
            $errors['values.prerequisite_tech_ids'] = ['前置科技不能引用自己'];
        } elseif ($prereqs !== []) {
            $known = DB::table('technology_definition')->whereIn('tech_id', $prereqs)->pluck('tech_id')->all();
            $missing = array_values(array_diff($prereqs, $known));
            if ($missing !== []) {
                $errors['values.prerequisite_tech_ids'] = ['前置科技不存在:' . implode('、', $missing)];
            }
        }

        // 解锁建筑:逐个必须已存在于 building_definition
        $unlocks = array_values($values['unlock_building_ids']);
        if (count($unlocks) !== count(array_unique($unlocks))) {
            $errors['values.unlock_building_ids'] = ['解锁建筑 ID 重复'];
        } elseif ($unlocks !== []) {
            $known = DB::table('building_definition')->whereIn('building_id', $unlocks)->pluck('building_id')->all();
            $missing = array_values(array_diff($unlocks, $known));
            if ($missing !== []) {
                $errors['values.unlock_building_ids'] = ['解锁建筑不存在:' . implode('、', $missing)];
            }
        }

        if ($errors !== []) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => $errors]);
        }

        $admin = $request->user();
        $reason = $data['reason'];

        $result = DB::transaction(function () use ($values, $prereqs, $unlocks, $cost, $minutes, $admin, $reason) {
            // 唯一性在锁内判(间隙锁 + 主键约束双保险):并发同 ID 时后一个要么在这里看到行,要么撞 PK 回滚
            if (DB::table('technology_definition')->where('tech_id', $values['tech_id'])->lockForUpdate()->exists()) {
                return 'duplicate';
            }

            $row = [
                'tech_id'               => $values['tech_id'],
                'era_key'               => $values['era_key'],
                'branch'                => $values['branch'],
                'name'                  => $values['name'],
                'knowledge_cost'        => (int) $cost,
                'research_minutes'      => $minutes,
                // 存库格式照现有行:两列都是 json 列存 JSON 数组(TechService / DefinitionController 按 json_decode 读)
                'prerequisite_tech_ids' => json_encode($prereqs, JSON_UNESCAPED_UNICODE),
                'unlock_building_ids'   => json_encode($unlocks, JSON_UNESCAPED_UNICODE),
            ];
            DB::table('technology_definition')->insert($row);

            $version = GameDataVersion::bump(
                "新增科技 {$values['tech_id']}({$values['name']})",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'technology_definition',
                'entity_id' => $values['tech_id'],
                'reason_code' => $reason,
                'after_json' => $row,
                'metadata_json' => ['game_data_version' => $version, 'operation' => 'create'],
            ]);

            return ['tech_id' => $values['tech_id'], 'version' => $version];
        });

        if ($result === 'duplicate') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['values.tech_id' => ['tech_id 已存在']],
            ]);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // ---- ③ 新增 NPC 定义 ----
    //
    // 全列必填(除 trait_multiplier 外无默认可依;name_zh 对新 NPC 强制 —— 150 行现已逐条有名,
    // 新加的没有名字等于回到「只靠 N151 认人」的老问题)。
    // 枚举列对权威来源校验(与 editNpc 共用 validateNpcStringField);
    // trait_json 照 NpcDefinitionSeeder::assertTraitJson 的同一口径:specs 逐条必须能构造成
    // 合法 ModifierSpec(target / scope / op 三重 allowlist),构造失败即拒 ——
    // target 拼错的特性在运行时只会「静默不生效」,那是最难查的一类线上问题。
    public function addNpc(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason'                     => ['required', 'string', 'min:2', 'max:80'],
            'values'                     => ['required', 'array'],
            'values.npc_id'              => ['required', 'string', 'max:16'],
            'values.name_key'            => ['required', 'string', 'max:64'],
            'values.name_zh'             => ['required', 'string', 'max:64'],
            'values.category'            => ['required', 'string', 'max:32'],
            'values.min_era'             => ['required', 'string', 'max:4'],
            'values.primary_skill_id'    => ['required', 'string', 'max:32'],
            'values.initial_skill_value' => ['required', 'numeric', 'min:0'],
            'values.initial_skill_level' => ['required', 'numeric', 'min:0'],
            'values.max_level'           => ['required', 'numeric', 'min:0'],
            'values.wage_per_min'        => ['required', 'numeric', 'min:0'],
            'values.food_per_min'        => ['required', 'numeric', 'min:0'],
            'values.rarity'              => ['required', 'string', 'max:16'],
            'values.recruit_source'      => ['required', 'string', 'max:32'],
            'values.recruit_desc_zh'     => ['required', 'string', 'max:191'],
            'values.trait_desc_zh'       => ['required', 'string', 'max:191'],
            // 对象或 JSON 字符串都收,形状在下面统一校验
            'values.trait_json'          => ['required'],
            'values.trait_multiplier'    => ['required', 'numeric', 'min:0'],
        ]);
        $values = $data['values'];

        $errors = [];

        if (! preg_match(self::NPC_ID_PATTERN, $values['npc_id'])) {
            $errors['values.npc_id'] = ['npc_id 必须是 N + 三位数字(与库内 N001~N150 同风格)'];
        }
        // name_key 是派生键:库内 150 行全部是 npc.{npc_id}.name,不收自由发挥的键名
        if ($values['name_key'] !== 'npc.' . $values['npc_id'] . '.name') {
            $errors['values.name_key'] = ['name_key 必须是 npc.{npc_id}.name'];
        }

        // 四个枚举列 + 三个文本列:与 editNpc 同一套校验
        foreach (array_merge(self::NPC_ENUM_EDITABLE, array_keys(self::NPC_TEXT_MAX)) as $field) {
            $error = self::validateNpcStringField($field, (string) $values[$field]);
            if ($error !== null) {
                $errors["values.{$field}"] = [$error];
            }
        }

        // primary_skill_id 的权威来源是 npc_skill_definition(§6.1 的 12 条,列上有外键)
        if (! DB::table('npc_skill_definition')->where('skill_id', $values['primary_skill_id'])->exists()) {
            $errors['values.primary_skill_id'] = ['不存在的技能 id(见 npc_skill_definition)'];
        }

        // 数值列:逐列 FIELD_MAX + 整数特判(与 editNpc 同一套)
        foreach (['initial_skill_value', 'initial_skill_level', 'max_level', 'wage_per_min', 'food_per_min', 'trait_multiplier'] as $col) {
            $value = (float) $values[$col];
            if ($value > self::NPC_FIELD_MAX[$col]) {
                $errors["values.{$col}"] = ['超出该字段允许的上限 ' . self::NPC_FIELD_MAX[$col]];
            } elseif (in_array($col, ['initial_skill_value', 'initial_skill_level', 'max_level'], true) && floor($value) !== $value) {
                $errors["values.{$col}"] = ['该字段必须是整数'];
            } elseif (in_array($col, ['initial_skill_level', 'max_level'], true) && $value < 1) {
                $errors["values.{$col}"] = ['等级必须是 1~10 的整数'];
            }
        }
        // 等级对必须自洽:initial > max 的 NPC 招出来第一天就「超上限」
        if (! isset($errors['values.initial_skill_level'], $errors['values.max_level'])
            && (float) $values['initial_skill_level'] > (float) $values['max_level']) {
            $errors['values.initial_skill_level'] = ['initial_skill_level 不得超过 max_level'];
        }

        // trait_json:三重 allowlist + 数值护栏,通过时拿到规范化 JSON 串
        $trait = self::validateTraitJson($values['trait_json']);
        if (isset($trait['error'])) {
            $errors['values.trait_json'] = [$trait['error']];
        }

        if ($errors !== []) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => $errors]);
        }

        $admin = $request->user();
        $reason = $data['reason'];

        $result = DB::transaction(function () use ($values, $trait, $admin, $reason) {
            // 唯一性在锁内判(间隙锁 + 主键约束双保险)
            if (DB::table('npc_definition')->where('npc_id', $values['npc_id'])->lockForUpdate()->exists()) {
                return 'duplicate';
            }

            $row = [
                'npc_id'              => $values['npc_id'],
                'name_key'            => $values['name_key'],
                'name_zh'             => $values['name_zh'],
                'category'            => $values['category'],
                'min_era'             => $values['min_era'],
                'primary_skill_id'    => $values['primary_skill_id'],
                'initial_skill_value' => (int) $values['initial_skill_value'],
                'initial_skill_level' => (int) $values['initial_skill_level'],
                'max_level'           => (int) $values['max_level'],
                'wage_per_min'        => (float) $values['wage_per_min'],
                'food_per_min'        => (float) $values['food_per_min'],
                'rarity'              => $values['rarity'],
                'recruit_source'      => $values['recruit_source'],
                'recruit_desc_zh'     => $values['recruit_desc_zh'],
                'trait_desc_zh'       => $values['trait_desc_zh'],
                'trait_json'          => $trait['json'],
                'trait_multiplier'    => (float) $values['trait_multiplier'],
            ];
            DB::table('npc_definition')->insert($row);

            $version = GameDataVersion::bump(
                "新增 NPC {$values['npc_id']}({$values['name_zh']})",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'npc_definition',
                'entity_id' => $values['npc_id'],
                'reason_code' => $reason,
                'after_json' => $row,
                'metadata_json' => ['game_data_version' => $version, 'operation' => 'create'],
            ]);

            return ['npc_id' => $values['npc_id'], 'version' => $version];
        });

        if ($result === 'duplicate') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['values.npc_id' => ['npc_id 已存在']],
            ]);
        }

        return ApiResponse::ok(['data' => $result]);
    }

    // trait_json 校验(与 NpcDefinitionSeeder::assertTraitJson 同一口径):specs 逐条必须能构造成
    // 合法 ModifierSpec(target / scope / op 三重 allowlist,构造失败即拒),另加数值护栏。
    // 接受对象或 JSON 字符串;通过时返回 ['json' => 规范化 JSON 串],失败返回 ['error' => 信息]。
    // 规范化时只保留 specs / unmapped_zh 两个已知键 —— 不让夹带的私货字段进库
    private static function validateTraitJson(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode(trim($raw), true);
        }
        if (! is_array($raw)) {
            return ['error' => '必须是 {"specs":[…],"unmapped_zh":[…]} 结构的对象,或其 JSON 字符串'];
        }

        $specs = $raw['specs'] ?? [];
        $unmapped = $raw['unmapped_zh'] ?? [];
        if (! is_array($specs) || ! array_is_list($specs) || ! is_array($unmapped) || ! array_is_list($unmapped)) {
            return ['error' => 'specs 与 unmapped_zh 都必须是列表'];
        }
        foreach ($unmapped as $i => $text) {
            if (! is_string($text)) {
                return ['error' => "unmapped_zh 第 {$i} 条必须是字符串"];
            }
        }

        foreach ($specs as $i => $spec) {
            if (! is_array($spec)) {
                return ['error' => "specs 第 {$i} 条必须是对象"];
            }
            try {
                new ModifierSpec(
                    (string) ($spec['target'] ?? ''),
                    (string) ($spec['scope'] ?? ''),
                    (string) ($spec['op'] ?? ''),
                    (float) ($spec['value'] ?? 0),
                    $spec['scope_key'] ?? null,
                );
            } catch (InvalidArgumentException $e) {
                return ['error' => "specs 第 {$i} 条非法 —— " . $e->getMessage()];
            }
            $max = ($spec['op'] ?? '') === ModifierSpec::OP_PCT ? self::TRAIT_SPEC_PCT_MAX : self::TRAIT_SPEC_FLAT_MAX;
            if (abs((float) ($spec['value'] ?? 0)) > $max) {
                return ['error' => "specs 第 {$i} 条的 value 绝对值超出上限 {$max}"];
            }
        }

        return ['json' => json_encode(['specs' => $specs, 'unmapped_zh' => $unmapped], JSON_UNESCAPED_UNICODE)];
    }

    // ---- ④ 新增市场定义(上市)----
    //
    // resource_id 必须已存在于 resource_definition 且尚无 market_definition 行(不准借道发明新资源)。
    // 身份列不让客户端传,服务器派生(防不一致):
    //   first_era 抄 resource_definition.first_era —— 新行没有 §8 原文可依,资源表是唯一权威;
    //   rs_code 优先抄 resource_definition.rs_code(§8 已编号但未上市的资源沿用编号),
    //     资源表没编号(NULL)时顺延市场表现有最大编号 +1(与 RS027 水泥 / RS028 药品的先例同一规则)。
    // market_category 是例外:resource_definition.category(6 个资源类)与它(11 个市场分组)
    // 语义不同、派生不出来 —— 收客户端值但只认库内已有分组(Fail Closed,新分组走迁移)。
    // trade_mode 只收 spot / non_tradeable:capacity_contract 是电力的特例,新资源不该长成它。
    public function addMarketDefinition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason'                 => ['required', 'string', 'min:2', 'max:80'],
            'values'                 => ['required', 'array'],
            'values.resource_id'     => ['required', 'string', 'max:32'],
            'values.market_category' => ['required', 'string', 'max:32'],
            'values.trade_mode'      => ['required', 'string', 'max:24'],
            'values.base_price'      => ['required', 'numeric', 'min:0'],
            'values.min_price'       => ['required', 'numeric', 'min:0'],
            'values.max_price'       => ['required', 'numeric', 'min:0'],
            'values.volatility'      => ['required', 'numeric', 'min:0'],
            'values.elasticity'      => ['required', 'numeric', 'min:0'],
            'values.fee_rate'        => ['required', 'numeric', 'min:0'],
            'values.base_liquidity'  => ['required', 'numeric', 'min:0'],
            'values.note'            => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);
        $values = $data['values'];

        $errors = [];

        $resource = DB::table('resource_definition')->where('resource_id', $values['resource_id'])->first();
        if ($resource === null) {
            $errors['values.resource_id'] = ['resource_id 不存在于 resource_definition(上市不能发明新资源)'];
        }

        if (! DB::table('market_definition')->where('market_category', $values['market_category'])->exists()) {
            $errors['values.market_category'] = ['未登记的市场分组(只能用库内已有分组,新分组走迁移)'];
        }

        if (! in_array($values['trade_mode'], self::MARKET_TRADE_MODE_SWITCHABLE, true)) {
            $errors['values.trade_mode'] = ['trade_mode 只允许 spot 或 non_tradeable(产能合约是电力的特例)'];
        }

        // 七个数值列:与 editMarketDefinition 同一套上限
        foreach (self::MARKET_FIELD_MAX as $col => $max) {
            if ((float) $values[$col] > $max) {
                $errors["values.{$col}"] = ['超出该字段允许的上限 ' . $max];
            }
        }

        // 价格三元组跨字段校验:min ≤ base ≤ max;现货 base 必须 > 0(与编辑器同一套理由)
        $base = (float) $values['base_price'];
        if ((float) $values['min_price'] > (float) $values['max_price']
            || $base < (float) $values['min_price'] || $base > (float) $values['max_price']) {
            $errors['values.base_price'] = ['价格三元组必须满足 min_price ≤ base_price ≤ max_price'];
        } elseif ($values['trade_mode'] === MarketDefinition::TRADE_MODE_SPOT && $base <= 0) {
            $errors['values.base_price'] = ['现货资源的 base_price 必须大于 0,否则该资源会变成免费'];
        }

        if ($errors !== []) {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, ['errors' => $errors]);
        }

        $admin = $request->user();
        $reason = $data['reason'];

        $result = DB::transaction(function () use ($values, $resource, $admin, $reason) {
            // 唯一性 + rs_code 顺延都要在锁内做:lockForUpdate 锁住整张定义表(28 行,低频管理操作),
            // 并发两笔上市不会拿到同一个顺延编号,也不会给同一资源插两行
            $rsCodes = DB::table('market_definition')->lockForUpdate()->pluck('rs_code', 'resource_id')->all();
            if (array_key_exists($values['resource_id'], $rsCodes)) {
                return 'duplicate';
            }

            $rsCode = $resource->rs_code;
            if ($rsCode === null || in_array($rsCode, $rsCodes, true)) {
                $maxNum = 0;
                foreach ($rsCodes as $code) {
                    if (preg_match('/^RS(\d+)$/', (string) $code, $m)) {
                        $maxNum = max($maxNum, (int) $m[1]);
                    }
                }
                $rsCode = 'RS' . str_pad((string) ($maxNum + 1), 3, '0', STR_PAD_LEFT);
            }

            $note = isset($values['note']) && trim((string) $values['note']) !== '' ? (string) $values['note'] : null;

            $row = [
                'resource_id'     => $values['resource_id'],
                'rs_code'         => $rsCode,
                'market_category' => $values['market_category'],
                'first_era'       => $resource->first_era,
                'trade_mode'      => $values['trade_mode'],
                'base_price'      => (float) $values['base_price'],
                'min_price'       => (float) $values['min_price'],
                'max_price'       => (float) $values['max_price'],
                'volatility'      => (float) $values['volatility'],
                'elasticity'      => (float) $values['elasticity'],
                'fee_rate'        => (float) $values['fee_rate'],
                'base_liquidity'  => (float) $values['base_liquidity'],
                'note'            => $note,
            ];
            DB::table('market_definition')->insert($row);

            $version = GameDataVersion::bump(
                "市场上市 {$values['resource_id']}({$rsCode})",
                'admin:' . $admin->username
            );

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type' => 'admin', 'actor_id' => $admin->id, 'user_id' => $admin->id,
                'entity_type' => 'market_definition',
                'entity_id' => $values['resource_id'],
                'reason_code' => $reason,
                'after_json' => $row,
                'metadata_json' => ['game_data_version' => $version, 'operation' => 'create'],
            ]);

            return ['resource_id' => $values['resource_id'], 'version' => $version];
        });

        if ($result === 'duplicate') {
            return ApiResponse::fail(ErrorCode::VALIDATION_ERROR, 422, [
                'errors' => ['values.resource_id' => ['该资源已有市场定义行(改数值请走编辑器)']],
            ]);
        }

        // 定义有请求级缓存,新增完必须失效 —— 否则同一请求后续的价目表还看不见这一行
        MarketDefinition::flush();

        return ApiResponse::ok(['data' => $result]);
    }
}
