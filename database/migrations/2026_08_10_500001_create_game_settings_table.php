<?php

use App\Support\GameSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 后台可配规则开关(game_settings)。
//
// 与 Definition 表的区别:Definition 是「游戏数值」(建筑产多少、要几个人),改动要 bump game_data_version;
// game_settings 是「规则开关」(某条规则要不要生效),用于运营救急与灰度,不影响数值版本。
//
// MySQL 5.7 兼容:JSON 列不能带 DEFAULT,所以 value_json 定义为 NOT NULL 无默认,插入时必须显式给值。
// setting_key 直接做主键(varchar(64) = 256 字节,远低于 InnoDB 767 字节索引上限,5.7 上安全)。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_settings', function (Blueprint $table) {
            $table->string('setting_key', 64)->primary();
            $table->json('value_json');
            $table->string('description', 191);
            // 最后修改人(users.id)。不建外键:管理员账号被删也要保留这条配置的来源痕迹
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // 初始设定随迁移落库(而不是放 Seeder):开关属于运行配置,任何跑过迁移的库都必须有这两行,
        // 否则后台设置页会是空的。GameSetting::get 仍带默认值兜底,缺行也不会改变游戏行为。
        $rows = [];
        foreach (GameSetting::DEFINITIONS as $key => $meta) {
            $rows[] = [
                'setting_key' => $key,
                'value_json'  => json_encode($meta['default'], JSON_UNESCAPED_UNICODE),
                'description' => $meta['description'],
                'updated_by'  => null,
                'updated_at'  => now(),
            ];
        }
        if ($rows) {
            DB::table('game_settings')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_settings');
    }
};
