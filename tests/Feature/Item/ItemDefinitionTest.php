<?php

namespace Tests\Feature\Item;

use App\Game\Definition\GameDataVersion;
use App\Game\Item\ItemCode;
use App\Game\Item\ItemDefinition;
use App\Game\Modifier\ModifierSpec;
use App\Game\Modifier\ModifierTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 定义层守门(v3.2 §7 的 24 行 + backlog §9 B 区):数据逐行对得上规格,而不是「跑得起来就算数」。
// M2 的 upgrade_to 断链教训:静默兜底的数据错误可以活很久,只有断言才抓得住。
class ItemDefinitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_seeds_exactly_the_24_rows_of_section_7(): void
    {
        $this->assertSame(24, DB::table('item_definition')->count(), '§7 是 24 行,多一行少一行都要先回文档核对');

        // item_id 必须是 IT001~IT024 的完整连续集合(缺号 = 抄漏了某一行)
        $ids = DB::table('item_definition')->orderBy('item_id')->pluck('item_id')->all();
        $expected = array_map(fn ($i) => sprintf('IT%03d', $i), range(1, 24));
        $this->assertSame($expected, $ids);
    }

    // 逐行抽查 §7 原文的关键数值(挑四档代表:最早 / 中期 / 工业 / 终局)
    public function test_key_rows_match_section_7_values(): void
    {
        $rows = DB::table('item_definition')->get()->keyBy('item_id');

        $cases = [
            // item_id => [category, min_era, durability, effect_code, effect_value, unit, trade_value]
            'IT001' => ['gathering_tool', 'I', 60, 'wood_output_pct', 8.0, 'percent', 8.0],
            'IT012' => ['medical_item', 'V', 20, 'disease_recovery_pct', 15.0, 'percent', 45.0],
            'IT017' => ['industrial_tool', 'VIII', 260, 'industry_output_pct', 18.0, 'percent', 220.0],
            'IT024' => ['engineering_tool', 'X', 450, 'megaproject_speed_pct', 25.0, 'percent', 1500.0],
        ];

        foreach ($cases as $itemId => [$category, $era, $durability, $code, $value, $unit, $trade]) {
            $row = $rows[$itemId];
            $this->assertSame($category, $row->category, $itemId . ' category');
            $this->assertSame($era, $row->min_era, $itemId . ' min_era');
            $this->assertSame($durability, (int) $row->durability, $itemId . ' durability');
            $this->assertSame($code, $row->effect_code, $itemId . ' effect_code');
            $this->assertEqualsWithDelta($value, (float) $row->effect_value, 0.0001, $itemId . ' effect_value');
            $this->assertSame($unit, $row->unit, $itemId . ' unit');
            $this->assertEqualsWithDelta($trade, (float) $row->trade_value, 0.0001, $itemId . ' trade_value');
        }
    }

    // §7 的成本矩阵抽查(八列里非零的那些)
    public function test_craft_costs_match_section_7(): void
    {
        $definitions = ItemDefinition::all();

        $this->assertSame(['wood' => 4.0, 'stone' => 2.0, 'money' => 2.0], $definitions['IT001']['craft_cost']);
        $this->assertSame(
            ['wood' => 4.0, 'stone' => 2.0, 'copper' => 2.0, 'bronze' => 8.0, 'money' => 15.0],
            $definitions['IT006']['craft_cost']
        );
        $this->assertSame(
            ['steel' => 40.0, 'electronic_components' => 40.0, 'money' => 1000.0],
            $definitions['IT024']['craft_cost']
        );

        // 24 件全部有成本:空成本 = 免费无限制作
        foreach ($definitions as $itemId => $def) {
            $this->assertNotSame([], $def['craft_cost'], $itemId . ' 没有制作成本');
        }
    }

    // B1 已批的耐久档划分:20 分钟档 = industrial_tool / engineering_tool / logistics_tool /
    // planning_tool / research_tool(min_era ≥ IX);其余 10 分钟档
    public function test_durability_tiers_follow_b1(): void
    {
        $industrialCategories = ['industrial_tool', 'engineering_tool', 'logistics_tool', 'planning_tool', 'research_tool'];

        foreach (ItemDefinition::all() as $itemId => $def) {
            $expected = in_array($def['category'], $industrialCategories, true)
                ? ItemCode::TIER_INDUSTRIAL
                : ItemCode::TIER_NORMAL;

            $this->assertSame($expected, $def['durability_tier'], $itemId . ' 的耐久档位与 B1 不符');
        }

        // §7 明文的两个档口径:普通 10 分钟 / 工业 20 分钟(默认值,后台可调)
        $this->assertSame(10.0, ItemCode::minutesPerDurabilityPoint(ItemCode::TIER_NORMAL));
        $this->assertSame(20.0, ItemCode::minutesPerDurabilityPoint(ItemCode::TIER_INDUSTRIAL));

        // B1 的第三条:medical_item 是一次性消耗品(按使用次数,不随时间递减)
        foreach (['IT012', 'IT020'] as $itemId) {
            $this->assertSame(ItemCode::DURABILITY_MODE_USES, ItemDefinition::find($itemId)['durability_mode']);
        }
    }

    // B3 的 effect_code → ModifierTarget 映射:每条 spec 的 target 必须是已登记的,
    // 且**产量类进 tool 乘区、非产量类进各自消费点**(绝不混)
    public function test_effect_specs_only_use_registered_targets(): void
    {
        $productionCodes = ['wood_output_pct', 'hunting_output_pct', 'agriculture_output_pct',
            'milling_efficiency_pct', 'mining_output_pct', 'bronze_output_pct',
            'iron_processing_efficiency_pct', 'knowledge_output_pct', 'industry_output_pct'];

        foreach (ItemDefinition::all() as $itemId => $def) {
            foreach ($def['specs'] as $spec) {
                $this->assertInstanceOf(ModifierSpec::class, $spec);
                $this->assertContains($spec->target, ModifierTarget::all(), $itemId . ' 用了未登记的 target');

                if (in_array($def['effect_code'], $productionCodes, true)) {
                    $this->assertSame(ModifierTarget::SLOT_TOOL, $spec->target, $itemId . ' 产量类效果必须进 tool 乘区');
                } else {
                    $this->assertNotSame(ModifierTarget::SLOT_TOOL, $spec->target, $itemId . ' 非产量类效果不许进 tool 乘区');
                    $this->assertArrayHasKey($spec->target, ModifierTarget::CONSUMPTION_POINTS, $itemId . ' 必须落在已登记消费点');
                }
            }
        }
    }

    // 逐条对照 B3 的落地结果(这是「映射有没有抄错」最硬的一张表)
    public function test_effect_mapping_table(): void
    {
        $definitions = ItemDefinition::all();

        // 产量类:target = tool
        $this->assertSpec($definitions['IT001'], ModifierTarget::SLOT_TOOL, ModifierSpec::SCOPE_RESOURCE, 'wood', 0.08);
        $this->assertSpec($definitions['IT002'], ModifierTarget::SLOT_TOOL, ModifierSpec::SCOPE_RESOURCE, 'berries', 0.08);
        $this->assertSpec($definitions['IT003'], ModifierTarget::SLOT_TOOL, ModifierSpec::SCOPE_BUILDING_CATEGORY, 'food_production', 0.10);
        $this->assertSpec($definitions['IT004'], ModifierTarget::SLOT_TOOL, ModifierSpec::SCOPE_RESOURCE, 'flour', 0.10);
        $this->assertSpec($definitions['IT007'], ModifierTarget::SLOT_TOOL, ModifierSpec::SCOPE_RESOURCE, 'bronze', 0.12);
        $this->assertSpec($definitions['IT010'], ModifierTarget::SLOT_TOOL, ModifierSpec::SCOPE_RESOURCE, 'iron_tools', 0.15);
        $this->assertSpec($definitions['IT017'], ModifierTarget::SLOT_TOOL, ModifierSpec::SCOPE_BUILDING_CATEGORY, 'processing', 0.18);
        $this->assertSpec($definitions['IT023'], ModifierTarget::SLOT_TOOL, ModifierSpec::SCOPE_RESOURCE, 'knowledge', 0.35);

        // 矿业工具一条 effect_code 展开成 6 条资源 spec(§6.1 没有「矿石」这个统称的资源)
        $miningKeys = array_map(fn ($s) => $s->scopeKey, $definitions['IT009']['specs']);
        sort($miningKeys);
        $this->assertSame(['coal', 'copper', 'iron', 'oil', 'rare_metals', 'tin'], $miningKeys);

        // 非产量类:落在已登记消费点,不进乘区
        $this->assertSpec($definitions['IT005'], ModifierTarget::CONSTRUCTION_SPEED_PCT, ModifierSpec::SCOPE_CITY, null, 0.08);
        $this->assertSpec($definitions['IT022'], ModifierTarget::GOVERNANCE_CAPACITY_PCT, ModifierSpec::SCOPE_CITY, null, 0.10);
        // 减免类必须是**负值**:维护成本 -8%,写成 +0.08 就成了「装上工具维护更贵」
        $this->assertSpec($definitions['IT016'], ModifierTarget::MAINTENANCE_COST_PCT, ModifierSpec::SCOPE_CITY, null, -0.08);
    }

    // 映射不到已登记 target 的效果:specs 为空 + unmapped_zh 有原文(不发明 target,也不静默丢弃)
    public function test_unmapped_effects_are_recorded_not_invented(): void
    {
        $unmappedItems = ['IT008', 'IT012', 'IT015', 'IT018', 'IT019', 'IT020', 'IT024'];

        foreach ($unmappedItems as $itemId) {
            $def = ItemDefinition::find($itemId);
            $this->assertSame([], $def['specs'], $itemId . ' 不该有 spec —— 它的效果没有可挂的 target');
            $this->assertNotSame([], $def['unmapped_zh'], $itemId . ' 必须在 unmapped_zh 里留下原文');
        }

        // 反过来:有 spec 的那些不该被误标成 unmapped
        $this->assertSame([], ItemDefinition::find('IT001')['unmapped_zh']);
    }

    // §7 的 crafting_source 映射:能精确对上 94 栋建筑的填 crafting_building_id,
    // 对不上的原样进 crafting_unmapped_zh(**不发明映射**),手工制作两列皆空
    public function test_crafting_source_mapping(): void
    {
        $definitions = ItemDefinition::all();

        // 精确对上的
        $this->assertSame('P03', $definitions['IT006']['crafting_building_id']);
        $this->assertSame('P05', $definitions['IT009']['crafting_building_id']);
        $this->assertSame('M01', $definitions['IT012']['crafting_building_id']);
        $this->assertSame('K03', $definitions['IT014']['crafting_building_id']);
        $this->assertSame('C03', $definitions['IT015']['crafting_building_id']);
        $this->assertSame('P08', $definitions['IT017']['crafting_building_id']);
        $this->assertSame('M02', $definitions['IT020']['crafting_building_id']);
        $this->assertSame('K04', $definitions['IT021']['crafting_building_id']);
        $this->assertSame('K05', $definitions['IT023']['crafting_building_id']);
        $this->assertSame('P11', $definitions['IT024']['crafting_building_id']);

        // 手工制作:两列皆空 = §7 明文的「无需建筑」
        foreach (['IT001', 'IT002'] as $itemId) {
            $this->assertNull($definitions[$itemId]['crafting_building_id']);
            $this->assertNull($definitions[$itemId]['crafting_unmapped_zh']);
            $this->assertSame('手工制作', $definitions[$itemId]['crafting_source_desc_zh']);
        }

        // 94 栋里不存在的来源:building_id 留空,原文进 unmapped(等建筑补齐或用户裁决)
        $unmapped = [];
        foreach ($definitions as $itemId => $def) {
            if ($def['crafting_unmapped_zh'] !== null) {
                $unmapped[$itemId] = $def['crafting_unmapped_zh'];
                $this->assertNull($def['crafting_building_id'], $itemId . ' 既然未映射就不该同时填 building_id');
            }
        }

        $this->assertSame([
            'IT003' => '木工作坊',
            'IT004' => '石工作坊',
            'IT005' => '木工作坊',
            'IT013' => '工坊',
            'IT016' => '研究院',
            'IT019' => '现代工厂',
        ], $unmapped);
    }

    // 已映射的 crafting_building_id 必须真的指向 94 栋之一(外键之外再断一次)
    public function test_every_mapped_crafting_building_exists(): void
    {
        $missing = DB::table('item_definition as i')
            ->whereNotNull('i.crafting_building_id')
            ->leftJoin('building_definition as b', 'i.crafting_building_id', '=', 'b.building_id')
            ->whereNull('b.building_id')
            ->pluck('i.item_id')->all();

        $this->assertSame([], $missing);
    }

    // item_definition 必须参与数值版本指纹:改一行效果值 = 全服产量变了,
    // 半年后要能回答「当时用的是哪一版数值」(§64 / §65)
    public function test_item_definition_participates_in_checksum(): void
    {
        $before = GameDataVersion::checksum();

        DB::table('item_definition')->where('item_id', 'IT001')->update(['effect_value' => 99.0]);

        $this->assertNotSame($before, GameDataVersion::checksum(), 'item_definition 不在 checksum 清单里,改数值就查无实据');
    }

    // 工具定义落地必须留下版本号 V3.4.0(全新库由 Seeder 写、已有库由 600005 迁移递增)
    public function test_item_data_version_is_recorded(): void
    {
        $this->assertTrue(
            DB::table('game_data_versions')->where('version', 'V3.4.0')->exists(),
            '工具定义层落地必须留下 V3.4.0 版本号'
        );

        $current = ltrim((string) GameDataVersion::current(), 'V');
        $this->assertTrue(version_compare($current, '3.4.0', '>='), '当前数值版本不该早于工具定义落地的版本');
    }

    private function assertSpec(array $def, string $target, string $scope, ?string $scopeKey, float $value): void
    {
        $matched = array_values(array_filter(
            $def['specs'],
            fn (ModifierSpec $s) => $s->target === $target && $s->scope === $scope && $s->scopeKey === $scopeKey
        ));

        $this->assertNotEmpty($matched, $def['item_id'] . " 缺少 {$target}/{$scope}/{$scopeKey} 的 spec");
        $this->assertEqualsWithDelta($value, $matched[0]->value, 0.0001, $def['item_id'] . ' spec 值不符');
    }
}
