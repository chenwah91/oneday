<?php

namespace Tests\Feature\Event;

use App\Game\NPC\NpcCode;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// EVT_BRAIN_DRAIN 人才流失全链回归(v3.2 §9.2 + M3-D1 合并波次)。
//
// 这条事件在 M3-D4 交付时是 Fail Closed 停用的:自动效果要写 city_npcs 的状态位,
// 而那是 app/Game/NPC 的职责边界,事件系统不越界。合并波次把入口开在 NPC 模块里
// (NpcRuntimeService::leaveRandom,与「士气过低自行离职」共用同一个写入点),
// 事件侧只多了一个窄 kind = npc_leave。
//
// 触发条件(events.json 原文):高技能 NPC ≥ 3(门槛 event_npc_high_skill_level 默认 6 级)
// 且 幸福 < 60,min_era = IX。
class BrainDrainTest extends EventTestCase
{
    use RefreshDatabase;

    // 触发 → 恰好流失一名在编 NPC → 写 NPC.LEAVE 审计(actor=system)
    public function test_trigger_makes_exactly_one_npc_leave_and_writes_audit(): void
    {
        [$city] = $this->makeDrainCity('drain');
        $this->onlyEnable('EVT_BRAIN_DRAIN');

        $this->runSettle($city, 1);

        $instances = $this->activeInstances($city);
        $this->assertCount(1, $instances, 'EVT_BRAIN_DRAIN 应已触发');
        $this->assertSame('EVT_BRAIN_DRAIN', $instances[0]->event_id);

        // 恰好一名:4 个在编 → 3 个在编 + 1 个 left
        $this->assertSame(1, $this->countByStatus($city, NpcCode::STATUS_LEFT));
        $this->assertSame(3, $this->countActive($city));

        // 掷点结果落库(§11.3):rolled 里记着走的是哪一行,之后只读不复掷
        $rolled = json_decode((string) $instances[0]->rolled_json, true);
        $this->assertArrayHasKey('npc_leave', $rolled);
        $leftId = (int) $rolled['npc_leave']['city_npc_id'];
        $this->assertSame(NpcCode::STATUS_LEFT, DB::table('city_npcs')->where('id', $leftId)->value('status'));
        // 岗位一并解绑:走了的人不能还占着建筑的槽位
        $this->assertNull(DB::table('city_npcs')->where('id', $leftId)->value('assigned_instance_id'));

        // 审计:actor=system、reason 指向本条事件、delta 记释放掉的常态开销速率
        $audit = DB::table('audit_logs')->where('action', 'NPC.LEAVE')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame('EVENT_BRAIN_DRAIN', $audit->reason_code);
        $this->assertSame((string) $leftId, $audit->entity_id);
        $metadata = json_decode((string) $audit->metadata_json, true);
        $this->assertSame('EVT_BRAIN_DRAIN', $metadata['event_id']);
        $this->assertSame($rolled['npc_leave']['npc_id'], $metadata['npc_id']);
        $delta = json_decode((string) $audit->delta_json, true);
        $this->assertLessThan(0.0, (float) $delta['wage_money_per_min']);
    }

    // 幂等:resolve 只跑选项效果,不会再流失第二个人;重放同一 key 也不会
    public function test_resolve_does_not_drain_a_second_npc(): void
    {
        [$city, $user] = $this->makeDrainCity('drainidem');
        $this->onlyEnable('EVT_BRAIN_DRAIN');

        $this->runSettle($city, 1);
        $instanceId = (int) $this->activeInstances($city)[0]->id;
        $this->assertSame(1, $this->countByStatus($city, NpcCode::STATUS_LEFT));

        // 选项 C「允许离开」:承认已经发生的流失,不做任何补救
        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'c', 'idempotency_key' => 'drain-1',
        ])->assertOk();

        $this->assertSame(1, $this->countByStatus($city, NpcCode::STATUS_LEFT), 'resolve 不该再走一个人');

        // 重放同一幂等键:同样不流失第二个人,也不写第二条 NPC.LEAVE
        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'c', 'idempotency_key' => 'drain-1',
        ])->assertOk();

        $this->assertSame(1, $this->countByStatus($city, NpcCode::STATUS_LEFT));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'NPC.LEAVE')->count());
    }

    // 选项 B「改善环境:资金-2000,幸福+4」照常可执行(流失已经发生,这是事后补救)
    public function test_option_b_still_pays_and_lifts_happiness(): void
    {
        [$city, $user] = $this->makeDrainCity('drainb');
        $this->onlyEnable('EVT_BRAIN_DRAIN');

        $this->runSettle($city, 1);
        $instanceId = (int) $this->activeInstances($city)[0]->id;
        $moneyBefore = (float) $city->fresh()->money;
        $happinessBefore = (float) $city->fresh()->happiness;

        $this->actingAs($user)->postJson('/api/city/events/resolve', [
            'event_instance_id' => $instanceId, 'choice' => 'b',
        ])->assertOk();

        $this->assertEqualsWithDelta($moneyBefore - 2000, (float) $city->fresh()->money, 0.01);
        $this->assertEqualsWithDelta($happinessBefore + 4, (float) $city->fresh()->happiness, 0.01);
        $this->assertSame(1, $this->countByStatus($city, NpcCode::STATUS_LEFT), '选项不改变已发生的流失');
    }

    // 无在编 NPC 时空转:效果不报错、事件照常成立,只在审计 notes 里留一句
    public function test_no_active_npc_is_a_harmless_no_op(): void
    {
        [$city] = $this->makeDrainCity('drainempty');
        // 条件已经成立(掷点前先满足 npc_skill_count ≥ 3),再把人全部清空 ——
        // 「条件成立与效果执行之间玩家把人辞光了」在生产里是真实可发生的顺序
        DB::table('city_npcs')->where('city_id', $city->id)->update(['status' => NpcCode::STATUS_LEFT]);
        DB::table('event_definition')->where('event_id', 'EVT_BRAIN_DRAIN')
            ->update(['condition_json' => json_encode(['all' => [], 'unmapped_zh' => []], JSON_UNESCAPED_UNICODE)]);
        $this->onlyEnable('EVT_BRAIN_DRAIN');

        $this->runSettle($city, 1);

        $this->assertCount(1, $this->activeInstances($city), '没人可流失不该阻止事件成立');
        $this->assertSame(0, $this->countActive($city));

        // 没有 NPC.LEAVE,但触发审计的 notes 里必须答得出「为什么一个人都没少」
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'NPC.LEAVE')->count());
        $trigger = DB::table('audit_logs')->where('action', 'EVENT.TRIGGER')->latest('id')->first();
        $notes = json_decode((string) $trigger->metadata_json, true)['notes'] ?? [];
        $this->assertNotEmpty(array_filter($notes, fn ($n) => str_starts_with((string) $n, 'npc_leave:')));
    }

    // 条件闸门:高技能 NPC 不足 3 名就不该抽中(哪怕掷点概率拉满)
    public function test_low_skill_city_never_triggers(): void
    {
        [$city] = $this->makeDrainCity('drainlow', highSkill: 2);
        $this->onlyEnable('EVT_BRAIN_DRAIN');

        $this->runSettle($city, 1);
        $this->assertCount(0, $this->activeInstances($city), '高技能 NPC 不足 → 硬门槛出局');

        $this->runSettle($city, 31);
        $this->assertCount(0, $this->activeInstances($city));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'NPC.LEAVE')->count());
    }

    // 定义行本身:启用、无停用理由、自动效果已是可执行 DSL(unmapped 已清空)
    public function test_definition_row_is_enabled_and_auto_effect_is_executable(): void
    {
        $row = DB::table('event_definition')->where('event_id', 'EVT_BRAIN_DRAIN')->first();

        $this->assertSame(1, (int) $row->enabled);
        $this->assertNull($row->disabled_reason);

        $auto = json_decode((string) $row->auto_effect_json, true);
        $this->assertSame([], $auto['unmapped_zh']);
        $this->assertCount(1, $auto['effects']);
        $this->assertSame('npc_leave', $auto['effects'][0]['kind']);
        // 恒 1 名:数量不是可配置项(Seeder 守门也会拒绝带 value / count 的写法)
        $this->assertArrayNotHasKey('value', $auto['effects'][0]);
        $this->assertArrayNotHasKey('count', $auto['effects'][0]);

        // 选项 A「加薪挽留」仍然没有落点:工资是 npc_definition 的全服定义值,
        // city_npcs 没有逐人工资列 —— 原样保留在 unmapped_zh,玩家在事件卡片上看得见
        $options = json_decode((string) $row->options_json, true);
        $this->assertNotEmpty($options['a']['unmapped_zh']);
        $this->assertSame([], $options['c']['unmapped_zh'], '「允许离开」= 什么都不做,不再是待接线');
    }

    // ---- 夹具 ----

    // 一座满足触发条件的城:时代 X、幸福 50(< 60)、$highSkill 名 6 级 NPC + 若干低级 NPC
    private function makeDrainCity(string $un, int $highSkill = 3): array
    {
        [$city, $user] = $this->makeCity($un, [
            'era_order'  => 10,
            'era_key'    => 'X',
            'happiness'  => 50,
            'money'      => 1000000,
            'population' => 0,
        ]);

        for ($i = 0; $i < $highSkill; $i++) {
            $this->addNpc($city, 'N024', 8);
        }
        // 再加一名低技能的:候选池是「全部在编」,不是「只有高技能」
        $this->addNpc($city, 'N005', 3);

        return [$city->fresh(), $user];
    }

    private function addNpc(City $city, string $npcId, int $level): int
    {
        $def = DB::table('npc_definition')->where('npc_id', $npcId)->first();

        return (int) DB::table('city_npcs')->insertGetId([
            'city_id' => $city->id, 'npc_id' => $npcId,
            'skill_level' => $level, 'xp' => 0,
            'skill_value' => (int) $def->initial_skill_value, 'morale' => 70,
            'status' => NpcCode::STATUS_IDLE, 'assigned_instance_id' => null,
            'acquired_source' => NpcCode::SOURCE_RECRUIT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function countActive(City $city): int
    {
        return DB::table('city_npcs')->where('city_id', $city->id)
            ->whereIn('status', NpcCode::ACTIVE_STATUSES)->count();
    }

    private function countByStatus(City $city, string $status): int
    {
        return DB::table('city_npcs')->where('city_id', $city->id)->where('status', $status)->count();
    }
}
