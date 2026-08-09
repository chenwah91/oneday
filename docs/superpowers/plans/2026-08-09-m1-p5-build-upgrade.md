# M1-P5 建造/升级/拆除(全安全链)实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: subagent-driven-development。步骤用 `- [ ]`。

**Goal:** 实现建造、升级(L1→L2→L3)、拆除三个经济 Mutation,走完整安全链:输入校验 → 所有权 → 幂等 → Revision → 游戏规则(占地/数量上限/资源足额)→ 事务+行锁 → 扣资源建实体 → 不变量 → 审计 → 返回 Diff。闭合"建农田→粮食转正→养人口"核心循环。

**Architecture:** 单城隐式归属(操作 `Auth::user()->city`);建造/升级/拆除即时生效(施工计时留 M2);M1 **不做科技/时代门槛**(建造仅受成本/占地/上限/资源约束,科技研究门槛留 M2)。幂等表 `idempotency_keys`;City `revision` 每次成功 Mutation +1。

**Tech Stack:** Laravel 12,MySQL 5.7 兼容。承接 P4 城市 Runtime/模拟。

## Global Constraints
- PHP UTF-8 无 BOM,LF,`<?php` 首;中文注释;YAGNI。
- 服务器权威:客户端只发意图 `{buildingId,x,y}`;成本/产出由服务器从定义读,不信客户端。
- 事务 + `lockForUpdate` 锁城市;不变量失败回滚;审计带 requestId/actor/delta/revision。
- 稳定 ErrorCode;稳定 Audit Action(BUILDING.BUILD/UPGRADE/DEMOLISH、SECURITY.AUTHORIZATION_FAILED)。
- DB:禁窗口函数/CTE;金额/资源 DECIMAL;UTC。
- 本地:PHP=`C:/xampp/php/php.exe`;test=`C:/xampp/php/php.exe artisan test`。测试库 RefreshDatabase。

---

### Task 1: 幂等表 + 建造(全安全链)

**Files:**
- Create: `database/migrations/2026_08_09_400001_create_idempotency_keys_table.php`
- Create: `app/Support/AuditAction.php`(追加建造类 action;若已存在则 append 常量)
- Modify: `app/Support/ErrorCode.php`(追加游戏错误码)
- Create: `app/Game/Building/BuildService.php`
- Create: `app/Http/Controllers/City/BuildController.php`
- Modify: `routes/web.php`(auth:web 组内加 `POST /api/city/build`)
- Test: `tests/Feature/City/BuildTest.php`

**Interfaces:**
- Produces:
  - 表 `idempotency_keys`(id, user_id, key, action, response_status, created_at, expires_at, unique(user_id,key))
  - ErrorCode 追加:`INSUFFICIENT_RESOURCE`、`BUILDING_LIMIT_REACHED`、`INVALID_POSITION`、`LAND_OCCUPIED`、`REVISION_CONFLICT`、`INVALID_BUILDING`
  - AuditAction 追加:`BUILDING_BUILD='BUILDING.BUILD'`、`BUILDING_UPGRADE='BUILDING.UPGRADE'`、`BUILDING_DEMOLISH='BUILDING.DEMOLISH'`、`SECURITY_AUTHORIZATION_FAILED='SECURITY.AUTHORIZATION_FAILED'`
  - `App\Game\Building\BuildService::build(City $city, string $buildingId, int $x, int $y, ?string $idempotencyKey, ?int $expectedRevision): array`
    - 成功返回 `['revision'=>int,'resources'=>[资源=>新值],'building'=>[...],'delta'=>[资源=>负值]]`
    - 失败抛 `App\Game\Building\GameRuleException`(带 errorCode + status),由 P1 异常渲染... 见下(用专用异常类,在 bootstrap 渲染或控制器捕获)。

- [ ] **Step 1: 写失败测试** `tests/Feature/City/BuildTest.php`

```php
<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function actingUser(): User
    {
        $u = User::create(['username' => 'builder', 'name' => 'builder', 'email' => 'b@b.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        // 给足资源以便建造 F02(木材20/石料5/资金12)
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => '木材'], ['amount' => 1000]);
        DB::table('city_resources')->updateOrInsert(['city_id' => $city->id, 'resource_id' => '石料'], ['amount' => 1000]);
        return $u;
    }

    public function test_build_succeeds_and_deducts_and_increments_revision(): void
    {
        $u = $this->actingUser();
        $res = $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 2, 'y' => 2]);

        $res->assertOk();
        $res->assertJson(['success' => true, 'data' => ['revision' => 1]]);
        $city = City::where('user_id', $u->id)->first();
        $this->assertSame(1, (int) $city->revision);
        $this->assertDatabaseHas('city_building_instances', ['city_id' => $city->id, 'building_id' => 'F02', 'x' => 2, 'y' => 2]);
        $wood = (float) DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', '木材')->value('amount');
        $this->assertSame(980.0, $wood); // 1000 - 20
        $this->assertSame('BUILDING.BUILD', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_build_rejects_insufficient_resources(): void
    {
        $u = User::create(['username' => 'poor', 'name' => 'poor', 'email' => 'p@p.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 0]);

        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 1, 'y' => 1])
            ->assertStatus(422)->assertJson(['error' => 'INSUFFICIENT_RESOURCE']);
        $this->assertDatabaseMissing('city_building_instances', ['city_id' => $city->id]);
    }

    public function test_build_rejects_occupied_land(): void
    {
        $u = $this->actingUser();
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 2, 'y' => 2])->assertOk();
        // 与已建重叠(F02 占 3x3,在 2,2)
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 3, 'y' => 3])
            ->assertStatus(422)->assertJson(['error' => 'LAND_OCCUPIED']);
    }

    public function test_build_is_idempotent(): void
    {
        $u = $this->actingUser();
        $body = ['buildingId' => 'F02', 'x' => 5, 'y' => 5, 'idempotencyKey' => 'fixed-key-1'];
        $this->actingAs($u)->postJson('/api/city/build', $body)->assertOk();
        $this->actingAs($u)->postJson('/api/city/build', $body)->assertOk(); // 重复不再扣/不再建
        $city = City::where('user_id', $u->id)->first();
        $count = DB::table('city_building_instances')->where('city_id', $city->id)->where('x', 5)->where('y', 5)->count();
        $this->assertSame(1, $count);
    }

    public function test_build_revision_conflict(): void
    {
        $u = $this->actingUser();
        $this->actingAs($u)->postJson('/api/city/build', ['buildingId' => 'F02', 'x' => 8, 'y' => 8, 'expectedRevision' => 999])
            ->assertStatus(409)->assertJson(['error' => 'REVISION_CONFLICT']);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 追加 ErrorCode 常量**(见 Interfaces),追加 AuditAction 常量(见 Interfaces)。

- [ ] **Step 4: 写 `app/Game/Building/GameRuleException.php`**

```php
<?php

namespace App\Game\Building;

use RuntimeException;

// 游戏规则/安全校验失败:带稳定错误码与 HTTP 状态,供控制器统一转 ApiResponse
class GameRuleException extends RuntimeException
{
    public function __construct(public string $errorCode, public int $status = 422)
    {
        parent::__construct($errorCode);
    }
}
```

- [ ] **Step 5: 写迁移 `idempotency_keys`**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 幂等键:同一用户同一 key 的经济操作只执行一次
return new class extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('key', 100);
            $table->string('action', 80);
            $table->integer('response_status')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->dateTime('expires_at')->nullable();
            $table->unique(['user_id', 'key']);
        });
    }
    public function down(): void { Schema::dropIfExists('idempotency_keys'); }
};
```

- [ ] **Step 6: 写 `app/Game/Building/BuildService.php`**

```php
<?php

namespace App\Game\Building;

use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

// 建造:完整安全链(幂等/Revision/占地/上限/资源/事务/审计)
class BuildService
{
    public static function build(City $city, string $buildingId, int $x, int $y, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        // 幂等:同一 user+key 已处理则直接成功返回(不重复扣建)
        if ($idempotencyKey !== null) {
            $existing = DB::table('idempotency_keys')->where('user_id', $city->user_id)->where('key', $idempotencyKey)->first();
            if ($existing) {
                return self::snapshotDiff($city->fresh());
            }
        }

        $def = DB::table('building_definition')->where('building_id', $buildingId)->first();
        if (! $def) {
            throw new GameRuleException(ErrorCode::INVALID_BUILDING, 422);
        }
        $lvl = DB::table('building_level_definition')->where('building_id', $buildingId)->where('level', 1)->first();
        $cost = json_decode($lvl->cost_json, true) ?: [];

        return DB::transaction(function () use ($city, $def, $buildingId, $x, $y, $cost, $idempotencyKey, $expectedRevision) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();

            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            // 占地:落在地图内
            $w = (int) $def->footprint_w; $h = (int) $def->footprint_h;
            if ($x < 0 || $y < 0 || $x + $w > $locked->map_width || $y + $h > $locked->map_height) {
                throw new GameRuleException(ErrorCode::INVALID_POSITION, 422);
            }

            // 占地:与现有建筑不重叠(矩形相交)
            $others = DB::table('city_building_instances as ci')
                ->join('building_definition as bd', 'ci.building_id', '=', 'bd.building_id')
                ->where('ci.city_id', $city->id)
                ->select('ci.x', 'ci.y', 'bd.footprint_w', 'bd.footprint_h')->get();
            foreach ($others as $o) {
                if ($x < $o->x + $o->footprint_w && $x + $w > $o->x && $y < $o->y + $o->footprint_h && $y + $h > $o->y) {
                    throw new GameRuleException(ErrorCode::LAND_OCCUPIED, 422);
                }
            }

            // 数量上限
            $count = DB::table('city_building_instances')->where('city_id', $city->id)->where('building_id', $buildingId)->count();
            if ($count >= (int) $def->max_count) {
                throw new GameRuleException(ErrorCode::BUILDING_LIMIT_REACHED, 422);
            }

            // 资源足额(资金单列在 cities.money)
            $resAmounts = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id');
            foreach ($cost as $res => $amt) {
                if ($res === '资金') {
                    if ((float) $locked->money < $amt) { throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422); }
                } elseif ((float) ($resAmounts[$res] ?? 0) < $amt) {
                    throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
                }
            }

            // 扣资源
            $delta = [];
            foreach ($cost as $res => $amt) {
                if ($res === '资金') {
                    DB::table('cities')->where('id', $city->id)->decrement('money', $amt);
                } else {
                    DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $res)->decrement('amount', $amt);
                }
                $delta[$res] = -$amt;
            }

            // 建实体
            $instanceId = DB::table('city_building_instances')->insertGetId([
                'city_id' => $city->id, 'building_id' => $buildingId, 'level' => 1,
                'x' => $x, 'y' => $y, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // 不变量:资源不为负(扣前已校验,双保险)
            $neg = DB::table('city_resources')->where('city_id', $city->id)->where('amount', '<', 0)->count();
            if ($neg > 0 || (float) DB::table('cities')->where('id', $city->id)->value('money') < 0) {
                throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422);
            }

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            if ($idempotencyKey !== null) {
                DB::table('idempotency_keys')->insert([
                    'user_id' => $city->user_id, 'key' => $idempotencyKey, 'action' => AuditAction::BUILDING_BUILD,
                    'response_status' => 200, 'created_at' => now(),
                ]);
            }

            AuditLogger::record(AuditAction::BUILDING_BUILD, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'delta_json' => $delta, 'idempotency_key' => $idempotencyKey,
                'metadata_json' => ['buildingId' => $buildingId, 'x' => $x, 'y' => $y],
            ]);

            return self::snapshotDiff($city->fresh(), $delta);
        });
    }

    // 返回资源/revision 简要 diff
    private static function snapshotDiff(City $city, array $delta = []): array
    {
        return [
            'revision'  => (int) $city->revision,
            'resources' => DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
            'money'     => (float) $city->money,
            'delta'     => $delta,
        ];
    }
}
```

- [ ] **Step 7: 写 `app/Http/Controllers/City/BuildController.php`**

```php
<?php

namespace App\Http\Controllers\City;

use App\Game\Building\BuildService;
use App\Game\Building\GameRuleException;
use App\Game\City\CityFactory;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// 建造入口:校验意图 → BuildService → 统一响应
class BuildController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'buildingId'       => ['required', 'string', 'max:16'],
            'x'                => ['required', 'integer', 'min:0', 'max:999'],
            'y'                => ['required', 'integer', 'min:0', 'max:999'],
            'idempotencyKey'   => ['nullable', 'string', 'max:100'],
            'expectedRevision' => ['nullable', 'integer'],
        ]);

        $city = CityFactory::createForUser($request->user());

        try {
            $diff = BuildService::build(
                $city, $data['buildingId'], (int) $data['x'], (int) $data['y'],
                $data['idempotencyKey'] ?? null,
                isset($data['expectedRevision']) ? (int) $data['expectedRevision'] : null
            );
        } catch (GameRuleException $e) {
            return ApiResponse::fail($e->errorCode, $e->status);
        }

        return ApiResponse::ok(['data' => $diff]);
    }
}
```

- [ ] **Step 8: 路由**(routes/web.php 的 auth:web 组内):
```php
        Route::post('/city/build', \App\Http\Controllers\City\BuildController::class)->middleware('throttle:api');
```

- [ ] **Step 9: 跑测试确认通过 + Commit** — `git add database/migrations app/Game/Building app/Support/ErrorCode.php app/Support/AuditAction.php app/Http/Controllers/City/BuildController.php routes/web.php tests/Feature/City/BuildTest.php && git commit -m "M1P5 建造(全安全链:幂等/Revision/占地/上限/资源/审计)"`

---

### Task 2: 升级(L1→L2→L3)+ 所有权

**Files:**
- Create: `app/Game/Building/UpgradeService.php`
- Create: `app/Http/Controllers/City/UpgradeController.php`
- Modify: `routes/web.php`(`POST /api/city/upgrade`)
- Test: `tests/Feature/City/UpgradeTest.php`

**Interfaces:**
- Produces:`POST /api/city/upgrade {instanceId, idempotencyKey?, expectedRevision?}`
  - 所有权:实例不属于本城 → 403 FORBIDDEN(审计 SECURITY.AUTHORIZATION_FAILED);不存在 → NOT_FOUND
  - 规则:level<3;下一级 cost 足额;扣资源;level++;审计 BUILDING.UPGRADE;revision++
  - `UpgradeService::upgrade(City $city, int $instanceId, ...): array`

- [ ] **Step 1: 写失败测试** `tests/Feature/City/UpgradeTest.php`

```php
<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\City;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    private function makeUserWithFarm(string $un): array
    {
        $u = User::create(['username' => $un, 'name' => $un, 'email' => "$un@x.com", 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        DB::table('city_resources')->where('city_id', $city->id)->update(['amount' => 100000]);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;
        return [$u, $city, $id];
    }

    public function test_upgrade_l1_to_l2_to_l3(): void
    {
        [$u, $city, $id] = $this->makeUserWithFarm('upgrader');
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instanceId' => $id])->assertOk();
        $this->assertSame(2, (int) CityBuildingInstance::find($id)->level);
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instanceId' => $id])->assertOk();
        $this->assertSame(3, (int) CityBuildingInstance::find($id)->level);
        // L3 已满级,再升级被拒
        $this->actingAs($u)->postJson('/api/city/upgrade', ['instanceId' => $id])->assertStatus(422);
    }

    public function test_cannot_upgrade_another_players_building(): void
    {
        [$ua, $ca, $ida] = $this->makeUserWithFarm('ownerA');
        $ub = User::create(['username' => 'attackerB', 'name' => 'attackerB', 'email' => 'atb@x.com', 'password' => 'password123']);
        CityFactory::createForUser($ub);

        $this->actingAs($ub)->postJson('/api/city/upgrade', ['instanceId' => $ida])
            ->assertStatus(403)->assertJson(['error' => 'FORBIDDEN']);
        // A 的建筑未被改动
        $this->assertSame(1, (int) CityBuildingInstance::find($ida)->level);
        $this->assertSame('SECURITY.AUTHORIZATION_FAILED', DB::table('audit_logs')->latest('id')->first()->action);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 写 `UpgradeService.php`**

```php
<?php

namespace App\Game\Building;

use App\Models\City;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

// 升级:L1→L2→L3,即时生效;严格所有权校验
class UpgradeService
{
    public static function upgrade(City $city, int $instanceId, ?string $idempotencyKey, ?int $expectedRevision): array
    {
        // 所有权:先全局查实例
        $inst = DB::table('city_building_instances')->where('id', $instanceId)->first();
        if (! $inst) {
            throw new GameRuleException(ErrorCode::NOT_FOUND, 404);
        }
        if ((int) $inst->city_id !== (int) $city->id) {
            AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'reason_code' => 'NOT_OWNER',
            ]);
            throw new GameRuleException(ErrorCode::FORBIDDEN, 403);
        }

        if ((int) $inst->level >= 3) {
            throw new GameRuleException(ErrorCode::BUILDING_LIMIT_REACHED, 422);
        }

        $nextLevel = (int) $inst->level + 1;
        $lvl = DB::table('building_level_definition')->where('building_id', $inst->building_id)->where('level', $nextLevel)->first();
        $cost = json_decode($lvl->cost_json, true) ?: [];

        return DB::transaction(function () use ($city, $inst, $instanceId, $nextLevel, $cost, $expectedRevision, $idempotencyKey) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();
            if ($expectedRevision !== null && (int) $locked->revision !== $expectedRevision) {
                throw new GameRuleException(ErrorCode::REVISION_CONFLICT, 409);
            }

            $resAmounts = DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id');
            foreach ($cost as $res => $amt) {
                $have = $res === '资金' ? (float) $locked->money : (float) ($resAmounts[$res] ?? 0);
                if ($have < $amt) { throw new GameRuleException(ErrorCode::INSUFFICIENT_RESOURCE, 422); }
            }

            $delta = [];
            foreach ($cost as $res => $amt) {
                if ($res === '资金') { DB::table('cities')->where('id', $city->id)->decrement('money', $amt); }
                else { DB::table('city_resources')->where('city_id', $city->id)->where('resource_id', $res)->decrement('amount', $amt); }
                $delta[$res] = -$amt;
            }

            DB::table('city_building_instances')->where('id', $instanceId)->update(['level' => $nextLevel, 'updated_at' => now()]);

            $newRevision = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $newRevision]);

            AuditLogger::record(AuditAction::BUILDING_UPGRADE, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $newRevision,
                'before_json' => ['level' => $nextLevel - 1], 'after_json' => ['level' => $nextLevel], 'delta_json' => $delta,
            ]);

            return [
                'revision'  => $newRevision,
                'building'  => ['id' => $instanceId, 'level' => $nextLevel],
                'resources' => DB::table('city_resources')->where('city_id', $city->id)->pluck('amount', 'resource_id')->map(fn ($a) => (float) $a)->all(),
                'money'     => (float) DB::table('cities')->where('id', $city->id)->value('money'),
                'delta'     => $delta,
            ];
        });
    }
}
```

- [ ] **Step 4: 写 `UpgradeController.php`**(结构同 BuildController,校验 `instanceId` required integer,调用 UpgradeService,捕获 GameRuleException):

```php
<?php

namespace App\Http\Controllers\City;

use App\Game\Building\GameRuleException;
use App\Game\Building\UpgradeService;
use App\Game\City\CityFactory;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpgradeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instanceId'       => ['required', 'integer'],
            'idempotencyKey'   => ['nullable', 'string', 'max:100'],
            'expectedRevision' => ['nullable', 'integer'],
        ]);

        $city = CityFactory::createForUser($request->user());

        try {
            $diff = UpgradeService::upgrade($city, (int) $data['instanceId'], $data['idempotencyKey'] ?? null, isset($data['expectedRevision']) ? (int) $data['expectedRevision'] : null);
        } catch (GameRuleException $e) {
            return ApiResponse::fail($e->errorCode, $e->status);
        }

        return ApiResponse::ok(['data' => $diff]);
    }
}
```

- [ ] **Step 5: 路由 + 跑测试通过 + Commit**
```php
        Route::post('/city/upgrade', \App\Http\Controllers\City\UpgradeController::class)->middleware('throttle:api');
```
`git add app/Game/Building/UpgradeService.php app/Http/Controllers/City/UpgradeController.php routes/web.php tests/Feature/City/UpgradeTest.php && git commit -m "M1P5 升级 L1→L2→L3 与所有权校验"`

---

### Task 3: 拆除 + 收尾 + 版本

**Files:**
- Create: `app/Http/Controllers/City/DemolishController.php`(内联简单逻辑或 DemolishService)
- Modify: `routes/web.php`(`POST /api/city/demolish`)
- Test: `tests/Feature/City/DemolishTest.php`

**Interfaces:**
- `POST /api/city/demolish {instanceId}` — 所有权校验(非本城 403 + 审计);删除实例;审计 BUILDING.DEMOLISH;revision++。M1 不返还资源。

- [ ] **Step 1: 写失败测试**(拆自己建筑成功;拆别人 403;拆后 revision+1、实例消失)。参照 UpgradeTest 结构写 `DemolishTest`:
```php
<?php

namespace Tests\Feature\City;

use App\Game\City\CityFactory;
use App\Models\CityBuildingInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(); }

    public function test_demolish_own_building(): void
    {
        $u = User::create(['username' => 'razer', 'name' => 'razer', 'email' => 'r@z.com', 'password' => 'password123']);
        $city = CityFactory::createForUser($u);
        $id = CityBuildingInstance::create(['city_id' => $city->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;

        $this->actingAs($u)->postJson('/api/city/demolish', ['instanceId' => $id])->assertOk();
        $this->assertDatabaseMissing('city_building_instances', ['id' => $id]);
        $this->assertSame('BUILDING.DEMOLISH', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_cannot_demolish_others_building(): void
    {
        $ua = User::create(['username' => 'da', 'name' => 'da', 'email' => 'da@x.com', 'password' => 'password123']);
        $ca = CityFactory::createForUser($ua);
        $id = CityBuildingInstance::create(['city_id' => $ca->id, 'building_id' => 'F02', 'level' => 1, 'x' => 1, 'y' => 1, 'status' => 'active'])->id;
        $ub = User::create(['username' => 'db', 'name' => 'db', 'email' => 'db@x.com', 'password' => 'password123']);
        CityFactory::createForUser($ub);

        $this->actingAs($ub)->postJson('/api/city/demolish', ['instanceId' => $id])->assertStatus(403);
        $this->assertDatabaseHas('city_building_instances', ['id' => $id]);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

- [ ] **Step 3: 写 `DemolishController.php`**

```php
<?php

namespace App\Http\Controllers\City;

use App\Game\City\CityFactory;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 拆除:所有权校验 + 删除实例 + 审计(M1 不返还资源)
class DemolishController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['instanceId' => ['required', 'integer']]);
        $city = CityFactory::createForUser($request->user());
        $instanceId = (int) $data['instanceId'];

        $inst = DB::table('city_building_instances')->where('id', $instanceId)->first();
        if (! $inst) {
            return ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        }
        if ((int) $inst->city_id !== (int) $city->id) {
            AuditLogger::record(AuditAction::SECURITY_AUTHORIZATION_FAILED, 'rejected', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId, 'reason_code' => 'NOT_OWNER',
            ]);
            return ApiResponse::fail(ErrorCode::FORBIDDEN, 403);
        }

        $newRevision = DB::transaction(function () use ($city, $instanceId, $inst) {
            $locked = DB::table('cities')->where('id', $city->id)->lockForUpdate()->first();
            DB::table('city_building_instances')->where('id', $instanceId)->delete();
            $rev = (int) $locked->revision + 1;
            DB::table('cities')->where('id', $city->id)->update(['revision' => $rev]);
            AuditLogger::record(AuditAction::BUILDING_DEMOLISH, 'success', [
                'actor_id' => $city->user_id, 'user_id' => $city->user_id, 'city_id' => $city->id,
                'entity_type' => 'building', 'entity_id' => (string) $instanceId,
                'city_revision_before' => (int) $locked->revision, 'city_revision_after' => $rev,
                'metadata_json' => ['buildingId' => $inst->building_id],
            ]);
            return $rev;
        });

        return ApiResponse::ok(['data' => ['revision' => $newRevision, 'demolishedId' => $instanceId]]);
    }
}
```

- [ ] **Step 4: 路由 + 跑测试通过 + Commit**
```php
        Route::post('/city/demolish', \App\Http\Controllers\City\DemolishController::class)->middleware('throttle:api');
```
`git add app/Http/Controllers/City/DemolishController.php routes/web.php tests/Feature/City/DemolishTest.php && git commit -m "M1P5 拆除与所有权校验"`

- [ ] **Step 5: 全量测试 + 迁移开发库 + 版本**
```bash
"C:/xampp/php/php.exe" artisan test
"C:/xampp/php/php.exe" artisan migrate --force
git add -A && git commit -m "v0.8.0 M1P5完成:建造/升级/拆除全安全链"
```

## 自检对照(§15 覆盖)
- 建造升级:T1/T2 ✅ | 上限 BUILDING_LIMIT_REACHED:T1 ✅ | 幂等建造:T1 ✅ | Revision 冲突:T1 ✅ | Ownership 403:T2/T3 ✅ | 事务回滚(不足即拒不建):T1 ✅ | Audit(delta+revision):T1/T2/T3 ✅
- 科技前置 TECH_NOT_UNLOCKED:M2(科技研究实现后)

## 不在范围
- 施工/升级计时(即时生效);科技/时代门槛;拆除返还资源 → M2
