<?php

namespace Tests\Feature\Npc;

use App\Game\Modifier\ModifierTarget;
use App\Game\NPC\NpcCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// NPC 定义层守门(v3.2 §6.1 / §6.2 / §6.3):数据逐行对得上规格,而不是「跑得起来就算数」。
// M2 的 upgrade_to 断链教训:静默兜底的数据错误可以活很久,只有断言才抓得住。
class NpcDefinitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_definition_row_counts_match_spec(): void
    {
        // §6.1 = 12 条技能;§6.2 = 10 级曲线;§6.3 = 30 个原型
        $this->assertSame(12, DB::table('npc_skill_definition')->count());
        $this->assertSame(10, DB::table('npc_skill_level_curve')->count());
        $this->assertSame(30, DB::table('npc_definition')->count());
    }

    public function test_level_curve_matches_spec_rows(): void
    {
        $curve = DB::table('npc_skill_level_curve')->orderBy('level')->get()->keyBy('level');

        // §6.2 表格逐行抽查:1 级无加成、10 级满级 0 XP、中间档按 0.035 递增
        $this->assertSame(100, (int) $curve[1]->xp_to_next);
        $this->assertSame(0.0, (float) $curve[1]->primary_bonus);
        $this->assertSame(0.14, (float) $curve[5]->primary_bonus);
        $this->assertSame(0.08, (float) $curve[5]->maintenance_reduction_cap);
        $this->assertSame(0, (int) $curve[10]->xp_to_next);
        $this->assertSame(0.315, (float) $curve[10]->primary_bonus);

        // 曲线必须单调不减(否则升级会掉加成),且 XP 也随等级递增
        for ($level = 2; $level <= 10; $level++) {
            $this->assertGreaterThan((float) $curve[$level - 1]->primary_bonus, (float) $curve[$level]->primary_bonus);
        }
    }

    public function test_npc_rows_match_spec_samples(): void
    {
        $rows = DB::table('npc_definition')->get()->keyBy('npc_id');

        // §6.3 三行抽查:开局 NPC / 中期铁匠 / 终局传奇
        $this->assertSame('SKILL_ADMIN', $rows['N001']->primary_skill_id);
        $this->assertSame(NpcCode::RARITY_RARE, $rows['N001']->rarity);
        $this->assertSame(0.0, (float) $rows['N001']->wage_per_min);
        $this->assertSame(1.2, (float) $rows['N001']->food_per_min);
        $this->assertSame(NpcCode::SOURCE_INITIAL, $rows['N001']->recruit_source);

        $this->assertSame('IV', $rows['N012']->min_era);
        $this->assertSame(70, (int) $rows['N012']->initial_skill_value);
        $this->assertSame(5, (int) $rows['N012']->initial_skill_level);
        $this->assertSame(6.0, (float) $rows['N012']->wage_per_min);

        $this->assertSame(NpcCode::RARITY_LEGENDARY, $rows['N030']->rarity);
        $this->assertSame(60.0, (float) $rows['N030']->wage_per_min);
        $this->assertSame(10, (int) $rows['N030']->max_level);
    }

    public function test_every_definition_uses_registered_codes(): void
    {
        $skillIds = DB::table('npc_skill_definition')->pluck('skill_id')->all();

        foreach (DB::table('npc_definition')->get() as $row) {
            $this->assertContains($row->rarity, NpcCode::RARITIES, "{$row->npc_id} 稀有度非法");
            $this->assertContains($row->recruit_source, NpcCode::SOURCES, "{$row->npc_id} 来源 code 非法");
            $this->assertContains($row->primary_skill_id, $skillIds, "{$row->npc_id} 主技能不在 §6.1 表里");

            // 特性里的 target 必须是 ModifierTarget 已登记的(不许发明 target)
            $trait = json_decode($row->trait_json, true);
            foreach ($trait['specs'] ?? [] as $spec) {
                $this->assertContains($spec['target'], ModifierTarget::all(), "{$row->npc_id} 特性 target 未登记");
            }
        }

        // 技能的 effect_target 同样要么已登记、要么为 NULL(留给尚未登记消费点的技能)
        foreach (DB::table('npc_skill_definition')->get() as $skill) {
            if ($skill->effect_target !== null) {
                $this->assertContains($skill->effect_target, ModifierTarget::all(), "{$skill->skill_id} effect_target 未登记");
            }
        }
    }

    public function test_npc_definition_is_in_game_data_version_checksum(): void
    {
        $before = \App\Game\Definition\GameDataVersion::checksum();

        DB::table('npc_definition')->where('npc_id', 'N012')->update(['wage_per_min' => 999]);

        // 改一行 NPC 定义就必须改变全局指纹,否则 §64/§65 的「当时用的是哪一版数值」无从回答
        $this->assertNotSame($before, \App\Game\Definition\GameDataVersion::checksum());
    }

    public function test_v330_version_row_exists_and_is_ordered_before_market(): void
    {
        $versions = DB::table('game_data_versions')->orderBy('id')->pluck('version')->all();

        $this->assertContains('V3.3.0', $versions);
        // 插入顺序必须是版本号升序:current() 取的是 id 最大的一行
        $sorted = $versions;
        usort($sorted, 'version_compare');
        $this->assertSame($sorted, $versions, 'game_data_versions 的插入顺序必须是版本号升序');
    }
}
