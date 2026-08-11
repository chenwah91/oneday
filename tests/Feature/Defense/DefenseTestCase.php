<?php

namespace Tests\Feature\Defense;

use App\Game\Item\ItemCode;
use App\Game\NPC\NpcCode;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Event\EventTestCase;

// M3-D5 国防联动测试的共同脚手架。
//
// 直接复用事件那一套(EventTestCase):时间冻结在固定基准、makeCity 清掉建城送的建筑、
// onlyEnable 精确控制候选池 —— 国防的两条事件本来就跑在事件引擎上,两套脚手架没有意义。
//
// 国防值的来源:D01 岗哨(L1 output_json = defense_score 25/分钟,4 工人)。
// 容量类产出在内核里于乘区之前提取,**不受用工 / 乘区 / 满足率影响**,
// 所以测试里只要把楼建成 active,25 点就到账 —— 不必派工,数字才干净。
abstract class DefenseTestCase extends EventTestCase
{
    use RefreshDatabase;

    // D01 岗哨 L1 的国防值(v3.2 §3.5 building_levels.json,单栋每级 25)
    protected const D01_DEFENSE = 25.0;

    // 建 N 栋岗哨,返回全城建筑口径国防值
    protected function addWatchtowers(City $city, int $count): float
    {
        for ($i = 0; $i < $count; $i++) {
            $this->addBuilding($city, 'D01');
        }

        return $count * self::D01_DEFENSE;
    }

    // 测试夹具:直接落一行 city_npcs(招募链路本身在 NPC 用例里验)
    protected function addNpc(City $city, string $npcId, string $status = NpcCode::STATUS_IDLE): int
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

    // 测试夹具:直接落一行 city_items(制作 / 装备链路本身在工具用例里验)
    protected function addItem(
        City $city,
        string $itemId,
        string $status = ItemCode::STATUS_EQUIPPED,
        ?int $instanceId = 1,
        ?float $durability = null
    ): int {
        $default = (float) DB::table('item_definition')->where('item_id', $itemId)->value('durability');

        return (int) DB::table('city_items')->insertGetId([
            'city_id' => $city->id, 'item_id' => $itemId,
            'durability_left' => $durability ?? $default,
            'status' => $status,
            'equipped_instance_id' => $status === ItemCode::STATUS_EQUIPPED ? $instanceId : null,
            'acquired_source' => ItemCode::SOURCE_CRAFT,
            'acquired_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // 直接写一行生效中的 city_active_modifiers(事件写的那种),用来验读取侧聚合
    protected function addModifier(City $city, string $target, string $op, float $value, int $minutes = 30): void
    {
        DB::table('city_active_modifiers')->insert([
            'city_id' => $city->id, 'source_type' => 'event', 'source_id' => 0,
            'target' => $target, 'scope' => 'city', 'scope_key' => null,
            'op' => $op, 'value' => $value,
            'starts_at' => now()->copy()->subMinute(),
            'ends_at' => now()->copy()->addMinutes($minutes),
            'created_at' => now(),
        ]);
    }
}
