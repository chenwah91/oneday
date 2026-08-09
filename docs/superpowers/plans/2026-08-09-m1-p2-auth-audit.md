# M1-P2 账号系统(Session Auth)与审计地基 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: subagent-driven-development / executing-plans。步骤用 `- [ ]`。

**Goal:** 用 Laravel Session 认证实现注册(用户名+email+密码+手机选填)、登录(用户名+密码)、登出、当前用户查询,配套失败限流,并落地 `audit_logs` 表与 AuditLogger 审计地基,认证事件写审计。

**Architecture:** 同源 SPA + Laravel Session(Cookie HttpOnly + CSRF)。API 路由在 `web` 中间件组(session/CSRF 生效)。认证走默认 `web` guard。审计 append-only,写 `audit_logs`,`request_id` 取自 P1 的 `Context`。

**Tech Stack:** Laravel 12,MySQL 5.7 兼容(本地 MariaDB),PHPUnit。承接 P1 的 `ApiResponse`/`ErrorCode`/`EnsureRequestId`/统一异常渲染。

## Global Constraints
- PHP 文件 UTF-8 无 BOM,LF,`<?php` 前无字符;中文注释;YAGNI。
- DB:utf8mb4 / snake_case / 时间 UTC;禁窗口函数/CTE/依赖 DB CHECK(MySQL 5.7);JSON 用 Laravel `->json()`。
- 密码用 Laravel `Hash`(bcrypt),不自实现;登录成功 `session()->regenerate()`;登出 `invalidate()+regenerateToken()`。
- 客户端不可信:输入用 FormRequest allowlist 校验;不返回 password_hash。
- 审计禁止 UPDATE/DELETE(append-only);日志/审计中禁止出现密码、token、session id、secret。
- 稳定 ErrorCode;稳定审计 Action Code(`AUTH.*`)。
- 本地命令:PHP=`C:/xampp/php/php.exe`;test=`C:/xampp/php/php.exe artisan test`;mysql=`C:/xampp/mysql/bin/mysql.exe -u root`。
- 交付前 `php -l` 过;子计划末尾跑全量 `artisan test` 全绿;每 Task 末提交,子计划完成带版本号。
- 测试库 `apg_test` 用 `RefreshDatabase`(仅测试库,已批准)。

---

### Task 1: 数据库迁移(users 扩展 + audit_logs)

**Files:**
- Create: `database/migrations/2026_08_09_100001_add_username_phone_to_users.php`
- Create: `database/migrations/2026_08_09_100002_create_audit_logs_table.php`
- Test: `tests/Feature/Auth/SchemaTest.php`

**Interfaces:**
- Produces:`users` 增列 `username`(唯一,可空以兼容旧默认迁移,但注册强制)、`phone`(可空);`audit_logs` 表(SECURITY §54 字段子集)。

- [ ] **Step 1: 写失败测试 `tests/Feature/Auth/SchemaTest.php`**

```php
<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_has_username_and_phone(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['username', 'phone']));
    }

    public function test_audit_logs_table_exists_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'occurred_at', 'request_id', 'actor_type', 'user_id', 'action', 'status',
        ]));
    }
}
```

- [ ] **Step 2: 跑测试确认失败** — `C:/xampp/php/php.exe artisan test --filter=SchemaTest`,期望 FAIL(列/表不存在)。

- [ ] **Step 3: 写 `..._add_username_phone_to_users.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 给 users 增加 username(登录标识)与 phone(选填)
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 32)->nullable()->unique()->after('id');
            $table->string('phone', 20)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'phone']);
        });
    }
};
```

- [ ] **Step 4: 写 `..._create_audit_logs_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 审计日志:append-only,可追溯谁在哪个请求改了什么(SECURITY §54)
return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at', 6);
            $table->char('request_id', 36);
            $table->char('trace_id', 36)->nullable();
            $table->string('idempotency_key', 100)->nullable();

            $table->string('actor_type', 32);           // player | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            $table->string('action', 80);               // AUTH.LOGIN_SUCCESS 等稳定码
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();

            $table->unsignedBigInteger('city_revision_before')->nullable();
            $table->unsignedBigInteger('city_revision_after')->nullable();

            $table->string('status', 24);               // success | failed | rejected
            $table->string('reason_code', 80)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->char('user_agent_hash', 64)->nullable();

            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('delta_json')->nullable();
            $table->json('metadata_json')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('request_id', 'idx_audit_request');
            $table->index(['user_id', 'occurred_at'], 'idx_audit_user_time');
            $table->index(['action', 'occurred_at'], 'idx_audit_action_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
```

- [ ] **Step 5: 跑测试确认通过** — `artisan test --filter=SchemaTest`,期望 2 PASS。

- [ ] **Step 6: Commit** — `git add database/migrations tests/Feature/Auth/SchemaTest.php && git commit -m "M1P2 迁移:users 扩展与 audit_logs 表"`

---

### Task 2: 审计地基(AuditAction 常量 + AuditLogger 服务)

**Files:**
- Create: `app/Support/AuditAction.php`
- Create: `app/Support/AuditLogger.php`
- Test: `tests/Feature/Auth/AuditLoggerTest.php`

**Interfaces:**
- Produces:
  - `App\Support\AuditAction` 常量:`AUTH_REGISTER='AUTH.REGISTER'`、`AUTH_LOGIN_SUCCESS='AUTH.LOGIN_SUCCESS'`、`AUTH_LOGIN_FAILED='AUTH.LOGIN_FAILED'`、`AUTH_LOGOUT='AUTH.LOGOUT'`
  - `App\Support\AuditLogger::record(string $action, string $status, array $attrs = []): void` — 写一条审计;自动填 `occurred_at`(now 微秒)、`request_id`(Context)、`ip_address`、`user_agent_hash`(sha256(UA))。`$attrs` 可含 `actor_type/actor_id/user_id/city_id/entity_type/entity_id/reason_code/before_json/after_json/delta_json/metadata_json`。默认 `actor_type='player'`。绝不写入密码/token。

- [ ] **Step 1: 写失败测试 `tests/Feature/Auth/AuditLoggerTest.php`**

```php
<?php

namespace Tests\Feature\Auth;

use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_writes_row_with_request_id_and_action(): void
    {
        AuditLogger::record(AuditAction::AUTH_LOGIN_FAILED, 'failed', [
            'reason_code' => 'BAD_CREDENTIALS',
            'metadata_json' => ['username' => 'someone'],
        ]);

        $row = DB::table('audit_logs')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('AUTH.LOGIN_FAILED', $row->action);
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->request_id);
        $this->assertNotNull($row->occurred_at);
    }
}
```

- [ ] **Step 2: 跑测试确认失败** — `artisan test --filter=AuditLoggerTest`,期望 FAIL(类不存在)。

- [ ] **Step 3: 写 `app/Support/AuditAction.php`**

```php
<?php

namespace App\Support;

// 稳定审计 Action Code(进入生产后保持稳定)
final class AuditAction
{
    public const AUTH_REGISTER = 'AUTH.REGISTER';
    public const AUTH_LOGIN_SUCCESS = 'AUTH.LOGIN_SUCCESS';
    public const AUTH_LOGIN_FAILED = 'AUTH.LOGIN_FAILED';
    public const AUTH_LOGOUT = 'AUTH.LOGOUT';
}
```

- [ ] **Step 4: 写 `app/Support/AuditLogger.php`**

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

// 审计写入(append-only)。自动补全时间/请求ID/IP/UA哈希;绝不记录密码或凭证明文。
final class AuditLogger
{
    public static function record(string $action, string $status, array $attrs = []): void
    {
        $request = request();
        $ua = $request?->userAgent();

        DB::table('audit_logs')->insert([
            'occurred_at'          => now()->format('Y-m-d H:i:s.u'),
            'request_id'           => Context::get('request_id'),
            'trace_id'             => $attrs['trace_id'] ?? null,
            'idempotency_key'      => $attrs['idempotency_key'] ?? null,
            'actor_type'           => $attrs['actor_type'] ?? 'player',
            'actor_id'             => $attrs['actor_id'] ?? null,
            'user_id'              => $attrs['user_id'] ?? null,
            'city_id'              => $attrs['city_id'] ?? null,
            'action'               => $action,
            'entity_type'          => $attrs['entity_type'] ?? null,
            'entity_id'            => $attrs['entity_id'] ?? null,
            'city_revision_before' => $attrs['city_revision_before'] ?? null,
            'city_revision_after'  => $attrs['city_revision_after'] ?? null,
            'status'               => $status,
            'reason_code'          => $attrs['reason_code'] ?? null,
            'ip_address'           => $request?->ip(),
            'user_agent_hash'      => $ua ? hash('sha256', $ua) : null,
            'before_json'          => isset($attrs['before_json']) ? json_encode($attrs['before_json'], JSON_UNESCAPED_UNICODE) : null,
            'after_json'           => isset($attrs['after_json']) ? json_encode($attrs['after_json'], JSON_UNESCAPED_UNICODE) : null,
            'delta_json'           => isset($attrs['delta_json']) ? json_encode($attrs['delta_json'], JSON_UNESCAPED_UNICODE) : null,
            'metadata_json'        => isset($attrs['metadata_json']) ? json_encode($attrs['metadata_json'], JSON_UNESCAPED_UNICODE) : null,
            'created_at'           => now(),
        ]);
    }
}
```

- [ ] **Step 5: 跑测试确认通过** — `artisan test --filter=AuditLoggerTest`,期望 PASS。

- [ ] **Step 6: Commit** — `git add app/Support/AuditAction.php app/Support/AuditLogger.php tests/Feature/Auth/AuditLoggerTest.php && git commit -m "M1P2 审计地基:AuditAction 与 AuditLogger"`

---

### Task 3: User 模型更新

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Auth/UserModelTest.php`

**Interfaces:**
- Produces:`User` 的 `$fillable` 含 `username,name,email,phone,password`;`password` 有 `hashed` cast;`$hidden` 含 `password,remember_token`。

- [ ] **Step 1: 写失败测试 `tests/Feature/Auth/UserModelTest.php`**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_creates_with_username_phone_and_hashes_password(): void
    {
        $user = User::create([
            'username' => 'zhangsan',
            'name'     => 'zhangsan',
            'email'    => 'z@example.com',
            'phone'    => '0123456789',
            'password' => 'password123',
        ]);

        $this->assertSame('zhangsan', $user->username);
        $this->assertNotSame('password123', $user->password); // 已哈希
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password123', $user->password));
        $this->assertArrayNotHasKey('password', $user->toArray()); // hidden
    }
}
```

- [ ] **Step 2: 跑测试确认失败** — 期望 FAIL(username 不可填充或 password 未哈希)。

- [ ] **Step 3: 修改 `app/Models/User.php`**(读取现有文件,把 `$fillable`/`$hidden`/`casts()` 改为):

```php
    protected $fillable = [
        'username',
        'name',
        'email',
        'phone',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
```

- [ ] **Step 4: 跑测试确认通过** — 期望 PASS。

- [ ] **Step 5: Commit** — `git add app/Models/User.php tests/Feature/Auth/UserModelTest.php && git commit -m "M1P2 User 模型:username/phone 与密码哈希"`

---

### Task 4: 注册接口(TDD)

**Files:**
- Create: `app/Http/Requests/Auth/RegisterRequest.php`
- Create: `app/Http/Controllers/Auth/RegisterController.php`
- Modify: `routes/web.php`(加 `POST /api/auth/register`,在既有 api 分组内,带 `throttle:api`)
- Test: `tests/Feature/Auth/RegisterTest.php`

**Interfaces:**
- Consumes:`User`、`AuditLogger`、`AuditAction`、`ApiResponse`、`ErrorCode`
- Produces:`POST /api/auth/register` body `{username,email,password,phone?}` → 201 `{success:true,data:{user:{id,username,email}}}`,自动登录(session);校验失败经 P1 异常渲染返回 `VALIDATION_ERROR`(422)。写 `AUTH.REGISTER` 审计。

- [ ] **Step 1: 写失败测试 `tests/Feature/Auth/RegisterTest.php`**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_succeeds_and_logs_in(): void
    {
        $res = $this->postJson('/api/auth/register', [
            'username' => 'wangwu',
            'email'    => 'w@example.com',
            'password' => 'password123',
            'phone'    => '0111222333',
        ]);

        $res->assertStatus(201);
        $res->assertJson(['success' => true, 'data' => ['user' => ['username' => 'wangwu']]]);
        $res->assertJsonMissingPath('data.user.password');
        $this->assertDatabaseHas('users', ['username' => 'wangwu', 'email' => 'w@example.com']);
        $this->assertAuthenticated();
        $this->assertSame('AUTH.REGISTER', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_register_requires_valid_input(): void
    {
        $this->postJson('/api/auth/register', ['username' => 'ab', 'email' => 'bad', 'password' => 'short'])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'error' => 'VALIDATION_ERROR']);
    }

    public function test_register_rejects_duplicate_username(): void
    {
        User::create(['username' => 'dup', 'name' => 'dup', 'email' => 'a@a.com', 'password' => 'password123']);

        $this->postJson('/api/auth/register', [
            'username' => 'dup', 'email' => 'b@b.com', 'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_phone_is_optional(): void
    {
        $this->postJson('/api/auth/register', [
            'username' => 'nophone', 'email' => 'n@n.com', 'password' => 'password123',
        ])->assertStatus(201);
    }
}
```

- [ ] **Step 2: 跑测试确认失败** — 期望 FAIL(路由 404)。

- [ ] **Step 3: 写 `app/Http/Requests/Auth/RegisterRequest.php`**

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

// 注册输入校验(allowlist)
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 用户名 3-20:字母数字下划线或中文
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', 'unique:users,username'],
            'email'    => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'phone'    => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]{6,20}$/'],
        ];
    }
}
```

- [ ] **Step 4: 写 `app/Http/Controllers/Auth/RegisterController.php`**

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

// 注册:创建账号并自动登录(session)
class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'username' => $data['username'],
            'name'     => $data['username'],
            'email'    => $data['email'],
            'phone'    => $data['phone'] ?? null,
            'password' => $data['password'], // 模型 hashed cast 自动哈希
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        AuditLogger::record(AuditAction::AUTH_REGISTER, 'success', [
            'actor_id' => $user->id,
            'user_id'  => $user->id,
            'entity_type' => 'user',
            'entity_id'   => (string) $user->id,
        ]);

        return ApiResponse::ok([
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]],
        ], 201);
    }
}
```

- [ ] **Step 5: 在 `routes/web.php` 的 api 分组内加路由**(与 `/health` 同组,带 `throttle:api`):

```php
    Route::post('/auth/register', \App\Http\Controllers\Auth\RegisterController::class);
```

- [ ] **Step 6: 跑测试确认通过** — `artisan test --filter=RegisterTest`,期望 4 PASS。

- [ ] **Step 7: Commit** — `git add app/Http/Requests/Auth app/Http/Controllers/Auth/RegisterController.php routes/web.php tests/Feature/Auth/RegisterTest.php && git commit -m "M1P2 注册接口"`

---

### Task 5: 登录接口与失败限流(TDD)

**Files:**
- Create: `app/Http/Controllers/Auth/LoginController.php`
- Modify: `app/Providers/AppServiceProvider.php`(加 `auth` 限流器:每 用户名+IP 每分钟 5 次)
- Modify: `routes/web.php`(加 `POST /api/auth/login`,带 `throttle:auth`)
- Test: `tests/Feature/Auth/LoginTest.php`

**Interfaces:**
- Consumes:`Auth`、`AuditLogger`、`ApiResponse`、`ErrorCode`
- Produces:`POST /api/auth/login` body `{username,password}` → 200 `{success:true,data:{user:{...}}}`(session regenerate);失败 401 `BAD_CREDENTIALS`;限流触发经 P1 渲染 `TOO_MANY_REQUESTS`(429)。审计 `AUTH.LOGIN_SUCCESS`/`AUTH.LOGIN_FAILED`。

- [ ] **Step 1: 写失败测试 `tests/Feature/Auth/LoginTest.php`**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'username' => 'loginuser', 'name' => 'loginuser',
            'email' => 'l@example.com', 'password' => 'password123',
        ]);
    }

    public function test_login_succeeds(): void
    {
        $this->makeUser();
        $res = $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'password123']);

        $res->assertOk();
        $res->assertJson(['success' => true, 'data' => ['user' => ['username' => 'loginuser']]]);
        $this->assertAuthenticated();
        $this->assertSame('AUTH.LOGIN_SUCCESS', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeUser();
        $res = $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'wrongpass']);

        $res->assertStatus(401);
        $res->assertJson(['success' => false, 'error' => 'BAD_CREDENTIALS']);
        $this->assertGuest();
        $this->assertSame('AUTH.LOGIN_FAILED', DB::table('audit_logs')->latest('id')->first()->action);
    }

    public function test_login_is_rate_limited_after_5_failures(): void
    {
        $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'wrongpass']);
        }
        $res = $this->postJson('/api/auth/login', ['username' => 'loginuser', 'password' => 'password123']);
        $res->assertStatus(429);
        $res->assertJson(['success' => false, 'error' => 'TOO_MANY_REQUESTS']);
    }
}
```

- [ ] **Step 2: 跑测试确认失败** — 期望 FAIL(路由 404)。

- [ ] **Step 3: 在 `AppServiceProvider::boot()` 加 `auth` 限流器**

```php
        // 登录限流:同一 用户名+IP 每分钟 5 次
        \Illuminate\Support\Facades\RateLimiter::for('auth', function (\Illuminate\Http\Request $request) {
            $key = strtolower((string) $request->input('username')) . '|' . $request->ip();
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($key);
        });
```

- [ ] **Step 4: 写 `app/Http/Controllers/Auth/LoginController.php`**

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// 登录:用户名 + 密码,成功后重建 session
class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['username' => $credentials['username'], 'password' => $credentials['password']])) {
            AuditLogger::record(AuditAction::AUTH_LOGIN_FAILED, 'failed', [
                'reason_code'   => 'BAD_CREDENTIALS',
                'metadata_json' => ['username' => $credentials['username']],
            ]);

            return ApiResponse::fail(ErrorCode::BAD_CREDENTIALS, 401);
        }

        $request->session()->regenerate();
        $user = Auth::user();

        AuditLogger::record(AuditAction::AUTH_LOGIN_SUCCESS, 'success', [
            'actor_id' => $user->id,
            'user_id'  => $user->id,
        ]);

        return ApiResponse::ok([
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]],
        ]);
    }
}
```

- [ ] **Step 5: 在 `app/Support/ErrorCode.php` 追加 `BAD_CREDENTIALS`**

```php
    public const BAD_CREDENTIALS = 'BAD_CREDENTIALS';
```

- [ ] **Step 6: 在 `routes/web.php` api 分组内加登录路由(带更严限流)**

```php
    Route::post('/auth/login', \App\Http\Controllers\Auth\LoginController::class)->middleware('throttle:auth');
```

- [ ] **Step 7: 跑测试确认通过** — `artisan test --filter=LoginTest`,期望 3 PASS。

- [ ] **Step 8: Commit** — `git add app/Http/Controllers/Auth/LoginController.php app/Support/ErrorCode.php app/Providers/AppServiceProvider.php routes/web.php tests/Feature/Auth/LoginTest.php && git commit -m "M1P2 登录接口与失败限流"`

---

### Task 6: 登出、当前用户、CSRF Cookie(TDD)

**Files:**
- Create: `app/Http/Controllers/Auth/SessionController.php`
- Modify: `routes/web.php`(加 `POST /api/auth/logout`、`GET /api/me`(均 `auth:web`)、`GET /api/csrf-cookie`)
- Test: `tests/Feature/Auth/SessionTest.php`

**Interfaces:**
- Produces:
  - `POST /api/auth/logout`(auth:web)→ 登出并失效 session,审计 `AUTH.LOGOUT`
  - `GET /api/me`(auth:web)→ `{success:true,data:{user:{id,username,email}}}`;未登录经 P1 渲染 `AUTH_REQUIRED`(401)
  - `GET /api/csrf-cookie` → 204(触发 `XSRF-TOKEN` cookie 下发,供 SPA 首次取用)

- [ ] **Step 1: 写失败测试 `tests/Feature/Auth/SessionTest.php`**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')
            ->assertStatus(401)
            ->assertJson(['success' => false, 'error' => 'AUTH_REQUIRED']);
    }

    public function test_me_returns_current_user_when_authenticated(): void
    {
        $user = User::create(['username' => 'meuser', 'name' => 'meuser', 'email' => 'me@e.com', 'password' => 'password123']);

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => ['user' => ['username' => 'meuser']]]);
    }

    public function test_logout_ends_session(): void
    {
        $user = User::create(['username' => 'logoutuser', 'name' => 'logoutuser', 'email' => 'lo@e.com', 'password' => 'password123']);

        $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();
    }

    public function test_csrf_cookie_endpoint_returns_204(): void
    {
        $this->get('/api/csrf-cookie')->assertNoContent();
    }
}
```

- [ ] **Step 2: 跑测试确认失败** — 期望 FAIL(路由 404)。

- [ ] **Step 3: 写 `app/Http/Controllers/Auth/SessionController.php`**

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

// 会话相关:当前用户 / 登出 / CSRF cookie
class SessionController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::ok([
            'data' => ['user' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email]],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        AuditLogger::record(AuditAction::AUTH_LOGOUT, 'success', [
            'actor_id' => $userId,
            'user_id'  => $userId,
        ]);

        return ApiResponse::ok(['data' => ['loggedOut' => true]]);
    }

    // 空响应,借 web 中间件下发 XSRF-TOKEN cookie
    public function csrfCookie(): Response
    {
        return response()->noContent();
    }
}
```

- [ ] **Step 4: 在 `routes/web.php` api 分组内加路由**

```php
    Route::get('/csrf-cookie', [\App\Http\Controllers\Auth\SessionController::class, 'csrfCookie']);
    Route::middleware('auth:web')->group(function () {
        Route::get('/me', [\App\Http\Controllers\Auth\SessionController::class, 'me']);
        Route::post('/auth/logout', [\App\Http\Controllers\Auth\SessionController::class, 'logout']);
    });
```

- [ ] **Step 5: 跑测试确认通过** — `artisan test --filter=SessionTest`,期望 4 PASS。

- [ ] **Step 6: Commit** — `git add app/Http/Controllers/Auth/SessionController.php routes/web.php tests/Feature/Auth/SessionTest.php && git commit -m "M1P2 登出/当前用户/CSRF cookie"`

---

### Task 7: 收尾——全量测试、语法、编码、版本提交

- [ ] **Step 1: 全量测试** — `C:/xampp/php/php.exe artisan test`,期望全绿(P1 的 13 + P2 新增)。
- [ ] **Step 2: `php -l`** 我们新增的 PHP 文件,全部 `No syntax errors`。
- [ ] **Step 3: BOM 抽查** 新增 PHP 文件无 `ef bb bf`。
- [ ] **Step 4: 手动冒烟(可选)** 起 `artisan serve`,`curl` 走一遍 csrf-cookie→register→me→logout。
- [ ] **Step 5: 版本提交** — `git add -A && git commit -m "v0.5.0 M1P2完成:Session 认证与审计地基"`

## 自检对照
- 注册(用户名/email/密码/手机选填):Task 4 ✅
- 登录(用户名+密码)+ 失败限流:Task 5 ✅
- 登出 / 当前用户 / CSRF cookie:Task 6 ✅
- Session 重建、Cookie 由 Laravel `web` 组管理:Task 4/5 ✅
- audit_logs + AuditLogger + AUTH.* 审计:Task 1/2,并在 4/5/6 写入 ✅
- 不返回密码、审计不含凭证:Task 3/2 ✅

## 不在范围(后续)
- 真正的 Authorization Policy(城市 Ownership)→ P5(有城市后)
- game_data_versions / definitions → P3
