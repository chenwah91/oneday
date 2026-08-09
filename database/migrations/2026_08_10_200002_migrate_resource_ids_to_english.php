<?php

use App\Game\Definition\GameDataVersion;
use App\Game\Resource\ResourceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 存档数据迁移:资源 ID 从中文名换成英文 code(保存档,不重建)
//
// 覆盖范围:
//   1) resource_definition.resource_id(主键)+ 顺带回填 rs_code
//   2) city_resources.resource_id(玩家存档)
//   3) building_level_definition 的 cost_json / input_json / output_json
//      —— 一律 json_decode → 键/值映射 → json_encode 写回,绝不用 SQL 字符串 REPLACE(会误伤)
//
// 明确不迁移:
//   - audit_logs.delta_json / before_json / after_json:审计是 Append-Only 的历史记录(CLAUDE §58),
//     历史就是历史,当时记的就是中文名,改写等于篡改审计。查询侧自行按时间判断即可。
//   - idempotency_keys.request_hash:hash 里含建造参数,不含资源名,理论上不受影响;
//     即便个别 key 对不上也只是重放时返回 409,而 key 24h 后即过期(prune 命令清理),影响窗口极小。
//
// 外键说明:resource_definition.resource_id 没有任何 FK 引用它(city_resources 只是隐式引用),
// 因此可以直接逐条 UPDATE 主键,不需要先 drop 约束。
return new class extends Migration {
    // 本迁移的早期版本用过 4 个与 v3.2 §0.2.1 权威表不一致的 code。
    // 已经跑过旧版(或被旧版跑到一半)的库里会残留这些键,这里一并纠正,
    // 让迁移对"任何历史形态的库"都能重跑到同一个终态。
    private const LEGACY_CODES = [
        'berry'         => ResourceCode::BERRIES,
        'electronics'   => ResourceCode::ELECTRONIC_COMPONENTS,
        'premium_food'  => ResourceCode::HIGH_QUALITY_FOOD,
        'defense_value' => ResourceCode::DEFENSE_SCORE,
    ];

    public function up(): void
    {
        // 中文名 => 英文 code,外加 旧 code => 权威 code
        $map = ResourceCode::chineseToCode() + self::LEGACY_CODES;

        $this->assertCoverage($map);
        $this->renameDefinitions($map);
        $this->renameCityResources($map);
        $this->rewriteLevelJson($map);

        // 定义数值已变(资源 ID 英文化 + 人均粮耗 0.03),记一版数据版本;
        // 只在已有 seed 数据的库上递增:全新库由 GameDataVersionSeeder 直接写入版本行
        if (DB::table('resource_definition')->exists()) {
            GameDataVersion::bump('资源ID英文化(中文名保留为显示名)+ 人均粮耗 0.1→0.03(v3.1 §10.1)', 'migration');
        }
    }

    public function down(): void
    {
        $map = ResourceCode::CHINESE_NAMES; // 英文 code => 中文名(反向映射)

        $this->renameDefinitions($map);
        $this->renameCityResources($map);
        $this->rewriteLevelJson($map);
    }

    // 迁移前断言:库里出现的所有 distinct resource_id 都必须在映射表里(容量类只出现在 JSON,单独并入)
    // 发现未映射的直接抛异常中止 —— 宁可失败,不可静默漏转
    private function assertCoverage(array $map): void
    {
        $seen = [];

        foreach (DB::table('resource_definition')->pluck('resource_id') as $id) {
            $seen[$id] = true;
        }
        foreach (DB::table('city_resources')->distinct()->pluck('resource_id') as $id) {
            $seen[$id] = true;
        }
        foreach (DB::table('building_level_definition')->get(['cost_json', 'input_json', 'output_json']) as $row) {
            foreach (array_keys(json_decode($row->cost_json ?: '{}', true) ?: []) as $k) {
                $seen[$k] = true;
            }
            foreach (['input_json', 'output_json'] as $col) {
                foreach (json_decode($row->{$col} ?: '[]', true) ?: [] as $entry) {
                    if (isset($entry['resource'])) {
                        $seen[$entry['resource']] = true;
                    }
                }
            }
        }

        $missing = [];
        foreach (array_keys($seen) as $id) {
            // 已经是目标形态(英文 code / 中文名)的直接跳过,让迁移可重复执行
            if (isset($map[$id]) || in_array($id, $map, true)) {
                continue;
            }
            $missing[] = $id;
        }

        if ($missing) {
            throw new RuntimeException('资源映射表未覆盖以下 resource_id,迁移中止:' . implode('、', $missing));
        }
    }

    // 迁移过程说明打印到控制台(测试环境静默,避免污染 PHPUnit 输出)
    private function note(string $message): void
    {
        if (PHP_SAPI === 'cli' && ! app()->environment('testing')) {
            fwrite(STDOUT, "  [resource-migrate] {$message}\n");
        }
    }

    // resource_definition:逐条 UPDATE 主键(无 FK 引用),顺带回填 rs_code
    private function renameDefinitions(array $map): void
    {
        $rsCode = $this->rsCodeMap();

        foreach (DB::table('resource_definition')->get(['resource_id']) as $row) {
            $to = $map[$row->resource_id] ?? null;
            if ($to === null || $to === $row->resource_id) {
                continue; // 已经是目标形态:迁移中断后重跑会走到这里
            }
            // 目标行已存在(中断重跑 + 重新 seed 过):旧行是残留,跳过改键并报告,不静默覆盖定义数据
            if (DB::table('resource_definition')->where('resource_id', $to)->exists()) {
                $this->note("resource_definition 已存在 {$to},跳过 {$row->resource_id} 的改键(请人工确认是否残留)");
                continue;
            }
            DB::table('resource_definition')->where('resource_id', $row->resource_id)->update([
                'resource_id' => $to,
                'rs_code'     => $rsCode[$to] ?? null,
            ]);
        }

        // rs_code 回填:已经是英文键的行(中断重跑 / 新库)也要补上
        foreach ($rsCode as $code => $rs) {
            DB::table('resource_definition')->where('resource_id', $code)->update(['rs_code' => $rs]);
        }
    }

    // city_resources:玩家存档,逐条 UPDATE(复合主键 city_id + resource_id)
    //
    // 幂等要求:迁移可能被中断后重跑,也可能有新代码抢在迁移前就往目标 code 写了行
    // (例如结算的人口吃粮那一行用的是英文常量),这时同一座城会同时存在中英文两行,
    // 直接 UPDATE 会撞复合主键报 Duplicate entry。
    // 处理:先把旧中文行的存量合并(相加)进目标行,再移除旧中文行 —— 玩家存量一分不丢;
    // 每一次合并都打印出来,不静默处理。
    private function renameCityResources(array $map): void
    {
        foreach (DB::table('city_resources')->distinct()->pluck('resource_id') as $from) {
            $to = $map[$from] ?? null;
            if ($to === null || $to === $from) {
                continue; // 已经是目标形态
            }

            $conflicts = DB::table('city_resources as src')
                ->join('city_resources as dst', function ($j) use ($to) {
                    $j->on('src.city_id', '=', 'dst.city_id')->where('dst.resource_id', '=', $to);
                })
                ->where('src.resource_id', $from)
                ->get(['src.city_id', 'src.amount as src_amount', 'dst.amount as dst_amount']);

            foreach ($conflicts as $c) {
                $merged = (float) $c->src_amount + (float) $c->dst_amount;
                DB::transaction(function () use ($c, $from, $to, $merged) {
                    DB::table('city_resources')->where('city_id', $c->city_id)->where('resource_id', $to)
                        ->update(['amount' => $merged]);
                    // 存量已并入目标行,移除旧中文行(不移除就无法改键,且会留下中文残留)
                    DB::table('city_resources')->where('city_id', $c->city_id)->where('resource_id', $from)->delete();
                });
                $this->note("city {$c->city_id}: {$from}({$c->src_amount}) 并入 {$to}({$c->dst_amount}) → {$merged}");
            }

            // 剩下的(目标行不存在)直接改键
            DB::table('city_resources')->where('resource_id', $from)->update(['resource_id' => $to]);
        }
    }

    // building_level_definition:三个 JSON 列,两种结构
    //   cost_json    = {资源: 数量}          → 换键
    //   input/output = [{resource: 资源, …}] → 换 resource 值
    private function rewriteLevelJson(array $map): void
    {
        foreach (DB::table('building_level_definition')->get(['building_id', 'level', 'cost_json', 'input_json', 'output_json']) as $row) {
            $update = [];

            $cost = json_decode($row->cost_json ?: '{}', true) ?: [];
            $newCost = [];
            foreach ($cost as $res => $amt) {
                $newCost[$map[$res] ?? $res] = $amt;
            }
            if ($newCost !== $cost) {
                $update['cost_json'] = json_encode($newCost, JSON_UNESCAPED_UNICODE);
            }

            foreach (['input_json', 'output_json'] as $col) {
                $list = json_decode($row->{$col} ?: '[]', true) ?: [];
                $newList = $list;
                foreach ($newList as $i => $entry) {
                    if (isset($entry['resource'])) {
                        $newList[$i]['resource'] = $map[$entry['resource']] ?? $entry['resource'];
                    }
                }
                if ($newList !== $list) {
                    $update[$col] = json_encode($newList, JSON_UNESCAPED_UNICODE);
                }
            }

            if ($update) {
                DB::table('building_level_definition')
                    ->where('building_id', $row->building_id)->where('level', $row->level)
                    ->update($update);
            }
        }
    }

    // code => RS 编号(v3.1 §8;§8 未收录的资源不在表里,回填时取 null)
    private function rsCodeMap(): array
    {
        $rows = json_decode(file_get_contents(database_path('data/resources.json')), true) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[$r['resource_id']] = $r['rs_code'] ?? null;
        }

        return $out;
    }
};
