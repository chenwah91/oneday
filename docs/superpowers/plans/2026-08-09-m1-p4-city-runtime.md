# M1-P4 城市 Runtime + 快照 + Time Delta 模拟 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: subagent-driven-development。步骤用 `- [ ]`。

**Goal:** 建立玩家城市 Runtime(城市/资源/建筑实例表),注册即自动建城并发随机初始资源,提供只读 City Snapshot(GET /api/city),快照时用 Time Delta Simulation 懒结算资源生产/消耗/维护与粮食消耗。

**Architecture:** 每玩家 1 座城市(隐式归属,通过 `Auth::user()->city` 定位,URL 不带 city_id,天然满足 Ownership)。定义与 Runtime 分离。模拟不扫全表:仅在读快照/玩家操作时按 `now - last_simulated_at` 结算。人口在 M1 固定(增长/衰减留 M2);存储上限、生产/维护/粮食消耗在本计划实现。

**Tech Stack:** Laravel 12,MySQL 5.7 兼容。承接 P2 认证、P3 定义数据。

## Global Constraints
- PHP UTF-8 无 BOM,LF,`<?php` 首;中文注释;YAGNI。
- DB:utf8mb4 / snake_case / UTC;金额/资源用 DECIMAL;禁窗口函数/CTE/DB CHECK。
- 服务器权威:客户端只读快照,不回传资源结果。
- 模拟公式:`actual = ratePerMin × elapsedSeconds / 60`;资源夹在 `[0, 存储上限]`;粮食/资金 ≥ 0。
- 常量集中在 `App\Game\Simulation\SimConstants`(FOOD_PER_CAPITA_PER_MIN、BASE_STORAGE、初始资源区间、地图尺寸)。
- 本地:PHP=`C:/xampp/php/php.exe`;test=`C:/xampp/php/php.exe artisan test`;mysql=`C:/xampp/mysql/bin/mysql.exe -u root`。测试库 RefreshDatabase。

## 关键设计:哪些 output 是"资源"哪些是"容量"
building_level 的 `output_json` 里,以下 8 个是**容量类**(计入城市容量,不入资源流):人口容量、仓储容量、治理容量、运输容量、国防值、贸易容量、金融容量、医疗容量。其余(粮食、木材…)是**资源**,计入生产。用 `SimConstants::CAPACITY_OUTPUTS` 常量数组区分。

---

### Task 1: Runtime 表迁移 + 模型

**Files:**
- Create: `database/migrations/2026_08_09_300001_create_cities_table.php`
- Create: `database/migrations/2026_08_09_300002_create_city_resources_table.php`
- Create: `database/migrations/2026_08_09_300003_create_city_building_instances_table.php`
- Create: `app/Models/City.php`
- Create: `app/Models/CityResource.php`
- Create: `app/Models/CityBuildingInstance.php`
- Test: `tests/Feature/City/CityRuntimeSchemaTest.php`

**Interfaces:**
- Produces:
  - `cities`(id, user_id 唯一 FK, name, revision unsigned bigint default 0, last_simulated_at datetime, money decimal(16,2), population int default 0, map_width int, map_height int, timestamps)
  - `city_resources`(city_id, resource_id, amount decimal(18,4), PK(city_id,resource_id), FK city_id→cities)
  - `city_building_instances`(id, city_id FK, building_id, level tinyint, x int, y int, status varchar(16) default 'active', created_at, updated_at)
  - 模型:`City`(hasMany resources/buildingInstances;belongsTo user)、`CityResource`、`CityBuildingInstance`

- [ ] **Step 1: 写失败测试** `tests/Feature/City/CityRuntimeSchemaTest.php`

```php
<?php

namespace Tests\Feature\City;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CityRuntimeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('cities', ['user_id', 'revision', 'last_simulated_at', 'money', 'population', 'map_width', 'map_height']));
        $this->assertTrue(Schema::hasColumns('city_resources', ['city_id', 'resource_id', 'amount']));
        $this->assertTrue(Schema::hasColumns('city_building_instances', ['city_id', 'building_id', 'level', 'x', 'y', 'status']));
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 写 3 个迁移**

`cities`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 玩家城市 Runtime
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('name', 64);
            $table->unsignedBigInteger('revision')->default(0);
            $table->dateTime('last_simulated_at');
            $table->decimal('money', 16, 2)->default(0);
            $table->integer('population')->default(0);
            $table->integer('map_width');
            $table->integer('map_height');
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }
    public function down(): void { Schema::dropIfExists('cities'); }
};
```

`city_resources`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('city_resources', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id');
            $table->string('resource_id', 32);
            $table->decimal('amount', 18, 4)->default(0);
            $table->primary(['city_id', 'resource_id']);
            $table->foreign('city_id')->references('id')->on('cities');
        });
    }
    public function down(): void { Schema::dropIfExists('city_resources'); }
};
```

`city_building_instances`:
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('city_building_instances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('city_id');
            $table->string('building_id', 16);
            $table->unsignedTinyInteger('level')->default(1);
            $table->integer('x');
            $table->integer('y');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->index('city_id');
            $table->foreign('city_id')->references('id')->on('cities');
        });
    }
    public function down(): void { Schema::dropIfExists('city_building_instances'); }
};
```

- [ ] **Step 4: 写 3 个模型**

`app/Models/City.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 玩家城市
class City extends Model
{
    protected $fillable = ['user_id', 'name', 'revision', 'last_simulated_at', 'money', 'population', 'map_width', 'map_height'];

    protected function casts(): array
    {
        return ['last_simulated_at' => 'datetime', 'money' => 'decimal:2'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function resources(): HasMany { return $this->hasMany(CityResource::class); }
    public function buildingInstances(): HasMany { return $this->hasMany(CityBuildingInstance::class); }
}
```

`app/Models/CityResource.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CityResource extends Model
{
    public $timestamps = false;
    protected $fillable = ['city_id', 'resource_id', 'amount'];
    protected function casts(): array { return ['amount' => 'decimal:4']; }
}
```

`app/Models/CityBuildingInstance.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CityBuildingInstance extends Model
{
    protected $fillable = ['city_id', 'building_id', 'level', 'x', 'y', 'status'];
}
```

- [ ] **Step 5: 跑测试确认通过 + Commit** — `git add database/migrations app/Models tests/Feature/City/CityRuntimeSchemaTest.php && git commit -m "M1P4 城市 Runtime 表与模型"`

---

### Task 2: 常量 + 建城服务(注册即建城,随机初始资源)

**Files:**
- Create: `app/Game/Simulation/SimConstants.php`
- Create: `app/Game/City/CityFactory.php`
- Modify: `app/Http/Controllers/Auth/RegisterController.php`(注册成功后建城)
- Test: `tests/Feature/City/OnboardingTest.php`

**Interfaces:**
- Produces:
  - `App\Game\Simulation\SimConstants`:`FOOD_PER_CAPITA_PER_MIN=0.1`、`BASE_STORAGE=200`、`MAP_W=20`、`MAP_H=20`、`START_POPULATION=10`、`CAPACITY_OUTPUTS=['人口容量','仓储容量','治理容量','运输容量','国防值','贸易容量','金融容量','医疗容量']`、`START_RESOURCES=['木材'=>[200,400],'石料'=>[100,200],'粮食'=>[300,500]]`、`START_MONEY=[200,400]`
  - `App\Game\City\CityFactory::createForUser(User $user): City` — 事务内建城 + 写随机初始资源(区间内随机)+ 设 `last_simulated_at=now`、`population=START_POPULATION`。幂等:若已有城市则直接返回。
- Consumes:`City`、`CityResource`

- [ ] **Step 1: 写失败测试** `tests/Feature/City/OnboardingTest.php`

```php
<?php

namespace Tests\Feature\City;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_city_with_starting_resources(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'username' => 'cityfounder', 'email' => 'c@f.com', 'password' => 'password123',
        ]);
        $res->assertStatus(201);

        $user = User::where('username', 'cityfounder')->first();
        $this->assertDatabaseHas('cities', ['user_id' => $user->id]);
        $city = $user->city ?? \App\Models\City::where('user_id', $user->id)->first();
        $this->assertGreaterThanOrEqual(200, (float) $city->resources()->where('resource_id', '木材')->value('amount'));
        $this->assertSame(10, $city->population);
    }
}
```
（若 User 无 `city` 关系,测试用 City::where 兜底;可在 User 模型加 `public function city(){return $this->hasOne(City::class);}`——本任务顺带加。）

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 写 `SimConstants.php`**(值见 Interfaces)。

- [ ] **Step 4: 写 `CityFactory.php`**

```php
<?php

namespace App\Game\City;

use App\Game\Simulation\SimConstants;
use App\Models\City;
use App\Models\CityResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// 建城:事务内创建城市 + 随机初始资源(幂等)
class CityFactory
{
    public static function createForUser(User $user): City
    {
        $existing = City::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user) {
            $city = City::create([
                'user_id'           => $user->id,
                'name'              => $user->username . '的城市',
                'revision'          => 0,
                'last_simulated_at' => now(),
                'money'             => random_int(SimConstants::START_MONEY[0], SimConstants::START_MONEY[1]),
                'population'        => SimConstants::START_POPULATION,
                'map_width'         => SimConstants::MAP_W,
                'map_height'        => SimConstants::MAP_H,
            ]);

            $rows = [];
            foreach (SimConstants::START_RESOURCES as $resId => [$lo, $hi]) {
                $rows[] = ['city_id' => $city->id, 'resource_id' => $resId, 'amount' => random_int($lo, $hi)];
            }
            CityResource::insert($rows);

            return $city;
        });
    }
}
```

- [ ] **Step 5: 在 `RegisterController` 建城 + User 加 city 关系**

在 `Auth::login($user); $request->session()->regenerate();` 之后、审计之前加:
```php
        \App\Game\City\CityFactory::createForUser($user);
```
在 `app/Models/User.php` 加关系:
```php
    public function city(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\City::class);
    }
```

- [ ] **Step 6: 跑测试确认通过 + Commit** — `git add app/Game app/Http/Controllers/Auth/RegisterController.php app/Models/User.php tests/Feature/City/OnboardingTest.php && git commit -m "M1P4 建城服务与注册建城"`

---

### Task 3: Time Delta 模拟服务(生产/消耗/维护/粮食/存储)

**Files:**
- Create: `app/Game/Simulation/SimulationService.php`
- Test: `tests/Feature/City/SimulationServiceTest.php`

**Interfaces:**
- Consumes:`City`、`CityResource`、`CityBuildingInstance`、`building_level_definition`、`SimConstants`
- Produces:`App\Game\Simulation\SimulationService::simulate(City $city): array`
  - 计算 `elapsed = now - last_simulated_at`(秒);读该城 active 建筑实例,联 `building_level_definition` 得每级 output/input/maintenance;
  - 资源净速率(每分钟)= Σoutput(非容量) − Σinput − 维护对应资源(维护粮食计入粮食支出);另减 人口粮食消耗 `population × FOOD_PER_CAPITA_PER_MIN`(计入粮食);
  - 存储上限 = `BASE_STORAGE + Σ 仓储容量 output`;各资源 `amount = clamp(amount + rate×elapsed/60, 0, 存储上限)`;粮食同样夹 [0, 存储上限];
  - 资金:`money = max(0, money − Σ维护资金/min × elapsed/60)`;
  - 事务内更新 `city_resources`、`cities.money`、`last_simulated_at=now`;**不改 revision**(读结算不算 mutation);
  - 返回 `['ratesPerMin'=>[资源=>净速率], 'storageCapacity'=>..., 'populationCapacity'=>..., 'elapsedSeconds'=>...]` 供快照用。
  - 人口本计划**不增减**(M2)。

- [ ] **Step 1: 写失败测试** `tests/Feature/City/SimulationServiceTest.php`

```php
<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Game\Simulation\SimulationService;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\CityResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // 需要 building_level_definition
    }

    private function makeCity(): City
    {
        $u = User::create(['username' => 'simuser', 'name' => 'simuser', 'email' => 's@s.com', 'password' => 'password123']);
        return CityFactory::createForUser($u);
    }

    public function test_farm_produces_food_over_time(): void
    {
        $city = $this->makeCity();
        // 放一座 F02 基础农田 L1(输出 粮食 14/min),active
        CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active']);
        $foodBefore = (float) CityResource::where('city_id', $city->id)->where('resource_id', '粮食')->value('amount');

        // 把 last_simulated_at 往前拨 60 秒,模拟经过 1 分钟
        $city->update(['last_simulated_at' => now()->subSeconds(60)]);
        SimulationService::simulate($city->fresh());

        $foodAfter = (float) CityResource::where('city_id', $city->id)->where('resource_id', '粮食')->value('amount');
        // 1 分钟:+14 粮食产出 − 人口(10)×0.1×1=1 消耗 = 净 +13(未触顶前)
        $this->assertEqualsWithDelta($foodBefore + 13, $foodAfter, 0.5);
    }

    public function test_food_never_below_zero(): void
    {
        $city = $this->makeCity();
        // 清空粮食,无产出建筑,人口消耗应把粮食夹在 0
        CityResource::where('city_id', $city->id)->where('resource_id', '粮食')->update(['amount' => 0.5]);
        $city->update(['last_simulated_at' => now()->subSeconds(600)]);
        SimulationService::simulate($city->fresh());
        $food = (float) CityResource::where('city_id', $city->id)->where('resource_id', '粮食')->value('amount');
        $this->assertGreaterThanOrEqual(0, $food);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 写 `SimulationService.php`**(核心结算逻辑)

```php
<?php

namespace App\Game\Simulation;

use App\Models\City;
use Illuminate\Support\Facades\DB;

// Time Delta 懒结算:按 now - last_simulated_at 应用生产/消耗/维护/粮食,资源夹在 [0, 存储上限]
class SimulationService
{
    public static function simulate(City $city): array
    {
        $now = now();
        $elapsed = max(0, $now->getTimestamp() - $city->last_simulated_at->getTimestamp());

        // 读 active 建筑实例的每级定义
        $levels = DB::table('city_building_instances as ci')
            ->join('building_level_definition as bl', function ($j) {
                $j->on('ci.building_id', '=', 'bl.building_id')->on('ci.level', '=', 'bl.level');
            })
            ->where('ci.city_id', $city->id)
            ->where('ci.status', 'active')
            ->select('bl.output_json', 'bl.input_json', 'bl.maintenance_money_per_min', 'bl.maintenance_food_per_min')
            ->get();

        $ratePerMin = [];   // 资源 => 每分钟净速率
        $storageCap = SimConstants::BASE_STORAGE;
        $populationCap = 0;
        $maintenanceMoneyPerMin = 0.0;

        foreach ($levels as $lv) {
            foreach (json_decode($lv->output_json ?: '[]', true) as $o) {
                $res = $o['resource']; $r = (float) $o['rate_per_min'];
                if ($res === '仓储容量') { $storageCap += $r; continue; }
                if ($res === '人口容量') { $populationCap += $r; continue; }
                if (in_array($res, SimConstants::CAPACITY_OUTPUTS, true)) { continue; } // 其他容量:M1 不结算
                $ratePerMin[$res] = ($ratePerMin[$res] ?? 0) + $r;
            }
            foreach (json_decode($lv->input_json ?: '[]', true) as $i) {
                $res = $i['resource']; $r = (float) $i['rate_per_min'];
                $ratePerMin[$res] = ($ratePerMin[$res] ?? 0) - $r;
            }
            // 维护粮食计入粮食支出
            $mf = (float) $lv->maintenance_food_per_min;
            if ($mf > 0) { $ratePerMin['粮食'] = ($ratePerMin['粮食'] ?? 0) - $mf; }
            $maintenanceMoneyPerMin += (float) $lv->maintenance_money_per_min;
        }

        // 人口粮食消耗
        $ratePerMin['粮食'] = ($ratePerMin['粮食'] ?? 0) - $city->population * SimConstants::FOOD_PER_CAPITA_PER_MIN;

        if ($elapsed > 0) {
            DB::transaction(function () use ($city, $ratePerMin, $elapsed, $storageCap, $maintenanceMoneyPerMin, $now) {
                $minutes = $elapsed / 60.0;
                $current = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id');

                foreach ($ratePerMin as $res => $rate) {
                    $base = (float) ($current[$res] ?? 0);
                    $val = $base + $rate * $minutes;
                    $val = max(0, min($val, $storageCap));
                    DB::table('city_resources')->updateOrInsert(
                        ['city_id' => $city->id, 'resource_id' => $res],
                        ['amount' => $val]
                    );
                }

                $money = max(0, (float) $city->money - $maintenanceMoneyPerMin * $minutes);
                DB::table('cities')->where('id', $city->id)->update([
                    'money'             => $money,
                    'last_simulated_at' => $now,
                ]);
            });
        }

        return [
            'ratesPerMin'        => $ratePerMin,
            'storageCapacity'    => $storageCap,
            'populationCapacity' => $populationCap,
            'elapsedSeconds'     => $elapsed,
        ];
    }
}
```

- [ ] **Step 4: 跑测试确认通过 + Commit** — `git add app/Game/Simulation/SimulationService.php tests/Feature/City/SimulationServiceTest.php && git commit -m "M1P4 Time Delta 模拟服务"`

---

### Task 4: City Snapshot API(GET /api/city)

**Files:**
- Create: `app/Http/Controllers/City/CityController.php`
- Modify: `routes/web.php`(在 `auth:web` 组内加 `GET /api/city`)
- Test: `tests/Feature/City/SnapshotTest.php`

**Interfaces:**
- Produces:`GET /api/city`(auth:web)→ 先 `SimulationService::simulate`,再返回:
```json
{"success":true,"data":{"city":{"id":1,"name":"...","revision":0,"population":10,"populationCapacity":0,
  "money":300,"mapWidth":20,"mapHeight":20,"storageCapacity":200,"lastSimulatedAt":"ISO",
  "resources":{"木材":300,...},"ratesPerMin":{"粮食":13,...},
  "buildings":[{"id":1,"buildingId":"F02","level":1,"x":1,"y":1,"status":"active"}]}}}
```
未登录 → AUTH_REQUIRED 401(P1 渲染)。

- [ ] **Step 1: 写失败测试** `tests/Feature/City/SnapshotTest.php`

```php
<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_snapshot_requires_auth(): void
    {
        $this->getJson('/api/city')->assertStatus(401)->assertJson(['error' => 'AUTH_REQUIRED']);
    }

    public function test_snapshot_returns_city_state(): void
    {
        $u = User::create(['username' => 'snapuser', 'name' => 'snapuser', 'email' => 'sn@s.com', 'password' => 'password123']);
        CityFactory::createForUser($u);

        $res = $this->actingAs($u)->getJson('/api/city');
        $res->assertOk();
        $res->assertJson(['success' => true, 'data' => ['city' => ['population' => 10, 'mapWidth' => 20]]]);
        $res->assertJsonStructure(['data' => ['city' => ['resources', 'ratesPerMin', 'storageCapacity', 'buildings']]]);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 写 `CityController.php`**

```php
<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Game\Simulation\SimulationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 城市只读快照
class CityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $city = CityFactory::createForUser($user); // 幂等:兜底老账号

        $sim = SimulationService::simulate($city);
        $city = $city->fresh();

        $resources = $city->resources()->pluck('amount', 'resource_id')
            ->map(fn ($a) => (float) $a)->all();

        $buildings = $city->buildingInstances()->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'buildingId' => $b->building_id, 'level' => $b->level,
                'x' => $b->x, 'y' => $b->y, 'status' => $b->status,
            ])->all();

        return ApiResponse::ok(['data' => ['city' => [
            'id'                 => $city->id,
            'name'               => $city->name,
            'revision'           => $city->revision,
            'population'         => $city->population,
            'populationCapacity' => $sim['populationCapacity'],
            'money'              => (float) $city->money,
            'mapWidth'           => $city->map_width,
            'mapHeight'          => $city->map_height,
            'storageCapacity'    => $sim['storageCapacity'],
            'lastSimulatedAt'    => $city->last_simulated_at->toIso8601String(),
            'resources'          => $resources,
            'ratesPerMin'        => $sim['ratesPerMin'],
            'buildings'          => $buildings,
        ]]]);
    }
}
```

- [ ] **Step 4: 路由**(routes/web.php 的 `auth:web` 组内):
```php
        Route::get('/city', [\App\Http\Controllers\City\CityController::class, 'show']);
```

- [ ] **Step 5: 跑测试确认通过 + Commit** — `git add app/Http/Controllers/City tests/Feature/City/SnapshotTest.php routes/web.php && git commit -m "M1P4 City Snapshot API"`

---

### Task 5: 收尾 + 开发库迁移 + 版本

- [ ] **Step 1: 全量测试** 全绿。
- [ ] **Step 2: 迁移开发库 apg**:`"C:/xampp/php/php.exe" artisan migrate --force`(新增 cities 等表)。
- [ ] **Step 3: `php -l` + BOM 抽查** 新增文件。
- [ ] **Step 4: 版本提交** — `git add -A && git commit -m "v0.7.0 M1P4完成:城市 Runtime、快照与 Time Delta 模拟"`

## 自检对照
- Runtime 表 + 模型:T1 ✅ | 注册建城 + 随机初始资源:T2 ✅ | Time Delta 生产/粮食/存储:T3 ✅ | 只读快照 API:T4 ✅
- Ownership:隐式(只操作自己城市)✅

## 不在范围(后续)
- 人口增长/衰减、幸福/治理/物流/电力完整:M2(P6 仅粮食/存储/资金)
- 建造/升级 mutation:P5(本计划只读)
