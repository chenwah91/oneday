<?php

namespace Tests\Feature\Npc;

use App\Game\City\CityFactory;
use App\Game\NPC\NpcCode;
use App\Game\NPC\NpcRandom;
use App\Game\NPC\NpcService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use App\Support\GameSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// NPC 四个端点的完整安全链(CLAUDE §42 / §83):
// 招募扣费 / 服务器权威掷点 / 越权 / 幂等 / Revision / 派驻互斥 / 槽位上限 / 撤下 / 辞退。
class NpcApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    protected function tearDown(): void { NpcRandom::createNormally(); parent::tearDown(); }

    // ---- 招募 ----

    public function test_recruit_deducts_money_creates_npc_and_bumps_revision(): void
    {
        $u = $this->makePlayer('recruiter', eraOrder: 2, money: 100000);
        $city = City::where('user_id', $u->id)->first();

        $res = $this->actingAs($u)->postJson('/api/city/npc/recruit', []);

        $res->assertOk();
        $npc = DB::table('city_npcs')->where('city_id', $city->id)->first();
        $this->assertNotNull($npc);
        $this->assertSame(NpcCode::STATUS_IDLE, $npc->status);
        $this->assertSame(NpcCode::SOURCE_RECRUIT, $npc->acquired_source);

        // 价格 = wage_per_min × 200 × 稀有度系数(A7),服务器算,客户端不参与
        $def = DB::table('npc_definition')->where('npc_id', $npc->npc_id)->first();
        $expected = 100000 - NpcService::recruitPrice($def);
        $this->assertEqualsWithDelta($expected, (float) $city->fresh()->money, 0.01);
        $this->assertSame(1, (int) $city->fresh()->revision);

        // 审计:delta 带资金变化,metadata 带掷出的稀有度(掷点结果必须落库,不可复掷)
        $audit = DB::table('audit_logs')->where('action', 'NPC.RECRUIT')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertArrayHasKey('money', json_decode($audit->delta_json, true));
        $this->assertSame($def->rarity, json_decode($audit->metadata_json, true)['rarity']);
    }

    // 客户端不能指定招募谁:多传的字段会被 allowlist 校验直接忽略(不是「照单接收」)
    public function test_recruit_ignores_client_supplied_npc_id(): void
    {
        $u = $this->makePlayer('nopick', eraOrder: 2, money: 100000);

        // 传一个时代 X 的传奇 NPC,服务器不该理会
        $this->actingAs($u)->postJson('/api/city/npc/recruit', ['npc_id' => 'N030'])->assertOk();

        $npcId = DB::table('city_npcs')->latest('id')->value('npc_id');
        $this->assertNotSame('N030', $npcId);
        // 时代 II 的可招募池只有 N006 / N007 / N008
        $this->assertContains($npcId, ['N006', 'N007', 'N008']);
    }

    public function test_recruit_rejects_when_era_too_low(): void
    {
        // 新城时代 I:可招募池(recruit_source = recruit)最早是时代 II
        $u = $this->makePlayer('lowera', eraOrder: 1, money: 100000);

        $this->actingAs($u)->postJson('/api/city/npc/recruit', [])
            ->assertStatus(422)->assertJson(['error' => 'NPC_ERA_REQUIRED']);
        $this->assertSame(0, DB::table('city_npcs')->count());
    }

    public function test_recruit_rejects_when_money_insufficient(): void
    {
        // 时代 II 最便宜的 N006 = 2 × 200 × 1.0 = 400
        $u = $this->makePlayer('poor', eraOrder: 2, money: 399);

        $this->actingAs($u)->postJson('/api/city/npc/recruit', [])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);
        $this->assertSame(0, DB::table('city_npcs')->count());
    }

    public function test_recruit_is_idempotent(): void
    {
        $u = $this->makePlayer('idem', eraOrder: 2, money: 100000);
        $city = City::where('user_id', $u->id)->first();

        $key = 'recruit-key-1';
        $this->actingAs($u)->postJson('/api/city/npc/recruit', ['idempotency_key' => $key])->assertOk();
        $moneyAfterFirst = (float) $city->fresh()->money;

        $this->actingAs($u)->postJson('/api/city/npc/recruit', ['idempotency_key' => $key])->assertOk();

        // 重放:不重复扣款、不重复建人
        $this->assertSame(1, DB::table('city_npcs')->where('city_id', $city->id)->count());
        $this->assertEqualsWithDelta($moneyAfterFirst, (float) $city->fresh()->money, 0.001);
    }

    public function test_recruit_rejects_stale_revision(): void
    {
        $u = $this->makePlayer('stale', eraOrder: 2, money: 100000);

        $this->actingAs($u)->postJson('/api/city/npc/recruit', ['expected_revision' => 99])
            ->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);
        $this->assertSame(0, DB::table('city_npcs')->count());
    }

    // ---- 服务器权威掷点(§30 / §66)----

    // 稀有度按权重掷点:用**脚本化随机源**打在权重边界上,断言落袋区间精确无误。
    // 权重默认 60 / 25 / 10 / 4 / 1(×10000 放大)→ 累加区间:
    //   [1, 600000] common / [600001, 850000] uncommon / [850001, 950000] rare
    //   [950001, 990000] epic / [990001, 1000000] legendary
    public function test_rarity_roll_respects_weight_boundaries(): void
    {
        $cases = [
            1        => NpcCode::RARITY_COMMON,
            600000   => NpcCode::RARITY_COMMON,
            600001   => NpcCode::RARITY_UNCOMMON,
            850000   => NpcCode::RARITY_UNCOMMON,
            850001   => NpcCode::RARITY_RARE,
            950000   => NpcCode::RARITY_RARE,
            950001   => NpcCode::RARITY_EPIC,
            990000   => NpcCode::RARITY_EPIC,
            990001   => NpcCode::RARITY_LEGENDARY,
            1000000  => NpcCode::RARITY_LEGENDARY,
        ];

        $index = 0;
        foreach ($cases as $roll => $expectedRarity) {
            $u = $this->makePlayer('roller' . $index++, eraOrder: 10, money: 100000000);
            $city = City::where('user_id', $u->id)->first();

            $this->scriptRandom([$roll]); // 第一次 int() 是权重掷点,后面的候选抽取回落到 $min
            NpcService::recruit($city, null, null);

            $npcId = DB::table('city_npcs')->where('city_id', $city->id)->value('npc_id');
            $rarity = DB::table('npc_definition')->where('npc_id', $npcId)->value('rarity');
            $this->assertSame($expectedRarity, $rarity, "掷点 {$roll} 应落在 {$expectedRarity}");
        }
    }

    // 同一个固定种子跑两遍,抽到的序列必须完全一致(服务器权威随机 = 可复现、可审计)
    public function test_seeded_rng_is_reproducible(): void
    {
        $first = $this->recruitSequenceWithSeed('seedone', 12345, 8);
        $second = $this->recruitSequenceWithSeed('seedtwo', 12345, 8);

        $this->assertSame($first, $second);
        // 换种子必须换结果,否则说明随机源根本没被用上
        $this->assertNotSame($first, $this->recruitSequenceWithSeed('seedthree', 999, 8));
    }

    // 固定种子下的稀有度分布黄金样本:权重 60/25/10/4/1 → 40 抽里普通占绝大多数
    public function test_seeded_rarity_distribution_is_weighted(): void
    {
        $sequence = $this->recruitSequenceWithSeed('dist', 20260811, 40);

        $counts = [];
        foreach ($sequence as $npcId) {
            $rarity = DB::table('npc_definition')->where('npc_id', $npcId)->value('rarity');
            $counts[$rarity] = ($counts[$rarity] ?? 0) + 1;
        }

        $this->assertSame(40, array_sum($counts));
        // common 权重 60 是第二名 uncommon(25)的 2.4 倍,40 抽里必须明显占优
        $this->assertGreaterThan($counts[NpcCode::RARITY_UNCOMMON] ?? 0, $counts[NpcCode::RARITY_COMMON] ?? 0);
        // legendary 权重 1(1%),40 抽里最多零星几个
        $this->assertLessThanOrEqual(3, $counts[NpcCode::RARITY_LEGENDARY] ?? 0);
    }

    // ---- 派驻 ----

    public function test_assign_and_unassign_round_trip(): void
    {
        [$u, $city, $instanceId, $npcId] = $this->makePlayerWithNpcAndFarm('assigner');

        $this->actingAs($u)->postJson('/api/city/npc/assign', [
            'city_npc_id' => $npcId, 'building_instance_id' => $instanceId,
        ])->assertOk();

        $npc = DB::table('city_npcs')->where('id', $npcId)->first();
        $this->assertSame(NpcCode::STATUS_ASSIGNED, $npc->status);
        $this->assertSame($instanceId, (int) $npc->assigned_instance_id);
        $this->assertSame('NPC.ASSIGN', DB::table('audit_logs')->latest('id')->first()->action);

        $this->actingAs($u)->postJson('/api/city/npc/unassign', ['city_npc_id' => $npcId])->assertOk();

        $npc = DB::table('city_npcs')->where('id', $npcId)->first();
        $this->assertSame(NpcCode::STATUS_IDLE, $npc->status);
        $this->assertNull($npc->assigned_instance_id);
        $this->assertSame('NPC.UNASSIGN', DB::table('audit_logs')->latest('id')->first()->action);
    }

    // 一 NPC 一岗(§52 / §67):已在岗的 NPC 再派到别处必须被拒,不能出现「同时两个岗位」
    public function test_assign_rejects_already_assigned_npc(): void
    {
        [$u, $city, $instanceId, $npcId] = $this->makePlayerWithNpcAndFarm('mutex');
        $second = $this->addBuilding($city, 'F02', 6);

        $this->actingAs($u)->postJson('/api/city/npc/assign', [
            'city_npc_id' => $npcId, 'building_instance_id' => $instanceId,
        ])->assertOk();

        $this->actingAs($u)->postJson('/api/city/npc/assign', [
            'city_npc_id' => $npcId, 'building_instance_id' => $second,
        ])->assertStatus(409)->assertJson(['error' => 'NPC_ALREADY_ASSIGNED']);

        // 仍然只在第一栋楼上
        $this->assertSame($instanceId, (int) DB::table('city_npcs')->where('id', $npcId)->value('assigned_instance_id'));
    }

    // 槽位上限(A5,后台可调):默认每栋 2 个,第三个必须被拒
    public function test_assign_rejects_when_slots_are_full(): void
    {
        [$u, $city, $instanceId, $first] = $this->makePlayerWithNpcAndFarm('slots');
        $second = $this->putNpc($city, 'N005');
        $third = $this->putNpc($city, 'N005');

        $this->actingAs($u)->postJson('/api/city/npc/assign', ['city_npc_id' => $first, 'building_instance_id' => $instanceId])->assertOk();
        $this->actingAs($u)->postJson('/api/city/npc/assign', ['city_npc_id' => $second, 'building_instance_id' => $instanceId])->assertOk();
        $this->actingAs($u)->postJson('/api/city/npc/assign', ['city_npc_id' => $third, 'building_instance_id' => $instanceId])
            ->assertStatus(422)->assertJson(['error' => 'NPC_SLOT_FULL']);

        $this->assertSame(2, DB::table('city_npcs')->where('assigned_instance_id', $instanceId)->count());
    }

    // 施工 / 升级中的建筑不能派人(不生产,派进去没有意义,也会让槽位被无谓占住)
    public function test_assign_rejects_non_active_building(): void
    {
        [$u, $city, $instanceId, $npcId] = $this->makePlayerWithNpcAndFarm('constructing');
        DB::table('city_building_instances')->where('id', $instanceId)->update(['status' => 'constructing']);

        $this->actingAs($u)->postJson('/api/city/npc/assign', [
            'city_npc_id' => $npcId, 'building_instance_id' => $instanceId,
        ])->assertStatus(422)->assertJson(['error' => 'NPC_NOT_AVAILABLE']);
    }

    // 越权:不能派驻别人的 NPC(403 + 审计 + Security Log)
    public function test_cannot_assign_other_players_npc(): void
    {
        [$victim, $victimCity, $victimInstance, $victimNpc] = $this->makePlayerWithNpcAndFarm('victim');
        [$attacker, $attackerCity, $attackerInstance] = $this->makePlayerWithFarm('attacker');

        $this->actingAs($attacker)->postJson('/api/city/npc/assign', [
            'city_npc_id' => $victimNpc, 'building_instance_id' => $attackerInstance,
        ])->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);

        $this->assertNull(DB::table('city_npcs')->where('id', $victimNpc)->value('assigned_instance_id'));
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'SECURITY.AUTHORIZATION_FAILED')->where('entity_type', 'city_npc')->count());
    }

    // 越权:不能把自己的 NPC 派进别人的建筑(建筑的 city_id 校验)
    public function test_cannot_assign_into_other_players_building(): void
    {
        [$victim, $victimCity, $victimInstance] = $this->makePlayerWithFarm('victim2');
        [$attacker, $attackerCity, $attackerInstance, $attackerNpc] = $this->makePlayerWithNpcAndFarm('attacker2');

        $this->actingAs($attacker)->postJson('/api/city/npc/assign', [
            'city_npc_id' => $attackerNpc, 'building_instance_id' => $victimInstance,
        ])->assertStatus(422)->assertJson(['error' => 'NPC_NOT_AVAILABLE']);

        $this->assertSame(0, DB::table('city_npcs')->where('assigned_instance_id', $victimInstance)->count());
    }

    public function test_assign_is_idempotent(): void
    {
        [$u, $city, $instanceId, $npcId] = $this->makePlayerWithNpcAndFarm('assignidem');
        $key = 'assign-key-1';

        $this->actingAs($u)->postJson('/api/city/npc/assign', [
            'city_npc_id' => $npcId, 'building_instance_id' => $instanceId, 'idempotency_key' => $key,
        ])->assertOk();
        $revision = (int) $city->fresh()->revision;

        // 重放同一 key:不再 +revision、不再写第二条审计,也不会撞上 NPC_ALREADY_ASSIGNED
        $this->actingAs($u)->postJson('/api/city/npc/assign', [
            'city_npc_id' => $npcId, 'building_instance_id' => $instanceId, 'idempotency_key' => $key,
        ])->assertOk();

        $this->assertSame($revision, (int) $city->fresh()->revision);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'NPC.ASSIGN')->count());
    }

    // 重复撤下:当成成功的无操作,不 +revision、不刷审计
    public function test_unassign_twice_is_a_no_op(): void
    {
        [$u, $city, $instanceId, $npcId] = $this->makePlayerWithNpcAndFarm('unassigntwice');
        $this->actingAs($u)->postJson('/api/city/npc/assign', ['city_npc_id' => $npcId, 'building_instance_id' => $instanceId])->assertOk();
        $this->actingAs($u)->postJson('/api/city/npc/unassign', ['city_npc_id' => $npcId])->assertOk();
        $revision = (int) $city->fresh()->revision;

        $this->actingAs($u)->postJson('/api/city/npc/unassign', ['city_npc_id' => $npcId])->assertOk();

        $this->assertSame($revision, (int) $city->fresh()->revision);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'NPC.UNASSIGN')->count());
    }

    // ---- 辞退 ----

    public function test_dismiss_marks_left_and_records_released_upkeep(): void
    {
        [$u, $city, $instanceId, $npcId] = $this->makePlayerWithNpcAndFarm('dismisser');
        $this->actingAs($u)->postJson('/api/city/npc/assign', ['city_npc_id' => $npcId, 'building_instance_id' => $instanceId])->assertOk();

        $this->actingAs($u)->postJson('/api/city/npc/dismiss', ['city_npc_id' => $npcId])->assertOk();

        $npc = DB::table('city_npcs')->where('id', $npcId)->first();
        $this->assertSame(NpcCode::STATUS_LEFT, $npc->status);
        $this->assertNull($npc->assigned_instance_id);

        $audit = DB::table('audit_logs')->where('action', 'NPC.DISMISS')->latest('id')->first();
        $delta = json_decode($audit->delta_json, true);
        // N005 工资 2/min、口粮 1/min,辞退释放的就是这两条速率
        $this->assertEqualsWithDelta(-2.0, $delta['wage_money_per_min'], 0.001);
        $this->assertEqualsWithDelta(-1.0, $delta['food_per_min'], 0.001);
    }

    public function test_dismiss_rejects_already_left_npc(): void
    {
        [$u, $city, $instanceId, $npcId] = $this->makePlayerWithNpcAndFarm('dismisstwice');
        $this->actingAs($u)->postJson('/api/city/npc/dismiss', ['city_npc_id' => $npcId])->assertOk();

        $this->actingAs($u)->postJson('/api/city/npc/dismiss', ['city_npc_id' => $npcId])
            ->assertStatus(422)->assertJson(['error' => 'NPC_NOT_AVAILABLE']);
    }

    // ---- 快照 ----

    public function test_snapshot_exposes_npcs_block(): void
    {
        [$u, $city, $instanceId, $npcId] = $this->makePlayerWithNpcAndFarm('snapshot');
        $this->actingAs($u)->postJson('/api/city/npc/assign', ['city_npc_id' => $npcId, 'building_instance_id' => $instanceId])->assertOk();

        $res = $this->actingAs($u)->getJson('/api/city');

        $res->assertOk();
        $res->assertJsonPath('data.city.npcs.total', 1);
        $res->assertJsonPath('data.city.npcs.assigned', 1);
        $res->assertJsonPath('data.city.npcs.idle', 0);
        $res->assertJsonPath('data.city.npcs.list.0.npc_id', 'N005');
        $res->assertJsonPath('data.city.npcs.list.0.status', NpcCode::STATUS_ASSIGNED);
        // 派驻关系:building_instance_id => [city_npc_id…]
        $this->assertSame([$npcId], $res->json("data.city.npcs.assignments.{$instanceId}"));
        // 工资 / 口粮速率与结算侧同源
        $this->assertEqualsWithDelta(2.0, $res->json('data.city.npcs.wage_money_per_min'), 0.001);
        $this->assertEqualsWithDelta(1.0, $res->json('data.city.npcs.food_per_min'), 0.001);
        // 槽位规则下发给前端画「x / y 槽」
        $this->assertSame(2, $res->json('data.city.npcs.slots_per_building'));
        $this->assertSame(3, $res->json('data.city.npcs.slots_per_building_l3'));
    }

    // ---- 后台设定改动立刻生效 ----

    public function test_slot_setting_change_takes_effect(): void
    {
        [$u, $city, $instanceId, $first] = $this->makePlayerWithNpcAndFarm('slotsetting');
        $second = $this->putNpc($city, 'N005');

        GameSetting::set(GameSetting::NPC_SLOTS_PER_BUILDING, 1, null, '收紧槽位');
        GameSetting::flush();

        $this->actingAs($u)->postJson('/api/city/npc/assign', ['city_npc_id' => $first, 'building_instance_id' => $instanceId])->assertOk();
        $this->actingAs($u)->postJson('/api/city/npc/assign', ['city_npc_id' => $second, 'building_instance_id' => $instanceId])
            ->assertStatus(422)->assertJson(['error' => 'NPC_SLOT_FULL']);
    }

    public function test_recruit_price_setting_change_takes_effect(): void
    {
        $u = $this->makePlayer('pricesetting', eraOrder: 2, money: 100000);
        $city = City::where('user_id', $u->id)->first();

        // 工资系数 200 → 10:N006 的价格从 400 变成 20
        GameSetting::set(GameSetting::NPC_RECRUIT_PRICE_WAGE_MULTIPLIER, 10, null, '压低招募价');
        GameSetting::flush();

        $this->actingAs($u)->postJson('/api/city/npc/recruit', [])->assertOk();

        $npcId = DB::table('city_npcs')->where('city_id', $city->id)->value('npc_id');
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();
        $this->assertEqualsWithDelta((float) $def->wage_per_min * 10, 100000 - (float) $city->fresh()->money, 0.01);
    }

    // ---- 夹具 ----

    private function makePlayer(string $un, int $eraOrder = 1, float $money = 100000): User
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $eraKey = DB::table('era')->where('era_order', $eraOrder)->value('era_key');
        DB::table('cities')->where('id', $city->id)
            ->update(['money' => $money, 'era_key' => $eraKey, 'era_order' => $eraOrder]);

        return $u;
    }

    private function makePlayerWithFarm(string $un): array
    {
        $u = $this->makePlayer($un, eraOrder: 2);
        $city = City::where('user_id', $u->id)->first();
        DB::table('city_building_instances')->where('city_id', $city->id)->delete();

        return [$u, $city, $this->addBuilding($city, 'F02', 1)];
    }

    private function makePlayerWithNpcAndFarm(string $un): array
    {
        [$u, $city, $instanceId] = $this->makePlayerWithFarm($un);

        return [$u, $city, $instanceId, $this->putNpc($city, 'N005')];
    }

    private function addBuilding(City $city, string $buildingId, int $x): int
    {
        return (int) CityBuildingInstance::create([
            'city_id' => $city->id, 'building_id' => $buildingId, 'level' => 1,
            'x' => $x, 'y' => 1, 'status' => 'active', 'assigned_workers' => 0,
        ])->id;
    }

    // 测试夹具:直接落一行 city_npcs(招募链路本身在上面的用例里单独验)
    private function putNpc(City $city, string $npcId): int
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        return (int) DB::table('city_npcs')->insertGetId([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => NpcCode::STATUS_IDLE, 'assigned_instance_id' => null,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // 脚本化随机源:按队列逐个返回,用尽后回落到 $min(仅测试用,生产恒走 random_int)
    private function scriptRandom(array $queue): void
    {
        NpcRandom::createUsing(function (int $min, int $max) use (&$queue) {
            $value = array_shift($queue);

            return $value === null ? $min : max($min, min($max, (int) $value));
        });
    }

    // 固定种子的确定性随机源(线性同余),连抽 $times 次,返回抽到的 npc_id 序列
    private function recruitSequenceWithSeed(string $un, int $seed, int $times): array
    {
        $u = $this->makePlayer($un, eraOrder: 10, money: 100000000);
        $city = City::where('user_id', $u->id)->first();

        $state = $seed;
        NpcRandom::createUsing(function (int $min, int $max) use (&$state) {
            $state = ($state * 1103515245 + 12345) % 2147483648;

            return $min + $state % ($max - $min + 1);
        });

        $sequence = [];
        for ($i = 0; $i < $times; $i++) {
            NpcService::recruit($city->fresh(), null, null);
            $sequence[] = DB::table('city_npcs')->where('city_id', $city->id)->latest('id')->value('npc_id');
        }

        NpcRandom::createNormally();

        return $sequence;
    }
}
