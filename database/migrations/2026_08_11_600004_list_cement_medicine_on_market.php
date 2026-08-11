<?php

use App\Game\Resource\ResourceCode;
use Database\Seeders\MarketDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RS027 水泥 / RS028 药品上市(M3 附带小项,docs/templates/v3.2-resource-source-mapping.md §7,草案已批)。
//
// 定位:v3.2 §16.1 的③「补充缺口」—— 两者已经有产线(水泥 ← P06 玻璃工坊、药品 ← M01 医馆,
// 均由 V3.2.0 的 2026_08_11_100001 补链落地),市场不是它们的唯一来源,只是让「产能不匹配」
// 的城市能买到缺口。这与电子元件(全服 0 产出、市场是时代 X 唯一来源)是两种不同的定位。
//
// 数值取草案 §7 的建议值 + 推荐的收敛选项:
//   RS027 水泥   14.0 / 7.7 / 33.6,建材档(volatility 0.05,elasticity 0.55,fee 0.03),first_era VII
//   RS028 药品    8.0 / 4.4 / 19.2,加工食品档(同上),first_era V
//     —— 草案 §7 对药品给了三个收敛选项,**采纳推荐的选项①「降价到 8.0」**:
//        20.0 会让 M01 的「粮食 6 → 药品 3」增值比达 5.0×(全部加工建筑是 1.25×~2.37×),
//        药品会立刻变成早期套利资源。8.0 的增值比是 2.0×,与同类持平。
// base_liquidity 按 9.C1 的模型算:round(20000 / base_price × 时代系数),IV~VII 系数 1.5
//   水泥 round(20000/14 × 1.5) = 2143;药品 round(20000/8 × 1.5) = 3750。
//
// 幂等:逐行 upsert 两行(不是整表重灌)—— 已被后台改过数值的其余 26 行一个字都不碰。
// 迁移与 Seeder 共用 MarketDefinitionSeeder::rows() 的同一份 market.json,不会出现两套数值。
return new class extends Migration
{
    private const NEW_RESOURCES = [ResourceCode::CEMENT, ResourceCode::MEDICINE];

    public function up(): void
    {
        // 建表迁移(500001)在本迁移之前,正常不会缺表;守卫只为「表被手工删过」的异常库不炸掉迁移链
        if (! Schema::hasTable('market_definition')) {
            return;
        }

        $rows = array_values(array_filter(
            MarketDefinitionSeeder::rows(),
            fn ($r) => in_array($r['resource_id'], self::NEW_RESOURCES, true)
        ));

        if (count($rows) !== count(self::NEW_RESOURCES)) {
            // market.json 抄漏了 —— 与其静默上市半种资源,不如让迁移当场停下来
            throw new RuntimeException('market.json 里没有找到 cement / medicine 两行,RS027/RS028 无法上市');
        }

        DB::table('market_definition')->upsert(
            $rows,
            ['resource_id'],
            ['rs_code', 'market_category', 'first_era', 'trade_mode', 'base_price', 'min_price',
                'max_price', 'volatility', 'elasticity', 'fee_rate', 'base_liquidity', 'note']
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('market_definition')) {
            return;
        }

        DB::table('market_definition')->whereIn('resource_id', self::NEW_RESOURCES)->delete();
    }
};
