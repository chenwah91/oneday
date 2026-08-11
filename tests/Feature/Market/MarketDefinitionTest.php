<?php

namespace Tests\Feature\Market;

use App\Game\Definition\GameDataVersion;
use App\Game\Market\MarketDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 定义层:market_definition 必须逐行等于 v3.2 §8,base_liquidity 必须等于 9.C1 的模型值。
// 这组用例是「数值有没有抄错」的唯一防线 —— 抄错一行,全服价格就错一辈子。
class MarketDefinitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    // §8 原文是 26 行;RS027 水泥 / RS028 药品由资源来源映射草案 §7 追加(V3.4.0 上市),
    // 所以定稿后的行集是 28 行。改这个数之前一定要先回文档核对
    public function test_seeds_exactly_the_28_rows_of_section_8_plus_rs027_rs028(): void
    {
        $this->assertSame(28, DB::table('market_definition')->count(), '§8 的 26 行 + 草案 §7 的 2 行,多一行少一行都要先回文档核对');

        // rs_code 必须是 RS001~RS028 的完整连续集合(缺号 = 抄漏了某一行)
        $codes = DB::table('market_definition')->orderBy('rs_code')->pluck('rs_code')->all();
        $expected = array_map(fn ($i) => sprintf('RS%03d', $i), range(1, 28));
        $this->assertSame($expected, $codes);
    }

    // RS027 / RS028:资源来源映射草案 §7 的建议价 + 推荐的收敛选项①(药品 8.0 而不是 20.0)。
    // 定位是 §16.1 的③「补充缺口」—— 两者都已有产线(水泥 ← P06、药品 ← M01),
    // 市场只是让产能不匹配的城市能买到缺口,不是唯一来源
    public function test_cement_and_medicine_are_listed_with_draft_values(): void
    {
        $rows = DB::table('market_definition')->get()->keyBy('resource_id');

        $cases = [
            // resource_id => [rs_code, category, first_era, base, min, max, volatility, elasticity, fee, liquidity]
            'cement'   => ['RS027', 'construction_material', 'VII', 14.0, 7.7, 33.6, 0.05, 0.55, 0.03, 2143],
            'medicine' => ['RS028', 'processed_food', 'V', 8.0, 4.4, 19.2, 0.05, 0.55, 0.03, 3750],
        ];

        foreach ($cases as $resourceId => [$rsCode, $category, $era, $base, $min, $max, $vol, $elasticity, $fee, $liquidity]) {
            $row = $rows[$resourceId];
            $this->assertSame($rsCode, $row->rs_code, $resourceId);
            $this->assertSame($category, $row->market_category, $resourceId . ' market_category');
            $this->assertSame($era, $row->first_era, $resourceId . ' first_era');
            $this->assertEqualsWithDelta($base, (float) $row->base_price, 0.0001, $resourceId . ' base_price');
            $this->assertEqualsWithDelta($min, (float) $row->min_price, 0.0001, $resourceId . ' min_price');
            $this->assertEqualsWithDelta($max, (float) $row->max_price, 0.0001, $resourceId . ' max_price');
            $this->assertEqualsWithDelta($vol, (float) $row->volatility, 0.0001, $resourceId . ' volatility');
            $this->assertEqualsWithDelta($elasticity, (float) $row->elasticity, 0.0001, $resourceId . ' elasticity');
            $this->assertEqualsWithDelta($fee, (float) $row->fee_rate, 0.0001, $resourceId . ' fee_rate');
            // 9.C1 模型:round(20000 / base_price × 时代系数),IV~VII 系数 1.5
            $this->assertEqualsWithDelta($liquidity, (float) $row->base_liquidity, 0.0001, $resourceId . ' base_liquidity');

            $this->assertTrue(MarketDefinition::isTradeable(MarketDefinition::find($resourceId)), $resourceId . ' 必须可交易');
        }
    }

    // 逐行抽查 §8 原文的关键数值(挑的是四个档位的代表:最便宜 / 中位 / 电子 / 终局)
    public function test_key_rows_match_section_8_values(): void
    {
        $rows = DB::table('market_definition')->get()->keyBy('resource_id');

        $cases = [
            // resource_id => [rs_code, base, min, max, volatility, elasticity, fee_rate]
            'food'                  => ['RS001', 2.0, 0.9, 6.4, 0.04, 0.75, 0.03],
            'iron'                  => ['RS012', 22.0, 9.9, 70.4, 0.07, 0.75, 0.03],
            'electronic_components' => ['RS022', 190.0, 85.5, 608.0, 0.1, 0.75, 0.03],
            'advanced_materials'    => ['RS026', 980.0, 441.0, 3136.0, 0.12, 0.75, 0.03],
        ];

        foreach ($cases as $resourceId => [$rsCode, $base, $min, $max, $vol, $elasticity, $fee]) {
            $row = $rows[$resourceId];
            $this->assertSame($rsCode, $row->rs_code, $resourceId);
            $this->assertEqualsWithDelta($base, (float) $row->base_price, 0.0001, $resourceId . ' base_price');
            $this->assertEqualsWithDelta($min, (float) $row->min_price, 0.0001, $resourceId . ' min_price');
            $this->assertEqualsWithDelta($max, (float) $row->max_price, 0.0001, $resourceId . ' max_price');
            $this->assertEqualsWithDelta($vol, (float) $row->volatility, 0.0001, $resourceId . ' volatility');
            $this->assertEqualsWithDelta($elasticity, (float) $row->elasticity, 0.0001, $resourceId . ' elasticity');
            $this->assertEqualsWithDelta($fee, (float) $row->fee_rate, 0.0001, $resourceId . ' fee_rate');
        }
    }

    // trade_mode 的三分:§8 明文「knowledge and money are non_tradeable;electricity uses capacity-contract」
    public function test_trade_modes_follow_section_8(): void
    {
        $modes = DB::table('market_definition')->pluck('trade_mode', 'resource_id');

        $this->assertSame(MarketDefinition::TRADE_MODE_NON_TRADEABLE, $modes['knowledge']);
        $this->assertSame(MarketDefinition::TRADE_MODE_NON_TRADEABLE, $modes['money']);
        $this->assertSame(MarketDefinition::TRADE_MODE_CAPACITY_CONTRACT, $modes['electricity']);

        // 其余 25 行全部是现货(§8 的 23 行 + RS027 水泥 / RS028 药品)
        $spot = $modes->filter(fn ($m) => $m === MarketDefinition::TRADE_MODE_SPOT);
        $this->assertCount(25, $spot);
    }

    // B1 裁决③ + M.2 残留①:电子元件全服 0 产出,市场是时代 X 唯一来源。
    // 它一旦被标成不可交易,时代 X 的 35 栋建筑就永远建不起来 —— 单独立一条用例守着
    public function test_electronic_components_must_be_tradeable(): void
    {
        $def = MarketDefinition::find('electronic_components');

        $this->assertNotNull($def);
        $this->assertTrue(MarketDefinition::isTradeable($def), '电子元件必须可交易,否则时代 X 全锁死');
    }

    // 9.C1:base_liquidity = round(20000 / base_price × 时代系数),I~III ×1.0 / IV~VII ×1.5 / VIII~X ×2.0。
    // 三个值直接取自 C1 表格里的三个示例,是「公式抄对了没有」最硬的对照
    public function test_base_liquidity_matches_c1_model(): void
    {
        $liquidity = DB::table('market_definition')->pluck('base_liquidity', 'resource_id');

        $this->assertEqualsWithDelta(10000, (float) $liquidity['food'], 0.0001, 'C1 示例:food(2.0) → 10000');
        $this->assertEqualsWithDelta(1364, (float) $liquidity['iron'], 0.0001, 'C1 示例:iron(22) → 约 1364');
        $this->assertEqualsWithDelta(41, (float) $liquidity['advanced_materials'], 0.0001, 'C1 示例:advanced_materials(980) → 约 41');

        // 不可交易的两行没有流动性可言(base_price 为 0 时公式本身也会除零)
        $this->assertEqualsWithDelta(0, (float) $liquidity['knowledge'], 0.0001);
        $this->assertEqualsWithDelta(0, (float) $liquidity['money'], 0.0001);
    }

    // 每一行的 resource_id 都必须在 resource_definition 里(外键之外再断一次:
    // 外键只保证「存在」,这条保证的是「26 行覆盖的正是 §8 点名的那 26 种」)
    public function test_every_row_points_at_a_real_resource(): void
    {
        $missing = DB::table('market_definition as m')
            ->leftJoin('resource_definition as r', 'm.resource_id', '=', 'r.resource_id')
            ->whereNull('r.resource_id')
            ->pluck('m.resource_id')->all();

        $this->assertSame([], $missing);
    }

    // market_definition 必须参与数值版本指纹:改一行基础价 = 全服价格变了,
    // 半年后要能回答「当时用的是哪一版数值」(§64 / §65)
    public function test_market_definition_participates_in_checksum(): void
    {
        $before = GameDataVersion::checksum();

        DB::table('market_definition')->where('resource_id', 'iron')->update(['base_price' => 999.0]);

        $this->assertNotSame($before, GameDataVersion::checksum(), 'market_definition 不在 checksum 清单里,改价就查无实据');
    }

    // 市场定义落地必须留下版本号 V3.3.1(全新库由 Seeder 写、已有库由 500003 迁移递增)。
    // 只断言「存在且当前版本不早于它」,不断言 current() 恰好等于 V3.3.1 ——
    // 同波次的 NPC(V3.3.0)与后续系统还会继续 bump,写死等号会让别人的正常交付变成本用例的失败
    public function test_market_data_version_is_recorded(): void
    {
        $this->assertTrue(
            DB::table('game_data_versions')->where('version', 'V3.3.1')->exists(),
            '市场定义层落地必须留下 V3.3.1 版本号'
        );

        $current = ltrim((string) GameDataVersion::current(), 'V');
        $this->assertTrue(version_compare($current, '3.3.1', '>='), '当前数值版本不该早于市场定义落地的版本');
    }

    // 版本行必须按版本号**升序**插入。
    // GameDataVersion::current() 取的是 id 最大的一行 —— 顺序一乱,「当前数值版本」就会回退到旧版本号,
    // 审计里每条记录挂的版本也跟着错。并行落地两个系统(NPC V3.3.0 / 市场 V3.3.1)时最容易踩
    public function test_data_versions_are_inserted_in_ascending_order(): void
    {
        $versions = DB::table('game_data_versions')->orderBy('id')->pluck('version')->all();

        $sorted = $versions;
        usort($sorted, 'version_compare');

        $this->assertSame($sorted, $versions, 'game_data_versions 的插入顺序必须是版本号升序');
    }
}
