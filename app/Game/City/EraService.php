<?php

namespace App\Game\City;

use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

// 时代升级(M2-B6):cities.era_key / era_order 是全项目唯一的「城市当前时代」口径。
//
// 建造闸门(BuildService)、研究闸门(TechService)、税收系数(财政)一律读这两列,
// 不要在别处再算一次「派生时代」—— B1 曾用「已解锁科技的最高时代」临时顶替,B6 起作废。
//
// 完整安全链照 BuildService 的顺序走:
//   幂等预检 → 事务 → cities 行锁 → 幂等复检 → Revision 校验 → 锁内先结算
//   → 条件逐维校验 → (费用)→ 写状态 → 不变量 → 审计 ERA.UPGRADE → revision + 1
class EraService
{
    // ---- 条件维度 code(响应契约里的 dimension 取值,全 snake_case) ----
    public const DIM_POPULATION = 'population';
    public const DIM_KNOWLEDGE = 'knowledge';
    public const DIM_FOOD = 'food';
    public const DIM_MONEY = 'money';
    public const DIM_BUILDING = 'building';       // 必须建筑,同时带 building_id
    public const DIM_GOVERNANCE = 'governance';   // 治理容量(§5.1「治理最低」)
    public const DIM_HAPPINESS = 'happiness';
    public const DIM_DEFENSE = 'defense';         // 国防值(§5.1「国防最低」)

    // 浮点比较容差:粮食/资金是 DECIMAL 落库、float 计算,恰好卡在门槛上时不该因为
    // 1e-13 的尾差被判成"差一点"
    private const EPSILON = 1e-9;

    // ---- 升级条件矩阵 ----
    //
    // 键 = 目标时代 era_order(2 表示 I→II),数值逐格抄自
    // docs/templates/v3.2.md §5.1「时代升级与科技的关系」表(2026-08-10 用户定稿),此处不做任何再平衡。
    // 库内 era 表只有 era_key / era_order / name 三列,没有条件列,所以矩阵落在代码常量里;
    // 将来若把条件搬进定义表,只需换掉 requirementsFor(),下游全部不动。
    //
    // 「必须建筑/条件」列是自然语言,这里只落地能唯一对应到 building_definition.building_id 的项,
    // 逐档映射与**跳过原因**见下方每一档的注释。跳过 = 该维度当前不校验(系统尚未实现),
    // 绝不用别的数字顶替造一个假门槛。
    private const REQUIREMENTS = [
        // I→II:住宅≥3(时代 I 住宅 = H01 兽皮帐篷);储藏坑≥1(S01,名称精确对应);
        //       稳定粮食来源 → 时代 I 唯一粮食建筑 F01 采集营地 ≥1(「稳定」二字无量化定义,按"已建成粮食建筑"落地)
        2 => [
            'population' => 50, 'knowledge' => 0, 'food' => 300, 'money' => 100,
            'governance' => 40, 'happiness' => 50, 'defense' => 20,
            'buildings'  => ['H01' => 3, 'S01' => 1, 'F01' => 1],
        ],
        // II→III:磨坊 P01、市场 C01 村落市场、采石场 R02。
        //        跳过:「发现铜/锡资源」—— 地图资源点系统(resource_node)尚未实现
        3 => [
            'population' => 200, 'knowledge' => 100, 'food' => 1000, 'money' => 500,
            'governance' => 120, 'happiness' => 55, 'defense' => 60,
            'buildings'  => ['P01' => 1, 'C01' => 1, 'R02' => 1],
        ],
        // III→IV:青铜作坊 P03、行政厅 A03。
        //         跳过:「发现铁/煤」—— 同上,资源点系统未实现
        4 => [
            'population' => 500, 'knowledge' => 300, 'food' => 3000, 'money' => 1500,
            'governance' => 250, 'happiness' => 58, 'defense' => 120,
            'buildings'  => ['P03' => 1, 'A03' => 1],
        ],
        // IV→V:大型农庄 F04;「学院前置」= 学院 K02(时代 V)的前置 = 学堂 K01。
        //       跳过:「区域贸易」—— 市场/贸易系统(D7)未实现
        5 => [
            'population' => 1500, 'knowledge' => 800, 'food' => 10000, 'money' => 5000,
            'governance' => 700, 'happiness' => 60, 'defense' => 250,
            'buildings'  => ['F04' => 1, 'K01' => 1],
        ],
        // V→VI:总督府 A05、医馆 M01。
        //       跳过:「稳定税收」—— 税收/财政净收入(C3)尚在开发,没有可判定的稳定口径
        6 => [
            'population' => 4000, 'knowledge' => 2000, 'food' => 25000, 'money' => 12000,
            'governance' => 1800, 'happiness' => 62, 'defense' => 450,
            'buildings'  => ['A05' => 1, 'M01' => 1],
        ],
        // VI→VII:大学 K03;「商会/银行前置」= 银行 C03(时代 VII)的前置 = 帝国市场 C02。
        //         跳过:「远程贸易」—— 市场系统未实现
        7 => [
            'population' => 10000, 'knowledge' => 5000, 'food' => 60000, 'money' => 30000,
            'governance' => 5000, 'happiness' => 65, 'defense' => 800,
            'buildings'  => ['K03' => 1, 'C02' => 1],
        ],
        // VII→VIII:三项条件全部跳过 ——
        //   「工业资源储备」没写是哪种资源、多少量;
        //   「钢铁厂规划」「铁路规划」指向 P07 / T08,两者都是时代 VIII 建筑,升级前根本建不出来,
        //   当成前提会变成死锁;“规划”本身在本项目没有对应实体。
        8 => [
            'population' => 25000, 'knowledge' => 12000, 'food' => 150000, 'money' => 80000,
            'governance' => 12000, 'happiness' => 65, 'defense' => 1500,
            'buildings'  => [],
        ],
        // VIII→IX:「钢铁/机械供应链」= 钢铁厂 P07 + 机械厂 P08(均时代 VIII,升级前可建)。
        //          跳过:「电网稳定」(电力系统 C2 未实现)、「医院规划」(医院 M02 是时代 IX 建筑)
        9 => [
            'population' => 60000, 'knowledge' => 30000, 'food' => 400000, 'money' => 200000,
            'governance' => 30000, 'happiness' => 68, 'defense' => 3000,
            'buildings'  => ['P07' => 1, 'P08' => 1],
        ],
        // IX→X:国际贸易中心 C04、科研中心 K04、现代防卫体系 = 现代防卫基地 D09(均时代 IX)
        10 => [
            'population' => 200000, 'knowledge' => 100000, 'food' => 1500000, 'money' => 800000,
            'governance' => 120000, 'happiness' => 72, 'defense' => 8000,
            'buildings'  => ['C04' => 1, 'K04' => 1, 'D09' => 1],
        ],
    ];

    // ---- 升级入口 ----

    // 无业务参数:一次调用只升一个时代(升到 era_order + 1),目标时代由服务器决定,客户端说了不算
    public static function upgrade(City $city, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        // 请求指纹:本操作没有业务参数,payload 为空(expected_revision 一律不进指纹)
        $requestHash = Idempotency::hash(AuditAction::ERA_UPGRADE, []);

        // 幂等:同一 user+key+action 已处理则直接成功返回;key 被复用到别的操作则 409
        if ($idempotencyKey !== null) {
            $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::ERA_UPGRADE, $requestHash);
            if ($existing) {
                return self::diff($city->fresh());
            }
        }

        return DB::transaction(function () use ($city, $idempotencyKey, $expectedRevision, $requestHash) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            // 幂等:锁后重新校验,关闭"锁前检查、锁后写入"之间的并发窗口(TOCTOU),与 Build/Tech 对齐
            if ($idempotencyKey !== null) {
                $existing = Idempotency::check((int) $city->user_id, $idempotencyKey, AuditAction::ERA_UPGRADE, $requestHash);
                if ($existing) {
                    return self::diff($city->fresh());
                }
            }

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 锁内先跑 Time Delta 结算(CLAUDE §51):
            // 人口/粮食/资金/幸福全是升级条件,不结算就是拿离线期间的旧值判定门槛
            $sim = SimulationService::applyLocked($locked, now());

            $currentOrder = (int) $locked->era_order;
            $target = DB::table('era')->where('era_order', $currentOrder + 1)->first();
            if (! $target) {
                // 已是最高时代:不是"时代不够",而是没有下一档可升
                throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422, [
                    'reason'       => 'max_era_reached',
                    'era_key'      => (string) $locked->era_key,
                    'era_order'    => $currentOrder,
                    'requirements' => [],
                ]);
            }

            $requirements = self::evaluate((int) $locked->id, $currentOrder, $sim);
            $unmet = array_values(array_filter($requirements, fn ($r) => ! $r['met']));
            if ($unmet) {
                // 前端要显示完整清单(满足的打勾、没满足的标橙),所以 details 里给全量维度而不只给缺口
                throw new GameRuleException(ErrorCode::ERA_REQUIRED, 422, [
                    'reason'       => 'requirements_not_met',
                    'era_key'      => (string) $target->era_key,
                    'era_order'    => (int) $target->era_order,
                    'requirements' => $requirements,
                    'unmet'        => array_map(fn ($r) => $r['dimension'].($r['building_id'] ? ':'.$r['building_id'] : ''), $unmet),
                ]);
            }

            // 费用:v3.2 §5.1 的八个维度全部写作「最低 / 储备」,没有任何一项标注为消耗,
            // §18 的设计规则也只写「同时检查人口、知识、粮食、资金、关键建筑、治理、幸福、国防」。
            // 所以时代升级是**门槛而非费用**,不扣任何资源;delta 保留为空数组,
            // 将来定义表真的给时代加了成本,只要往这里填 [资源 => 数量] 即可,审计/响应结构不用动。
            $delta = [];

            DB::table('cities')->where('id', $city->id)->update([
                'era_key'   => $target->era_key,
                'era_order' => (int) $target->era_order,
            ]);

            // 不变量(CLAUDE §52):新时代必须是 era 表里真实存在的一档,且恰好前进一格
            $after = DB::table('cities')->where('id', $city->id)->first(['era_key', 'era_order']);
            $valid = DB::table('era')
                ->where('era_key', $after->era_key)->where('era_order', $after->era_order)->exists();
            if (! $valid || (int) $after->era_order !== $currentOrder + 1) {
                throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                Idempotency::store((int) $city->user_id, (int) $city->id, $idempotencyKey, AuditAction::ERA_UPGRADE, $requestHash);
            }

            AuditLogger::record(AuditAction::ERA_UPGRADE, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'city', 'entity_id' => (string) $city->id,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'before_json' => ['era_key' => (string) $locked->era_key, 'era_order' => $currentOrder],
                'after_json'  => ['era_key' => (string) $target->era_key, 'era_order' => (int) $target->era_order],
                'delta_json'  => $delta, 'idempotency_key' => $idempotencyKey,
                // 达标时的实测值:事后回查「他当时到底是靠什么过的线」
                'metadata_json' => ['requirements' => $requirements],
            ]);

            return self::diff($city->fresh(), $delta);
        });
    }

    // ---- 条件校验 ----

    // 逐维评估「从 $fromOrder 升到 $fromOrder + 1」的条件。
    // 返回列表,每项 = ['dimension', 'building_id', 'required', 'current', 'met'](契约字段全 snake_case)。
    // $sim 必须是 applyLocked / simulate 的返回值:人口、幸福、国防值、资源余额一律取结算后的最新值
    public static function evaluate(int $cityId, int $fromOrder, array $sim): array
    {
        $need = self::REQUIREMENTS[$fromOrder + 1] ?? null;
        if ($need === null) {
            return [];
        }

        $resources = $sim['resources'] ?? [];
        $rows = [];

        $rows[] = self::row(self::DIM_POPULATION, $need['population'], (float) ($sim['population'] ?? 0));
        $rows[] = self::row(self::DIM_KNOWLEDGE, $need['knowledge'], (float) ($resources[ResourceCode::KNOWLEDGE] ?? 0));
        $rows[] = self::row(self::DIM_FOOD, $need['food'], (float) ($resources[ResourceCode::FOOD] ?? 0));
        // 资金单列在 cities.money,不在 city_resources(与 Build / Tech 同一口径)
        $rows[] = self::row(self::DIM_MONEY, $need['money'], (float) ($sim['money'] ?? 0));

        // 必须建筑:按 building_id 数各自一行,前端据此显示「还差几栋」
        if ($need['buildings']) {
            $counts = DB::table('city_building_instances')
                ->where('city_id', $cityId)
                ->where('status', 'active')
                ->whereIn('building_id', array_keys($need['buildings']))
                ->groupBy('building_id')
                ->selectRaw('building_id, count(*) as instance_count')
                ->pluck('instance_count', 'building_id')
                ->map(fn ($c) => (int) $c)->all();

            foreach ($need['buildings'] as $buildingId => $count) {
                $rows[] = self::row(self::DIM_BUILDING, $count, (float) ($counts[$buildingId] ?? 0), $buildingId);
            }
        }

        // 治理容量:唯一来源是结算内核返回的 governanceCapacity(= output_json 里 governance_capacity 之和)。
        // 绝不在这里另算一遍,更不能去读 building_level_definition.governance_bonus 列 ——
        // 两者数值并不相等,同时读就是双计(M2 backlog C4 点名的坑)。
        //
        // ⚠️ W6 起内核另有一个 governanceCapacityEffective(= (建筑口径 + 行政 NPC flat) × (1 + NPC/工具/事件 pct)),
        // 时代门槛**刻意继续读建筑口径**:一位随时可辞退的 NPC、一件会磨损的工具、一个 20 分钟的事件 buff
        // 都不该把城市顶过升代门槛(过了线人一走城市就"倒退")。时代要的是常备行政力,
        // 税收效率要的是此刻的行政效率 —— 与 DIM_DEFENSE 读 defenseScore(而非有效国防值)是同一条口径,
        // 两条都由测试钉死(GovernanceCapacityTest / DefenseThreatTest 各一条)
        $rows[] = self::row(self::DIM_GOVERNANCE, $need['governance'], (float) ($sim['governanceCapacity'] ?? 0));

        $rows[] = self::row(self::DIM_HAPPINESS, $need['happiness'], (float) ($sim['happiness'] ?? 0));
        $rows[] = self::row(self::DIM_DEFENSE, $need['defense'], (float) ($sim['defenseScore'] ?? 0));

        return $rows;
    }

    // 单条条件行。current 保留两位小数:粮食/资金是连续量,原样输出会给前端一串浮点噪声
    private static function row(string $dimension, int|float $required, float $current, ?string $buildingId = null): array
    {
        return [
            'dimension'   => $dimension,
            'building_id' => $buildingId,
            'required'    => (float) $required,
            'current'     => round($current, 2),
            'met'         => $current + self::EPSILON >= (float) $required,
        ];
    }

    // ---- 只读查询 ----

    // 某个时代的「国防最低」(§5.1 九档)—— **威胁需求(M3-D5)的唯一来源**。
    //
    // 口径:处在时代 N 的城市,它的威胁需求 = 「升出时代 N 所需的国防最低」= REQUIREMENTS[N+1]。
    // 时代 I 的城市看的就是 §5.1 第一行的 I→II 20,与 backlog 9.E1 批准的
    // 「threat_requirement(era) = §5.1 的国防最低列(I→II 20、II→III 60、…、IX→X 8000)」逐字对应。
    // 最高时代 X 没有下一档,沿用最后一档 8000(不新造第十个数字)。
    //
    // 为什么开这个访问器而不是让 DefenseService 抄一份九档数字:抄第二份就有两个来源,
    // 后台改了时代门槛而威胁需求不动(或反过来)——正是 M2 governance_bonus 双口径踩过的坑。
    public static function defenseRequirement(int $eraOrder): float
    {
        $key = max(1, $eraOrder) + 1;

        if (isset(self::REQUIREMENTS[$key])) {
            return (float) self::REQUIREMENTS[$key]['defense'];
        }

        return (float) self::REQUIREMENTS[max(array_keys(self::REQUIREMENTS))]['defense'];
    }

    // era_key => era_order(建造/研究闸门共用;era 表只有 10 行,查一次很便宜)
    public static function orders(): array
    {
        return DB::table('era')->pluck('era_order', 'era_key')
            ->map(fn ($o) => (int) $o)->all();
    }

    // 快照区块:当前时代 + 下一时代的逐维条件清单(已是最高时代时 next 为 null)。
    // era_name 一并给出:时代中文名只存在 era.name 这一处,不让前端另抄一张「序号 → 名字」表(§13)
    public static function snapshot(object $city, array $sim): array
    {
        $currentOrder = (int) $city->era_order;
        // 当前档与下一档一次查完(era 表 10 行,两行分两次查不划算)
        $eras = DB::table('era')->whereIn('era_order', [$currentOrder, $currentOrder + 1])
            ->get()->keyBy('era_order');
        $current = $eras[$currentOrder] ?? null;
        $next = $eras[$currentOrder + 1] ?? null;

        return [
            'era_key'   => (string) $city->era_key,
            'era_order' => $currentOrder,
            'era_name'  => $current?->name,
            'next'      => $next === null ? null : [
                'era_key'      => (string) $next->era_key,
                'era_order'    => (int) $next->era_order,
                'era_name'     => $next->name,
                'requirements' => self::evaluate((int) $city->id, $currentOrder, $sim),
            ],
        ];
    }

    // ---- 响应 ----

    // 升级响应:与 Build / Tech 的 diff 同构(revision + 资源 + delta),另带新的时代区块。
    // 时代区块里的 next.requirements 需要一份结算结果:此处刚提交完事务,直接用 simulate 拿最新投影
    // (elapsed 必为 0,不会二次结算,也不会再写库)
    private static function diff(City $city, array $delta = []): array
    {
        $sim = SimulationService::simulate($city);

        return [
            'revision'  => (int) $city->revision,
            // map 型(键为资源 code)一律过 ApiResponse::map:空时也要是 `{}` 不是 `[]`(见 BuildService::snapshotDiff)。
            // era 区块里的 next.requirements 是**列表型**(一行一条门槛),保持数组不动
            'resources' => ApiResponse::map(DB::table('city_resources')->where('city_id', $city->id)
                ->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all()),
            'money'     => (float) $city->money,
            'delta'     => ApiResponse::map($delta),
            'era'       => self::snapshot($city, $sim),
        ];
    }
}
