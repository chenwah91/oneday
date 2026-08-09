# M1-P3 定义数据与 Seed 管道 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: subagent-driven-development。步骤用 `- [ ]`。

**Goal:** 建立游戏静态定义表(时代/资源/建筑/建筑等级/科技/数据版本),并把 v3.1 数据(10 时代、31 资源、94 建筑、282 等级、50 科技)通过 Seeder 一键入库,附带数量与引用完整性校验。

**Architecture:** 定义数据放 `database/data/*.json`(已由提取校验生成,即为 Seed 数据源),Seeder 读 JSON 批量入库;定义与 Runtime 分离(本计划只做 Definition)。数值以 `docs/templates/v3.1.md` 为准。

**Tech Stack:** Laravel 12 Migration/Seeder,MySQL 5.7 兼容(本地 MariaDB)。

## Global Constraints
- PHP UTF-8 无 BOM,LF,`<?php` 首;中文注释;YAGNI。
- DB:utf8mb4 / snake_case / UTC;JSON 用 `->json()`;禁窗口函数/CTE/DB CHECK。
- 资源/建筑/科技 ID 与数值**不得擅改**(以 v3.1 为准,CLAUDE §33)。资源 JSON 键用中文资源名,与 `resource_definition.resource_id` 一致。
- 数据源文件已存在且经校验:`database/data/{eras,resources,buildings,building_levels,technologies}.json`。
- 本地:PHP=`C:/xampp/php/php.exe`;test=`C:/xampp/php/php.exe artisan test`;mysql=`C:/xampp/mysql/bin/mysql.exe -u root`。
- 测试库 `apg_test` 用 RefreshDatabase(已批准)。交付前 `php -l` 过;末尾全量 `artisan test` 绿。

---

### Task 1: 定义表迁移(6 张表)

**Files:**
- Create: `database/migrations/2026_08_09_200001_create_era_table.php`
- Create: `database/migrations/2026_08_09_200002_create_resource_definition_table.php`
- Create: `database/migrations/2026_08_09_200003_create_technology_definition_table.php`
- Create: `database/migrations/2026_08_09_200004_create_building_definition_table.php`
- Create: `database/migrations/2026_08_09_200005_create_building_level_definition_table.php`
- Create: `database/migrations/2026_08_09_200006_create_game_data_versions_table.php`
- Test: `tests/Feature/Definition/DefinitionSchemaTest.php`

**Interfaces:**
- Produces 6 表。外键顺序:era → resource_definition(first_era→era)、technology_definition(era_key→era)、building_definition(era_key→era)、building_level_definition(building_id→building_definition)。`building_definition.tech_id` 为普通字段(不加 FK)。

- [ ] **Step 1: 写失败测试 `tests/Feature/Definition/DefinitionSchemaTest.php`**

```php
<?php

namespace Tests\Feature\Definition;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DefinitionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_definition_tables_exist(): void
    {
        foreach (['era', 'resource_definition', 'technology_definition', 'building_definition', 'building_level_definition', 'game_data_versions'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "缺表 $t");
        }
        $this->assertTrue(Schema::hasColumns('building_definition', ['building_id', 'era_key', 'footprint_w', 'footprint_h', 'tech_id']));
        $this->assertTrue(Schema::hasColumns('building_level_definition', ['building_id', 'level', 'cost_json', 'output_json', 'capacity']));
    }
}
```

- [ ] **Step 2: 跑测试确认失败** — `artisan test --filter=DefinitionSchemaTest`,FAIL。

- [ ] **Step 3: 写 6 个迁移**

`..._create_era_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 文明时代
return new class extends Migration {
    public function up(): void
    {
        Schema::create('era', function (Blueprint $table) {
            $table->string('era_key', 4)->primary();
            $table->integer('era_order')->unique();
            $table->string('name', 64);
        });
    }
    public function down(): void { Schema::dropIfExists('era'); }
};
```

`..._create_resource_definition_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 资源定义(resource_id 用中文资源名,与建筑成本/产出 JSON 的键一致)
return new class extends Migration {
    public function up(): void
    {
        Schema::create('resource_definition', function (Blueprint $table) {
            $table->string('resource_id', 32)->primary();
            $table->string('name', 64)->unique();
            $table->string('category', 32);
            $table->string('first_era', 4);
            $table->boolean('is_population_consumable')->default(false);
            $table->boolean('is_strategic')->default(false);
            $table->foreign('first_era')->references('era_key')->on('era');
        });
    }
    public function down(): void { Schema::dropIfExists('resource_definition'); }
};
```

`..._create_technology_definition_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 科技定义
return new class extends Migration {
    public function up(): void
    {
        Schema::create('technology_definition', function (Blueprint $table) {
            $table->string('tech_id', 32)->primary();
            $table->string('era_key', 4);
            $table->string('branch', 32);
            $table->string('name', 96);
            $table->integer('knowledge_cost');
            $table->decimal('research_minutes', 10, 2);
            $table->json('prerequisite_tech_ids')->nullable();
            $table->json('unlock_building_ids')->nullable();
            $table->foreign('era_key')->references('era_key')->on('era');
        });
    }
    public function down(): void { Schema::dropIfExists('technology_definition'); }
};
```

`..._create_building_definition_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 建筑定义(静态;每级成本/产出在 building_level_definition)
return new class extends Migration {
    public function up(): void
    {
        Schema::create('building_definition', function (Blueprint $table) {
            $table->string('building_id', 16)->primary();
            $table->string('era_key', 4);
            $table->string('category', 32);
            $table->string('series_key', 64);
            $table->string('name', 96);
            $table->integer('max_count');
            $table->integer('footprint_w');
            $table->integer('footprint_h');
            $table->integer('base_workers');
            $table->integer('base_build_seconds');
            $table->string('tech_id', 32)->nullable();
            $table->integer('population_min')->default(0);
            $table->decimal('governance_ratio_min', 5, 2)->default(0);
            $table->integer('happiness_min')->default(0);
            $table->string('upgrade_to_building_id', 16)->nullable();
            $table->foreign('era_key')->references('era_key')->on('era');
        });
    }
    public function down(): void { Schema::dropIfExists('building_definition'); }
};
```

`..._create_building_level_definition_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 建筑等级定义(L1/L2/L3 每级的成本/时间/产出/维护/加成)
return new class extends Migration {
    public function up(): void
    {
        Schema::create('building_level_definition', function (Blueprint $table) {
            $table->string('building_id', 16);
            $table->unsignedTinyInteger('level');
            $table->string('cost_type', 24);
            $table->json('cost_json');
            $table->integer('duration_seconds');
            $table->integer('worker_required');
            $table->json('input_json')->nullable();
            $table->json('output_json')->nullable();
            $table->decimal('maintenance_money_per_min', 14, 4)->default(0);
            $table->decimal('maintenance_food_per_min', 14, 4)->default(0);
            $table->decimal('maintenance_fuel_per_min', 14, 4)->default(0);
            $table->decimal('power_per_min', 14, 4)->default(0);
            $table->decimal('happiness_bonus', 12, 2)->default(0);
            $table->decimal('governance_bonus', 12, 2)->default(0);
            $table->decimal('defense_score', 12, 2)->default(0);
            $table->decimal('capacity', 14, 2)->default(0);
            $table->primary(['building_id', 'level']);
            $table->foreign('building_id')->references('building_id')->on('building_definition');
        });
    }
    public function down(): void { Schema::dropIfExists('building_level_definition'); }
};
```

`..._create_game_data_versions_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 游戏数据版本(定位"玩家当时用的是哪一版数值")
return new class extends Migration {
    public function up(): void
    {
        Schema::create('game_data_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('version', 32)->unique();
            $table->char('checksum', 64)->nullable();
            $table->dateTime('deployed_at');
            $table->string('deployed_by', 64)->nullable();
            $table->text('notes')->nullable();
        });
    }
    public function down(): void { Schema::dropIfExists('game_data_versions'); }
};
```

- [ ] **Step 4: 跑测试确认通过** — `artisan test --filter=DefinitionSchemaTest`,PASS。

- [ ] **Step 5: Commit** — `git add database/migrations tests/Feature/Definition/DefinitionSchemaTest.php && git commit -m "M1P3 定义表迁移(时代/资源/科技/建筑/等级/数据版本)"`

---

### Task 2: Seeder(6 个)+ 提交数据文件 + 完整性测试

**Files:**
- Create: `database/seeders/EraSeeder.php`
- Create: `database/seeders/ResourceDefinitionSeeder.php`
- Create: `database/seeders/TechnologyDefinitionSeeder.php`
- Create: `database/seeders/BuildingDefinitionSeeder.php`
- Create: `database/seeders/BuildingLevelDefinitionSeeder.php`
- Create: `database/seeders/GameDataVersionSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`(按序调用)
- Commit(数据文件):`database/data/*.json`
- Test: `tests/Feature/Definition/SeedIntegrityTest.php`

**Interfaces:**
- Consumes:`database/data/*.json`
- Produces:`php artisan db:seed` 后 era=10、resource_definition=31、technology_definition=50、building_definition=94、building_level_definition=282、game_data_versions≥1;引用完整。

- [ ] **Step 1: 写失败测试 `tests/Feature/Definition/SeedIntegrityTest.php`**

```php
<?php

namespace Tests\Feature\Definition;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_counts_match_v31(): void
    {
        $this->assertSame(10, DB::table('era')->count());
        $this->assertSame(31, DB::table('resource_definition')->count());
        $this->assertSame(50, DB::table('technology_definition')->count());
        $this->assertSame(94, DB::table('building_definition')->count());
        $this->assertSame(282, DB::table('building_level_definition')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('game_data_versions')->count());
    }

    public function test_every_building_has_three_levels(): void
    {
        $bad = DB::table('building_level_definition')
            ->select('building_id')
            ->groupBy('building_id')
            ->havingRaw('COUNT(*) <> 3')
            ->get();
        $this->assertCount(0, $bad, '有建筑不是恰好3级');
    }

    public function test_referential_integrity(): void
    {
        // 每个建筑的 era_key 都存在
        $eraKeys = DB::table('era')->pluck('era_key')->all();
        $badEra = DB::table('building_definition')->whereNotIn('era_key', $eraKeys)->count();
        $this->assertSame(0, $badEra);

        // 每个 level 的 building_id 都存在
        $buildingIds = DB::table('building_definition')->pluck('building_id')->all();
        $badLevel = DB::table('building_level_definition')->whereNotIn('building_id', $buildingIds)->count();
        $this->assertSame(0, $badLevel);
    }

    public function test_cost_json_keys_are_known_resources_or_currency(): void
    {
        $resources = DB::table('resource_definition')->pluck('resource_id')->all();
        $level = DB::table('building_level_definition')->where('building_id', 'F02')->where('level', 1)->first();
        $cost = json_decode($level->cost_json, true);
        foreach (array_keys($cost) as $res) {
            $this->assertContains($res, $resources, "成本资源 $res 不在 resource_definition");
        }
    }
}
```

- [ ] **Step 2: 跑测试确认失败** — `artisan test --filter=SeedIntegrityTest`,FAIL(Seeder 未实现)。

- [ ] **Step 3: 写 6 个 Seeder**

`EraSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EraSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/eras.json')), true);
        DB::table('era')->insert(array_map(fn ($r) => [
            'era_key'   => $r['era_key'],
            'era_order' => $r['era_order'],
            'name'      => $r['name'],
        ], $rows));
    }
}
```

`ResourceDefinitionSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ResourceDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/resources.json')), true);
        DB::table('resource_definition')->insert(array_map(fn ($r) => [
            'resource_id'              => $r['resource_id'],
            'name'                     => $r['name'],
            'category'                 => $r['category'],
            'first_era'                => $r['first_era'],
            'is_population_consumable' => $r['is_population_consumable'] ? 1 : 0,
            'is_strategic'             => $r['is_strategic'] ? 1 : 0,
        ], $rows));
    }
}
```

`TechnologyDefinitionSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TechnologyDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/technologies.json')), true);
        DB::table('technology_definition')->insert(array_map(fn ($r) => [
            'tech_id'               => $r['tech_id'],
            'era_key'               => $r['era'],
            'branch'                => $r['branch'],
            'name'                  => $r['name'],
            'knowledge_cost'        => $r['knowledge_cost'],
            'research_minutes'      => $r['research_minutes'],
            'prerequisite_tech_ids' => json_encode($r['prerequisite_tech_ids'], JSON_UNESCAPED_UNICODE),
            'unlock_building_ids'   => json_encode($r['unlock_building_ids'], JSON_UNESCAPED_UNICODE),
        ], $rows));
    }
}
```

`BuildingDefinitionSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BuildingDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/buildings.json')), true);

        // 名称→ID 映射,用于把"升级去向"(名称)解析为 building_id
        $nameToId = [];
        foreach ($rows as $r) {
            $nameToId[$r['name']] = $r['building_id'];
        }

        $insert = array_map(function ($r) use ($nameToId) {
            $upgradeName = $r['upgrade_to'] ?? '';
            $upgradeId = ($upgradeName !== '' && $upgradeName !== '终局' && isset($nameToId[$upgradeName]))
                ? $nameToId[$upgradeName] : null;

            return [
                'building_id'          => $r['building_id'],
                'era_key'              => $r['era'],
                'category'             => $r['category'],
                'series_key'           => $r['series'],
                'name'                 => $r['name'],
                'max_count'            => $r['max_count'],
                'footprint_w'          => $r['footprint_w'],
                'footprint_h'          => $r['footprint_h'],
                'base_workers'         => $r['base_workers'],
                'base_build_seconds'   => $r['base_build_seconds'],
                'tech_id'              => $r['tech_id'],
                'population_min'       => 0,
                'governance_ratio_min' => 0,
                'happiness_min'        => 0,
                'upgrade_to_building_id' => $upgradeId,
            ];
        }, $rows);

        DB::table('building_definition')->insert($insert);
    }
}
```

`BuildingLevelDefinitionSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BuildingLevelDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/building_levels.json')), true);

        $insert = array_map(fn ($r) => [
            'building_id'               => $r['building_id'],
            'level'                     => $r['level'],
            'cost_type'                 => $r['cost_type'],
            'cost_json'                 => json_encode($r['cost'], JSON_UNESCAPED_UNICODE),
            'duration_seconds'          => $r['duration_seconds'],
            'worker_required'           => $r['worker_required'],
            'input_json'                => json_encode($r['input'], JSON_UNESCAPED_UNICODE),
            'output_json'               => json_encode($r['output'], JSON_UNESCAPED_UNICODE),
            'maintenance_money_per_min' => $r['maintenance']['money_per_min'],
            'maintenance_food_per_min'  => $r['maintenance']['food_per_min'],
            'maintenance_fuel_per_min'  => $r['maintenance']['fuel_per_min'],
            'power_per_min'             => $r['maintenance']['power_per_min'],
            'happiness_bonus'           => $r['happiness_bonus'],
            'governance_bonus'          => $r['governance_bonus'],
            'defense_score'             => $r['defense_score'],
            'capacity'                  => $r['capacity'],
        ], $rows);

        // 分块插入(282 行),避免单条 SQL 过长
        foreach (array_chunk($insert, 100) as $chunk) {
            DB::table('building_level_definition')->insert($chunk);
        }
    }
}
```

`GameDataVersionSeeder.php`:
```php
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
    }
}
```

- [ ] **Step 4: 改 `database/seeders/DatabaseSeeder.php` 的 `run()`**(按外键顺序调用):

```php
    public function run(): void
    {
        $this->call([
            EraSeeder::class,
            ResourceDefinitionSeeder::class,
            TechnologyDefinitionSeeder::class,
            BuildingDefinitionSeeder::class,
            BuildingLevelDefinitionSeeder::class,
            GameDataVersionSeeder::class,
        ]);
    }
```

- [ ] **Step 5: 跑测试确认通过** — `artisan test --filter=SeedIntegrityTest`,全 PASS。

- [ ] **Step 6: Commit(含数据文件)** — `git add database/data database/seeders tests/Feature/Definition/SeedIntegrityTest.php && git commit -m "M1P3 定义 Seeder 与 v3.1 数据文件"`

---

### Task 3: Seed 开发库 + 收尾 + 版本

- [ ] **Step 1: 全量测试** — `C:/xampp/php/php.exe artisan test`,全绿。
- [ ] **Step 2: 迁移并 Seed 开发库 apg**(前端/API 用的是 apg)

Run:
```bash
"C:/xampp/php/php.exe" artisan migrate --force
"C:/xampp/php/php.exe" artisan db:seed --force
"C:/xampp/mysql/bin/mysql.exe" -u root apg -e "SELECT (SELECT COUNT(*) FROM era) era,(SELECT COUNT(*) FROM resource_definition) res,(SELECT COUNT(*) FROM building_definition) bld,(SELECT COUNT(*) FROM building_level_definition) lvl,(SELECT COUNT(*) FROM technology_definition) tech;"
```
Expected:era=10,res=31,bld=94,lvl=282,tech=50。

- [ ] **Step 3: `php -l` + BOM 抽查** 新增 PHP(迁移+Seeder)全过、无 BOM。
- [ ] **Step 4: 版本提交** — `git add -A && git commit -m "v0.6.0 M1P3完成:定义数据入库(10时代/31资源/94建筑/282等级/50科技)"`

## 自检对照
- 6 定义表 + game_data_versions:Task 1 ✅
- v3.1 数据入库(数量对齐 + 引用完整):Task 2 ✅
- 开发库可用于后续 API/前端:Task 3 ✅

## 不在范围(后续)
- market/event/npc 定义表:M2
- 建造前置的 population_min/governance/happiness 真实值:M2(本计划默认 0)
- 跨建筑时代升级(upgrade_to_building_id 已存但 M1 不用)
