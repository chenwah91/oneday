<?php

use Database\Seeders\NpcDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NPC 原型池 30 → 150(v3.2 §6.3 + docs/templates/npcs-150-expansion-draft.json)。
//
// 三件事,只对**已有数据的库**做(全新库由 DatabaseSeeder 一次灌满,见下面的 era 守卫):
//   ① 新增 N031~N150 共 120 行(扩充草案,含逐条 name_zh);
//   ② 刷新其中 10 行军事 NPC 的 trait_json —— 草案里它们的国防特性还挂在 unmapped_zh,
//      W4-B 已经把 defense_score_flat / defense_score_pct 登记到 ModifierTarget 并接线到 DefenseService,
//      所以按 trait_desc_zh 原文逐条提升为 spec:
//        flat:N036(+6)N053(+15)N096(+7)N113(+14)
//        pct :N071(+18%)N083(+22%)N090(+30%)N117(+15%)N143(+24%)N150(+32%)
//      (N010 / N016 / N027 的同类提升在 W4-B 的 2026_08_11_900002 里已经做过,这里不重复动);
//   ③ name_zh 回填:120 行取草案的中文名,N001~N030 保持 NULL(拟名待批,前端回落 name_key)。
//
// ══ 幂等口径(可重跑)══════════════════════════════════════════════════════════
// 逐行判断「在不在库里」:
//   不在  → 按 npcs.json 整行插入(过一遍 NpcDefinitionSeeder 的守门);
//   已在  → **只刷 trait_json 与 name_zh 两列**。
// 为什么不整行覆盖:工资 / 口粮 / 初始技能是后台可调的运营值(AdminDefinitionController),
// 整行刷回 json 等于把运营调过的数悄悄回滚 —— 与 2026_08_11_900002 同一条纪律。
//
// ══ 为什么要有这一支(而不是只靠 Seeder)══════════════════════════════════════
// 定义 Seeder 只在 `migrate:fresh --seed` 的全新库上跑。已有数据的库(开发 apg / 线上)
// 跑完迁移后 npc_definition 仍然只有 30 行:招募池不变、120 个新原型永远抽不到,
// 而版本号却已经 bump 到 V3.6.0 —— 「版本说升级了、功能没升级」是最难排查的半上线状态。
return new class extends Migration
{
    // 本支负责的新增区间(N031~N150)
    private const FIRST_NEW = 31;

    private const LAST_NEW = 150;

    public function up(): void
    {
        // 全新库:era 还没 seed,插 npc_definition 会撞 min_era 外键。
        // 数据交给紧随其后的 DatabaseSeeder,顺序天然正确(与 400001 / 400005 同一守卫)
        if (! Schema::hasTable('npc_definition') || ! DB::table('era')->exists()) {
            return;
        }

        $data = json_decode(file_get_contents(database_path('data/npcs.json')), true);
        $rows = NpcDefinitionSeeder::npcRows($data['npcs'], $data['skills'], withNameZh: true);

        $existing = DB::table('npc_definition')->pluck('npc_id')->all();
        $existing = array_flip($existing);

        foreach ($rows as $row) {
            if (! self::isNew($row['npc_id'])) {
                continue; // N001~N030 由 §6.3 原表与 W4-B 负责,本支一列不动
            }

            if (! isset($existing[$row['npc_id']])) {
                DB::table('npc_definition')->insert($row);

                continue;
            }

            // 重跑路径:只把「本支负责的两列」刷回 npcs.json 的样子
            DB::table('npc_definition')->where('npc_id', $row['npc_id'])->update([
                'trait_json' => $row['trait_json'],
                'name_zh'    => $row['name_zh'],
            ]);
        }
    }

    // 回滚 = 退回 30 条池 + 清空 name_zh。
    //
    // 已经被玩家招进城的原型**不删**:city_npcs.npc_id 只是一个 code(没有外键),
    // 删掉定义行会让那些 NPC 在快照的联查里整行消失(工资照收、人却不见了)。
    // 这类行改为只清 name_zh —— 数据回不去,但也不制造孤儿。
    public function down(): void
    {
        if (! Schema::hasTable('npc_definition')) {
            return;
        }

        $ids = [];
        for ($i = self::FIRST_NEW; $i <= self::LAST_NEW; $i++) {
            $ids[] = sprintf('N%03d', $i);
        }

        $inUse = Schema::hasTable('city_npcs')
            ? DB::table('city_npcs')->whereIn('npc_id', $ids)->distinct()->pluck('npc_id')->all()
            : [];

        DB::table('npc_definition')->whereIn('npc_id', array_values(array_diff($ids, $inUse)))->delete();

        // 留下来的行(以及 N001~N030)一律清空 name_zh:这一列由本支引入,回滚就该回到「没有中文名」
        DB::table('npc_definition')->update(['name_zh' => null]);
    }

    private static function isNew(string $npcId): bool
    {
        if (! preg_match('/^N(\d{3})$/', $npcId, $m)) {
            return false;
        }

        $n = (int) $m[1];

        return $n >= self::FIRST_NEW && $n <= self::LAST_NEW;
    }
};
