<?php

namespace App\Game\Event;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

// 事件定义层读取入口(event_definition 表 = v3.2 §9.2 的 30 行)。
//
// 三条纪律(与 MarketDefinition / GameSetting 同源):
//   1. 全项目只有这里读 event_definition,业务代码不许再自己查表;
//   2. **请求级**缓存,不是进程级 —— 后台改完定义,下一个请求的触发判定必须立刻用新值
//      (任务硬约束:「事件后台改动必须即刻影响后续触发」)。用 Context 而不是 static:
//      它跟随请求生命周期,后台写入路径改完再 flush() 一次就彻底干净;
//   3. 缺表 / 缺行一律当作「没有这个事件」,绝不 fallback 出一条凭空的定义(Fail Closed)。
final class EventDefinition
{
    private const CACHE_KEY = 'event_definitions';

    // 整表(event_id => 定义数组)。表不存在 / 未 seed 时返回空数组
    public static function all(): array
    {
        if (Context::has(self::CACHE_KEY)) {
            return Context::get(self::CACHE_KEY);
        }

        $rows = [];
        foreach (DB::table('event_definition')->orderBy('event_id')->get() as $row) {
            $rows[(string) $row->event_id] = self::toArray($row);
        }

        Context::add(self::CACHE_KEY, $rows);

        return $rows;
    }

    // 单个事件定义;未登记返回 null
    public static function find(string $eventId): ?array
    {
        return self::all()[$eventId] ?? null;
    }

    // 可参与触发掷点的定义(后台开关为开)。禁用的事件连候选池都进不去
    public static function enabled(): array
    {
        return array_filter(self::all(), fn ($d) => $d['enabled']);
    }

    // 清空请求级缓存(后台改完定义、测试里直接改库之后调用)
    public static function flush(): void
    {
        Context::forget(self::CACHE_KEY);
    }

    // 行 → 数组。JSON 三列在这里一次性解开,业务侧不再各自 json_decode
    private static function toArray(object $row): array
    {
        return [
            'event_id'         => (string) $row->event_id,
            'name_zh'          => (string) $row->name_zh,
            'name_key'         => (string) $row->name_key,
            'category'         => (string) $row->category,
            'event_type'       => (string) $row->event_type,
            'min_era'          => (string) $row->min_era,
            'base_weight'      => (float) $row->base_weight,
            'cooldown_minutes' => (int) $row->cooldown_minutes,
            'duration_minutes' => (int) $row->duration_minutes,
            'enabled'          => (bool) $row->enabled,
            'disabled_reason'  => $row->disabled_reason === null ? null : (string) $row->disabled_reason,
            // 效果强度倍率(后台可调):所有效果的**数值**统一乘它。
            // 做成一个标量而不是让后台直接编辑 JSON:手写 JSON 迟早写出一条 target 不存在的死配置,
            // 而那种配置在运行时只会「静默不生效」——比 seed 失败难查一万倍
            'effect_multiplier' => (float) $row->effect_multiplier,
            'condition_desc_zh' => (string) $row->condition_desc_zh,
            'auto_effect_desc_zh' => (string) $row->auto_effect_desc_zh,
            // 三个选项的 §9.2 原文:结构化 DSL 是「机器执行的那一份」,原文是「人看的那一份」,
            // 玩家面板显示的正是它们(label_zh 只是短标签)
            'option_a_desc_zh' => $row->option_a_desc_zh === null ? null : (string) $row->option_a_desc_zh,
            'option_b_desc_zh' => $row->option_b_desc_zh === null ? null : (string) $row->option_b_desc_zh,
            'option_c_desc_zh' => $row->option_c_desc_zh === null ? null : (string) $row->option_c_desc_zh,
            'condition_json'   => self::decode($row->condition_json),
            'auto_effect_json' => self::decode($row->auto_effect_json),
            'options_json'     => self::decode($row->options_json),
        ];
    }

    // 解析 JSON 列。脏值一律退化成空结构(Fail Safe:一条脏定义不该让整座城市的结算炸掉;
    // 入库前 Seeder 已经逐条守门过,这里是运行时的第二道)
    private static function decode(?string $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
