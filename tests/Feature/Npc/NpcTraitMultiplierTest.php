<?php

namespace Tests\Feature\Npc;

use App\Game\Item\ItemCode;
use App\Game\NPC\NpcCode;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Event\EventTestCase;

// W11-B 任务4:npc_definition.trait_multiplier —— NPC 特性的强度倍率。
//
// 走**真实快照**验(SimulationService → 治理容量消费点),不是单测 NpcTraitScale ——
// 倍率的价值全在「后台改完,玩家下一次结算就该看到变化」这条链上,
// 中间任何一个读取点忘了带上倍率,单测都发现不了。
//
// 素材(与 GovernanceCapacityTest 同一套,数值可对照):
//   A01 行政所 L1 治理容量 80
//   N001 领袖   治理容量 +10%(pct)
//   N051 行政   治理容量 +20 (flat)
//   IT022 城市规划工具 治理效率 +10%(pct,**工具来源,不该被 NPC 倍率影响**)
class NpcTraitMultiplierTest extends EventTestCase
{
    use RefreshDatabase;

    // A01 行政所 L1 的治理容量(容量类产出在乘区之前提取,不派工也计)
    private const A01_GOVERNANCE = 80.0;

    protected function setUp(): void
    {
        parent::setUp();

        // 关掉全部事件:一条随机抽中的 EVT_CORRUPTION 会往同一条 target 上再投一份,把精确值断言算歪
        DB::table('event_definition')->update(['enabled' => false]);
        \App\Game\Event\EventDefinition::flush();
    }

    // ---------- 默认值:落地即零行为变化 ----------

    public function test_default_multiplier_keeps_the_original_numbers(): void
    {
        $this->assertSame(
            0,
            DB::table('npc_definition')->where('trait_multiplier', '<>', 1)->count(),
            '全表 150 行的默认倍率必须都是 1.0000(落地不改变任何既有数值)'
        );

        [$city] = $this->makeCity('tmdefault', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N001');

        $sim = $this->runSettle($city, 10);

        // 80 × 1.10 = 88(与 GovernanceCapacityTest 的既有断言逐字一致)
        $this->assertEqualsWithDelta(0.10, $sim['governanceCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(88.0, $sim['governanceCapacityEffective'], 1e-6);
    }

    // ---------- pct:N001 治理 +10%,倍率 2.0 → +20% ----------

    public function test_multiplier_scales_a_pct_trait(): void
    {
        [$city] = $this->makeCity('tmpct', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N001');

        DB::table('npc_definition')->where('npc_id', 'N001')->update(['trait_multiplier' => 2.0]);

        $sim = $this->runSettle($city, 10);

        // 0.10 × 2.0 = 0.20 → 80 × 1.20 = 96
        $this->assertEqualsWithDelta(0.20, $sim['governanceCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(96.0, $sim['governanceCapacityEffective'], 1e-6);
    }

    // ---------- flat 同乘:同一个人变强,不该因为写法不同而只强一半 ----------

    public function test_multiplier_scales_a_flat_trait_too(): void
    {
        [$city] = $this->makeCity('tmflat', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N051'); // 治理容量 +20(flat)

        DB::table('npc_definition')->where('npc_id', 'N051')->update(['trait_multiplier' => 2.5]);

        $sim = $this->runSettle($city, 10);

        // 20 × 2.5 = 50 → 80 + 50 = 130
        $this->assertEqualsWithDelta(50.0, $sim['governanceCapacityFlat'], 1e-6);
        $this->assertEqualsWithDelta(130.0, $sim['governanceCapacityEffective'], 1e-6);
    }

    // ---------- 只乘 NPC 来源:工具投稿不受影响 ----------

    // 同一条 target 上 NPC 与工具各投一份:倍率只放大 NPC 那一份。
    // 连坐会让「调 NPC」变成「顺手调了工具」—— 这条用例就是拿来防连坐的
    public function test_multiplier_does_not_touch_tool_contributions(): void
    {
        [$city] = $this->makeCity('tmtool', ['population' => 40, 'era_order' => 1]);
        $instanceId = $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N001');                                          // pct +0.10
        $this->addItem($city, 'IT022', ItemCode::STATUS_EQUIPPED, $instanceId); // pct +0.10(工具)

        DB::table('npc_definition')->where('npc_id', 'N001')->update(['trait_multiplier' => 2.0]);

        $sim = $this->runSettle($city, 10);

        // NPC 0.10×2 = 0.20,工具仍是 0.10 → 合计 0.30;连坐的话会是 0.40
        $this->assertEqualsWithDelta(0.30, $sim['governanceCapacityPct'], 1e-6, '倍率只作用于 NPC 来源');
        $this->assertEqualsWithDelta(104.0, $sim['governanceCapacityEffective'], 1e-6); // 80 × 1.30
    }

    // ---------- 后台改完即刻生效(端到端)----------

    public function test_admin_edit_takes_effect_on_the_next_snapshot(): void
    {
        [$city] = $this->makeCity('tmadmin', ['population' => 40, 'era_order' => 1]);
        $this->addBuilding($city, 'A01');
        $this->addNpc($city, 'N001');

        $admin = \App\Models\User::create([
            'username' => 'tmadmin2', 'name' => 'tmadmin2', 'email' => 'tmadmin2@example.com', 'password' => 'password123',
        ]);
        $admin->forceFill(['role' => 'admin'])->save();

        $this->actingAs($admin)->postJson('/api/admin/definitions/npc', [
            'npc_id' => 'N001', 'field' => 'trait_multiplier', 'value' => 0, 'reason' => 'N001 特性整体停用',
        ])->assertOk();

        $sim = $this->runSettle($city, 10);

        // 倍率 0 = 该 NPC 的特性整体失效(工资口粮照收,那是另一套列)
        $this->assertEqualsWithDelta(0.0, $sim['governanceCapacityPct'], 1e-6);
        $this->assertEqualsWithDelta(self::A01_GOVERNANCE, $sim['governanceCapacityEffective'], 1e-6);
    }

    // ---------- 测试夹具(与 GovernanceCapacityTest 同款)----------

    // 直接落一行 city_npcs(招募链路本身在 NPC 用例里验)
    private function addNpc(City $city, string $npcId, string $status = NpcCode::STATUS_IDLE): int
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        return (int) DB::table('city_npcs')->insertGetId([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => (int) $def->initial_skill_level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => $status, 'assigned_instance_id' => null,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // 直接落一行 city_items(制作 / 装备链路本身在工具用例里验)
    private function addItem(City $city, string $itemId, string $status, ?int $instanceId): int
    {
        $durability = (float) DB::table('item_definition')->where('item_id', $itemId)->value('durability');

        return (int) DB::table('city_items')->insertGetId([
            'city_id' => $city->id, 'item_id' => $itemId,
            'durability_left' => $durability,
            'status' => $status,
            'equipped_instance_id' => $status === ItemCode::STATUS_EQUIPPED ? $instanceId : null,
            'acquired_source' => ItemCode::SOURCE_CRAFT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
