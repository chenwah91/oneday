<?php

use Database\Seeders\MarketDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M3-D3 市场三张表(v3.2 §8 + backlog §5.1)。
//
// 三张表的分工:
//   market_definition   = 定义层(26 行 = §8 全表)。属「游戏数值」,改动要 bump game_data_version,
//                         所以它进 GameDataVersion::CHECKSUM_TABLES。
//   city_market_orders  = 成交流水(§69「市场记录必须可追踪 buyer/resource/quantity/price/fee/timestamp/request_id」)。
//                         同时是定价内核算「全服供需移动平均」的唯一数据来源(见 PriceEngine)。
//   city_market_quota   = 单城单窗成交量累计,是「单笔 / 时间窗成交量上限」(§69 + 9.C7)的落点。
//
// 精度:所有价格 / 数量 / 金额一律 DECIMAL,禁止 float(v3.2 §2.4「资金建议数据库使用 DECIMAL,
// 禁止使用 Float 作为最终余额」)。价格类 (14,4) 与 §5.1 一致;数量 / 金额类 (18,4) 与
// city_resources.amount / cities.money 同宽,避免跨表运算被静默截断。
//
// MySQL 5.7 兼容:无 JSON 默认值、无窗口函数依赖;移动平均靠 (resource_id, window_index) 索引 + GROUP BY,
// 不用 OVER() / CTE(线上 5.7 会直接语法错误)。
return new class extends Migration
{
    public function up(): void
    {
        // ---- 定义层:26 行,逐行抄 v3.2 §8 ----
        Schema::create('market_definition', function (Blueprint $table) {
            // 主键即资源 code(与 city_resources.resource_id / resource_definition.resource_id 同口径)
            $table->string('resource_id', 32)->primary();
            // §8 的 RS 编号:文档对照用,不参与任何计算
            $table->string('rs_code', 8);
            // §8 的 category 列。刻意不叫 category:resource_definition 已经有一个语义不同的 category
            // (raw_material / processed_good…),两列同名会让后台与查询长期混淆
            $table->string('market_category', 32);
            // §8 的 first_era 列。它与 resource_definition.first_era 有 4 处不一致(见 docs 汇报),
            // 市场侧一律以 §8 为准,base_liquidity 的时代系数也由它派生
            $table->string('first_era', 8);
            // spot(现货,可买卖)/ capacity_contract(产能合约,M3 D3 不做现货买卖)/ non_tradeable(不可交易)
            $table->string('trade_mode', 24);
            // 基础价 / 硬下限 / 硬上限:§8 原值,后台可改(这三列是「基础价 + 夹取区间」的单一来源)
            $table->decimal('base_price', 14, 4);
            $table->decimal('min_price', 14, 4);
            $table->decimal('max_price', 14, 4);
            // 波动率(每 epoch 的确定性扰动幅度 ±volatility)/ 弹性(供需失衡对目标价的放大)/ 手续费率
            $table->decimal('volatility', 14, 4);
            $table->decimal('elasticity', 14, 4);
            $table->decimal('fee_rate', 14, 4);
            // 流动性(9.C1):滑点与成交量上限共同的分母。= round(20000 / base_price × 时代系数)
            $table->decimal('base_liquidity', 14, 4);
            // §8 的 note_zh:仅供后台显示,不参与计算
            $table->string('note', 191)->nullable();

            // 刻意**不建** resource_id → resource_definition 的外键,与 city_resources 保持同一约定:
            // 资源主键被整体改名过一次(2026_08_10_200002 的中文 → 英文 code 迁移),外键会把这类
            // 迁移彻底锁死(改父表时子表报 1451,改子表时又找不到父行)。
            // 「26 行是否都指向真实资源」由 MarketDefinitionTest 的数据断言守,不靠 DDL 约束。
        });

        // ---- 成交流水 ----
        Schema::create('city_market_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('city_id');
            $table->unsignedBigInteger('user_id');
            $table->string('resource_id', 32);
            $table->string('side', 4);                       // buy / sell
            $table->decimal('quantity', 18, 4);
            // mid_price = 本 epoch 的服务器基准价(未含手续费 / 滑点)。
            // 留这一列是为了事后能把成交价拆回「基准价 × 滑点 × 手续费」三段,
            // 只存最终 unit_price 的话,价格投诉根本查不出是哪一段贵了
            $table->decimal('mid_price', 14, 4);
            // slippage_rate = 本笔滑点率(0.5 × quantity / base_liquidity,方向见 TradeService)
            $table->decimal('slippage_rate', 14, 4);
            // unit_price = 玩家实际的每单位成交价 = |money_delta| / quantity(含手续费与滑点)
            $table->decimal('unit_price', 14, 4);
            // fee / slippage 均为「金额」而非比率,直接可加总做经济报表(§56 的 fee 字段口径)
            $table->decimal('fee', 18, 4);
            $table->decimal('slippage', 18, 4);
            // money_delta:买为负、卖为正。与 cities.money 的实际变动完全相等
            $table->decimal('money_delta', 18, 4);
            $table->unsignedBigInteger('window_index');
            // §69 要求流水可回溯到具体请求;request_id 与 audit_logs 同值,两边可对上
            $table->char('request_id', 36)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->dateTime('created_at');

            // 玩家 / 后台查自己的成交历史
            $table->index(['city_id', 'created_at'], 'idx_market_orders_city_time');
            $table->index(['user_id', 'created_at'], 'idx_market_orders_user_time');
            // 定价内核算全服供需移动平均。查询固定是「一次取回全部资源」:
            //   WHERE window_index BETWEEN ? AND ? GROUP BY resource_id, side
            // 所以索引首列必须是 window_index(范围条件在前、分组列在后),
            // 反过来建 (resource_id, window_index) 的话这条批量查询用不上索引,会全表扫
            $table->index(['window_index', 'resource_id'], 'idx_market_orders_window_resource');

            $table->foreign('city_id')->references('id')->on('cities');
        });

        // ---- 单城单窗成交量(成交量上限的落点)----
        Schema::create('city_market_quota', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id');
            $table->string('resource_id', 32);
            $table->unsignedBigInteger('window_index');
            // 买卖都计入同一个累计值:上限限制的是「换手量」,不是净头寸 ——
            // 分开计会让「买 10% + 卖 10%」变成一窗 20% 的换手,反刷上限形同虚设
            $table->decimal('traded_qty', 18, 4)->default(0);

            // 复合主键顺序 = 查询顺序:单窗查 (city, resource, window) 等值命中;
            // 每小时累计查 (city, resource, window > E-N) 走同一个前缀,不必再建二级索引
            $table->primary(['city_id', 'resource_id', 'window_index']);
            $table->foreign('city_id')->references('id')->on('cities');
        });

        // 定义数据随迁移落库(而不是只放 Seeder)。
        //
        // 理由:定义 Seeder 只在 `migrate:fresh --seed` 的全新库上跑,已有数据的库(开发 apg / 线上)
        // 跑完迁移后 market_definition 会是**空表** —— 市场每一笔买卖都会返回 RESOURCE_NOT_TRADEABLE,
        // 而 2026_08_11_500003 的版本 bump 也会因为「表是空的」而跳过,
        // 结果是「迁移全绿、功能全死、版本号还查不出来」这种最难排查的半上线状态。
        // 与 2026_08_10_500001(game_settings 随迁移灌行)同一条理由:
        // 任何跑过迁移的库都必须能直接用。
        //
        // 幂等:表非空就完全不动(重跑迁移 / 已被后台改过数值的库都不会被覆盖);
        // 全新库上这里灌完 26 行后,DatabaseSeeder 里的 MarketDefinitionSeeder 走 upsert,是无害的重刷。
        if (! DB::table('market_definition')->exists()) {
            DB::table('market_definition')->insert(MarketDefinitionSeeder::rows());
        }
    }

    public function down(): void
    {
        // 顺序必须与 up() 相反:两张运行时表都有 city_id 外键,先删子表再删父表方向的依赖
        // (backlog §11.4「新表有跨表外键,down() 不能是裸 dropIfExists 的默认顺序」)
        Schema::dropIfExists('city_market_quota');
        Schema::dropIfExists('city_market_orders');
        Schema::dropIfExists('market_definition');
    }
};
