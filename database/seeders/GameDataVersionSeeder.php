<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GameDataVersionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.1.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M1 初始数值:10时代/31资源/94建筑/282等级/50科技',
            ]
        );

        // 全新库直接写到当前 seed 数据对应的版本;
        // 已有数据的库由 2026_08_10_200002 迁移末尾的 GameDataVersion::bump 递增到同一版本
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.1.2'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => '资源ID英文化(中文名保留为显示名)+ 人均粮耗 0.1→0.03(v3.1 §10.1)',
            ]
        );

        // 已有数据的库由 2026_08_10_400001 迁移递增到同一版本
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.1.3'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => '枚举值英文化:building category/series/cost_type/resource category/tech branch → 英文 code(v3.2 §0.2 第二批)',
            ]
        );

        // 次版本递增(数据形状变化):已有数据的库由 2026_08_11_100002 迁移递增到同一版本
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.2.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'RESOURCE_SOURCE_MAPPING(黏土/砂石/水泥/药品补链)+ BUILDING_UPGRADE_REMAP(6 条跨代升级链重映射)',
            ]
        );

        // 定义表列形状变化(删三列双口径):已有数据的库由 2026_08_11_200001 迁移递增到同一版本。
        // ⚠️ 本方法内的插入顺序 = 版本号升序,新增版本一律追加在**末尾**:
        // GameDataVersion::current() 取的是 id 最大的一行,插反了会让「当前版本」回退到旧版本号
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.2.1'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => '删除 building_level_definition 的 happiness_bonus / governance_bonus / defense_score 三列双口径(单一来源 output_json)',
            ]
        );

        // M3-D1 NPC 定义层落地(新增三张定义表,进 checksum 表清单)。
        // 已有数据的库由 2026_08_11_400004 迁移递增到同一版本。
        // 位置:必须排在下面 V3.3.1 市场那段**之前**(版本号严格升序,见下段的说明)。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.3.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-D1 NPC 定义层:12 条技能 + 10 级曲线 + 30 个 NPC 原型(v3.2 §6.1 / §6.2 / §6.3)',
            ]
        );

        // M3-D3 市场定义表落地(新增 market_definition 26 行,进 checksum 表清单)。
        // 已有数据的库由 2026_08_11_500003 迁移递增到同一版本。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        //    M3-D1 NPC 的 V3.3.0 应排在本行**之前** —— 两个 agent 并行落地时若 NPC 后到,
        //    合并的人要把 V3.3.0 那段挪到这一段上面,不能直接追加在下面。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.3.1'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-D3 市场定义层:market_definition 26 行(v3.2 §8 全表)+ base_liquidity 模型(9.C1)',
            ]
        );

        // M3-D2 工具定义层落地(新增 item_definition 24 行,进 checksum 表清单)
        // + RS027 水泥 / RS028 药品上市(market_definition 26 → 28 行,既有定义表的行集变了 → 吃次版本位)。
        // 已有数据的库由 2026_08_11_600005 迁移递增到同一版本。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.4.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-D2 工具定义层:item_definition 24 行(v3.2 §7)+ RS027 水泥 / RS028 药品上市(资源来源映射草案 §7)',
            ]
        );

        // M3-D4 事件定义层落地(新增 event_definition 30 行,进 checksum 表清单)。
        // 已有数据的库由 2026_08_11_700003 迁移递增到同一版本。
        // 为什么是**补丁位**:既有定义表一行一列都没动,所有城市的产出 / 成本 / 升级链完全不变;
        // 但 checksum 表清单多了一张表,指纹随之改变,所以仍要留一个版本号。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.4.1'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-D4 事件定义层:event_definition 30 行(v3.2 §9.2 全表)+ 条件/效果 DSL 结构化(9.D1)',
            ]
        );

        // M.1 电力系统落地(定义数据的**含义**变了,吃次版本位)。
        // 已有数据的库由 2026_08_11_800004 迁移递增到同一版本。
        // 为什么不是补丁位:同一批定义数据在 V3.4.1 与 V3.5.0 下会算出不同的产量 ——
        //   power_per_min(57 行)由零读取转为耗电需求;
        //   output_json 的 electricity(9 行)由普通库存产出转为装机容量(不再入库);
        //   input_json 的 electricity(36 行)不再读取(与 power_per_min 双计);
        //   EVT_BLACKOUT 一行由停用转启用,条件 / 自动效果 / 两个选项全部换成可执行 DSL。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        //    同波次并行落地的 D5 国防若也要 bump,应排在本段**之后**取下一个号。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.5.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M.1 电力系统:power_per_min(57 行)由零读取转为耗电需求 + electricity 由库存资源转为产能合约(§8 RS017 / 9.F4)+ EVT_BLACKOUT 复活',
            ]
        );

        // M3-D5 国防联动落地(6 行定义数据的行内容变了,吃补丁位)。
        // 已有数据的库由 2026_08_11_900003 迁移递增到同一版本。
        // 为什么是补丁位:没有新表、没有一栋建筑的产出 / 成本 / 升级链被改;
        //   event_definition 的 EVT_RAID / EVT_BORDER_TENSION 两行由停用转启用(条件与效果换成可执行 DSL);
        //   item_definition 的 IT008、npc_definition 的 N010 / N016 / N027 四行把国防效果由 unmapped 提升为 spec。
        // 但同一批定义数据在 V3.5.0 与 V3.5.1 下会算出不同结果(开始挨劫掠、军事 NPC 与防御装备开始加国防值),
        // 所以仍要留一个版本号(§64 / §65)。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.5.1'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-D5 国防联动:EVT_RAID / EVT_BORDER_TENSION 复活 + IT008 国防 flat 与 N010/N016/N027 国防特性由 unmapped 提升为可执行',
            ]
        );

        // NPC 池 30 → 150 落地(定义表行集 + 列集都变了,吃次版本位)。
        // 已有数据的库由 2026_08_12_100004 迁移递增到同一版本。
        // 为什么不是补丁位:npc_definition 从 30 行变成 150 行(招募候选池整整多了 120 个原型,
        // 时代 I 从此也能招募)、多了 name_zh 一列,10 行军事 NPC 的国防特性由 unmapped 提升为 spec
        // (有效国防值 / 威胁覆盖率 / EVT_RAID 损失比例都会跟着变),EVT_BRAIN_DRAIN 由停用转启用。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.6.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'NPC 原型池 30 → 150(新增 N031~N150 + name_zh 中文名)+ 10 行军事 NPC 国防特性提升为 spec + EVT_BRAIN_DRAIN 人才流失复活',
            ]
        );

        // M3-W5 容量 / 税收 / 市场价格三组 target 接线(19 行定义数据的行内容变了,吃补丁位)。
        // 已有数据的库由 2026_08_12_200003 迁移递增到同一版本。
        // 为什么是补丁位:没有新表、没有新行、没有一栋建筑的产出 / 成本 / 升级链被改 ——
        //   event_definition 六行由停用转启用(EVT_ROUTE_BREAK / EVT_PORT_CONGESTION / EVT_CRIME /
        //     EVT_CORRUPTION / EVT_SPECULATION / EVT_OIL_SHOCK),EVT_TRADE_BOOM 两个选项提升,
        //     EVT_TAX_PROTEST 只刷说明(维持停用:税率固定不可调);
        //   item_definition 的 IT018、npc_definition 的 N013 与 10 位物流 NPC 由 unmapped 提升为 spec;
        //   npc_skill_definition 的 SKILL_LOGISTICS 补上 effect_target。
        // 但同一批定义数据在 V3.6.0 与 V3.6.1 下会算出不同结果(运输容量会被事件/NPC 改动 → 物流乘区跟着变、
        // 税收会被事件打折、市场买入价会被价格冲击抬高、单城成交量上限开始受贸易容量约束),
        // 所以仍要留一个版本号(§64 / §65)。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.6.1'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-W5 容量/税收/价格三组 target 接线:六条事件复活(路线中断/港口拥堵/犯罪浪潮/贪腐案/市场投机/石油冲击)+ IT018 与 11 位 NPC 特性提升 + 贸易容量接市场成交量上限',
            ]
        );

        // M3-W6 治理容量死 target 清偿(4 行定义数据的行内容变了,吃补丁位)。
        // 已有数据的库由 2026_08_12_300002 迁移递增到同一版本。
        // 为什么是补丁位:没有新表、没有新行、没有一栋建筑的产出 / 成本 / 升级链被改 ——
        //   npc_definition 的 N013 / N051 / N111 由 governance_capacity_pct(op=flat)
        //     改挂 governance_capacity_flat(数值 30 / 20 / 22 一个没动);
        //   event_definition 的 EVT_CORRUPTION 选项 B「治理容量暂时-10%」由 unmapped 提升为 modifier。
        // 但同一批定义数据在 V3.6.1 与 V3.6.2 下会算出不同结果:18 位行政 NPC 与 IT022 的治理加成
        // 第一次真的进 governanceLoad → governanceEfficiency → 税收,所以仍要留一个版本号(§64 / §65)。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.6.2'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-W6 治理容量 target 清偿:拆成 governance_capacity_flat + pct 两条并接进结算内核(18 位行政 NPC 与 IT022 的治理加成首次生效)+ EVT_CORRUPTION 选项 B 治理减益提升',
            ]
        );

        // M3-W10 用户 2026-08-12 拍板的三组数据改动(39 行定义数据的行内容变了,吃**次版本位**)。
        // 已有数据的库由 2026_08_12_400002 迁移递增到同一版本。
        // 为什么不是补丁位:改的不只是数值,而是玩法条件 ——
        //   · item_definition 6 行的 crafting_building_id 由 NULL 变成实际建筑,
        //     ItemService::craft 的既有建筑闸门从此对这 6 件生效(同样的城市在 V3.6.2 能做、V3.7.0 会被挡);
        //   · event_definition 3 行的选项效果集合变了(EVT_CORRUPTION 选项 A 由「只扣钱不办事」
        //     变成确定性解除两条减益、选项 B 由 -10% 折算为 -5%,EVT_PORT_CONGESTION 选项 A 追加拥堵解除);
        //   · npc_definition 30 行回填 name_zh(显示列,但进 checksum 的列集内容变了)。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.7.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'M3-W10 用户拍板三组数据改动:N001~N030 中文拟名回填(全表 150 名互异)+ EVT_CORRUPTION 选项 A 改为确定性解除、选项 B 净额折算为 -5%、EVT_PORT_CONGESTION 选项 A 追加拥堵解除 + 6 件工具改挂现有制作建筑(IT003/IT005→P02、IT004→P04、IT013→P05、IT016→K03、IT019→P08)',
            ]
        );

        // W11-B 定义层扩容(定义数据的**形状**变了,吃次版本位)。
        // 已有数据的库由 2026_08_13_100003 迁移递增到同一版本。
        // 为什么不是补丁位:checksum 的表清单多了 era_upgrade_requirement(时代门槛 9 行,
        // 同时是国防威胁需求的唯一来源),npc_definition 多了 trait_multiplier 列(150 行默认 1.0000)。
        // 落地当刻行为零变化(门槛逐格搬自同一份常量、倍率默认 1.0),但两组数值从此后台可调,
        // 半年后回查「他升代时门槛是多少」必须能看出版本分界(§64 / §65)。
        // ⚠️ 版本号必须严格升序追加在末尾:current() 取 id 最大的一行,插反了「当前版本」会回退。
        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.8.0'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => 'W11-B 定义层扩容:时代升级门槛搬表(era_upgrade_requirement 9 行 = §5.1,同时是国防威胁需求的唯一来源)+ npc_definition 新增 trait_multiplier 特性强度倍率 + 建筑等级 JSON 条目 / 科技 / 建筑上限 / NPC 等级曲线 / 时代门槛后台可编辑',
            ]
        );

        DB::table('game_data_versions')->updateOrInsert(
            ['version' => 'V3.8.1'],
            [
                'deployed_at' => now(),
                'deployed_by' => 'seed',
                'notes'       => '删除 building_definition 五个死列(三门槛列恒0无读取 + base_workers/base_build_seconds 被 level 表取代;用户 2026-08-13 拍板删5留3,upgrade_to_building_id 与资源两标记保留)',
            ]
        );
    }
}
