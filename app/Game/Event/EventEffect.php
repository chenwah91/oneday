<?php

namespace App\Game\Event;

use App\Game\Defense\DefenseService;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcRuntimeService;
use App\Game\Resource\ResourceCode;
use App\Game\Simulation\SimConstants;
use App\Support\ErrorCode;
use App\Support\GameRuleException;
use App\Support\GameSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// 事件效果的应用引擎(v3.2 §9.2 的「自动效果 / 三个选项」→ 真实的库存与状态变化)。
//
// ══ 三条口径,全部来自已批准的裁决 ═════════════════════════════════════════════
// ① **正向事件直接发资源**(§13 帽修正方向,用户 2026-08-10 拍板③ + backlog §11.1 方向④):
//    「农业产量+20%,持续 15 分钟」不再做成 event 乘区(满配城市的乘区早被 §13 的 2.75 帽吃光,
//    正向事件对强城市 100% 无效),而是折算成一次性发放:
//        发放量 = 当前 gross 产出速率 × 加成率 × 持续分钟
//    折算的依据是玩家**此刻真实的产能**,所以强城市拿得多、弱城市拿得少,方向与原设计一致,
//    但完全不占加成帽。发放走 EVENT.REWARD 审计,幂等由「一个实例只结算一次」保证。
// ② **负向事件走 event 乘区**(值恒 ≤0 → 乘数 <1.0,惩罚方向本来就不受 §13 帽约束)。
// ③ **幸福 / 治安走 flat 通道**(D0.2):
//    幸福 duration=0 → 改**当前值**;duration>0 → 改**目标值**(由 §10.2 的快落慢升自然收敛);
//    治安只有派生值没有存量(§10.8 = 国防覆盖率映射),**一律**走 security_flat,
//    duration=0 的事件按后台设定 event_instant_security_minutes 给一个时长。
//
// ══ 掷点一次、落库、不复掷(backlog §11.3)═══════════════════════════════════
// 「损失 8%~15%」「随机哪一栋停工」这类随机在**触发时**掷完并写进 city_events.rolled_json;
// resolve 时只读不掷 —— 否则玩家可以反复结算刷一个更轻的损失。
// 掷点本身来自 EventRandom 的确定性派生(HMAC(密钥, city|window|label)),重登录也刷不出新结果。
final class EventEffect
{
    // 本次应用累计到的资源变化(资源 code => 数量,含 money)
    private array $delta = [];

    // 本次应用产生的说明(进审计 metadata:哪些效果因为承接不了而没生效)
    private array $notes = [];

    // $city    锁到的 cities 行(applyLocked 之后的最新值由 $sim 提供,这里只用 id / 幸福等列)
    // $sim     本次 SimulationService::applyLocked 的结果(资源 / 资金 / 人口 / 产能全部取它)
    // $seed    掷点种子的前缀,形如 [city_id, window_index] —— 同一实例的所有掷点共用它
    // $lossReduction 事件损失减免(D0.3 登记的 event_loss_reduction_pct,消费点就是本类)。
    //                只减免**自动效果里的库存损失**,不减免选项里玩家自愿掏的钱 ——
    //                「危机管理」应对的是天灾,不是替玩家付庆典的账
    public function __construct(
        private object $city,
        private array $sim,
        private array $definition,
        private array $seed,
        private float $lossReduction = 0.0,
    ) {
    }

    public function delta(): array
    {
        return $this->delta;
    }

    public function notes(): array
    {
        return $this->notes;
    }

    // ---------- 触发路径:应用自动效果 ----------

    // 返回 ['rolled' => 掷点结果, 'applied' => 已应用的累计] —— 两者都要落 city_events
    public function applyAuto(int $instanceId, Carbon $startsAt, Carbon $endsAt): array
    {
        $rolled = [];
        $applied = ['resources' => [], 'population' => 0.0, 'happiness' => 0.0];

        foreach ($this->definition['auto_effect_json']['effects'] ?? [] as $i => $effect) {
            $this->applyOne($effect, "auto:{$i}", $instanceId, $startsAt, $endsAt, $rolled, $applied);
        }

        $applied['resources'] = $this->delta;

        return ['rolled' => $rolled, 'applied' => $applied];
    }

    // ---------- 结算路径:应用某个选项 ----------

    // $instance 是锁到的 city_events 行;$rolled / $applied 由调用方从实例行解出并回写
    public function applyOption(object $instance, array $option, array &$rolled, array &$applied): void
    {
        $startsAt = Carbon::parse($instance->triggered_at);
        $endsAt = Carbon::parse($instance->expires_at);

        // 先算清楚这一选项要玩家掏多少定额资源,付不起就整条拒绝 ——
        // 不做「能扣多少扣多少」:那会让玩家用空钱包白拿选项的好处
        $this->assertAffordable($option['effects'] ?? []);

        foreach ($option['effects'] ?? [] as $i => $effect) {
            $this->applyOne($effect, "option:{$i}", (int) $instance->id, $startsAt, $endsAt, $rolled, $applied, $instance);
        }

        foreach ($this->delta as $code => $amount) {
            $applied['resources'][$code] = round((float) ($applied['resources'][$code] ?? 0) + $amount, 4);
        }

        foreach ($option['unmapped_zh'] ?? [] as $text) {
            $this->notes[] = 'unmapped:' . $text;
        }
    }

    // ---------- 单条效果分发 ----------

    private function applyOne(
        array $effect,
        string $label,
        int $instanceId,
        Carbon $startsAt,
        Carbon $endsAt,
        array &$rolled,
        array &$applied,
        ?object $instance = null
    ): void {
        match ((string) $effect['kind']) {
            EventCode::EFFECT_RESOURCE_DELTA        => $this->resourceDelta($effect),
            EventCode::EFFECT_RESOURCE_PCT_OF_STOCK => $this->resourcePctOfStock($effect, $label, $rolled),
            EventCode::EFFECT_GRANT_PRODUCTION_PCT  => $this->grantProduction($effect, $label, $rolled),
            EventCode::EFFECT_MODIFIER              => $this->modifier($effect, $label, $instanceId, $startsAt, $endsAt, $rolled),
            EventCode::EFFECT_HAPPINESS             => $this->happiness($effect, $instanceId, $startsAt, $endsAt, $applied),
            EventCode::EFFECT_SECURITY              => $this->security($effect, $instanceId, $startsAt, $endsAt),
            EventCode::EFFECT_POPULATION_PCT        => $this->populationPct($effect, $label, $rolled, $applied),
            EventCode::EFFECT_CONSTRUCTION_DELAY_PCT => $this->constructionDelay($effect, $label, $rolled),
            EventCode::EFFECT_THREAT_LOSS_PCT       => $this->threatLoss($effect, $rolled),
            EventCode::EFFECT_NPC_LEAVE             => $this->npcLeave($label, $rolled),

            // ---- 选项专用:改「已经发生的效果」----
            EventCode::EFFECT_LOSS_SCALE     => $this->adjustLoss($rolled, $applied, scale: (float) $effect['value']),
            EventCode::EFFECT_LOSS_SET_PCT   => $this->adjustLoss($rolled, $applied, targetPct: (float) $effect['value']),
            EventCode::EFFECT_ROLL_TAKE_MAX  => $this->rollTakeMax($rolled, $applied),
            EventCode::EFFECT_MODIFIER_SET_VALUE => $this->setModifierValue(
                $instanceId,
                (float) $effect['value'],
                // target 省略 → event 乘区(既有行为,30 条里除 EVT_BLACKOUT 外全部走这条)。
                // M.1 起可以点名别的乘区:EVT_BLACKOUT 的「减益降为-10%」改的是 power 那一行
                (string) ($effect['target'] ?? ModifierTarget::SLOT_EVENT)
            ),
            EventCode::EFFECT_MODIFIER_SCALE => $this->scaleModifier(
                $instanceId,
                (float) $effect['value'],
                (string) ($effect['target'] ?? ModifierTarget::SLOT_EVENT)
            ),
            EventCode::EFFECT_FLAT_SET       => $this->setFlat($effect, $instanceId, $startsAt, $endsAt, $applied),
            EventCode::EFFECT_DURATION_SCALE => $this->rescheduleScale($instance, (float) $effect['value']),
            EventCode::EFFECT_DURATION_SET_MINUTES => $this->rescheduleTo($instance, now()->copy()->addMinutes((int) $effect['value'])),
            EventCode::EFFECT_END_NOW        => $this->rescheduleTo($instance, now()),
            EventCode::EFFECT_CONSTRUCTION_DELAY_REVERT => $this->revertConstructionDelay($rolled),
            default => null,
        };
    }

    // ---------- 资源 ----------

    // 定额增减。效果强度倍率(后台可调)乘在这里
    private function resourceDelta(array $effect): void
    {
        $this->addDelta((string) $effect['resource'], (float) $effect['value'] * $this->strength());
    }

    // 按当前库存的百分比增减(§9.2 的「损失粮食库存 8%~15%」)。
    // 区间在触发时掷一次并落 rolled,选项要改损失时按 base × pct 反算退还额
    private function resourcePctOfStock(array $effect, string $label, array &$rolled): void
    {
        // 不指定 resource 时随机挑一种**当前有库存**的非资金资源(§9.2 EVT_CRIME 的「随机库存损失」)。
        // 候选按 code 排序后再掷点 —— 顺序固定,掷点才可重算(与 pickInstance / producedInGroup 同一条纪律)
        $resource = isset($effect['resource'])
            ? (string) $effect['resource']
            : $this->pickStockResource($label);
        if ($resource === null) {
            $this->notes[] = 'resource_pct_of_stock:本城没有可损失的库存,本次不生效';

            return;
        }
        $rolled['loss_resource'] ??= $effect['resource'] ?? $resource;

        $pct = $this->rollValue($effect, $label) * $this->strength();

        // 事件损失减免(D0.3 的 event_loss_reduction_pct):只作用在**损失**方向,
        // 减免后的比例落进 rolled.loss.pct,「损失减半」类选项按减免后的值继续算,不会双重减免
        if ($pct < 0 && $this->lossReduction > 0) {
            $pct *= max(0.0, 1.0 - $this->lossReduction);
        }

        $base = $this->stock($resource);
        $amount = $base * $pct;

        $this->addDelta($resource, $amount);

        // 只记第一条损失:§9.2 里没有任何一条事件有两处 pct 损失,
        // 真出现了也只该有一处能被「损失减半」类选项调整,多记一条反而会让退还额算错
        $rolled['loss'] ??= [
            'resource' => $resource,
            'pct'      => round($pct, 6),
            'base'     => round($base, 4),
            'amount'   => round($amount, 4),
        ];
    }

    // **正向事件的直接发资源**(§13 帽修正方向)。
    // 发放量 = Σ(该资源当前 gross 产出速率) × 加成率 × 分钟数。
    // 用 gross 产出而不是净速率:净速率会被下游吃料抵消,导致「产得越多发得越少」的怪结果
    private function grantProduction(array $effect, string $label, array &$rolled): void
    {
        $minutes = (float) ($effect['minutes'] ?? max(1, $this->definition['duration_minutes']));
        $rate = (float) $effect['value'] * $this->strength();
        $production = $this->sim['grossProductionPerMin'] ?? [];

        $resources = $effect['resources'] ?? null;

        if ($resources === null) {
            // 资源组:从「本城**实际在产**且属于该组」的资源里取。
            // pick=1 → 随机挑一种(§9.2 的「随机加工链」);pick=0 → 全组
            $group = (string) $effect['resource_group'];
            $candidates = $this->producedInGroup($group, $production);
            if ($candidates === []) {
                $this->notes[] = "grant:{$group} 组当前没有任何在产资源,本次不发放";

                return;
            }

            $pick = (int) ($effect['pick'] ?? 0);
            if ($pick === 1) {
                $index = EventRandom::index(count($candidates), ...[...$this->seed, $label, 'group']);
                $resources = [$candidates[$index]];
                $rolled['grant_resource'] = $resources[0];
            } else {
                $resources = $candidates;
            }
        }

        foreach ($resources as $code) {
            $amount = (float) ($production[$code] ?? 0.0) * $rate * $minutes;
            if ($amount > 0) {
                $this->addDelta((string) $code, $amount);
            }
        }
    }

    // 本城在产的某一资源类别(resource_definition.category)。
    // 「在产」= gross 产出速率 > 0:没有产能的资源发 0 没有意义,也会让随机挑中一个空资源
    private function producedInGroup(string $group, array $production): array
    {
        $codes = DB::table('resource_definition')->where('category', $group)->pluck('resource_id')->all();

        $out = [];
        foreach ($codes as $code) {
            if ((float) ($production[$code] ?? 0) > 0) {
                $out[] = (string) $code;
            }
        }
        sort($out); // 顺序固定,随机挑选才可重算

        return $out;
    }

    // ---------- 持续型 modifier ----------

    private function modifier(array $effect, string $label, int $instanceId, Carbon $startsAt, Carbon $endsAt, array &$rolled): void
    {
        $scope = (string) $effect['scope'];
        $scopeKey = $effect['scope_key'] ?? null;

        // 随机二选一的作用域(§9.2 的「随机道路/农田」)
        if (isset($effect['scope_keys'])) {
            $keys = $effect['scope_keys'];
            $index = EventRandom::index(count($keys), ...[...$this->seed, $label, 'scope']);
            $scopeKey = (string) $keys[$index];
            $rolled['scope_key'] = $scopeKey;
        }

        // 随机挑一栋建筑实例(§9.2 的「随机矿场停工」「随机工业建筑停工」)
        if ($scope === ModifierSpec::SCOPE_BUILDING_INSTANCE) {
            $instanceIdPicked = $this->pickInstance($effect['pick'] ?? [], $label);
            if ($instanceIdPicked === null) {
                $this->notes[] = 'modifier:没有符合条件的建筑实例,本次不生效';

                return;
            }
            $scopeKey = (string) $instanceIdPicked;
            $rolled['building_instance_id'] = $instanceIdPicked;
        }

        // 单条效果可以自带更短的时长(§9.2 的「伐木停工 10 分钟」)
        $ends = isset($effect['minutes'])
            ? $startsAt->copy()->addMinutes((int) $effect['minutes'])
            : $endsAt;

        // 值也可以是区间(W5:§9.2 EVT_SPECULATION 的「价格+25%~50%」)。
        // rollValue 给了 min/max 就掷点、否则取定值 —— 掷出来的数**写进 modifier 行本身**,
        // 落库即定死(§11.3 掷点纪律:选项路径只读不重掷)
        $this->insertModifier(
            $instanceId,
            (string) $effect['target'],
            $scope,
            $scopeKey,
            ModifierSpec::OP_PCT,
            $this->rollValue($effect, $label) * $this->strength(),
            $startsAt,
            $ends
        );
    }

    // 随机挑一种**当前有库存**的非资金资源(§9.2 EVT_CRIME「随机库存损失」)。
    // 顺序按 code 排序固定,掷点才可重算;一件库存都没有时返回 null(调用方记 note 后空转)
    private function pickStockResource(string $label): ?string
    {
        $stocks = $this->sim['resources'] ?? [];
        ksort($stocks);

        $candidates = [];
        foreach ($stocks as $code => $amount) {
            if ((string) $code !== ResourceCode::MONEY && (float) $amount > 0) {
                $candidates[] = (string) $code;
            }
        }

        if ($candidates === []) {
            return null;
        }

        return $candidates[EventRandom::index(count($candidates), ...[...$this->seed, $label, 'stock'])];
    }

    // 按 category / series 过滤后随机挑一栋 active 实例
    private function pickInstance(array $pick, string $label): ?int
    {
        $column = ($pick['scope'] ?? EventCode::SCOPE_CATEGORY) === EventCode::SCOPE_SERIES
            ? 'bd.series_key'
            : 'bd.category';

        $ids = DB::table('city_building_instances as ci')
            ->join('building_definition as bd', 'ci.building_id', '=', 'bd.building_id')
            ->where('ci.city_id', $this->city->id)
            ->where('ci.status', 'active')
            ->whereIn($column, $pick['keys'] ?? [])
            ->orderBy('ci.id') // 顺序固定,掷点才可重算
            ->pluck('ci.id')->all();

        if ($ids === []) {
            return null;
        }

        return (int) $ids[EventRandom::index(count($ids), ...[...$this->seed, $label, 'instance'])];
    }

    // 写一行 city_active_modifiers。target / scope / op 三重 allowlist 由 ModifierSpec 的构造函数把关 ——
    // 构造不出来就直接抛,绝不写一行运行时「静默不生效」的脏 modifier
    private function insertModifier(
        int $instanceId,
        string $target,
        string $scope,
        ?string $scopeKey,
        string $op,
        float $value,
        Carbon $startsAt,
        Carbon $endsAt
    ): void {
        new ModifierSpec($target, $scope, $op, $value, $scope === ModifierSpec::SCOPE_CITY ? null : $scopeKey);

        DB::table('city_active_modifiers')->insert([
            'city_id'     => $this->city->id,
            'source_type' => 'event',
            'source_id'   => $instanceId,
            'target'      => $target,
            'scope'       => $scope,
            'scope_key'   => $scope === ModifierSpec::SCOPE_CITY ? null : $scopeKey,
            'op'          => $op,
            'value'       => round($value, 4),
            'starts_at'   => $startsAt,
            'ends_at'     => $endsAt,
            'created_at'  => now(),
        ]);
    }

    // ---------- 幸福 / 治安 ----------

    // D 区 D4 批准口径:duration=0 → 改当前值;duration>0 → 改目标值(flat 通道)
    private function happiness(array $effect, int $instanceId, Carbon $startsAt, Carbon $endsAt, array &$applied): void
    {
        $value = (float) $effect['value'] * $this->strength();

        if ($this->definition['duration_minutes'] > 0) {
            $this->insertModifier(
                $instanceId,
                ModifierTarget::HAPPINESS_FLAT,
                ModifierSpec::SCOPE_CITY,
                null,
                ModifierSpec::OP_FLAT,
                $value,
                $startsAt,
                $endsAt
            );

            return;
        }

        $this->bumpHappinessNow($value, $applied);
    }

    // 瞬时改当前幸福:落库夹在 [0, 100]。
    // 用 $sim['happiness'] 作基数(它是本次结算刚写进库的值),不再查一次库
    private function bumpHappinessNow(float $value, array &$applied): void
    {
        $current = (float) ($this->sim['happiness'] ?? $this->city->happiness);
        $next = max(SimConstants::HAPPINESS_MIN, min(SimConstants::HAPPINESS_MAX, $current + $value));

        DB::table('cities')->where('id', $this->city->id)->update(['happiness' => $next]);

        // 内存里的基数同步推进:同一次结算里可能有两条幸福效果
        $this->sim['happiness'] = $next;
        $applied['happiness'] = round((float) ($applied['happiness'] ?? 0) + ($next - $current), 4);
    }

    // 治安没有存量可改(§10.8 是「国防值 / 人口」的覆盖率映射),一律走 security_flat。
    // duration=0 的事件按后台设定给一个时长,否则 flat 通道没有起止就无从生效
    private function security(array $effect, int $instanceId, Carbon $startsAt, Carbon $endsAt): void
    {
        $ends = $this->definition['duration_minutes'] > 0
            ? $endsAt
            : $startsAt->copy()->addMinutes((int) GameSetting::get(GameSetting::EVENT_INSTANT_SECURITY_MINUTES));

        $this->insertModifier(
            $instanceId,
            ModifierTarget::SECURITY_FLAT,
            ModifierSpec::SCOPE_CITY,
            null,
            ModifierSpec::OP_FLAT,
            (float) $effect['value'] * $this->strength(),
            $startsAt,
            $ends
        );
    }

    // ---------- 人口 ----------

    // §9.2 的「人口+2%~5%」。夹在 [§10.1 的人口下限, 人口容量]:
    // 事件不该把人塞进没有住房的城市,也不该把人口打到低于内核允许的下限
    private function populationPct(array $effect, string $label, array &$rolled, array &$applied): void
    {
        $pct = $this->rollValue($effect, $label) * $this->strength();
        $base = (float) ($this->sim['population'] ?? $this->city->population);
        $capacity = (float) ($this->sim['populationCapacity'] ?? 0);

        $target = $base * (1.0 + $pct);
        $target = max((float) SimConstants::MIN_POPULATION, min($target, max($capacity, $base)));
        $amount = (int) round($target - $base);

        if ($amount !== 0) {
            DB::table('cities')->where('id', $this->city->id)->update(['population' => (int) round($base) + $amount]);
            $this->sim['population'] = (int) round($base) + $amount;
        }

        $applied['population'] = (int) ($applied['population'] ?? 0) + $amount;
        $rolled['population_roll'] ??= [
            'pct'   => round($pct, 6),
            'min'   => (float) ($effect['min'] ?? $effect['value'] ?? 0),
            'max'   => (float) ($effect['max'] ?? $effect['value'] ?? 0),
            'base'  => round($base, 4),
            'amount' => $amount,
        ];
    }

    // ---------- 施工进度 ----------

    // §9.2 的「随机项目进度回退 10%」:把随机一个在建 / 升级实例的完工时刻往后推
    // 「剩余工期 × pct」。不去改一个并不存在的「进度百分比」列 —— 项目里的施工进度就是完工时刻本身
    private function constructionDelay(array $effect, string $label, array &$rolled): void
    {
        $now = now();
        $rows = DB::table('city_building_instances')
            ->where('city_id', $this->city->id)
            ->whereIn('status', ['constructing', 'upgrading'])
            ->whereNotNull('construction_finished_at')
            ->orderBy('id')
            ->get(['id', 'construction_finished_at']);

        if ($rows->isEmpty()) {
            $this->notes[] = 'construction_delay:本城没有在建项目,本次不生效';

            return;
        }

        $row = $rows[EventRandom::index($rows->count(), ...[...$this->seed, $label, 'project'])];
        $finish = Carbon::parse($row->construction_finished_at);
        $remaining = max(0, $finish->getTimestamp() - $now->getTimestamp());
        $delay = (int) round($remaining * (float) $effect['value'] * $this->strength());

        if ($delay <= 0) {
            return;
        }

        DB::table('city_building_instances')->where('id', $row->id)
            ->update(['construction_finished_at' => $finish->copy()->addSeconds($delay)]);

        $rolled['construction'] = ['instance_id' => (int) $row->id, 'delay_seconds' => $delay];
    }

    // 选项「安全投入:取消回退」:把推迟的时间原样还回去
    private function revertConstructionDelay(array &$rolled): void
    {
        if (! isset($rolled['construction'])) {
            return;
        }

        $row = DB::table('city_building_instances')
            ->where('id', $rolled['construction']['instance_id'])
            ->where('city_id', $this->city->id)
            ->first(['id', 'construction_finished_at']);

        // 项目可能已经完工 / 被拆:还不回去就算了,但要在审计里留一句
        if (! $row || $row->construction_finished_at === null) {
            $this->notes[] = 'construction_revert:项目已完工或已不存在,无需回退';

            return;
        }

        DB::table('city_building_instances')->where('id', $row->id)->update([
            'construction_finished_at' => Carbon::parse($row->construction_finished_at)
                ->subSeconds((int) $rolled['construction']['delay_seconds']),
        ]);

        $rolled['construction']['reverted'] = true;
    }

    // ---------- 威胁等级损失(M3-D5,§9.2 EVT_RAID)----------

    // §9.2「按威胁需求/国防值计算资源损失」。比例不在 events.json 里 ——
    // 由 DefenseService 按「国防缺口 × 威胁档」算(9.E2 + §17),所以这条效果只带作用范围:
    //   不带 resource      → 全部**非资金**库存(9.E2「作用于非资金库存」);
    //   带 resource: money → 只作用于资金(选项 B 赎金:资金损失,库存无损)。
    private function threatLoss(array $effect, array &$rolled): void
    {
        $pct = $this->threatLossPct($rolled);

        if ($pct <= 0) {
            // 威胁档为安全(或后台把倍率调成 0):没有损失。
            // 不静默 —— 玩家收到一条「敌军劫掠」却毫发无损时,审计里要答得出为什么
            $this->notes[] = 'threat_loss:当前威胁档不产生损失(覆盖率已达标或倍率为 0)';

            return;
        }

        // ---- 单一资源(赎金)----
        if (isset($effect['resource'])) {
            $code = (string) $effect['resource'];
            $base = $this->stock($code);
            $amount = -$base * $pct;

            $this->addDelta($code, $amount);
            $rolled['threat']['ransom'] = [
                'resource' => $code,
                'base'     => round($base, 4),
                'amount'   => round($amount, 4),
            ];

            return;
        }

        // ---- 全部非资金库存 ----
        // 资金不在 city_resources 里(单列 cities.money),$sim['resources'] 天然只有库存资源;
        // 顺序按 code 排序固定,rolled 的内容才可复盘、测试才可断言
        $stocks = $this->sim['resources'] ?? [];
        ksort($stocks);

        $entries = [];
        $totalBase = 0.0;
        $totalAmount = 0.0;

        foreach ($stocks as $code => $stock) {
            $base = (float) $stock;
            if ((string) $code === ResourceCode::MONEY || $base <= 0) {
                continue;
            }

            $amount = -$base * $pct;
            $this->addDelta((string) $code, $amount);

            $entries[] = ['resource' => (string) $code, 'base' => round($base, 4), 'amount' => round($amount, 4)];
            $totalBase += $base;
            $totalAmount += $amount;
        }

        if ($entries === []) {
            $this->notes[] = 'threat_loss:本城没有可损失的库存,本次无损失';

            return;
        }

        // 与 resourcePctOfStock 同一个 rolled.loss 结构,多一个 entries ——
        // 「损失减半 / 损失归零」类选项(adjustLoss)因此对两种损失一视同仁,不必分两套退还逻辑
        $rolled['loss'] ??= [
            'resource' => null, // 多资源,单资源字段留空
            'pct'      => round(-$pct, 6),
            'base'     => round($totalBase, 4),
            'amount'   => round($totalAmount, 4),
            'entries'  => $entries,
        ];
    }

    // 本实例的损失比例(正数)。**触发时算一次、落 rolled、之后只读**(§11.3 掷点纪律):
    // 否则玩家可以先造几栋防御建筑再回来结算,把已经发生的损失算小 —— 或者反过来被算大。
    // 落的是**最终值**(已乘事件强度、已过损失减免链),选项路径直接复用,不会二次减免。
    private function threatLossPct(array &$rolled): float
    {
        if (isset($rolled['threat']['loss_pct'])) {
            return (float) $rolled['threat']['loss_pct'];
        }

        $defense = DefenseService::evaluate($this->city, $this->sim);
        $pct = DefenseService::raidLossPct($defense) * $this->strength();

        // 事件损失减免(D0.3 的 event_loss_reduction_pct,消费点是 EventService::lossReduction):
        // 与 resourcePctOfStock 同一条链,防御工具 / 危机管理特性对劫掠一样有效
        if ($pct > 0 && $this->lossReduction > 0) {
            $pct *= max(0.0, 1.0 - $this->lossReduction);
        }

        $rolled['threat'] = [
            'level'          => $defense['threat_level'],
            'coverage'       => $defense['coverage'],
            'demand'         => $defense['threat_demand'],
            'defense_score'  => $defense['defense_score'],
            'loss_reduction' => round($this->lossReduction, 6),
            'loss_pct'       => round($pct, 6),
        ];

        return $pct;
    }

    // ---------- 人才流失(M3-D1 合并波次,§9.2 EVT_BRAIN_DRAIN)----------

    // 「随机流失 1 名在编 NPC」。本类**不写 city_npcs** —— 状态位归 NPC 模块所有,
    // 这里只调 NpcRuntimeService::leaveRandom(),并把掷点用的确定性随机源注入进去。
    //
    // 掷点落库、只读不复掷(§11.3):结果写进 rolled.npc_leave;rolled 里已经有它就直接返回,
    // 所以「同一实例被重复结算」不会流失第二个人(resolve 路径本身也只跑选项效果,不跑 auto)。
    private function npcLeave(string $label, array &$rolled): void
    {
        if (isset($rolled['npc_leave'])) {
            return;
        }

        $left = NpcRuntimeService::leaveRandom(
            $this->city,
            'EVENT_BRAIN_DRAIN',
            // 事件掷点必须可重算:同一 (城市, 窗口, 标签) 永远挑同一个下标
            fn (int $count) => EventRandom::index($count, ...[...$this->seed, $label, 'npc']),
            ['event_id' => $this->definition['event_id'] ?? null],
        );

        if ($left === null) {
            // 空转不是错误:一座还没招人的城照样可能抽中这条事件。
            // 但要留痕 —— 玩家收到「人才流失」却一个人都没少时,审计里得答得出为什么
            $this->notes[] = 'npc_leave:本城当前没有在编 NPC,本次没有人离职';

            return;
        }

        $rolled['npc_leave'] = $left;
    }

    // ---------- 选项:调整已发生的效果 ----------

    // 「损失减半」/「损失降至 3%」:按 rolled.loss 的 base × 新旧比例差退还。
    // 退还而不是「重新计算库存的百分比」——玩家在这期间可能已经吃掉 / 补充了粮食,
    // 按当时的 base 退才是玩家实际损失掉的那一份
    private function adjustLoss(array &$rolled, array &$applied, ?float $scale = null, ?float $targetPct = null): void
    {
        if (! isset($rolled['loss'])) {
            return;
        }

        $loss = $rolled['loss'];
        $oldPct = (float) $loss['pct'];
        // pct 是负数(损失方向),targetPct 由 events.json 写成正数比例 → 取负
        $newPct = $targetPct !== null ? -abs($targetPct) : $oldPct * $scale;

        // 只允许把损失变小:选项文案里没有「损失翻倍」这种写法,
        // 万一后台把系数填成 2,也不该让一个「补救选项」反而多扣玩家的粮
        if ($newPct < $oldPct) {
            $newPct = $oldPct;
        }

        $refund = (float) $loss['base'] * ($newPct - $oldPct); // 正数 = 退还

        if ($refund > 0) {
            // 两种损失形态用同一条退还逻辑:
            //   单资源(resource_pct_of_stock,如 EVT_GRANARY_PEST 的粮食)→ 直接退那一种;
            //   多资源(threat_loss_pct,EVT_RAID 的全库存)→ 按各自的 base 比例逐种退,
            //   合计恰好等于 base 合计 × 比例差(测试里对账的就是这一条)
            if (isset($loss['entries']) && is_array($loss['entries'])) {
                foreach ($loss['entries'] as $i => $entry) {
                    $entryRefund = (float) $entry['base'] * ($newPct - $oldPct);
                    if ($entryRefund > 0) {
                        $this->addDelta((string) $entry['resource'], $entryRefund);
                    }
                    $rolled['loss']['entries'][$i]['amount'] = round((float) $entry['base'] * $newPct, 4);
                }
            } else {
                $this->addDelta((string) $loss['resource'], $refund);
            }
        }

        $rolled['loss']['pct'] = round($newPct, 6);
        $rolled['loss']['amount'] = round((float) $loss['base'] * $newPct, 4);
        $rolled['loss']['adjusted'] = true;
        $applied['loss_refund'] = round((float) ($applied['loss_refund'] ?? 0) + $refund, 4);
    }

    // 选项「人口增长取高值」:把已经掷出的区间结果提升到上限,补发差额
    private function rollTakeMax(array &$rolled, array &$applied): void
    {
        if (! isset($rolled['population_roll'])) {
            return;
        }

        $roll = $rolled['population_roll'];
        $extraPct = (float) $roll['max'] - (float) $roll['pct'];
        if ($extraPct <= 0) {
            return;
        }

        $base = (float) $roll['base'];
        $current = (float) ($this->sim['population'] ?? $this->city->population);
        $capacity = (float) ($this->sim['populationCapacity'] ?? 0);

        $target = min($current + $base * $extraPct, max($capacity, $current));
        $amount = (int) round($target - $current);

        if ($amount > 0) {
            DB::table('cities')->where('id', $this->city->id)->update(['population' => (int) round($current) + $amount]);
            $this->sim['population'] = (int) round($current) + $amount;
            $applied['population'] = (int) ($applied['population'] ?? 0) + $amount;
        }

        $rolled['population_roll']['pct'] = (float) $roll['max'];
        $rolled['population_roll']['amount'] = (int) ($roll['amount'] ?? 0) + $amount;
    }

    // 「减益降为 -15%」:把本实例写下的某一格乘区 modifier 值整体改掉。
    //
    // $target 默认 event 乘区(= M3-D4 落地时的既有行为)。M.1 电力上线后多了一个合法取值:
    // EVT_BLACKOUT 的自动效果写的是 target=power(全城电力可用量 -40%),
    // 它的选项 A「启用备用燃料 → 减益降为 -10%」自然也要改 power 那一行而不是 event 那一行。
    // 只允许改**七乘区**里的 target:flat 通道有自己的 setFlat(),消费点类 target 没有「减益」语义
    private function setModifierValue(int $instanceId, float $value, string $target = ModifierTarget::SLOT_EVENT): void
    {
        if (! ModifierTarget::isSlot($target)) {
            $this->notes[] = "modifier_set_value:target「{$target}」不是七乘区之一,本条跳过";

            return;
        }

        DB::table('city_active_modifiers')
            ->where('source_type', 'event')->where('source_id', $instanceId)
            ->where('target', $target)
            ->update(['value' => round($value * $this->strength(), 4)]);
    }

    // 「立即恢复一半」/「价格冲击减半」/「港口减益取消」:把本实例写下的某条 target 的 modifier 值 ×系数(W5)。
    //
    // 与 setModifierValue 的分工见 EventCode::EFFECT_MODIFIER_SCALE 的说明:
    // 原文给的是**比例**而不是新数值,且被改的那条可能是掷点掷出来的(EVT_SPECULATION 的 +25%~50%),
    // 根本没有可写死的绝对值。
    //
    // 允许的 target 比 setModifierValue 宽:七乘区 **与** 消费点类 target 都可以 ——
    // 「恢复一半运输容量」「价格冲击减半」改的正是消费点类的那几行。
    // 但 flat 通道(幸福 / 治安)仍然排除:它有自己的 setFlat(),两条路都能改同一行 = 双改。
    //
    // 系数夹在 [0, 1]:选项只允许把减益变小(0 = 取消),后台把它填成 2 也不会加重惩罚。
    // 逐行读出再写回(不用 SQL 表达式):行数是个位数,而 `value = value * k` 的 SQL 写法
    // 在 MySQL / MariaDB 的 DECIMAL 舍入上各有脾气,读回来算干净再写更稳
    private function scaleModifier(int $instanceId, float $scale, string $target): void
    {
        if (in_array($target, ModifierTarget::FLAT_TARGETS, true)) {
            $this->notes[] = "modifier_scale:target「{$target}」属于 flat 通道,应走 flat_set,本条跳过";

            return;
        }
        if (! in_array($target, ModifierTarget::all(), true)) {
            $this->notes[] = "modifier_scale:target「{$target}」未登记,本条跳过";

            return;
        }

        $factor = max(0.0, min(1.0, $scale));

        $rows = DB::table('city_active_modifiers')
            ->where('source_type', 'event')->where('source_id', $instanceId)
            ->where('target', $target)
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            DB::table('city_active_modifiers')->where('id', $row->id)
                ->update(['value' => round((float) $row->value * $factor, 4)]);
        }

        if ($rows->isEmpty()) {
            $this->notes[] = "modifier_scale:本实例没有 target={$target} 的减益可调整";
        }
    }

    // 「大型庆典:幸福+8」这类「把 auto 的效果换成另一个数」。
    // 有 flat modifier 行就改行;没有(duration=0 的瞬时型)就按差额补改当前值
    private function setFlat(array $effect, int $instanceId, Carbon $startsAt, Carbon $endsAt, array &$applied): void
    {
        $target = ($effect['channel'] ?? 'happiness') === 'security'
            ? ModifierTarget::SECURITY_FLAT
            : ModifierTarget::HAPPINESS_FLAT;
        $value = round((float) $effect['value'] * $this->strength(), 4);

        $updated = DB::table('city_active_modifiers')
            ->where('source_type', 'event')->where('source_id', $instanceId)
            ->where('target', $target)
            ->update(['value' => $value]);

        if ($updated > 0) {
            return;
        }

        if ($target === ModifierTarget::HAPPINESS_FLAT) {
            // auto 没有写过 flat 行(duration=0):按「目标值 − 已改的量」补一次当前值
            $this->bumpHappinessNow($value - (float) ($applied['happiness'] ?? 0), $applied);

            return;
        }

        $this->security(['value' => (float) $effect['value']], $instanceId, $startsAt, $endsAt);
    }

    // ---------- 选项:改持续时间 ----------

    // 「持续时间-50%」:按**剩余**时长缩放(已经过去的那一段不能撤销)
    private function rescheduleScale(?object $instance, float $scale): void
    {
        if ($instance === null) {
            return;
        }

        $now = now();
        $remaining = max(0, Carbon::parse($instance->expires_at)->getTimestamp() - $now->getTimestamp());

        $this->rescheduleTo($instance, $now->copy()->addSeconds((int) round($remaining * $scale)));
    }

    // 把实例与它名下所有 modifier 的结束时刻改到 $endsAt(end_now 就是改到此刻)
    private function rescheduleTo(?object $instance, Carbon $endsAt): void
    {
        if ($instance === null) {
            return;
        }

        DB::table('city_events')->where('id', $instance->id)->update(['expires_at' => $endsAt]);
        DB::table('city_active_modifiers')
            ->where('source_type', 'event')->where('source_id', $instance->id)
            ->where('ends_at', '>', $endsAt)
            ->update(['ends_at' => $endsAt]);

        $instance->expires_at = $endsAt;
    }

    // ---------- 资源落库 ----------

    // 把累计的 delta 写进 city_resources / cities.money。
    // 夹取:下限 0(§52 不变量),上限仓储容量(超出部分记进 notes,不静默吞掉)
    public function commitResources(): array
    {
        $storage = (float) ($this->sim['storageCapacity'] ?? SimConstants::BASE_STORAGE);
        $actual = [];

        foreach ($this->delta as $code => $amount) {
            if (abs($amount) < 1e-9) {
                continue;
            }

            $before = $this->stock($code);

            if ($code === ResourceCode::MONEY) {
                // 资金没有仓储上限(§10.5 的资金是纯数值),只夹下限
                $after = max(0.0, $before + $amount);
                DB::table('cities')->where('id', $this->city->id)->update(['money' => round($after, 2)]);
                $this->sim['money'] = round($after, 2);
            } else {
                $after = max(0.0, min($before + $amount, $storage));
                if ($before + $amount > $storage + 1e-9) {
                    $this->notes[] = "storage_full:{$code} 发放被仓储上限截断 " . round($before + $amount - $storage, 4);
                }
                DB::table('city_resources')->updateOrInsert(
                    ['city_id' => $this->city->id, 'resource_id' => $code],
                    ['amount' => round($after, 4)]
                );
                $this->sim['resources'][$code] = round($after, 4);
            }

            $actual[$code] = round($after - $before, 4);
        }

        return $actual;
    }

    // ---------- 工具 ----------

    // 定额支出付得起吗(选项路径)。付不起整条拒绝,不做部分扣除
    private function assertAffordable(array $effects): void
    {
        $need = [];
        foreach ($effects as $effect) {
            if ((string) $effect['kind'] !== EventCode::EFFECT_RESOURCE_DELTA) {
                continue;
            }
            $value = (float) $effect['value'] * $this->strength();
            if ($value < 0) {
                $code = (string) $effect['resource'];
                $need[$code] = ($need[$code] ?? 0.0) - $value;
            }
        }

        foreach ($need as $code => $amount) {
            if ($this->stock($code) + 1e-9 < $amount) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422, [
                    'resource' => $code,
                    'required' => round($amount, 4),
                    'current'  => round($this->stock($code), 4),
                ]);
            }
        }
    }

    // 区间掷点:给了 min/max 就在区间里掷(结果落 rolled),否则取定值
    private function rollValue(array $effect, string $label): float
    {
        if (isset($effect['min'], $effect['max'])) {
            return EventRandom::between((float) $effect['min'], (float) $effect['max'], ...[...$this->seed, $label, 'range']);
        }

        return (float) $effect['value'];
    }

    // 效果强度倍率(后台逐事件可调,默认 1.0)。所有效果的**数值**统一乘它,时长不乘
    private function strength(): float
    {
        return max(0.0, (float) ($this->definition['effect_multiplier'] ?? 1.0));
    }

    private function stock(string $code): float
    {
        if ($code === ResourceCode::MONEY) {
            return (float) ($this->sim['money'] ?? $this->city->money);
        }

        return (float) ($this->sim['resources'][$code] ?? 0.0);
    }

    private function addDelta(string $code, float $amount): void
    {
        $this->delta[$code] = ($this->delta[$code] ?? 0.0) + $amount;
    }
}
