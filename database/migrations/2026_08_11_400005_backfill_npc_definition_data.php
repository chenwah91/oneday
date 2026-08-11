<?php

use App\Game\Definition\GameDataVersion;
use Database\Seeders\NpcDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 补灌 NPC 定义数据(M3-D1 收尾)。
//
// 为什么还要这一支:2026_08_11_400001 的**第一个版本**只建表不灌数据,而开发库 apg 在那个版本
// 上已经跑过一次 migrate —— 三张表建出来了但全是空的,400004 的版本 bump 也因为「表是空的」跳过了。
// 迁移一旦记进 migrations 表就不会重跑,所以只能另开一支把这种「建了表没数据」的库补回来。
// 线上如果也在那个时间窗里跑过 migrate,同样靠这一支自愈。
//
// 三种库各自的走向:
//   ① 全新库(era 空)          → 直接返回,数据与版本号全部交给 DatabaseSeeder,顺序天然正确;
//   ② 已补齐的库(定义表非空)  → 数据段跳过,版本段发现 V3.3.0 已在也跳过,整支等于空操作;
//   ③ 建了表没数据的库          → 补灌三张表 + 追加一个版本号。
//
// 版本号刻意**不硬写 V3.3.0**:③ 类库上并行落地的市场 V3.3.1 可能已经写在前面了,
// 硬插一个更小的版本号会让 GameDataVersion::current()(取 id 最大的一行)直接回退到旧版本。
// 所以只有在「当前最新版本还比 V3.3.0 小」时才写 V3.3.0,否则按补丁位正常往后递增 ——
// 无论哪条路径,game_data_versions 的 id 顺序都保持版本号升序。
return new class extends Migration
{
    private const VERSION = 'V3.3.0';

    public function up(): void
    {
        // 全新库:era 还没 seed,插 npc_definition 会撞 min_era 外键。数据交给 Seeder
        if (! DB::table('era')->exists()) {
            return;
        }

        $data = json_decode(file_get_contents(database_path('data/npcs.json')), true);

        // 顺序 = 外键依赖顺序:技能 → 曲线 → 原型
        if (! DB::table('npc_skill_definition')->exists()) {
            DB::table('npc_skill_definition')->insert(NpcDefinitionSeeder::skillRows($data['skills']));
        }
        if (! DB::table('npc_skill_level_curve')->exists()) {
            DB::table('npc_skill_level_curve')->insert(NpcDefinitionSeeder::curveRows($data['level_curve']));
        }
        if (! DB::table('npc_definition')->exists()) {
            DB::table('npc_definition')->insert(NpcDefinitionSeeder::npcRows($data['npcs'], $data['skills']));
        }

        if (DB::table('game_data_versions')->where('version', self::VERSION)->exists()) {
            return; // 400004 或 Seeder 已经写过,不重复
        }

        $latest = DB::table('game_data_versions')->orderByDesc('id')->value('version');
        $note = 'M3-D1 NPC 定义层:12 条技能 + 10 级曲线 + 30 个 NPC 原型(v3.2 §6.1 / §6.2 / §6.3)';

        // 最新版本还小于 V3.3.0 → 正常写 V3.3.0;否则按补丁位递增,保证 id 顺序仍是版本号升序
        if ($latest === null || version_compare(ltrim($latest, 'V'), ltrim(self::VERSION, 'V'), '<')) {
            GameDataVersion::bump($note, 'migration', self::VERSION);

            return;
        }

        GameDataVersion::bump($note . '(补灌)', 'migration');
    }

    public function down(): void
    {
        // 定义数据与版本历史都不随回滚删除:表本身的 down 在 400001 里(整表 drop),
        // 版本历史是追加式记录,与 audit 同口径
    }
};
