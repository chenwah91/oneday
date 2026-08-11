<?php

use Database\Seeders\EventDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M3-D4 随机事件四张表 + 事件结算时钟(v3.2 §9 + CLAUDE §70 + backlog §6.1)。
//
// 四张表的分工:
//   event_definition      = 定义层(30 行 = §9.2 全表)。属「游戏数值」,后台改一行就改全服事件,
//                           所以它进 GameDataVersion::CHECKSUM_TABLES。
//   city_events           = 事件实例(§70 点名的六个字段一个不少:
//                           instance / definition / city / triggered_at / expires_at / status)。
//   city_event_cooldowns  = 同一 event_id 的冷却(§9.1),复合主键,一城一事件一行。
//   city_active_modifiers = **持续型事件效果的统一落点**(backlog §6.1 原文),
//                           由 EventMultiplierProvider 读回 event 乘区与 happiness/security flat 通道。
//                           source_type 预留给 npc / item —— 表结构一次做对,后续系统直接复用。
//
// 刻意**不建** city_event_logs(backlog §6.1 曾列):事件的资源流水已经有两处可回放的落点 ——
//   ① audit_logs 的 EVENT.TRIGGER / EVENT.REWARD / EVENT.RESOLVE(带 delta_json,且已挂 Hash Chain);
//   ② city_events.applied_json(本实例累计造成的资源变化,resolve 的退还 / 补发要按它算)。
// 再加一张只写不读的流水表,只会变成第三份口径(M2 的双口径就是这么来的)。§9.1 要求的
// 「事件实例、玩家选择和资源变化写入 city_event_log / audit_logs」由上面两处满足。
//
// MySQL 5.7 兼容:无 JSON 默认值、无 CHECK、状态用 varchar 不用 ENUM、数值一律 DECIMAL。
return new class extends Migration
{
    public function up(): void
    {
        // ---- 定义层:30 行,逐行抄 v3.2 §9.2 ----
        Schema::create('event_definition', function (Blueprint $table) {
            $table->string('event_id', 32)->primary();
            // 中文显示名(§9.2 原表只有 event_id,没有名称列;中文名只作显示,不参与任何判定)
            $table->string('name_zh', 32);
            $table->string('name_key', 64);
            // §9.2 的 category(agriculture / disaster / food / …)。权重的城市状态修正按它分组
            $table->string('category', 32);
            // positive / negative:§13 帽修正方向的分流依据 ——
            // 正向事件直接发资源(不占加成帽),负向事件才走 event 乘区
            $table->string('event_type', 16);
            $table->string('min_era', 8);

            // ---- 后台可调的四个数值 + 一个开关(用户 2026-08-10 拍板③「所有事件必须后台可设定」)----
            $table->decimal('base_weight', 10, 4);
            $table->unsignedInteger('cooldown_minutes');
            $table->unsignedInteger('duration_minutes');
            // 效果强度倍率:所有效果的数值统一乘它(见 EventDefinition 顶部注释里
            // 「为什么不让后台直接编辑 effect JSON」)
            $table->decimal('effect_multiplier', 10, 4)->default(1);
            $table->boolean('enabled')->default(true);
            // 停用原因:enabled=false 时必填。它会随后台列表下发,
            // 让运营一眼看出「这条为什么是灰的」,而不是以为有人手滑关掉了
            $table->string('disabled_reason', 255)->nullable();

            // §9.2 的五列自然语言原文:一字不改地存着。
            // 结构化 DSL 是「机器执行的那一份」,原文是「人对账的那一份」,两份都要在
            $table->string('condition_desc_zh', 191);
            $table->string('auto_effect_desc_zh', 191);
            $table->string('option_a_desc_zh', 191)->nullable();
            $table->string('option_b_desc_zh', 191)->nullable();
            $table->string('option_c_desc_zh', 191)->nullable();

            // 结构化 DSL(见 database/data/events.json 顶部的 _dsl_* 说明)。
            // MySQL 5.7 的 JSON 列不能有默认值,所以一律 nullable 由应用侧兜底
            $table->text('condition_json')->nullable();
            $table->text('auto_effect_json')->nullable();
            $table->text('options_json')->nullable();

            // 候选池按「时代 + 是否启用」筛,30 行虽然全表扫也不贵,但索引让意图留在 schema 里
            $table->index(['enabled', 'min_era'], 'idx_event_def_enabled_era');
        });

        // ---- 事件实例(§70)----
        Schema::create('city_events', function (Blueprint $table) {
            $table->bigIncrements('id'); // = §70 的 event_instance_id
            $table->unsignedBigInteger('city_id');
            $table->string('event_id', 32); // = §70 的 event_definition_id

            $table->string('status', 16)->default('active'); // active / resolved / expired
            $table->dateTime('triggered_at');
            $table->dateTime('expires_at');
            $table->dateTime('resolved_at')->nullable();
            $table->string('chosen_option', 4)->nullable(); // a / b / c

            // 触发时**已经掷出**的随机结果(损失百分比 / 随机作用域 / 随机建筑实例…)。
            // backlog §11.3 点名:掷出结果必须落库,否则玩家可以反复 resolve 刷一个更轻的损失
            $table->text('rolled_json')->nullable();
            // 本实例迄今造成的资源 / 幸福 / 人口变化累计。
            // 「损失减半」「损失降至 3%」这类选项要按它算退还额,没有它就只能重新猜一遍
            $table->text('applied_json')->nullable();

            // 触发时的资格窗口号(= floor(triggered_at / event_window_seconds))。
            // 同一窗口不会对同一城市触发两次,查问题时也能直接对上掷点的种子
            $table->unsignedBigInteger('window_index');

            // 玩家查自己的事件、懒结算查「还有哪些没到期」都走这条
            $table->index(['city_id', 'status'], 'idx_city_events_city_status');
            // 到期扫描:懒结算按 (status, expires_at) 翻牌
            $table->index(['status', 'expires_at'], 'idx_city_events_status_expires');

            $table->foreign('city_id')->references('id')->on('cities');
        });

        // ---- 冷却(§9.1「同一 event_id 受冷却时间限制」)----
        Schema::create('city_event_cooldowns', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id');
            $table->string('event_id', 32);
            // 这一刻之前该事件不再进候选池。触发时写 = triggered_at + cooldown_minutes
            $table->dateTime('available_at');

            $table->primary(['city_id', 'event_id']);
            $table->foreign('city_id')->references('id')->on('cities');
        });

        // ---- 持续型 modifier 的统一落点(D0 总线的数据源)----
        Schema::create('city_active_modifiers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('city_id');
            // event / npc / item:现在只有 event 在写,列先留好,后续系统直接复用同一张表
            $table->string('source_type', 16);
            // 来源实体 id(事件 = city_events.id)。退还 / 缩短 / 提前结束都按它定位
            $table->unsignedBigInteger('source_id');

            // ModifierTarget 的三类名单之一(event / happiness_flat / security_flat / …),
            // 写入前由 ModifierSpec 的构造函数做 allowlist 校验(未登记的 target 直接抛)
            $table->string('target', 48);
            // ModifierSpec::SCOPE_*(city / building_instance / building_category / resource)
            $table->string('scope', 24);
            $table->string('scope_key', 64)->nullable();
            $table->string('op', 8); // pct / flat
            $table->decimal('value', 12, 4);

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('created_at');

            // 结算准备段的唯一查询:本城 + 与结算窗口有交集的行。
            // 首列 city_id(等值)、次列 ends_at(范围)—— 与 SQL 的条件顺序一致
            $table->index(['city_id', 'ends_at'], 'idx_active_mod_city_ends');
            // 按来源实例定位(选项调整 / 提前结束)
            $table->index(['source_type', 'source_id'], 'idx_active_mod_source');

            $table->foreign('city_id')->references('id')->on('cities');
        });

        // ---- 事件的懒结算时钟 ----
        //
        // 为什么不复用 last_simulated_at:那一列由结算内核推进,而事件的资格窗口判定走的是
        // NpcRuntimeService / TechService 同款的懒结算路径(快照与事件端点各自触发),
        // 两者共用一个时钟会互相吃掉对方的经过时间。NULL = 从未结算过,首次按 last_simulated_at 起算。
        Schema::table('cities', function (Blueprint $table) {
            $table->dateTime('event_settled_at')->nullable()->after('last_simulated_at');
        });

        // 定义数据随迁移落库(而不是只放 Seeder)。
        //
        // 理由与 2026_08_11_500001(市场)一字不差:定义 Seeder 只在 `migrate:fresh --seed` 的全新库上跑,
        // 已有数据的库(开发 apg / 线上)跑完迁移后 event_definition 会是**空表** ——
        // 事件永远不触发,而版本 bump 也会因为「表是空的」而跳过,
        // 结果是「迁移全绿、功能全死、版本号还查不出来」这种最难排查的半上线状态。
        //
        // 幂等:表非空就完全不动(重跑迁移 / 已被后台改过数值的库都不会被覆盖)。
        if (! DB::table('event_definition')->exists()) {
            DB::table('event_definition')->insert(EventDefinitionSeeder::rows());
        }
    }

    public function down(): void
    {
        // 顺序必须与 up() 相反:三张运行时表都有 city_id 外键,先删子表再谈父表
        // (backlog §11.4「新表有跨表外键,down() 不能是裸 dropIfExists 的默认顺序」)
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('event_settled_at');
        });

        Schema::dropIfExists('city_active_modifiers');
        Schema::dropIfExists('city_event_cooldowns');
        Schema::dropIfExists('city_events');
        Schema::dropIfExists('event_definition');
    }
};
