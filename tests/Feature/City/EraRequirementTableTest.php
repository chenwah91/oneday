<?php

namespace Tests\Feature\City;

use App\Game\City\EraService;
use App\Game\Defense\DefenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

// W11-B 任务3:时代升级门槛从 EraService::REQUIREMENTS 常量搬进 era_upgrade_requirement 表。
//
// 搬表这类改动最容易出的不是"报错",而是**悄悄搬错一格** —— 九档 × 八维 = 七十多个数字,
// 抄漏一个不会有任何异常,只会让某一档的门槛从此不对。所以第一条用例是**黄金样本**:
// 逐档逐维把表里的值与常量原值对齐,一格不差。
//
// 另外两条守的是搬表本身的两个风险:
//   ② 表空时必须 Fail Closed(抛异常),**绝不静默回退常量** —— 回退会造出两套真相:
//      后台改的数值全部不生效而页面一切正常,是最坏的失败方式;
//   ③ 国防威胁需求与升代门槛仍然**同源**:改表里的 defense,两处必须一起变。
//      当初把 defenseRequirement() 开成访问器就是为了这条,搬表不能把它搬散。
class EraRequirementTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // 常量原值(搬表的数据源)。用反射读私有常量 —— 数字只存在于 EraService 一处,测试里不再誊写一遍
    private function matrix(): array
    {
        return (new ReflectionClass(EraService::class))->getConstant('REQUIREMENTS');
    }

    // ---------- ① 黄金样本:搬前 = 搬后,逐档逐维 ----------

    public function test_table_matches_the_constant_cell_by_cell(): void
    {
        $matrix = $this->matrix();

        // 档数先对上:少一档 = 那一档的升级从此没有门槛(或直接升不上去)
        $this->assertSame(
            array_keys($matrix),
            DB::table('era_upgrade_requirement')->orderBy('era_order')->pluck('era_order')
                ->map(fn ($o) => (int) $o)->all(),
            '门槛表必须逐档覆盖常量矩阵的每一档'
        );

        foreach ($matrix as $eraOrder => $need) {
            $fromTable = EraService::requirementsFor((int) $eraOrder);
            $this->assertNotNull($fromTable, "升到时代 {$eraOrder} 的门槛在表里缺失");

            foreach (['population', 'knowledge', 'food', 'money', 'governance', 'happiness', 'defense'] as $dimension) {
                $this->assertSame(
                    (int) $need[$dimension],
                    (int) $fromTable[$dimension],
                    "升到时代 {$eraOrder} 的 {$dimension} 门槛与常量原值不一致"
                );
            }

            // 必须建筑清单同样逐条对齐(键与数量都要对上;空清单也要真的是空)
            $this->assertSame(
                $need['buildings'],
                $fromTable['buildings'],
                "升到时代 {$eraOrder} 的必须建筑清单与常量原值不一致"
            );
        }
    }

    // 最高时代没有下一档:requirementsFor 返回 null,evaluate 因此给出空清单(与搬表前同行为)
    public function test_top_era_has_no_next_requirement(): void
    {
        $maxOrder = (int) DB::table('era')->max('era_order');

        $this->assertNull(EraService::requirementsFor($maxOrder + 1));
        $this->assertSame([], EraService::evaluate(1, $maxOrder, []));
    }

    // ---------- ② 表空 → Fail Closed ----------

    public function test_empty_table_fails_closed_instead_of_falling_back_to_the_constant(): void
    {
        DB::table('era_upgrade_requirement')->delete();
        EraService::flushRequirements();

        $this->expectException(RuntimeException::class);
        // 回退到常量会"看起来正常"地跑下去 —— 那正是这条用例要禁止的
        EraService::requirementsFor(2);
    }

    public function test_empty_table_also_fails_closed_for_the_defense_requirement(): void
    {
        DB::table('era_upgrade_requirement')->delete();
        EraService::flushRequirements();

        $this->expectException(RuntimeException::class);
        EraService::defenseRequirement(3);
    }

    // ---------- ③ 威胁需求与升代门槛同源 ----------

    // 搬表后 §5.1 的九档「国防最低」仍然逐档等于威胁需求(与 DefenseThreatTest 是两份独立誊写)
    public function test_defense_requirement_still_reads_the_same_nine_bands(): void
    {
        $nine = [20, 60, 120, 250, 450, 800, 1500, 3000, 8000];

        foreach ($nine as $i => $expected) {
            $this->assertSame((float) $expected, EraService::defenseRequirement($i + 1));
        }

        // 最高时代 X 沿用最后一档;越界入参夹回时代 I(两条都是搬表前的既有口径)
        $this->assertSame(8000.0, EraService::defenseRequirement(10));
        $this->assertSame(20.0, EraService::defenseRequirement(0));
    }

    // 改表里的一格 defense → 升代门槛与威胁需求**同时**变。
    // 这一条是搬表最关键的验收:两处若各读各的,这里会有一处纹丝不动
    public function test_editing_defense_moves_both_the_era_gate_and_the_threat_demand(): void
    {
        // 时代 IV 的城市:威胁需求 = 升出时代 IV 所需的国防最低 = REQUIREMENTS[5]['defense'] = 250
        $this->assertSame(250.0, EraService::defenseRequirement(4));
        $this->assertSame(250, EraService::requirementsFor(5)['defense']);

        DB::table('era_upgrade_requirement')->where('era_order', 5)->update(['defense' => 999]);
        EraService::flushRequirements();

        // 两处一起动
        $this->assertSame(999.0, EraService::defenseRequirement(4), '威胁需求必须跟着门槛表走');
        $this->assertSame(999, EraService::requirementsFor(5)['defense']);
    }

    // 请求级缓存:同一请求内改了库但没 flush 时读到的仍是旧值(所以后台编辑器改完必须 flush)。
    // 这条把"为什么编辑器结尾要调 flushRequirements"钉死,免得以后有人当成多余的一行删掉
    public function test_requirements_are_cached_per_request_until_flushed(): void
    {
        $this->assertSame(50, EraService::requirementsFor(2)['population']);

        DB::table('era_upgrade_requirement')->where('era_order', 2)->update(['population' => 77]);
        $this->assertSame(50, EraService::requirementsFor(2)['population'], '未 flush 时应仍是缓存值');

        EraService::flushRequirements();
        $this->assertSame(77, EraService::requirementsFor(2)['population']);
    }

    // 门槛表改动会流进威胁等级的实际判定(不只是访问器层面的数字对上)
    public function test_threat_demand_in_defense_evaluate_follows_the_table(): void
    {
        DB::table('era_upgrade_requirement')->where('era_order', 6)->update(['defense' => 900]);
        EraService::flushRequirements();

        $city = (object) ['id' => 1, 'era_order' => 5];
        $defense = DefenseService::evaluate($city, ['defenseScore' => 0.0]);

        $this->assertSame(900.0, $defense['threat_demand_base']);
    }
}
