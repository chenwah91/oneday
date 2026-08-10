<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M2-B6:城市时代落地。
//
// era_key / era_order 两列一次 ALTER 加完(MySQL 5.7 上 ALTER 会重建表,拆成两条等于重建两次)。
// 存档一律靠列默认值回填到时代 I —— 不写任何无 WHERE 的 UPDATE。
//
// 为什么两列都存:
//   era_key  是对外契约与定义表外键口径(building_definition / technology_definition 都用 era_key);
//   era_order 是所有比较运算的口径(时代闸门、税收系数按 era_order 读),
//             存了序号就不必为「II 和 III 谁大」再去 join era 表。
// 两者必须同步更新,写入点只有 EraService::upgrade() 一处。
//
// 刻意不加外键到 era(era_key):era.era_key 是 VARCHAR(4),这里按任务要求用 VARCHAR(8),
// 长度不一致的外键在 MySQL 5.7 上不稳(索引前缀/字符集差异会直接报 errno 150),
// 取值合法性由 EraService 只从 era 表取下一档来保证。
return new class extends Migration {
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->string('era_key', 8)->default('I')->after('population');
            $table->unsignedTinyInteger('era_order')->default(1)->after('era_key');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['era_key', 'era_order']);
        });
    }
};
