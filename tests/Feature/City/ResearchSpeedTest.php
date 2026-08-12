<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Modifier\ConsumptionPoint;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcCode;
use App\Game\Technology\TechService;
use App\Models\City;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// M3-W7:死 target `research_speed_pct` 的清偿(消费点 = TechService 算 finished_at 的那一行)。
//
// 接线前的形态与 market_fee_pct 一模一样 ——「登记了 ≠ 生效」而且**是静默的**:
// §6.3 的 6 位学者类 NPC 早就写好了 specs(N048 +8% / N070 +16% / N080 +25% /
// N106 +8% / N130 +17% / N140 +28%),TechService 却从没读过它,
// 玩家花钱招了学者、研究一秒都没快,还不会报错。
//
// ══ 口径(与施工加速逐字一致,这一条是本文件的核心)═════════════════════════════
//     实际时长 = 基础时长 ÷ (1 + Σpct)      ← **速度口径**
// 不是 × (1 − Σpct):后者在 Σpct ≥ 1 时会把时长打成 0 或负数(两位高级学者叠起来就够得着),
// 而速度式无论加成多大都只趋近于 0、永远到不了 0。下限另夹 RESEARCH_SPEED_FLOOR = 0.1。
//
// 用例分四层:
//   ① 消费点层:三个来源(事件 modifier / 在编 NPC / 已装备工具)各验一遍;
//   ② 口径层(假失败):速度式 vs 时长式的差别用**精确秒数**钉死,写成乘法这里立刻红;
//   ③ 黄金样本层:TECH_I_SUST(1 分钟 = 60 秒)在各档加成下的精确完工秒数 + 审计三字段;
//   ④ 不追溯层:开单之后再招学者,已在研项目的 finished_at 一秒都不许动(v3.2 附录 A.3)。
class ResearchSpeedTest extends TestCase
{
    use RefreshDatabase;

    protected const BASE = '2026-01-01 00:00:00';

    // TECH_I_SUST:时代 I、无前置、知识成本 20、research_minutes = 1 → 基础 60 秒
    private const TECH = 'TECH_I_SUST';
    private const BASE_SECONDS = 60;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow(Carbon::parse(self::BASE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: City} */
    private function makeCity(string $un): array
    {
        $user = User::create(['username' => $un, 'name' => $un, 'email' => "{$un}@example.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($user);

        // 时代 I + 足够的知识与资金;人口 0 让结算不吃粮、不长人(本文件只验工期)
        DB::table('cities')->where('id', $city->id)->update([
            'era_key' => 'I', 'era_order' => 1, 'money' => 1000000, 'population' => 0,
            'last_simulated_at' => self::BASE,
        ]);
        DB::table('city_resources')->updateOrInsert(
            ['city_id' => $city->id, 'resource_id' => 'knowledge'],
            ['amount' => 1000]
        );

        return [$user, $city->fresh()];
    }

    private function addNpc(City $city, string $npcId): void
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        DB::table('city_npcs')->insert([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => NpcCode::STATUS_IDLE, 'assigned_instance_id' => null,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addModifier(City $city, float $value): void
    {
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => ModifierTarget::RESEARCH_SPEED_PCT, 'scope' => ModifierSpec::SCOPE_CITY,
            'scope_key' => null, 'op' => ModifierSpec::OP_PCT, 'value' => $value,
            'starts_at' => now()->copy()->subMinute(),
            'ends_at' => now()->copy()->addMinutes(60),
            'created_at' => now(),
        ]);
    }

    // §7 的 24 件工具里**一件都没有**投稿 research_speed_pct。为了验「工具这个来源读不读得到」,
    // 这里把一件现成工具的 effect_json 临时改成研究加速 —— 验的是**消费点的取数路径**,不是数值。
    // 将来真出现研究工具时,这条用例保证它一装上就生效
    private function equipResearchItem(City $city, float $value): void
    {
        DB::table('item_definition')->where('item_id', 'IT016')->update([
            'effect_json' => json_encode([
                'specs' => [[
                    'target' => ModifierTarget::RESEARCH_SPEED_PCT,
                    'scope'  => ModifierSpec::SCOPE_CITY,
                    'op'     => ModifierSpec::OP_PCT,
                    'value'  => $value,
                ]],
                'unmapped_zh' => [],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $instanceId = (int) DB::table('city_building_instances')->where('city_id', $city->id)->value('id');
        $durability = (float) DB::table('item_definition')->where('item_id', 'IT016')->value('durability');

        DB::table('city_items')->insert([
            'city_id' => $city->id, 'item_id' => 'IT016',
            'durability_left' => $durability, 'status' => 'equipped',
            'equipped_instance_id' => $instanceId,
            'acquired_source' => 'test', 'acquired_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // 走真实端点下单,返回本次研究的实际工期(秒)
    private function researchSeconds(User $user, City $city, string $techId = self::TECH): int
    {
        $this->actingAs($user)->postJson('/api/city/research', ['tech_id' => $techId])->assertOk();

        $row = DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', $techId)->first(['started_at', 'finished_at']);

        return Carbon::parse($row->finished_at)->getTimestamp() - Carbon::parse($row->started_at)->getTimestamp();
    }

    // ---------- ① 消费点层:三个来源各验一遍 ----------

    // 基准:一个投稿都没有 → 就是定义值 60 秒
    public function test_base_duration_without_any_contribution(): void
    {
        [$user, $city] = $this->makeCity('rsbase');

        $this->assertSame(0.0, ConsumptionPoint::pct(ModifierTarget::RESEARCH_SPEED_PCT, (int) $city->id));
        $this->assertSame(self::BASE_SECONDS, $this->researchSeconds($user, $city));
    }

    // 来源①:事件写下的持续型 modifier(+50% → 60 / 1.5 = 40 秒)
    public function test_speed_from_event_modifier(): void
    {
        [$user, $city] = $this->makeCity('rsevt');
        $this->addModifier($city, 0.50);

        $this->assertSame(40, $this->researchSeconds($user, $city));
    }

    // 来源②:在编 NPC 的特性。N080「研究速度 +25%」→ 60 / 1.25 = 48 秒(整除,不含取整误差)
    public function test_speed_from_npc_trait(): void
    {
        [$user, $city] = $this->makeCity('rsnpc');
        $this->addNpc($city, 'N080');

        $this->assertSame(48, $this->researchSeconds($user, $city));
    }

    // 来源③:已装备且耐久 > 0 的工具
    public function test_speed_from_equipped_item(): void
    {
        [$user, $city] = $this->makeCity('rsitem');
        $this->equipResearchItem($city, 0.20);

        // 60 / 1.20 = 50
        $this->assertSame(50, $this->researchSeconds($user, $city));
    }

    // 三个来源同时在场:各数一次,不重不漏。+0.05 +0.25 +0.20 = +0.50 → 60 / 1.5 = 40
    public function test_all_three_sources_compose_once(): void
    {
        [$user, $city] = $this->makeCity('rsall');
        $this->addModifier($city, 0.05);
        $this->addNpc($city, 'N080');       // +25%
        $this->equipResearchItem($city, 0.20);

        $this->assertEqualsWithDelta(0.50, ConsumptionPoint::pct(ModifierTarget::RESEARCH_SPEED_PCT, (int) $city->id), 1e-9);
        $this->assertSame(40, $this->researchSeconds($user, $city));
    }

    // 未在编的 NPC(已离职)不投稿 —— 与其余消费点同口径
    public function test_inactive_npc_does_not_contribute(): void
    {
        [$user, $city] = $this->makeCity('rsleft');
        $this->addNpc($city, 'N080');
        DB::table('city_npcs')->where('city_id', $city->id)->update(['status' => NpcCode::STATUS_LEFT]);

        $this->assertSame(self::BASE_SECONDS, $this->researchSeconds($user, $city));
    }

    // 过期的 modifier 不计入
    public function test_expired_modifier_does_not_contribute(): void
    {
        [$user, $city] = $this->makeCity('rsexp');
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => ModifierTarget::RESEARCH_SPEED_PCT, 'scope' => ModifierSpec::SCOPE_CITY,
            'scope_key' => null, 'op' => ModifierSpec::OP_PCT, 'value' => 0.50,
            'starts_at' => now()->copy()->subHours(2),
            'ends_at' => now()->copy()->subHour(),
            'created_at' => now(),
        ]);

        $this->assertSame(self::BASE_SECONDS, $this->researchSeconds($user, $city));
    }

    // ---------- ② 口径层(假失败)----------

    // **假失败 1** —— 必须是**除以** (1 + pct),不是乘 (1 − pct)。
    // +28% 时两种写法差 4 秒(47 vs 43),这条用例就是拿来防写成乘法的
    public function test_duration_uses_the_speed_form_not_the_multiplicative_form(): void
    {
        [$user, $city] = $this->makeCity('rsform');
        $this->addNpc($city, 'N140'); // +28%

        // 60 / 1.28 = 46.875 → round → 47
        $seconds = $this->researchSeconds($user, $city);
        $this->assertSame(47, $seconds, '口径是 时长 ÷ (1 + pct)');
        // 时长式会得到 60 × (1 − 0.28) = 43.2 → 43,与 47 是两个明确不同的数
        $this->assertNotSame((int) round(self::BASE_SECONDS * (1 - 0.28)), $seconds, '不许写成乘 (1 − pct)');
    }

    // **假失败 2** —— Σpct ≥ 1 时时长绝不能变成 0(那就是「瞬间完成研究」= 可刷)。
    // 时长式在 +100% 时直接归零;速度式得到 60 / 2 = 30
    public function test_hundred_percent_bonus_halves_instead_of_zeroing(): void
    {
        [$user, $city] = $this->makeCity('rsfull');
        $this->addModifier($city, 1.0);

        $seconds = $this->researchSeconds($user, $city);
        $this->assertSame(30, $seconds, '+100% 是「速度翻倍」= 时长减半,不是「时长归零」');
        $this->assertGreaterThan(0, $seconds);
    }

    // **假失败 3** —— 极端负值不该把工期打成无穷 / 负数:速度倍率夹在 RESEARCH_SPEED_FLOOR
    public function test_extreme_negative_pct_is_clamped_by_the_speed_floor(): void
    {
        [$user, $city] = $this->makeCity('rsfloor');
        $this->addModifier($city, -5.0);

        // 60 / 0.1 = 600
        $this->assertSame(600, $this->researchSeconds($user, $city));
        $this->assertSame(0.1, TechService::RESEARCH_SPEED_FLOOR);
    }

    // 口径与施工加速**同一条**:两处的下限常量必须相等,否则「同一句话两个数」
    public function test_floor_matches_the_construction_speed_floor(): void
    {
        $this->assertSame(
            \App\Game\Building\ConstructionService::CONSTRUCTION_SPEED_FLOOR,
            TechService::RESEARCH_SPEED_FLOOR,
            '研究加速与施工加速是同一条口径,下限也必须是同一个数'
        );
    }

    // ---------- ③ 黄金样本层 ----------

    // **黄金样本** —— TECH_I_SUST(基础 60 秒)在各档加成下的精确完工秒数。
    //
    //   无加成            60 / 1.00 = 60
    //   N048 +8%          60 / 1.08 = 55.5555… → 56
    //   N080 +25%         60 / 1.25 = 48        (整除)
    //   N140 +28%         60 / 1.28 = 46.875   → 47
    //   N080 + N140 +53%  60 / 1.53 = 39.2156… → 39
    //   六位学者全招齐 +102%(0.08+0.16+0.25+0.08+0.17+0.28)
    //                     60 / 2.02 = 29.7029… → 30
    // 取整方向 round(与 ConstructionService::plannedSeconds 同口径):
    // 工期不是资源,四舍五入不产生任何可套利的零头
    public function test_golden_sample_exact_finish_seconds_per_tier(): void
    {
        $cases = [
            ['rsg0', [],                 60],
            ['rsg1', ['N048'],           56],
            ['rsg2', ['N080'],           48],
            ['rsg3', ['N140'],           47],
            ['rsg4', ['N080', 'N140'],   39],
            ['rsg5', ['N048', 'N070', 'N080', 'N106', 'N130', 'N140'], 30],
        ];

        foreach ($cases as [$un, $npcs, $expected]) {
            [$user, $city] = $this->makeCity($un);
            foreach ($npcs as $npcId) {
                $this->addNpc($city, $npcId);
            }

            $this->assertSame($expected, $this->researchSeconds($user, $city),
                sprintf('%s(%s)应完工于 %d 秒', $un, implode('+', $npcs) ?: '无加成', $expected));
        }

        // 定义表数据本身也钉一遍:少一位学者或改了数值,上面的样本就不再成立
        $this->assertSame(6, DB::table('npc_definition')
            ->where('trait_json', 'like', '%"' . ModifierTarget::RESEARCH_SPEED_PCT . '"%')->count());
    }

    // 审计必须同时留下实际时长与基础时长(照 BuildService 的 durationSeconds / baseDurationSeconds 先例):
    // 半年后要能回答「他这条科技为什么只花了 48 秒」
    public function test_audit_records_both_actual_and_base_duration(): void
    {
        [$user, $city] = $this->makeCity('rsaudit');
        $this->addNpc($city, 'N080');

        $this->researchSeconds($user, $city);

        $meta = json_decode((string) DB::table('audit_logs')
            ->where('action', 'TECH.RESEARCH_START')->latest('id')->value('metadata_json'), true);

        $this->assertSame(48, $meta['durationSeconds'], '实际时长(已含研究加速)');
        $this->assertSame(60, $meta['baseDurationSeconds'], '定义值折减前的秒数');
        $this->assertEqualsWithDelta(0.25, $meta['researchSpeedPct'], 1e-9);
        // 原有字段保持不动(定义口径的分钟数),前端与旧查询不受影响
        $this->assertEqualsWithDelta(1.0, $meta['researchMinutes'], 1e-9);
    }

    // 快照的 technologies.researching 给的是折减后的完工时刻(前端倒计时读它)
    public function test_snapshot_researching_block_carries_the_shortened_finish_time(): void
    {
        [$user, $city] = $this->makeCity('rssnap');
        $this->addNpc($city, 'N080');
        $this->researchSeconds($user, $city);

        $researching = $this->actingAs($user)->getJson('/api/city')->json('data.city.technologies.researching');

        $this->assertSame(self::TECH, $researching['tech_id']);
        $this->assertSame(
            48,
            Carbon::parse($researching['finished_at'])->getTimestamp() - Carbon::parse($researching['started_at'])->getTimestamp()
        );
    }

    // ---------- ④ 不追溯层(v3.2 附录 A.3)----------

    // 开单之后再招学者:已在研项目的 finished_at **一秒都不许动**。
    // 追溯会让同一项研究出现两套真相(下单时的口径 vs 结算时的口径),玩家还会看到进度条倒退
    public function test_hiring_a_scholar_after_the_order_does_not_speed_up_the_running_research(): void
    {
        [$user, $city] = $this->makeCity('rsnoretro');

        $this->actingAs($user)->postJson('/api/city/research', ['tech_id' => self::TECH])->assertOk();
        $finishedBefore = (string) DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', self::TECH)->value('finished_at');

        // 事后招两位学者 + 写一条事件加速
        $this->addNpc($city, 'N080');
        $this->addNpc($city, 'N140');
        $this->addModifier($city, 1.0);

        // 拉一次快照(会跑结算 + 科技懒结算)
        $this->actingAs($user)->getJson('/api/city')->assertOk();

        $finishedAfter = (string) DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', self::TECH)->value('finished_at');

        $this->assertSame($finishedBefore, $finishedAfter, '在研项目的完工时刻已经算死,事后加成不追溯');
        $this->assertSame(60, Carbon::parse($finishedAfter)->getTimestamp() - Carbon::parse(self::BASE)->getTimestamp());
    }

    // 反过来:辞退学者也不会让在研项目变慢(同一条「算死」纪律的另一面)
    public function test_dismissing_a_scholar_does_not_slow_the_running_research(): void
    {
        [$user, $city] = $this->makeCity('rsnoretro2');
        $this->addNpc($city, 'N080');

        $this->assertSame(48, $this->researchSeconds($user, $city));
        $finishedBefore = (string) DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', self::TECH)->value('finished_at');

        DB::table('city_npcs')->where('city_id', $city->id)->update(['status' => NpcCode::STATUS_LEFT]);
        $this->actingAs($user)->getJson('/api/city')->assertOk();

        $this->assertSame($finishedBefore, (string) DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', self::TECH)->value('finished_at'));
    }

    // 折减后的完工时刻到点后照常解锁(懒结算读的是同一列,接线没有改动完成路径)
    public function test_shortened_research_unlocks_on_time(): void
    {
        [$user, $city] = $this->makeCity('rsunlock');
        $this->addNpc($city, 'N080');
        $this->researchSeconds($user, $city);

        // 48 秒后(基础工期 60 秒还没到)
        Carbon::setTestNow(Carbon::parse(self::BASE)->addSeconds(48));
        $this->actingAs($user)->getJson('/api/city')->assertOk();

        $this->assertSame(TechService::STATUS_UNLOCKED, (string) DB::table('city_technologies')
            ->where('city_id', $city->id)->where('tech_id', self::TECH)->value('status'));
    }

    // ---------- 登记表 ----------

    public function test_target_is_registered_as_wired_to_tech_service(): void
    {
        $entry = ModifierTarget::CONSUMPTION_POINTS[ModifierTarget::RESEARCH_SPEED_PCT];

        $this->assertTrue($entry['wired'], 'research_speed_pct 必须标记为已接线');
        $this->assertSame('App\Game\Technology\TechService', $entry['consumer']);
        $this->assertTrue(class_exists($entry['consumer']));
    }
}
