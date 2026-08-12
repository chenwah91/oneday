<?php

namespace App\Game\Defense;

use App\Game\City\EraService;
use App\Game\Item\ItemCode;
use App\Game\Item\ItemDefinition;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcBonus;
use App\Game\NPC\NpcCode;
use App\Support\GameSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 国防联动(M3-D5,v3.2 §11 的 defense_score / threat_level 两个字段 + §17 国防行 + backlog 9.E 区)。
//
// ══ 一句话 ═══════════════════════════════════════════════════════════════════
// 本类是**国防威胁侧的唯一口径**:威胁需求、有效国防值、覆盖率、威胁等级、EVT_RAID 的损失率
// 全部由 evaluate() 一次算出。快照的 defense 区块、事件条件的 threat_level、
// EVT_RAID 的损失公式三处读的是**同一个** evaluate() 结果,不存在「两处各算一次」的分叉。
//
// ══ 威胁需求:单一来源,不复制第二份数字 ══════════════════════════════════════
// 9.E1 批准「威胁需求表 = 直接复用 §5.1 已定稿的九档『国防最低』」,所以这里调用
// EraService::defenseRequirement() 取数,本文件里**一个九档数字都没有**。
// 口径:处在时代 N 的城市看的是「升出时代 N 所需的国防最低」(时代 I → 20,时代 IX → 8000,
// 时代 X 沿用 8000)。运营要调难度走全局倍率 defense_threat_demand_multiplier,不改九档。
//
// ══ 分档(9.E1 + v3.2 §16.5)═════════════════════════════════════════════════
// §16.5 明列「威胁等级判定与威胁需求数值表」为 M3 延后项 —— v3.2 正文里没有分档明细,
// 所以按覆盖率分档,两个切点都是后台设定(默认 1.00 / 0.60):
//     coverage ≥ 1.00        → low    安全
//     0.60 ≤ coverage < 1.00 → medium 紧张
//     coverage < 0.60        → high   危险
// 枚举值取 §11 明文的 low / medium / high(中文名只作显示,不参与判定)。
//
// ══ flat / pct 为什么在读取侧聚合 ═══════════════════════════════════════════
// defense_score 是**容量类产出**,结算内核在乘区之前就把它从 output_json 提取成全城值。
// 本波次与电力波次并行,SimulationService 归电力所有者(backlog §10.2 文件所有权互斥),
// 所以国防的 flat / pct 一律在读取侧由本类叠加,内核容量提取一个字未改。
//
// 合成顺序固定(唯一一处,别处不许再算):
//     有效国防值 = max(0, (建筑口径 + Σdefense_score_flat) × (1 + Σdefense_score_pct))
//     威胁需求   = §5.1 国防最低 × 全局倍率 × (1 + Σthreat_demand_pct)
//
// **已知口径差(W4-B 明示保留)**:§10.8 的 security 覆盖率与 §10.2 的国防幸福加成仍读
// **建筑口径**(不含 flat / pct)。两个理由:①内核归并行波次所有,本波次不动它;
// ②语义上那两处要的是「常备城防 / 人口」,而威胁等级要含临时增援(动员守军的 +25% 该顶得住
// 这一次劫掠,却不该让全城治安凭空跳一档)。内核合并后若要统一,只需在那两处把
// $defenseScore 换成 effectiveDefenseScore(),本类已经把入口备好。
//
// ══ 时代门槛读哪一份 ═════════════════════════════════════════════════════════
// EraService 的时代升级判定(DIM_DEFENSE)继续读**建筑口径**,不含本类的临时加成 ——
// 否则玩家可以靠一个 20 分钟的事件 buff 顶过时代门槛,buff 一过城市就"倒退"回未达标。
// 时代要的是常备国防,威胁等级要的是此刻实力,两者刻意不同源。
final class DefenseService
{
    // ---------- 威胁等级(§11 的 enum low / medium / high)----------

    public const LEVEL_LOW = 'low';       // 安全:国防覆盖率达标
    public const LEVEL_MEDIUM = 'medium'; // 紧张:未达标但仍在缓冲区内
    public const LEVEL_HIGH = 'high';     // 危险:国防严重不足

    public const LEVELS = [self::LEVEL_LOW, self::LEVEL_MEDIUM, self::LEVEL_HIGH];

    // 档序号:事件条件(「威胁等级≥中」)与权重修正(「国防达标」)都按序号比较,
    // 字符串枚举没有大小关系,序号才有
    public const LEVEL_RANKS = [
        self::LEVEL_LOW    => 0,
        self::LEVEL_MEDIUM => 1,
        self::LEVEL_HIGH   => 2,
    ];

    // 中文显示名(只作显示,不参与任何判定)
    public const LEVEL_NAMES_ZH = [
        self::LEVEL_LOW    => '安全',
        self::LEVEL_MEDIUM => '紧张',
        self::LEVEL_HIGH   => '危险',
    ];

    // ---------- 主入口 ----------

    // 国防读数块。$sim = 本次 SimulationService 的结算结果(defenseScore 取它,不另算一份)。
    //
    // 返回的键一律 snake_case:它直接进 HTTP 契约(快照的 defense 区块),
    // 也直接进事件条件的 metrics —— 两处同形,免得再做一次键名转换。
    public static function evaluate(object $city, array $sim, ?Carbon $now = null): array
    {
        $now ??= now();
        $cityId = (int) $city->id;
        $eraOrder = (int) ($city->era_order ?? 1);

        $base = (float) ($sim['defenseScore'] ?? 0.0);
        $bonus = self::bonuses($cityId, $now);

        // 有效国防值:先加 flat 再乘 pct(顺序固定,写在类注释里)。
        // 下限夹 0:后台把系数调成大负数也不该出现负国防值
        $score = max(0.0, ($base + $bonus['flat']) * (1.0 + $bonus['pct']));

        // 威胁需求:九档来自 EraService(单一来源)× 全局倍率 × 事件抬升
        $demandBase = EraService::defenseRequirement($eraOrder)
            * (float) GameSetting::get(GameSetting::DEFENSE_THREAT_DEMAND_MULTIPLIER);
        $demand = max(0.0, $demandBase * (1.0 + $bonus['demand_pct']));

        // 需求为 0(运营把倍率调成 0,或将来出现无威胁时代):没有分母就没有威胁,
        // 覆盖率按 1.0 记(= 安全档),绝不做 0 除
        $coverage = $demand > 0 ? $score / $demand : 1.0;

        // 国防总开关(运营救急):关掉之后威胁档恒为安全(low),EVT_RAID 也就不会造成任何损失
        //(raidLossPct 的安全档倍率是 0)。国防值 / 需求 / 覆盖率的读数照常算出来回传 ——
        // 与物流 / 电力两个开关同一条口径:止血的同时还看得见「本来会是什么档」
        $level = GameSetting::get(GameSetting::DEFENSE_GATE_ENABLED) === true
            ? self::levelFor($coverage)
            : self::LEVEL_LOW;

        return [
            'threat_level'       => $level,
            'threat_rank'        => self::LEVEL_RANKS[$level],
            'defense_score'      => round($score, 4),
            // 建筑口径(= 内核从 output_json 聚合的容量值):快照里一并给出,
            // 玩家才看得出「我的国防有多少是常备的、多少是临时 buff」
            'defense_score_base' => round($base, 4),
            'defense_flat'       => round($bonus['flat'], 4),
            'defense_pct'        => round($bonus['pct'], 6),
            'threat_demand'      => round($demand, 4),
            'threat_demand_base' => round($demandBase, 4),
            'threat_demand_pct'  => round($bonus['demand_pct'], 6),
            'coverage'           => round($coverage, 6),
        ];
    }

    // 有效国防值(便捷入口)。内核合并后若要让 §10.8 治安 / §10.2 幸福也读有效值,
    // 在那两处调用本方法即可 —— 参数与 evaluate 一致,不会出现第二套合成顺序
    public static function effectiveDefenseScore(object $city, array $sim, ?Carbon $now = null): float
    {
        return (float) self::evaluate($city, $sim, $now)['defense_score'];
    }

    // 覆盖率 → 威胁档。两个切点都是后台设定;
    // 若运营把「紧张」阈值调得比「安全」还高,以安全阈值为准(不让配置错误造出空档)
    public static function levelFor(float $coverage): string
    {
        $safe = (float) GameSetting::get(GameSetting::DEFENSE_THREAT_COVERAGE_SAFE);
        $tense = min((float) GameSetting::get(GameSetting::DEFENSE_THREAT_COVERAGE_TENSE), $safe);

        if ($coverage >= $safe) {
            return self::LEVEL_LOW;
        }

        return $coverage >= $tense ? self::LEVEL_MEDIUM : self::LEVEL_HIGH;
    }

    // ---------- EVT_RAID 损失率(9.E2 + v3.2 §17)----------

    // 返回**正数比例**(0.30 = 损失 30%),调用方自己取负。
    //
    //   缺口率 = clamp(1 − coverage, 0, 1)
    //   损失率 = clamp(缺口率 × 基础倍率 × 威胁档倍率, 0, 上限)
    //
    // 默认参数(基础 1.0 / 紧张档 1.0 / 上限 0.30)下,紧张档退化成 9.E2 的原式
    //     lossPct = clamp(1 − defense_score / threat_requirement, 0, 0.30);
    // 危险档再乘 1.5,承接 §17「事件损失倍率随国防缺口放大」的方向,但仍受同一个上限夹取。
    // 安全档恒 0:达标的城市不该被劫掠(EVT_RAID 的条件本来也进不来)。
    public static function raidLossPct(array $defense): float
    {
        // 总开关关掉时直接 0:evaluate() 已经把威胁档压成 low(倍率 0),这里再拦一道是
        // 为了「调用方自己拼了一个 defense 数组」的路径也照样不掉血(Fail Closed 的反向:止血要止得干净)
        if (GameSetting::get(GameSetting::DEFENSE_GATE_ENABLED) !== true) {
            return 0.0;
        }

        $tierMultiplier = match ($defense['threat_level'] ?? self::LEVEL_LOW) {
            self::LEVEL_HIGH   => (float) GameSetting::get(GameSetting::DEFENSE_RAID_LOSS_MULT_HIGH),
            self::LEVEL_MEDIUM => (float) GameSetting::get(GameSetting::DEFENSE_RAID_LOSS_MULT_MEDIUM),
            default            => 0.0,
        };

        $shortfall = max(0.0, min(1.0, 1.0 - (float) ($defense['coverage'] ?? 1.0)));
        $raw = $shortfall
            * (float) GameSetting::get(GameSetting::DEFENSE_RAID_LOSS_BASE_MULTIPLIER)
            * $tierMultiplier;

        return max(0.0, min($raw, (float) GameSetting::get(GameSetting::DEFENSE_RAID_LOSS_MAX_PCT)));
    }

    // ---------- 快照(CityController 的 M3-DEFENSE 锚点)----------

    // §11 点名的两个字段是 defense_score 与 threat_level;另外四项(需求 / 覆盖率 / flat / pct)
    // 一并给出,否则玩家只看到一个「危险」标签却无从知道差多少、差在哪
    public static function snapshot(object $city, array $sim, ?Carbon $now = null): array
    {
        $defense = self::evaluate($city, $sim, $now);
        $defense['threat_level_zh'] = self::LEVEL_NAMES_ZH[$defense['threat_level']];

        return $defense;
    }

    // ---------- 三个 target 的聚合(唯一消费点)----------

    // 返回 ['flat' => Σdefense_score_flat, 'pct' => Σdefense_score_pct, 'demand_pct' => Σthreat_demand_pct]。
    //
    // 三个来源,各自只数一次:
    //   ① city_active_modifiers 里生效中的行(事件写的,如动员守军 +25%、边境紧张需求 +30%);
    //   ② 在编 NPC 的特性(§6.3 N010 +12 / N016 +15% / N027 +20%);
    //   ③ 已装备且耐久 > 0 的工具(§7 IT008 +8)。
    //
    // 为什么工具按「件」累加而不是像 tool 乘区那样同类取最高:§7 的「同类只取最高」是
    // **单栋建筑内**的规则(防止一栋楼堆满同款工具刷产量),而国防 flat 是全城效果 ——
    // 两件青铜卫士装在两栋楼上就是两份城防,这与「一栋楼里塞十把镐子」不是一回事。
    private static function bonuses(int $cityId, Carbon $now): array
    {
        $total = ['flat' => 0.0, 'pct' => 0.0, 'demand_pct' => 0.0];

        // ---- ① 生效中的 modifier 行 ----
        if (DB::getSchemaBuilder()->hasTable('city_active_modifiers')) {
            $rows = DB::table('city_active_modifiers')
                ->where('city_id', $cityId)
                ->whereIn('target', [
                    ModifierTarget::DEFENSE_SCORE_FLAT,
                    ModifierTarget::DEFENSE_SCORE_PCT,
                    ModifierTarget::THREAT_DEMAND_PCT,
                ])
                ->where('scope', ModifierSpec::SCOPE_CITY)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>', $now)
                ->get(['target', 'op', 'value']);

            foreach ($rows as $row) {
                self::accumulate($total, (string) $row->target, (string) $row->op, (float) $row->value);
            }
        }

        // ---- ② 在编 NPC 的特性(只读 NPC 的定义与在编状态,不写、不改 NPC 系统任何东西)----
        if (DB::getSchemaBuilder()->hasTable('city_npcs')) {
            $traits = DB::table('city_npcs as cn')
                ->join('npc_definition as nd', 'cn.npc_id', '=', 'nd.npc_id')
                ->where('cn.city_id', $cityId)
                ->whereIn('cn.status', NpcCode::ACTIVE_STATUSES)
                ->pluck('nd.trait_json');

            foreach ($traits as $json) {
                self::accumulateSpecs($total, NpcBonus::specsFromJson($json));
            }
        }

        // ---- ③ 已装备且耐久 > 0 的工具(stored / broken 一律不算:前者没装上、后者已报废)----
        if (DB::getSchemaBuilder()->hasTable('city_items')) {
            $equipped = DB::table('city_items')
                ->where('city_id', $cityId)
                ->where('status', ItemCode::STATUS_EQUIPPED)
                ->whereNotNull('equipped_instance_id')
                ->where('durability_left', '>', 0)
                ->pluck('item_id');

            if ($equipped->isNotEmpty()) {
                $definitions = ItemDefinition::all();
                foreach ($equipped as $itemId) {
                    self::accumulateSpecs($total, $definitions[(string) $itemId]['specs'] ?? []);
                }
            }
        }

        return $total;
    }

    // ModifierSpec[] → 累加(只认全城作用域:国防值是全城读数,没有「单栋楼的国防」)
    private static function accumulateSpecs(array &$total, array $specs): void
    {
        foreach ($specs as $spec) {
            if (! $spec instanceof ModifierSpec || $spec->scope !== ModifierSpec::SCOPE_CITY) {
                continue;
            }
            self::accumulate($total, $spec->target, $spec->op, $spec->value);
        }
    }

    // 单条累加。op 必须与 target 的口径一致(flat 通道只收 flat、pct 通道只收 pct):
    // 口径不符的行一律跳过,而不是"猜"一个语义 —— 猜错的效果在运行时只表现为数值悄悄不对
    private static function accumulate(array &$total, string $target, string $op, float $value): void
    {
        match (true) {
            $target === ModifierTarget::DEFENSE_SCORE_FLAT && $op === ModifierSpec::OP_FLAT
                => $total['flat'] += $value,
            $target === ModifierTarget::DEFENSE_SCORE_PCT && $op === ModifierSpec::OP_PCT
                => $total['pct'] += $value,
            $target === ModifierTarget::THREAT_DEMAND_PCT && $op === ModifierSpec::OP_PCT
                => $total['demand_pct'] += $value,
            default => null,
        };
    }
}
