<?php

namespace App\Support;

use App\Game\Resource\ResourceCode;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

// 后台可配规则开关(game_settings 表的读写入口)。
//
// 与 Definition 数值的分工:
//   - building_level_definition 等 = 「游戏数值」,改动要 bump game_data_version(AdminDefinitionController);
//   - game_settings              = 「规则开关」,决定某条规则要不要生效,用于运营救急,不动数值版本。
//
// 三条纪律:
//   1. Allowlist(CLAUDE §45):只有 DEFINITIONS 里登记过的 key 才能读写,未知 key 一律拒绝,
//      不允许后台随手造一个 key 出来(否则代码里没人读它,只会变成误导运营的死配置);
//   2. 请求级缓存:SimulationService::applyLocked 在事务内被高频调用,每实例查一次库不可接受。
//      一次请求内整表只查一次,之后全部走 Context 缓存(用 Context 而非类内 static:
//      Context 跟随请求生命周期,测试里每个用例重建 Application 会自动清空,不会跨用例串味);
//   3. 缺行/缺表不改变游戏行为:get 永远能回退到 DEFINITIONS 里登记的默认值。
final class GameSetting
{
    // ---------- 已登记的开关 ----------

    // 工人「只减不增」的操作永远放行(v3.2 §10.4 的宽松执行,用户 2026-08-10 拍板)
    public const WORKER_ASSIGN_ALLOW_DECREASE_ALWAYS = 'worker_assign_allow_decrease_always';

    // 「没派工人就不生产」的总开关:关掉后 workerFactor 恒为 1.0
    public const WORKER_GATE_ENABLED = 'worker_gate_enabled';

    // 建城初始资源(对象型):{resource_code: 数量},含 money 与 knowledge
    public const INITIAL_RESOURCES = 'initial_resources';

    // ---------- 设定类型 ----------

    // 布尔开关(true / false 二选一)
    public const TYPE_BOOL = 'bool';

    // 资源映射对象:{资源 code: 非负数量},逐键校验 code 合法 + 数量在 [0, MAX_RESOURCE_AMOUNT]
    public const TYPE_RESOURCE_MAP = 'resource_map';

    // 对象型设定的单键数量上限(防止后台一次发出天文数字把经济打穿)
    public const MAX_RESOURCE_AMOUNT = 1000000;

    // 对象型设定的最大键数(allowlist 本身已把 code 限死在 31 种库存资源内,这里只是第二道保险)
    private const MAX_RESOURCE_KEYS = 40;

    // 建城初始资源的登记默认值(= 现行硬编码初始资源的区间中点 + knowledge 100)。
    //
    // 为什么是中点:SimConstants::START_RESOURCES / START_MONEY 是随机区间
    // (money 200~400、wood 200~400、stone 100~200、food 300~500),对象型设定只存一个定值,
    // 取中点是对「现行初始资源」最中性的折算,不偏向送多也不偏向送少。
    //
    // 为什么加 knowledge 100:时代 I 的 5 项科技各要 20~30 知识,时代 I 又没有任何建筑产知识 →
    // 新号研究不了科技、也就建不了任何建筑(STATUS「新号开局硬锁」)。100 知识够研 3~4 条,
    // 玩家能自己走通「研究 → 建造 → 派工」的第一圈。**这是测试期数值,正式上线前另调。**
    //
    // 注意:数量应低于 SimConstants::BASE_STORAGE(1000),否则新城建成时资源已超仓储上限,
    // 首次结算会被夹掉一部分。后台调大时要一并考虑仓储。
    public const INITIAL_RESOURCES_DEFAULT = [
        ResourceCode::MONEY     => 300,
        ResourceCode::WOOD      => 300,
        ResourceCode::STONE     => 150,
        ResourceCode::FOOD      => 400,
        ResourceCode::KNOWLEDGE => 100,
    ];

    // key => [默认值, 类型, 中文说明]。
    // default 必须与「该设定未接入前的历史行为」一致(初始资源是唯一例外:用户拍板测试期送知识解锁新号硬锁)。
    // type 决定校验路径:bool 走布尔分支,resource_map 走逐键 allowlist + 数值范围校验(见 castValue)。
    public const DEFINITIONS = [
        self::WORKER_ASSIGN_ALLOW_DECREASE_ALWAYS => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'description' => '工人只减不增的操作永远放行:人口暴跌导致历史分配超上限时,玩家仍能撤人(关闭后撤人也要满足劳动力上限)',
        ],
        self::WORKER_GATE_ENABLED => [
            'default'     => true,
            'type'        => self::TYPE_BOOL,
            'description' => '没派工人就不生产的总开关:关闭后所有建筑的用工乘区恒为 1.0(运营救急用,会让全服产量立刻恢复满额)',
        ],
        self::INITIAL_RESOURCES => [
            'default'     => self::INITIAL_RESOURCES_DEFAULT,
            'type'        => self::TYPE_RESOURCE_MAP,
            'description' => '建城初始资源(含 money / knowledge):只影响此后新建的城市,不回填老城。数量上限 100 万,建议低于仓储上限 1000',
        ],
    ];

    // 请求级缓存键(整表一次读入的 key => 值 映射)
    private const CACHE_KEY = 'game_settings';

    // 读取一个开关。未登记的 key、库里没有的行、值解析失败,一律回退到默认值(Fail Safe:
    // 配置读不出来时维持历史行为,而不是让游戏内核崩掉或静默换一套规则)。
    // $default 显式传入时优先于 DEFINITIONS 里的默认值。
    public static function get(string $key, mixed $default = null): mixed
    {
        $values = self::load();

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return $default ?? (self::DEFINITIONS[$key]['default'] ?? null);
    }

    // 写入一个开关:事务 + 审计(ADMIN.CONFIG_CHANGE)。返回 ['before' => …, 'after' => …]
    // $by = 操作管理员的 users.id;$reason 进审计 reason_code(列宽 80,调用方须先校验长度)
    public static function set(string $key, mixed $value, ?int $by, ?string $reason = null): array
    {
        if (! isset(self::DEFINITIONS[$key])) {
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        $value = self::castValue($key, $value);

        $result = DB::transaction(function () use ($key, $value, $by, $reason) {
            // lockForUpdate:锁住该行直到提交,防止并发改同一开关时 before/after 审计值出现丢失更新
            $row = DB::table('game_settings')->where('setting_key', $key)->lockForUpdate()->first();
            $before = $row ? self::decode($row->value_json, $key) : self::DEFINITIONS[$key]['default'];

            $payload = [
                'value_json'  => json_encode($value, JSON_UNESCAPED_UNICODE),
                'description' => self::DEFINITIONS[$key]['description'],
                'updated_by'  => $by,
                'updated_at'  => now(),
            ];

            if ($row) {
                DB::table('game_settings')->where('setting_key', $key)->update($payload);
            } else {
                // 缺行(库比代码旧)时补写,而不是报错:新开关上线后第一次改动即建行
                DB::table('game_settings')->insert(['setting_key' => $key] + $payload);
            }

            AuditLogger::record(AuditAction::ADMIN_CONFIG_CHANGE, 'success', [
                'actor_type'    => 'admin',
                'actor_id'      => $by,
                'user_id'       => $by,
                'entity_type'   => 'game_setting',
                'entity_id'     => $key,
                'reason_code'   => $reason,
                'before_json'   => [$key => $before],
                'after_json'    => [$key => $value],
                'metadata_json' => ['description' => self::DEFINITIONS[$key]['description']],
            ]);

            return ['before' => $before, 'after' => $value];
        });

        // 失效缓存:本请求后续的结算必须读到刚写入的新值。
        // 只 forget 不回填 —— 万一外层事务回滚,下次 load() 重新读库,不会残留一个根本没落库的值。
        self::flush();

        return $result;
    }

    // 后台设置页用:已登记开关的当前值 + 说明 + 最后修改人/时间。
    // 库里存在但代码里已不再登记的历史 key 追加在后面并标记 registered=false(不可编辑),
    // 让运营看得见「这行是残留」,而不是被静默隐藏。
    public static function all(): array
    {
        $rows = DB::table('game_settings')->get()->keyBy('setting_key');
        $list = [];

        foreach (self::DEFINITIONS as $key => $meta) {
            $row = $rows[$key] ?? null;
            $list[] = [
                'setting_key' => $key,
                'value'       => $row ? self::decode($row->value_json, $key) : $meta['default'],
                'default'     => $meta['default'],
                'type'        => $meta['type'],
                'description' => $meta['description'],
                'updated_by'  => $row?->updated_by === null ? null : (int) $row->updated_by,
                'updated_at'  => $row?->updated_at,
                'registered'  => true,
                // 对象型设定的编辑器元数据:可选键清单 + 数量上限。
                // 后台据此渲染「键/值表格 + 追加下拉」,不必让运营手写 JSON(手写 JSON 迟早写出脏配置)
                'options'     => $meta['type'] === self::TYPE_RESOURCE_MAP ? self::resourceMapOptions() : null,
                'max_value'   => $meta['type'] === self::TYPE_RESOURCE_MAP ? self::MAX_RESOURCE_AMOUNT : null,
            ];
        }

        foreach ($rows as $key => $row) {
            if (isset(self::DEFINITIONS[$key])) {
                continue;
            }
            $list[] = [
                'setting_key' => (string) $key,
                'value'       => json_decode((string) $row->value_json, true),
                'default'     => null,
                'type'        => null,
                'description' => (string) $row->description,
                'updated_by'  => $row->updated_by === null ? null : (int) $row->updated_by,
                'updated_at'  => $row->updated_at,
                'registered'  => false,
                'options'     => null,
                'max_value'   => null,
            ];
        }

        return $list;
    }

    // 清空请求级缓存(写入后、测试里改库后调用)
    public static function flush(): void
    {
        Context::forget(self::CACHE_KEY);
    }

    // 整表一次读入(每请求只查一次库)
    private static function load(): array
    {
        if (Context::has(self::CACHE_KEY)) {
            return Context::get(self::CACHE_KEY);
        }

        $values = [];
        foreach (DB::table('game_settings')->get(['setting_key', 'value_json']) as $row) {
            $values[(string) $row->setting_key] = self::decode($row->value_json, (string) $row->setting_key);
        }

        Context::add(self::CACHE_KEY, $values);

        return $values;
    }

    // 解析存储值:解析不出来时回退默认值(脏数据不该让内核换一套规则)
    private static function decode(?string $json, string $key): mixed
    {
        $value = json_decode((string) $json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::DEFINITIONS[$key]['default'] ?? null;
        }

        return self::castValue($key, $value, self::DEFINITIONS[$key]['default'] ?? null);
    }

    // 按登记类型规整取值。类型不符时:$fallback 给了就回退,没给则视为非法输入拒绝(写入路径)
    private static function castValue(string $key, mixed $value, mixed $fallback = null): mixed
    {
        $type = self::DEFINITIONS[$key]['type'] ?? null;

        if ($type === self::TYPE_BOOL) {
            if (is_bool($value)) {
                return $value;
            }
            if ($fallback !== null) {
                return (bool) $fallback;
            }
            // 写入路径:只收真正的 true/false,不做 "1"/"on"/"yes" 的模糊解释
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        if ($type === self::TYPE_RESOURCE_MAP) {
            $clean = self::normalizeResourceMap($value);
            if ($clean !== null) {
                return $clean;
            }
            if (is_array($fallback)) {
                // 读取路径:库里是脏值 → 回退登记默认值(Fail Safe,不让脏配置改变开局)
                return $fallback;
            }
            throw new GameRuleException(ErrorCode::VALIDATION_ERROR, 422);
        }

        return $value;
    }

    // 对象型设定的逐键校验。合法返回规整后的映射,非法返回 null(由调用方决定回退还是拒绝)。
    //
    // 拒绝的输入:非对象 / 空对象 / 数组式 JSON(["wood"]) / 键数超限 /
    //            未登记的资源 code / 容量类 code(不是库存资源,进不了 city_resources)/
    //            非数值(字符串 "100"、true、null)/ 嵌套对象 / 负数 / 超过 100 万 / NaN·INF
    private static function normalizeResourceMap(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            return null;
        }
        if (count($value) > self::MAX_RESOURCE_KEYS) {
            return null;
        }

        $clean = [];
        foreach ($value as $code => $amount) {
            $code = (string) $code;

            // allowlist:必须是 ResourceCode 登记过的库存资源;容量类(governance_capacity 等)不是库存资源
            if (! isset(ResourceCode::CHINESE_NAMES[$code]) || ResourceCode::isCapacity($code)) {
                return null;
            }

            // 只收真正的数字。字符串 "100" 一律拒绝:模糊解释会让「后台填错」变成静默生效
            if (! is_int($amount) && ! is_float($amount)) {
                return null;
            }
            if (is_float($amount) && ! is_finite($amount)) {
                return null;
            }
            if ($amount < 0 || $amount > self::MAX_RESOURCE_AMOUNT) {
                return null;
            }

            $clean[$code] = $amount;
        }

        return $clean;
    }

    // 对象型设定可用的资源 code 清单(后台编辑器的下拉来源:code + 中文显示名)
    public static function resourceMapOptions(): array
    {
        $options = [];
        foreach (ResourceCode::CHINESE_NAMES as $code => $name) {
            if (ResourceCode::isCapacity($code)) {
                continue;
            }
            $options[] = ['code' => $code, 'name' => $name];
        }

        return $options;
    }
}
