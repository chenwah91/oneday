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
        // §6.1 = 12 条技能;§6.2 = 10 级曲线;§6.3 原表 30 个原型 + 扩充草案 120 个 = 150
        $this->assertSame(12, DB::table('npc_skill_definition')->count());
        $this->assertSame(10, DB::table('npc_skill_level_curve')->count());
        $this->assertSame(150, DB::table('npc_definition')->count());

        // 编号必须是连续的 N001~N150:断号意味着草案里漏了一行,而漏掉的那一行
        // 在运行时只表现为「这个原型永远抽不到」—— 与 M2 的 upgrade_to 断链同一类静默故障
        $ids = DB::table('npc_definition')->orderBy('npc_id')->pluck('npc_id')->all();
        $expected = [];
        for ($i = 1; $i <= 150; $i++) {
            $expected[] = sprintf('N%03d', $i);
        }
        $this->assertSame($expected, $ids);
    }

    // 中文名落地(150 条扩充):N031~N150 逐条有名且**不重名**;N001~N030 暂留 NULL
    public function test_name_zh_is_filled_for_the_expansion_and_null_for_the_original_thirty(): void
    {
        $rows = DB::table('npc_definition')->orderBy('npc_id')->get()->keyBy('npc_id');

        $names = [];
        foreach ($rows as $npcId => $row) {
            $index = (int) substr($npcId, 1);

            if ($index <= 30) {
                // 拟名待项目负责人批准 → 服务端不编占位名,前端回落 name_key
                $this->assertNull($row->name_zh, "{$npcId} 的中文名尚未批准,不该有值");

                continue;
            }

            $this->assertNotNull($row->name_zh, "{$npcId} 缺中文名");
            $this->assertNotSame('', trim((string) $row->name_zh));
            $names[] = $row->name_zh;
        }

        $this->assertCount(120, $names);
        $this->assertSame(count($names), count(array_unique($names)), '扩充版中文名不得重名');
    }

    // 军事 NPC 的国防特性必须**全部**是可执行 spec:
    // W4-B 已经登记了 defense_score_flat / defense_score_pct 并接线到 DefenseService,
    // 再留在 unmapped_zh 就是「数据写了、运行时不生效」—— 本波次逐条提升的就是这 10 行
    public function test_military_defense_traits_are_all_executable_specs(): void
    {
        $expected = [
            'N036' => ['flat', 6.0],   'N053' => ['flat', 15.0],
            'N096' => ['flat', 7.0],   'N113' => ['flat', 14.0],
            'N071' => ['pct', 0.18],   'N083' => ['pct', 0.22],
            'N090' => ['pct', 0.30],   'N117' => ['pct', 0.15],
            'N143' => ['pct', 0.24],   'N150' => ['pct', 0.32],
        ];

        $rows = DB::table('npc_definition')->get()->keyBy('npc_id');

        foreach ($expected as $npcId => [$op, $value]) {
            $trait = json_decode($rows[$npcId]->trait_json, true);
            $target = $op === 'flat' ? ModifierTarget::DEFENSE_SCORE_FLAT : ModifierTarget::DEFENSE_SCORE_PCT;

            $spec = collect($trait['specs'])->firstWhere('target', $target);
            $this->assertNotNull($spec, "{$npcId} 的国防特性没有提升为 {$target}");
            $this->assertSame('city', $spec['scope']);
            $this->assertSame($op, $spec['op']);
            $this->assertEqualsWithDelta($value, (float) $spec['value'], 1e-9);
            $this->assertSame([], $trait['unmapped_zh'], "{$npcId} 提升后 unmapped_zh 应清空");
        }

        // 全表兜底:任何一行的 trait_desc_zh 里出现「国防」,都不该还挂在 unmapped_zh 里
        foreach ($rows as $npcId => $row) {
            $trait = json_decode($row->trait_json, true);
            foreach ($trait['unmapped_zh'] ?? [] as $text) {
                $this->assertStringNotContainsString('国防', $text, "{$npcId} 还有未提升的国防特性:{$text}");
            }
        }
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
        // 30 → 150 扩充 + name_zh 列 + EVT_BRAIN_DRAIN 复活 = 次版本位
        $this->assertContains('V3.6.0', $versions);
        // 插入顺序必须是版本号升序:current() 取的是 id 最大的一行
        $sorted = $versions;
        usort($sorted, 'version_compare');
        $this->assertSame($sorted, $versions, 'game_data_versions 的插入顺序必须是版本号升序');
    }
}
