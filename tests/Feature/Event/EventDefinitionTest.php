<?php

namespace Tests\Feature\Event;

use App\Game\Definition\GameDataVersion;
use App\Game\Event\EventCode;
use App\Game\Event\EventDefinition;
use App\Game\Modifier\ModifierSpec;
use App\Support\GameSetting;
use Database\Seeders\EventDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

// 事件定义层(v3.2 §9.2 的 30 行)+ DSL 守门 + 数值版本。
//
// 这一份守的是「数据本身」:30 条一条不少、正负分类正确、
// 结构化 DSL 的每一个 metric / kind / target 都在 allowlist 里、
// 以及 §13 帽修正方向的硬约束(正向事件不许占乘区)。
class EventDefinitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ---- 逐行对照 §9.2 ----

    public function test_thirty_events_are_seeded(): void
    {
        $this->assertSame(30, DB::table('event_definition')->count(), 'v3.2 §9.2 是 30 行,一条都不能少');
    }

    // §9.2 的 event_id / category / min_era / 四个数值列逐行抄:抽查首尾与几条关键行
    public function test_seed_matches_v32_table(): void
    {
        $rows = DB::table('event_definition')->pluck('base_weight', 'event_id')->all();

        $this->assertEqualsWithDelta(12, (float) $rows['EVT_HARVEST'], 0.0001);
        $this->assertEqualsWithDelta(3, (float) $rows['EVT_GLOBAL_CRISIS'], 0.0001);

        $drought = DB::table('event_definition')->where('event_id', 'EVT_DROUGHT')->first();
        $this->assertSame('disaster', $drought->category);
        $this->assertSame('II', $drought->min_era);
        $this->assertSame(45, (int) $drought->cooldown_minutes);
        $this->assertSame(20, (int) $drought->duration_minutes);
        $this->assertSame('农业建筑≥2', $drought->condition_desc_zh);
        $this->assertSame('农业产量-35%', $drought->auto_effect_desc_zh);
    }

    // 中文名称保留显示 + 英文 code 做主键(用户要求的口径)
    public function test_event_id_is_english_code_and_name_is_chinese(): void
    {
        foreach (DB::table('event_definition')->get() as $row) {
            $this->assertMatchesRegularExpression('/^EVT_[A-Z_]+$/', $row->event_id);
            $this->assertNotSame('', trim($row->name_zh));
        }
    }

    // ---- 三栏落地对照:specs / unmapped / disabled ----

    public function test_disabled_events_all_carry_a_reason(): void
    {
        $disabled = DB::table('event_definition')->where('enabled', false)->get();

        $this->assertGreaterThan(0, $disabled->count());
        foreach ($disabled as $row) {
            $this->assertNotNull($row->disabled_reason, "{$row->event_id} 停用了却没写原因");
            $this->assertNotSame('', trim($row->disabled_reason));
        }

        foreach (DB::table('event_definition')->where('enabled', true)->get() as $row) {
            $this->assertNull($row->disabled_reason, "{$row->event_id} 是启用状态却挂着停用原因");
        }
    }

    // 启用的事件必须真的有能执行的效果 —— 否则触发了也什么都不会发生(那正是我们要避免的「静默无效」)
    public function test_enabled_events_have_executable_auto_effects_or_options(): void
    {
        foreach (EventDefinition::enabled() as $eventId => $definition) {
            $count = count($definition['auto_effect_json']['effects'] ?? []);
            foreach ($definition['options_json'] ?? [] as $option) {
                $count += $option === null ? 0 : count($option['effects'] ?? []);
            }

            $this->assertGreaterThan(0, $count, "{$eventId} 是启用状态,但没有任何可执行的效果");
        }
    }

    // §13 帽修正方向(用户 2026-08-10 拍板③ + backlog §11.1 方向④):
    // 正向事件一律「直接发资源」,绝不出现在 event 乘区里
    public function test_positive_events_never_use_the_event_multiplier(): void
    {
        foreach (EventDefinition::all() as $eventId => $definition) {
            $blocks = [$definition['auto_effect_json'] ?? []];
            foreach ($definition['options_json'] ?? [] as $option) {
                if ($option !== null) {
                    $blocks[] = $option;
                }
            }

            foreach ($blocks as $block) {
                foreach ($block['effects'] ?? [] as $effect) {
                    if (($effect['kind'] ?? '') === EventCode::EFFECT_MODIFIER && ($effect['target'] ?? '') === 'event') {
                        $this->assertLessThanOrEqual(0, (float) $effect['value'], "{$eventId} 往 event 乘区放了加成");
                        $this->assertSame(EventCode::TYPE_NEGATIVE, $definition['event_type'], "{$eventId} 是正向事件却用了 event 乘区");
                    }
                    if (($effect['kind'] ?? '') === EventCode::EFFECT_GRANT_PRODUCTION_PCT) {
                        $this->assertSame(EventCode::TYPE_POSITIVE, $definition['event_type'], "{$eventId} 是负向事件却直接发资源");
                    }
                }
            }
        }
    }

    // 每一条 DSL 的 metric / kind 都在 allowlist 里,modifier 都能构造成合法 ModifierSpec
    public function test_dsl_is_fully_within_the_allowlist(): void
    {
        foreach (EventDefinition::all() as $eventId => $definition) {
            foreach ($definition['condition_json']['all'] ?? [] as $condition) {
                $this->assertContains($condition['metric'], EventCode::CONDITION_METRICS, "{$eventId} 的 metric 未登记");
                $this->assertContains($condition['op'], EventCode::OPS);
            }

            foreach ($definition['auto_effect_json']['effects'] ?? [] as $effect) {
                $this->assertContains($effect['kind'], EventCode::EFFECT_KINDS, "{$eventId} 的 kind 未登记");
                $this->assertNotContains($effect['kind'], EventCode::OPTION_ONLY_KINDS, "{$eventId} 的自动效果用了选项专用 kind");

                if ($effect['kind'] === EventCode::EFFECT_MODIFIER) {
                    $spec = new ModifierSpec(
                        $effect['target'],
                        $effect['scope'],
                        ModifierSpec::OP_PCT,
                        (float) $effect['value'],
                        $effect['scope'] === ModifierSpec::SCOPE_CITY ? null : ($effect['scope_key'] ?? 'x')
                    );
                    $this->assertSame($effect['target'], $spec->target);
                }
            }
        }
    }

    // unmapped_zh 是「原样保留」的清单:承接不了的文案必须留在这里,不许静默丢弃。
    // 30 条里确实存在 unmapped(否则说明有人偷偷把它们删了)
    public function test_unmapped_texts_are_preserved(): void
    {
        $total = 0;
        foreach (EventDefinition::all() as $definition) {
            $total += count($definition['auto_effect_json']['unmapped_zh'] ?? []);
            $total += count($definition['condition_json']['unmapped_zh'] ?? []);
            foreach ($definition['options_json'] ?? [] as $option) {
                $total += $option === null ? 0 : count($option['unmapped_zh'] ?? []);
            }
        }

        $this->assertGreaterThan(20, $total, 'unmapped 清单不该是空的:承接不了的文案必须原样保留');
    }

    // ---- Seeder 守门(假失败:把 metric 改坏,seed 必须炸)----

    public function test_seeder_rejects_unknown_condition_metric(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/未在 EventCode::CONDITION_METRICS 登记/');

        $this->seedWithMutation(function (array &$event) {
            $event['condition_json']['all'][0]['metric'] = 'threat_level';
        });
    }

    public function test_seeder_rejects_positive_event_using_event_multiplier(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/正向效果一律走 grant_production_pct/');

        $this->seedWithMutation(function (array &$event) {
            $event['auto_effect_json']['effects'] = [
                ['kind' => 'modifier', 'target' => 'event', 'scope' => 'city', 'value' => 0.2],
            ];
        }, 'EVT_HARVEST');
    }

    // 把 events.json 读出来改一行,再走一遍 Seeder 的守门逻辑(不落库)
    private function seedWithMutation(callable $mutate, string $eventId = 'EVT_HARVEST'): void
    {
        $data = json_decode(file_get_contents(database_path('data/events.json')), true);

        foreach ($data['events'] as $index => $event) {
            if ($event['event_id'] === $eventId) {
                $mutate($event);
                $data['events'][$index] = $event;
            }
        }

        $tmp = database_path('data/events.json');
        $backup = file_get_contents($tmp);
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        try {
            EventDefinitionSeeder::rows();
        } finally {
            file_put_contents($tmp, $backup);
        }
    }

    // ---- 数值版本 ----

    public function test_event_definition_is_in_the_checksum_and_version_is_bumped(): void
    {
        $this->assertNotNull(
            DB::table('game_data_versions')->where('version', 'V3.4.1')->first(),
            'M3-D4 定义层落地必须留下 V3.4.1'
        );

        $before = GameDataVersion::checksum();
        DB::table('event_definition')->where('event_id', 'EVT_DROUGHT')->update(['base_weight' => 99]);

        $this->assertNotSame($before, GameDataVersion::checksum(), 'event_definition 必须进 checksum:改一行权重指纹就该变');
    }

    // ---- 全局参数(9.D 区批准默认值)----

    public function test_event_settings_are_registered_with_approved_defaults(): void
    {
        $this->assertSame(60, GameSetting::get(GameSetting::EVENT_WINDOW_SECONDS));
        $this->assertSame(0.08, GameSetting::get(GameSetting::EVENT_TRIGGER_CHANCE));
        $this->assertSame(3, GameSetting::get(GameSetting::EVENT_MAX_ACTIVE));
        $this->assertSame(1, GameSetting::get(GameSetting::EVENT_MAX_ACTIVE_DISASTER));
        $this->assertSame(3, GameSetting::get(GameSetting::EVENT_OFFLINE_MAX_TRIGGERS));
        $this->assertSame(1, GameSetting::get(GameSetting::EVENT_DIFFICULTY_MULTIPLIER));
        $this->assertSame(1.5, GameSetting::get(GameSetting::EVENT_WEIGHT_FOOD_DEFICIT));
        $this->assertSame(2, GameSetting::get(GameSetting::EVENT_WEIGHT_GOVERNANCE_OVERLOAD));
        $this->assertSame(0.7, GameSetting::get(GameSetting::EVENT_WEIGHT_HIGH_HAPPINESS));
        $this->assertTrue(GameSetting::get(GameSetting::EVENT_ENABLED));
    }

    // 迁移把 event_ 前缀的设定行都补进了 game_settings(后台设置页才有「最后修改时间」)
    public function test_event_settings_rows_exist_in_database(): void
    {
        $keys = array_filter(array_keys(GameSetting::DEFINITIONS), fn ($k) => str_starts_with($k, 'event_'));

        $this->assertGreaterThanOrEqual(20, count($keys));
        foreach ($keys as $key) {
            $this->assertTrue(
                DB::table('game_settings')->where('setting_key', $key)->exists(),
                "{$key} 没有落进 game_settings"
            );
        }
    }
}
