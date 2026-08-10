<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// V3.2.0 定义数据落地(已有库的补丁;全新库由 Seeder 直接读 database/data/*.json 得到同一形态)
//
// 依据两份已批准草案:
//   1) docs/templates/v3.2-resource-source-mapping.md §2~§5(方案 A):无来源资源补链
//      - R02 采石场   新增副产 黏土 clay        10 / 13.5 / 18   每分钟
//      - R02 采石场   新增副产 砂石 sand_gravel 10 / 13.5 / 18   每分钟
//      - P06 玻璃工坊 新增投入 石料 stone       6  / 7.08 / 8.7  每分钟
//        + 新增产出 水泥 cement                 6  / 8.1  / 10.8 每分钟
//      - M01 医馆     新增投入 粮食 food        6  / 7.08 / 8.7  每分钟
//        + 新增产出 药品 medicine               3  / 4.05 / 5.4  每分钟
//      - 黏土 / 砂石 first_era 统一为来源建筑所在时代 II(草案 §3.4 / P3)
//      - 51 条 cement 成本行、54 条 electronic_components 成本行一条都不改(草案 §4.2 A3-7)
//   2) docs/templates/v3.2-building-upgrade-remap.md §2(策略甲):跨代升级链重映射
//      - P05→P07、E03→E04、E04→E05、C01→C02、C02→C04、K03→K04 六条补链
//      - 其余断链维持 NULL;P03 / P04 / C03 三条 UNRESOLVED 同样维持 NULL(不猜)
//      - M01→M02 断开置 NULL:资源草案 §5.4 选项 ②,药品唯一来源不可被升级掉
//
// 幂等:输入/产出按资源 code 判重后追加,重跑不会写出重复条目;upgrade / first_era 是幂等赋值。
// 可回滚:down() 精确移除本次追加的条目并还原 upgrade_to / first_era 原值。
//
// 不 bump 版本:GDV V3.2.0 由 2026_08_11_100002 单独一支迁移负责(与 400001 同款拆法)。
return new class extends Migration {
    // building_id => level => ['input'|'output' => [资源 code => 每分钟速率]]
    private const LEVEL_DELTA = [
        'R02' => [
            1 => ['output' => ['clay' => 10, 'sand_gravel' => 10]],
            2 => ['output' => ['clay' => 13.5, 'sand_gravel' => 13.5]],
            3 => ['output' => ['clay' => 18, 'sand_gravel' => 18]],
        ],
        'P06' => [
            1 => ['input' => ['stone' => 6], 'output' => ['cement' => 6]],
            2 => ['input' => ['stone' => 7.08], 'output' => ['cement' => 8.1]],
            3 => ['input' => ['stone' => 8.7], 'output' => ['cement' => 10.8]],
        ],
        'M01' => [
            1 => ['input' => ['food' => 6], 'output' => ['medicine' => 3]],
            2 => ['input' => ['food' => 7.08], 'output' => ['medicine' => 4.05]],
            3 => ['input' => ['food' => 8.7], 'output' => ['medicine' => 5.4]],
        ],
    ];

    // 来源建筑 => [迁移后的 upgrade_to_building_id, 迁移前的原值]
    private const UPGRADE_REMAP = [
        'P05' => ['P07', null],
        'E03' => ['E04', null],
        'E04' => ['E05', null],
        'C01' => ['C02', null],
        'C02' => ['C04', null],
        'K03' => ['K04', null],
        'M01' => [null, 'M02'],
    ];

    // 资源 => [迁移后 first_era, 迁移前 first_era]
    private const FIRST_ERA = [
        'clay'        => ['II', 'III'],
        'sand_gravel' => ['II', 'VII'],
    ];

    public function up(): void
    {
        if (! $this->definitionsSeeded()) {
            return; // 全新库:定义表还没 seed,没有可迁移的行
        }

        $this->assertCoverage();

        $rows = 0;
        foreach (self::LEVEL_DELTA as $buildingId => $levels) {
            foreach ($levels as $level => $delta) {
                $rows += $this->applyLevel($buildingId, $level, $delta, true);
            }
        }
        $this->note("产出/配方补链:更新 {$rows} 条 building_level_definition");

        $upgrades = 0;
        foreach (self::UPGRADE_REMAP as $from => [$to, $_old]) {
            $upgrades += DB::table('building_definition')->where('building_id', $from)
                ->update(['upgrade_to_building_id' => $to]);
        }
        $this->note("升级链重映射:更新 {$upgrades} 条 building_definition.upgrade_to_building_id");

        $eras = 0;
        foreach (self::FIRST_ERA as $resourceId => [$new, $_old]) {
            $eras += DB::table('resource_definition')->where('resource_id', $resourceId)
                ->update(['first_era' => $new]);
        }
        $this->note("首次时代校正:更新 {$eras} 条 resource_definition.first_era");

        $this->verify();
    }

    public function down(): void
    {
        if (! $this->definitionsSeeded()) {
            return;
        }

        foreach (self::LEVEL_DELTA as $buildingId => $levels) {
            foreach ($levels as $level => $delta) {
                $this->applyLevel($buildingId, $level, $delta, false);
            }
        }

        foreach (self::UPGRADE_REMAP as $from => [$_new, $old]) {
            DB::table('building_definition')->where('building_id', $from)
                ->update(['upgrade_to_building_id' => $old]);
        }

        foreach (self::FIRST_ERA as $resourceId => [$_new, $old]) {
            DB::table('resource_definition')->where('resource_id', $resourceId)
                ->update(['first_era' => $old]);
        }
    }

    private function definitionsSeeded(): bool
    {
        return DB::table('building_definition')->exists()
            && DB::table('building_level_definition')->exists()
            && DB::table('resource_definition')->exists();
    }

    // 迁移前断言覆盖率:本次要动的每一行都必须存在,少一行就中止,宁可失败不可静默漏改
    private function assertCoverage(): void
    {
        $missing = [];

        foreach (self::LEVEL_DELTA as $buildingId => $levels) {
            foreach (array_keys($levels) as $level) {
                $exists = DB::table('building_level_definition')
                    ->where('building_id', $buildingId)->where('level', $level)->exists();
                if (! $exists) {
                    $missing[] = "building_level_definition:{$buildingId} L{$level}";
                }
            }
        }

        // 来源建筑与重映射目标建筑都必须存在(目标不存在会写出新的断链)
        foreach (self::UPGRADE_REMAP as $from => [$to, $_old]) {
            foreach (array_filter([$from, $to]) as $id) {
                if (! DB::table('building_definition')->where('building_id', $id)->exists()) {
                    $missing[] = "building_definition:{$id}";
                }
            }
        }

        // 补链引用到的资源必须都在 resource_definition 里(否则等于造出无定义资源)
        $referenced = ['clay', 'sand_gravel', 'stone', 'cement', 'food', 'medicine'];
        foreach (array_merge($referenced, array_keys(self::FIRST_ERA)) as $resourceId) {
            if (! DB::table('resource_definition')->where('resource_id', $resourceId)->exists()) {
                $missing[] = "resource_definition:{$resourceId}";
            }
        }

        if ($missing) {
            throw new RuntimeException(
                'V3.2.0 数据迁移覆盖率断言失败,迁移中止,缺少:' . implode('、', array_unique($missing))
            );
        }
    }

    // 单条等级定义的 input_json / output_json 增删。$add=true 追加(已存在则跳过),false 移除
    private function applyLevel(string $buildingId, int $level, array $delta, bool $add): int
    {
        $row = DB::table('building_level_definition')
            ->where('building_id', $buildingId)->where('level', $level)
            ->first(['input_json', 'output_json']);

        if ($row === null) {
            return 0;
        }

        $update = [];
        foreach (['input', 'output'] as $field) {
            if (! isset($delta[$field])) {
                continue;
            }

            $column = $field . '_json';
            $list = json_decode($row->{$column} ?: '[]', true) ?: [];
            $codes = array_column($list, 'resource');
            $changed = false;

            foreach ($delta[$field] as $code => $rate) {
                $at = array_search($code, $codes, true);

                if ($add && $at === false) {
                    $list[] = ['resource' => $code, 'rate_per_min' => $rate];
                    $changed = true;
                } elseif (! $add && $at !== false) {
                    unset($list[$at]);
                    $list = array_values($list);
                    $codes = array_column($list, 'resource');
                    $changed = true;
                }
            }

            if ($changed) {
                $update[$column] = json_encode($list, JSON_UNESCAPED_UNICODE);
            }
        }

        if (! $update) {
            return 0;
        }

        return DB::table('building_level_definition')
            ->where('building_id', $buildingId)->where('level', $level)
            ->update($update);
    }

    // 迁移后校验:补链的资源确实有产出行了,升级链目标合法且无自环
    private function verify(): void
    {
        foreach (['clay', 'sand_gravel', 'cement', 'medicine'] as $resourceId) {
            $found = 0;
            foreach (DB::table('building_level_definition')->pluck('output_json') as $json) {
                if (in_array($resourceId, array_column(json_decode($json ?: '[]', true) ?: [], 'resource'), true)) {
                    $found++;
                }
            }
            if ($found === 0) {
                throw new RuntimeException("V3.2.0 数据迁移校验失败:{$resourceId} 迁移后仍然 0 产出");
            }
            $this->note("  {$resourceId}:{$found} 条产出行");
        }

        $ids = DB::table('building_definition')->pluck('building_id')->all();
        $bad = DB::table('building_definition')
            ->whereNotNull('upgrade_to_building_id')
            ->where(function ($q) use ($ids) {
                $q->whereNotIn('upgrade_to_building_id', $ids)
                    ->orWhereColumn('upgrade_to_building_id', 'building_id');
            })
            ->pluck('upgrade_to_building_id', 'building_id')->all();

        if ($bad) {
            $pairs = [];
            foreach ($bad as $from => $to) {
                $pairs[] = "{$from}→{$to}";
            }
            throw new RuntimeException('V3.2.0 数据迁移校验失败:升级链非法 ' . implode('、', $pairs));
        }

        $nulls = DB::table('building_definition')->whereNull('upgrade_to_building_id')->count();
        $this->note('升级链校验通过:' . (count($ids) - $nulls) . " 条有效链接,{$nulls} 条 NULL");
    }

    // 迁移过程说明打印到控制台(测试环境静默,避免污染 PHPUnit 输出)
    private function note(string $message): void
    {
        if (PHP_SAPI === 'cli' && ! app()->environment('testing')) {
            fwrite(STDOUT, "  [v320-migrate] {$message}\n");
        }
    }
};
