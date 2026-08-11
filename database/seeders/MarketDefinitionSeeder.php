<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// 市场定义 Seed:26 行 = v3.2 §8 全表(逐行照抄,不做任何加工)。
//
// base_liquidity 是 §8 没有、由 9.C1 批准的模型算出来的:
//     base_liquidity = round(20000 / base_price × 时代系数)
//     时代系数按 §8 的 first_era 列取:I~III ×1.0、IV~VII ×1.5、VIII~X ×2.0
// 算好的值直接落在 market.json 里而不是运行时现算 —— 定义表必须是**可后台改的数值**,
// 现算会让后台改完 base_price 之后流动性跟着悄悄变,那就不是「可编辑的数值」而是隐藏公式了。
class MarketDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        // upsert 而不是 insert:建表迁移(2026_08_11_500001)已经把 26 行灌过一遍,
        // 全新库上「迁移 + Seeder」会连着跑两次,用 insert 会直接主键冲突。
        // upsert 让这里退化成「把定义刷回 JSON 里的基准值」,重复跑安全。
        // 注意它会覆盖后台改过的数值 —— 这正是 db:seed 的语义(把定义重置回代码里的基准),
        // 日常运营改数值走后台,不要用 db:seed
        DB::table('market_definition')->upsert(
            self::rows(),
            ['resource_id'],
            ['rs_code', 'market_category', 'first_era', 'trade_mode', 'base_price', 'min_price',
                'max_price', 'volatility', 'elasticity', 'fee_rate', 'base_liquidity', 'note']
        );
    }

    // JSON → 数据库行的映射。做成 public static 是为了让建表迁移也能用同一份映射 ——
    // 迁移里再抄一遍列名,早晚会和这里对不上
    public static function rows(): array
    {
        $rows = json_decode(file_get_contents(database_path('data/market.json')), true);

        return array_map(fn ($r) => [
            'resource_id'     => $r['resource_id'],
            'rs_code'         => $r['rs_code'],
            'market_category' => $r['market_category'],
            'first_era'       => $r['first_era'],
            'trade_mode'      => $r['trade_mode'],
            'base_price'      => $r['base_price'],
            'min_price'       => $r['min_price'],
            'max_price'       => $r['max_price'],
            'volatility'      => $r['volatility'],
            'elasticity'      => $r['elasticity'],
            'fee_rate'        => $r['fee_rate'],
            'base_liquidity'  => $r['base_liquidity'],
            'note'            => $r['note'] ?? null,
        ], $rows);
    }
}
