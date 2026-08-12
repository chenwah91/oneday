<?php

namespace App\Game\Definition;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 游戏数据版本(§64 / §65):递增 V3.1.N → V3.1.(N+1),并为该版 Definition 内容留下 checksum 指纹
class GameDataVersion
{
    // 当前版本的「每请求」缓存键。用 Context 而不是类内 static:
    // Context 跟随请求生命周期,测试里每个用例会重建 Application 自动清空,不会跨用例串味。
    private const CACHE_KEY = 'game_data_version';

    // 参与 checksum 的 Definition 表 => 用于排序的主键列。
    // MySQL 5.7 没有窗口函数 / CTE,所以不在 SQL 里聚合,统一逐表 get() 后在 PHP 侧拼接。
    private const CHECKSUM_TABLES = [
        'era'                       => ['era_key'],
        'resource_definition'       => ['resource_id'],
        'building_definition'       => ['building_id'],
        'building_level_definition' => ['building_id', 'level'],
        'technology_definition'     => ['tech_id'],
        // M3-D3 市场定义(26 行 = v3.2 §8):基础价 / 波动率 / 流动性都是「游戏数值」,
        // 后台改一行就会改变全服价格,必须进指纹,否则半年后回查「当时为什么是这个价」会查不出来
        'market_definition'         => ['resource_id'],
        // M3-D1 NPC 定义(v3.2 §6):三张表都是「游戏数值」——
        // npc_definition 后台可改(工资 / 口粮 / 初始技能),等级曲线决定每一级的乘区加成,
        // 技能表决定岗位匹配。三张里任何一行变了,玩家看到的产量就会变,所以都要进指纹
        'npc_skill_definition'      => ['skill_id'],
        'npc_skill_level_curve'     => ['level'],
        'npc_definition'            => ['npc_id'],
        // M3-D2 工具定义(24 行 = v3.2 §7):耐久 / 效果值 / 制作成本都是「游戏数值」,
        // 后台改一行就会改变全服的产量与制作门槛,必须进指纹 —— 否则半年后回查
        // 「他那件工具当时为什么加 18% 而不是 15%」会查不出来(§64 / §65)
        'item_definition'           => ['item_id'],
        // M3-D4 事件定义(30 行 = v3.2 §9.2):权重 / 冷却 / 持续时间 / 效果强度 / 启用开关
        // 全都是「游戏数值」,后台改一行就会改变全服的事件频率与强度,必须进指纹 ——
        // 否则半年后回查「他那天怎么会连着吃到三次干旱」会查不出来(§64 / §65)
        'event_definition'          => ['event_id'],
        // W11-B 时代升级门槛(9 行 = v3.2 §5.1):人口 / 知识 / 粮食 / 资金 / 治理 / 幸福 / 国防七维 + 必须建筑。
        // 它一张表管两处口径 —— 升代门槛与**国防威胁需求**(见 EraService::defenseRequirement),
        // 后台改一行等于同时改了「什么时候能升代」与「多少国防才算安全」,必须进指纹:
        // 否则半年后回查「他升到时代 VI 时门槛是多少」会查不出来(§64 / §65)
        'era_upgrade_requirement'   => ['era_order'],
    ];

    // 当前(最新)版本号。一次请求内只查一次库,后续调用走 Context 缓存;库里没有任何版本时返回 null。
    public static function current(): ?string
    {
        // 用 has() 而不是 get() 判断:版本查不到时缓存的是 null,也要认成「已查过」,否则每次都会重查
        if (Context::has(self::CACHE_KEY)) {
            return Context::get(self::CACHE_KEY);
        }

        $version = DB::table('game_data_versions')->orderByDesc('id')->value('version');
        Context::add(self::CACHE_KEY, $version);

        return $version;
    }

    // $version 显式给值时按该版本号写入(次版本 / 主版本递增,例如数据形状变化 V3.1.3 → V3.2.0);
    // 省略时沿用默认行为:在最新版本上把补丁位 +1
    public static function bump(string $note, string $by, ?string $version = null): string
    {
        $next = $version ?? self::nextPatchVersion();
        DB::table('game_data_versions')->insert([
            'version'     => $next,
            'checksum'    => self::checksum(),
            'deployed_at' => now(),
            'deployed_by' => $by,
            'notes'       => $note,
        ]);

        // 失效缓存:本请求后面的快照 / 审计必须拿到刚 bump 出来的新版本。
        // 只 forget 不回填,是为了让 bump 所在事务万一回滚时,下一次 current() 重新读库,不会残留一个根本不存在的版本号。
        Context::forget(self::CACHE_KEY);

        return $next;
    }

    // 默认递增规则:最新版本的补丁位 +1(库里一条版本都没有时从 V3.1.0 起算)
    private static function nextPatchVersion(): string
    {
        $latest = DB::table('game_data_versions')->orderByDesc('id')->value('version') ?? 'V3.1.0';
        $parts = explode('.', ltrim($latest, 'V'));
        $patch = (int) ($parts[2] ?? 0) + 1;

        return 'V' . ($parts[0] ?? '3') . '.' . ($parts[1] ?? '1') . '.' . $patch;
    }

    // Definition 全量内容指纹:各表按主键 ORDER BY,整行 json_encode 后拼接,取 sha256。
    // 性能说明:这里是五张定义表的全表读取(合计数百行)。bump 只在后台改数值 / 发版时触发,属低频管理操作,
    // 全表读可接受;不要为了省这点开销把它挪到玩家请求路径上。
    public static function checksum(): string
    {
        $buffer = '';

        foreach (self::CHECKSUM_TABLES as $table => $primaryKey) {
            // 表清单会随每个 M3 波次增长,而**早期的 bump 迁移**会在新表建出来之前就跑
            // (例:V3.2.1 的迁移排在 NPC / 市场建表迁移之前)。缺表就跳过而不是炸掉整条迁移链:
            // 少一张表只是让那一版的指纹不含它,而炸掉迁移会让线上升级卡在半路
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);
            foreach ($primaryKey as $column) {
                $query->orderBy($column);
            }

            foreach ($query->get() as $row) {
                // 表名一起进哈希:防止两张表内容互换时指纹反而不变
                // JSON_INVALID_UTF8_SUBSTITUTE:保证 json_encode 永不返回 false,否则会算出一个「稳定但错误」的指纹
                $buffer .= $table . ':' . json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            }
        }

        return hash('sha256', $buffer);
    }
}
