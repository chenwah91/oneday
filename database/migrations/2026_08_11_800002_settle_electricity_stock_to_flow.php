<?php

use App\Game\Resource\ResourceCode;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ⚠️ **本支迁移会改动玩家存量数据,上传前先备份数据库。**
//
// M.1 电力系统:把 city_resources 里的 electricity **存量**清算掉(9.F4「存量电力在迁移时清零并折算补偿」)。
//
// ══ 为什么要清算 ═══════════════════════════════════════════════════════════════
// §8 RS017 electricity 的 trade_mode 是 `capacity_contract`(产能合约),9.F4 据此裁决电力
// **做流量不做库存**。M.1 落地后:
//   · 发电(建筑 output_json 的 electricity)不再进 grossOut → 不再入库;
//   · 耗电改读 power_per_min 那一列 → 不再从库存扣。
// 于是历史上攒下来的那一行 electricity 会永久卡住:只减不增、最后停在某个数,
// 既不能用也不能卖(市场里它是 capacity_contract,本来就买卖不了),对玩家是纯粹的沉没资产。
//
// ══ 处置口径(刻意不删行)═════════════════════════════════════════════════════
//   ① 存量 → 按 market_definition 的基础价折算成资金补偿给玩家(拿不到价就只清零不补偿);
//   ② 库存**归零**(UPDATE),**不删除 city_resources 的行** ——
//      项目红线要求「删数据要先取批准」,而清零已经足够达成「不再作为普通库存」的目的;
//      要不要顺手删掉这些零行,留给用户单独拍板(见交付汇报)。
//   ③ 每一笔补偿写一条 ADMIN.COMPENSATION 审计(§80「不要直接手动 SQL 改玩家资源」)。
//
// 幂等:只处理 amount > 0 的行。跑第二次时它们已经是 0 → 一行都不匹配 → 完全 no-op,不会重复补偿。
return new class extends Migration
{
    public function up(): void
    {
        // 表还不存在(全新库在建表迁移之前跑到这里)→ 没有存量可清算
        if (! Schema::hasTable('city_resources')) {
            return;
        }

        $rows = DB::table('city_resources')
            ->where('resource_id', ResourceCode::ELECTRICITY)
            ->where('amount', '>', 0)
            ->get(['city_id', 'amount']);

        if ($rows->isEmpty()) {
            return;
        }

        // 折算单价:§8 的基础价(electricity = 0.90)。市场表还没建 / 没这一行 → 单价 0,只清零不补偿。
        // 不硬编码 0.90 是为了不制造第二个数值来源(定义表改了价,补偿口径自动跟着改)
        $unitPrice = 0.0;
        if (Schema::hasTable('market_definition')) {
            $unitPrice = (float) (DB::table('market_definition')
                ->where('resource_id', ResourceCode::ELECTRICITY)
                ->value('base_price') ?? 0);
        }

        foreach ($rows as $row) {
            $amount = (float) $row->amount;
            $compensation = round($amount * $unitPrice, 2);

            DB::transaction(function () use ($row, $amount, $compensation, $unitPrice) {
                DB::table('city_resources')
                    ->where('city_id', $row->city_id)
                    ->where('resource_id', ResourceCode::ELECTRICITY)
                    ->update(['amount' => 0]);

                if ($compensation > 0) {
                    DB::table('cities')->where('id', $row->city_id)->increment('money', $compensation);
                }

                if (! Schema::hasTable('audit_logs')) {
                    return;
                }

                $userId = DB::table('cities')->where('id', $row->city_id)->value('user_id');

                AuditLogger::record(AuditAction::ADMIN_COMPENSATION, 'success', [
                    'actor_type'  => 'system',
                    'actor_id'    => null,
                    'user_id'     => $userId,
                    'city_id'     => $row->city_id,
                    'entity_type' => 'city_resource',
                    'entity_id'   => ResourceCode::ELECTRICITY,
                    'reason_code' => 'POWER_FLOW_MIGRATION',
                    'before_json' => [ResourceCode::ELECTRICITY => $amount],
                    'after_json'  => [ResourceCode::ELECTRICITY => 0],
                    'delta_json'  => [
                        ResourceCode::ELECTRICITY => -$amount,
                        ResourceCode::MONEY       => $compensation,
                    ],
                    'metadata_json' => [
                        'migration'  => '2026_08_11_800002_settle_electricity_stock_to_flow',
                        'reason'     => '电力改为产能合约(§8 RS017 capacity_contract / 9.F4「流量不做库存」),存量按基础价折算成资金',
                        'unit_price' => $unitPrice,
                    ],
                ]);
            });
        }
    }

    public function down(): void
    {
        // 不回滚:补偿已经发出去了,回滚只会把玩家资金减掉却还不回一份已经没有任何用途的电力存量。
        // 与 game_data_versions 同口径 —— 已发生的经济事实不靠迁移倒带(要纠正请走管理员补偿)
    }
};
