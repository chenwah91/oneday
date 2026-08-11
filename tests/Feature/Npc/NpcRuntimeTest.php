<?php

namespace Tests\Feature\Npc;

use App\Game\City\CityFactory;
use App\Game\NPC\NpcCode;
use App\Game\NPC\NpcRandom;
use App\Game\NPC\NpcRuntimeService;
use App\Game\Simulation\SimConstants;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// NPC 运行时懒结算(backlog §9 A1 自然增长 / A4 士气离职 / A6 XP)。
//
// 这四件事全部走 NpcRuntimeService(与 TechService::settleFinished 同一条懒结算路径),
// 结算内核一个字都没为它们改动过 —— 这也是本文件要守住的边界。
class NpcRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void
    {
        NpcRandom::createNormally();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- A6:XP 与升级 ----

    // 已派驻:10 XP/min × 20min = 200 XP;N005 初始 3 级,到 4 级要 580 → 停在 3 级
    public function test_assigned_npc_accumulates_xp(): void
    {
        [$city, $instanceId] = $this->makeCity('xpgain');
        $npcId = $this->putNpc($city, 'N005', $instanceId);

        $this->settleAfterMinutes($city, 20);

        $npc = DB::table('city_npcs')->where('id', $npcId)->first();
        $this->assertSame(200, (int) $npc->xp);
        $this->assertSame(3, (int) $npc->skill_level);
    }

    // 跨级:100min × 10 = 1000 XP;3→4 花掉 580,余 420(4→5 要 919,不够)
    public function test_xp_levels_up_and_carries_remainder(): void
    {
        [$city, $instanceId] = $this->makeCity('xplevel');
        $npcId = $this->putNpc($city, 'N005', $instanceId);

        $this->settleAfterMinutes($city, 100);

        $npc = DB::table('city_npcs')->where('id', $npcId)->first();
        $this->assertSame(4, (int) $npc->skill_level);
        $this->assertSame(420, (int) $npc->xp);
    }

    // 一次结算内连升多级:600min × 10 = 6000 XP → 3→4(580)→5(919)→6(1313)→7(1758),余 1430
    public function test_xp_can_cross_multiple_levels_in_one_settle(): void
    {
        [$city, $instanceId] = $this->makeCity('xpmulti');
        $npcId = $this->putNpc($city, 'N005', $instanceId);

        $this->settleAfterMinutes($city, 600);

        $npc = DB::table('city_npcs')->where('id', $npcId)->first();
        $this->assertSame(7, (int) $npc->skill_level);
        $this->assertSame(6000 - 580 - 919 - 1313 - 1758, (int) $npc->xp);
    }

    // 未派驻不涨 XP(§6.2「工作中每 60 秒获得基础 XP」)
    public function test_idle_npc_gains_no_xp(): void
    {
        [$city] = $this->makeCity('xpidle');
        $npcId = $this->putNpc($city, 'N005', null);

        $this->settleAfterMinutes($city, 100);

        $this->assertSame(0, (int) DB::table('city_npcs')->where('id', $npcId)->value('xp'));
    }

    // 离线封顶:结算窗口最多 MAX_OFFLINE_SECONDS(12h),挂机 24h 只按 12h 补算
    public function test_offline_xp_is_capped_by_max_offline_window(): void
    {
        [$city, $instanceId] = $this->makeCity('xpoffline');
        $npcId = $this->putNpc($city, 'N005', $instanceId, 10); // 满级不涨 XP,改用工资视角
        $second = $this->putNpc($city, 'N005', $instanceId);

        $this->settleAfterMinutes($city, 24 * 60);

        // 12h × 10 XP/min = 7200 XP,不是 24h 的 14400
        $capMinutes = SimConstants::MAX_OFFLINE_SECONDS / 60;
        $totalXp = 0;
        $npc = DB::table('city_npcs')->where('id', $second)->first();
        for ($level = 3; $level < (int) $npc->skill_level; $level++) {
            $totalXp += (int) DB::table('npc_skill_level_curve')->where('level', $level)->value('xp_to_next');
        }
        $totalXp += (int) $npc->xp;
        $this->assertSame((int) ($capMinutes * 10), $totalXp);
    }

    // ---- A4:士气 ----

    public function test_morale_recovers_when_everything_is_fine(): void
    {
        [$city] = $this->makeCity('moraleup');
        $npcId = $this->putNpc($city, 'N005', null);
        DB::table('city_npcs')->where('id', $npcId)->update(['morale' => 70]);

        // 10 分钟 × +0.5 = +5
        $this->settleAfterMinutes($city, 10, ['happiness' => 80, 'maintenanceArrears' => false]);

        $this->assertEqualsWithDelta(75.0, (float) DB::table('city_npcs')->where('id', $npcId)->value('morale'), 0.01);
    }

    public function test_morale_drops_on_wage_arrears(): void
    {
        [$city] = $this->makeCity('moralearrears');
        $npcId = $this->putNpc($city, 'N005', null);

        // 欠薪 -2/min × 10 = -20
        $this->settleAfterMinutes($city, 10, ['happiness' => 80, 'maintenanceArrears' => true]);

        $this->assertEqualsWithDelta(50.0, (float) DB::table('city_npcs')->where('id', $npcId)->value('morale'), 0.01);
    }

    // 欠薪与低幸福可以叠加(-2 且 -1 = -3/min)
    public function test_morale_penalties_stack(): void
    {
        [$city] = $this->makeCity('moralestack');
        $npcId = $this->putNpc($city, 'N005', null);

        $this->settleAfterMinutes($city, 10, ['happiness' => 40, 'maintenanceArrears' => true]);

        $this->assertEqualsWithDelta(40.0, (float) DB::table('city_npcs')->where('id', $npcId)->value('morale'), 0.01);
    }

    // 关掉士气开关 = 回到「未接入前的行为」:一分不动
    public function test_morale_switch_off_freezes_morale(): void
    {
        [$city] = $this->makeCity('moraleoff');
        $npcId = $this->putNpc($city, 'N005', null);
        GameSetting::set(GameSetting::NPC_MORALE_ENABLED, false, null, '救急关闭');
        GameSetting::flush();

        $this->settleAfterMinutes($city, 60, ['happiness' => 10, 'maintenanceArrears' => true]);

        $this->assertEqualsWithDelta(70.0, (float) DB::table('city_npcs')->where('id', $npcId)->value('morale'), 0.01);
    }

    // ---- A4:离职 ----

    public function test_low_morale_npc_can_leave_and_writes_audit(): void
    {
        [$city, $instanceId] = $this->makeCity('leave');
        $npcId = $this->putNpc($city, 'N005', $instanceId);
        DB::table('city_npcs')->where('id', $npcId)->update(['morale' => 10]);

        // 离职判定用的是**本次结算之后**的士气,所以必须让士气继续往下走(欠薪 -2/min):
        // 一切正常时 60 分钟会回升 +30,士气反而爬回阈值以上,那是设计如此(欠薪解决了人就不走了)
        // 掷点 1 <= 1000(10% × 10000)→ 离职
        $this->scriptRandom([1]);
        $this->settleAfterMinutes($city, 60, ['happiness' => 80, 'maintenanceArrears' => true]);

        $npc = DB::table('city_npcs')->where('id', $npcId)->first();
        $this->assertSame(NpcCode::STATUS_LEFT, $npc->status);
        $this->assertNull($npc->assigned_instance_id);

        $audit = DB::table('audit_logs')->where('action', 'NPC.LEAVE')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame('MORALE_TOO_LOW', $audit->reason_code);
    }

    public function test_high_morale_npc_never_leaves(): void
    {
        [$city, $instanceId] = $this->makeCity('noleave');
        $npcId = $this->putNpc($city, 'N005', $instanceId);
        DB::table('city_npcs')->where('id', $npcId)->update(['morale' => 90]);

        // 即使掷点必中,士气高于阈值也不进掷点分支
        $this->scriptRandom([1, 1, 1, 1]);
        $this->settleAfterMinutes($city, 600, ['happiness' => 80, 'maintenanceArrears' => false]);

        $this->assertSame(NpcCode::STATUS_ASSIGNED, DB::table('city_npcs')->where('id', $npcId)->value('status'));
    }

    // ---- A1:自然增长 ----

    public function test_natural_growth_adds_npc_within_gates(): void
    {
        [$city] = $this->makeCity('growth');

        // 掷点命中 + 抽第 0 个候选;150 池下时代 I 的 natural_growth 池是这 9 个
        $this->scriptRandom([1, 0]);
        $this->settleAfterMinutes($city, 60, ['happiness' => 80, 'population' => 30, 'populationCapacity' => 100]);

        $npc = DB::table('city_npcs')->where('city_id', $city->id)->first();
        $this->assertNotNull($npc);
        $this->assertSame(NpcCode::SOURCE_NATURAL_GROWTH, $npc->acquired_source);
        $this->assertContains($npc->npc_id, ['N002', 'N003', 'N004', 'N031', 'N032', 'N033', 'N091', 'N092', 'N093']);
        $this->assertSame('NPC.NATURAL_GROWTH', DB::table('audit_logs')->latest('id')->first()->action);
    }

    // 住房闸:空余率不足 5% 一律不长人(A1)
    public function test_natural_growth_blocked_by_housing_gate(): void
    {
        [$city] = $this->makeCity('growthhousing');

        $this->scriptRandom([1, 0]);
        $this->settleAfterMinutes($city, 60, ['happiness' => 80, 'population' => 99, 'populationCapacity' => 100]);

        $this->assertSame(0, DB::table('city_npcs')->where('city_id', $city->id)->count());
    }

    // 幸福闸:低于 60 不长人(A1)
    public function test_natural_growth_blocked_by_happiness_gate(): void
    {
        [$city] = $this->makeCity('growthhappy');

        $this->scriptRandom([1, 0]);
        $this->settleAfterMinutes($city, 60, ['happiness' => 59, 'population' => 30, 'populationCapacity' => 100]);

        $this->assertSame(0, DB::table('city_npcs')->where('city_id', $city->id)->count());
    }

    // 离线雪崩防护:12h = 12 个窗口,即使窗窗掷中也最多补 2 名(A1 的 offline_max)
    public function test_offline_natural_growth_is_capped(): void
    {
        [$city] = $this->makeCity('growthoffline');

        // 每窗都掷中并抽第 0 个候选
        $this->scriptRandom(array_fill(0, 60, 1));
        $this->settleAfterMinutes($city, 12 * 60, ['happiness' => 80, 'population' => 30, 'populationCapacity' => 1000]);

        $this->assertSame(2, DB::table('city_npcs')->where('city_id', $city->id)->count());
    }

    // 全城自然增长上限 = floor(人口 / 500) + 2:已有 2 个就不再长
    public function test_natural_growth_respects_city_cap(): void
    {
        [$city] = $this->makeCity('growthcap');
        $this->putNpc($city, 'N002', null, null, NpcCode::SOURCE_NATURAL_GROWTH);
        $this->putNpc($city, 'N003', null, null, NpcCode::SOURCE_NATURAL_GROWTH);

        $this->scriptRandom(array_fill(0, 60, 1));
        $this->settleAfterMinutes($city, 12 * 60, ['happiness' => 80, 'population' => 30, 'populationCapacity' => 1000]);

        $this->assertSame(2, DB::table('city_npcs')->where('city_id', $city->id)->count());
    }

    // 关掉自然增长开关 = 回到「未接入前的行为」
    public function test_natural_growth_switch_off(): void
    {
        [$city] = $this->makeCity('growthoff');
        GameSetting::set(GameSetting::NPC_NATURAL_GROWTH_ENABLED, false, null, '救急关闭');
        GameSetting::flush();

        $this->scriptRandom(array_fill(0, 60, 1));
        $this->settleAfterMinutes($city, 12 * 60, ['happiness' => 80, 'population' => 30, 'populationCapacity' => 1000]);

        $this->assertSame(0, DB::table('city_npcs')->where('city_id', $city->id)->count());
    }

    // 不足一个窗口不掷点(60 分钟窗口,只过了 30 分钟)
    public function test_partial_window_does_not_roll(): void
    {
        [$city] = $this->makeCity('growthpartial');

        $this->scriptRandom([1, 0]);
        $this->settleAfterMinutes($city, 30, ['happiness' => 80, 'population' => 30, 'populationCapacity' => 1000]);

        $this->assertSame(0, DB::table('city_npcs')->where('city_id', $city->id)->count());
    }

    // ---- 时钟 ----

    // 结算时钟必须推进:同一段时间不能被反复补算
    public function test_settle_advances_clock_and_is_not_replayed(): void
    {
        [$city, $instanceId] = $this->makeCity('clock');
        $npcId = $this->putNpc($city, 'N005', $instanceId);

        $this->settleAfterMinutes($city, 20);
        $this->assertSame(200, (int) DB::table('city_npcs')->where('id', $npcId)->value('xp'));

        // 再结算一次(时间没动)→ XP 不变
        NpcRuntimeService::settle($city->fresh(), $this->sim([]));
        $this->assertSame(200, (int) DB::table('city_npcs')->where('id', $npcId)->value('xp'));
        $this->assertNotNull(DB::table('cities')->where('id', $city->id)->value('npc_settled_at'));
    }

    // 快照端点会触发懒结算(与科技懒结算同一条路径)
    public function test_snapshot_triggers_runtime_settle(): void
    {
        [$city, $instanceId, $user] = $this->makeCity('snapsettle', withUser: true);
        $npcId = $this->putNpc($city, 'N005', $instanceId);

        $base = Carbon::parse('2026-01-01 00:00:00');
        DB::table('cities')->where('id', $city->id)
            ->update(['last_simulated_at' => $base, 'npc_settled_at' => $base]);
        Carbon::setTestNow($base->copy()->addMinutes(30));

        $this->actingAs($user)->getJson('/api/city')->assertOk();

        $this->assertSame(300, (int) DB::table('city_npcs')->where('id', $npcId)->value('xp'));
    }

    // ---- 夹具 ----

    private function makeCity(string $un, bool $withUser = false): array
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();
        DB::table('cities')->where('id', $city->id)->update(['money' => 100000, 'population' => 30]);

        $inst = CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => 'F02', 'level' => 1,
            'x' => 1, 'y' => 1, 'status' => 'active', 'assigned_workers' => 4,
        ]);

        $city = $city->fresh();

        return $withUser ? [$city, (int) $inst->id, $u] : [$city, (int) $inst->id];
    }

    private function putNpc(City $city, string $npcId, ?int $instanceId, ?int $level = null, ?string $source = null): int
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        return (int) DB::table('city_npcs')->insertGetId([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => $level ?? (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => $instanceId === null ? NpcCode::STATUS_IDLE : NpcCode::STATUS_ASSIGNED,
            'assigned_instance_id' => $instanceId,
            'acquired_source' => $source ?? NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // 结算内核的返回值在本文件里是**输入**(士气看幸福与欠费,自然增长看人口与容量),
    // 所以直接手工构造,不去跑一遍完整结算 —— 这样每条断言的自变量只有一个
    private function sim(array $overrides): array
    {
        return array_merge([
            'happiness' => 80.0, 'population' => 30, 'populationCapacity' => 1000.0,
            'maintenanceArrears' => false,
        ], $overrides);
    }

    private function settleAfterMinutes(City $city, int $minutes, array $sim = []): void
    {
        $base = Carbon::parse('2026-01-01 00:00:00');
        DB::table('cities')->where('id', $city->id)
            ->update(['last_simulated_at' => $base, 'npc_settled_at' => $base]);
        Carbon::setTestNow($base->copy()->addMinutes($minutes));

        NpcRuntimeService::settle($city->fresh(), $this->sim($sim));
    }

    private function scriptRandom(array $queue): void
    {
        NpcRandom::createUsing(function (int $min, int $max) use (&$queue) {
            $value = array_shift($queue);

            return $value === null ? $min : max($min, min($max, (int) $value));
        });
    }
}
