# M1-P1 Laravel 骨架与安全中间件地基 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把项目搭成一个可运行的 Laravel 12 应用,建立请求 ID 追踪、统一 JSON 响应、稳定错误码与生产错误隐藏三件安全地基,并让 `php artisan test` 一键跑通。

**Architecture:** Laravel 12(精简结构:中间件与异常在 `bootstrap/app.php` 注册,无 `Http/Kernel.php`)。本地开发连 MariaDB,生产连 MySQL 5.7(双环境差异记入 `docs/ops/db-mariadb-vs-mysql57.md`)。所有 `/api/*` 请求带 `X-Request-ID`;异常在 `api/*` 一律返回稳定 JSON,生产不外泄细节。共享支撑类放 `app/Support`;业务模块 `app/Game/*` 按需在后续子计划创建(本计划不预建空目录)。

**Tech Stack:** PHP 8.2(本地 XAMPP)、Laravel 12、MariaDB 10.4(本地)、Composer(项目内 `composer.phar` 2.10.2)、PHPUnit(Laravel 默认)。

## Global Constraints

- 本地命令前缀:PHP = `C:/xampp/php/php.exe`;Composer = `C:/xampp/php/php.exe composer.phar`;Artisan = `C:/xampp/php/php.exe artisan`;MySQL 客户端 = `C:/xampp/mysql/bin/mysql.exe -u root`(无密码);MariaDB 需已启动(`mysqld`)。
- 所有 PHP 文件:UTF-8 无 BOM,LF 换行,`<?php` 前无任何字符。
- 所有代码注释一律用中文。
- 数据库:字符集 `utf8mb4`,排序规则显式 `utf8mb4_unicode_ci`;表名/字段名 snake_case;时间统一存 UTC。
- ⚠️ 即使本地 MariaDB 支持,也**禁用窗口函数、CTE、依赖 DB 层 CHECK 约束**(MySQL 5.7 不支持/不强制)——见 `docs/ops/db-mariadb-vs-mysql57.md`。
- 认证走 Laravel Session(P2 实现);本计划只搭地基,不实现登录。
- 交付任何 PHP 文件前 `C:/xampp/php/php.exe -l <file>` 通过;子计划结束跑 `artisan test` 全绿。
- 稳定错误码集中在 `App\Support\ErrorCode`,进入生产后保持稳定(CLAUDE §32)。
- ⚠️ 测试连接使用 `apg_test` 库;`RefreshDatabase` 会清空重建该测试库的表(仅测试库,已获用户批准)。
- git 提交:中文简短说明;每个 Task 末尾提交,子计划完成带小版本号。

---

### Task 1: 安装 Laravel 12 到现有项目

把 Laravel 12 脚手架并入已有仓库(含 `docs/`、`CLAUDE.md`、`SECURITY.md`、`.gitattributes`、`composer.phar`、`.git`),不破坏这些既有文件。

**Files:**
- Create: 整个 Laravel 骨架(`artisan`、`app/`、`bootstrap/`、`config/`、`database/`、`public/`、`resources/`、`routes/`、`storage/`、`tests/`、`composer.json`、`composer.lock`、`phpunit.xml`、`.env`、`.env.example`、`.editorconfig` 等)
- Modify: `.gitignore`(用 Laravel 版覆盖后,补回 `/composer.phar`)

**Interfaces:**
- Produces: 可运行的 Laravel 应用;`C:/xampp/php/php.exe artisan` 可用;默认 `tests/` 通过。

- [ ] **Step 1: 用 composer 在临时目录创建 Laravel 12**

Run:
```bash
cd "C:/Users/User/Documents/apg"
"C:/xampp/php/php.exe" composer.phar create-project "laravel/laravel:^12.0" _laravel_tmp --no-interaction
```
Expected: `_laravel_tmp/` 生成,末尾出现 `Application ready!` 之类成功信息;`_laravel_tmp/artisan` 存在。

- [ ] **Step 2: 把骨架文件(含隐藏文件)移入项目根,再删临时目录**

Run:
```bash
cd "C:/Users/User/Documents/apg"
shopt -s dotglob
mv -f _laravel_tmp/* .
shopt -u dotglob
rmdir _laravel_tmp
```
Expected: 项目根出现 `artisan`、`app/`、`routes/`、`.env` 等;`_laravel_tmp` 已删除。既有 `docs/`、`CLAUDE.md`、`SECURITY.md`、`.gitattributes`、`composer.phar`、`.git` 仍在。

- [ ] **Step 3: 补回 `.gitignore` 对 composer.phar 的忽略**

Laravel 的 `.gitignore` 覆盖了我们的版本,把工具本体忽略补回。在 `.gitignore` 末尾追加:
```gitignore

# 项目内 Composer 工具(不入库)
/composer.phar
```

- [ ] **Step 4: 验证 artisan 与默认测试**

Run:
```bash
cd "C:/Users/User/Documents/apg"
"C:/xampp/php/php.exe" artisan --version
"C:/xampp/php/php.exe" artisan test
```
Expected: 打印 `Laravel Framework 12.x.x`;默认 `Tests: X passed`(Laravel 自带 ExampleTest 通过,DB 尚未配置也应通过,因默认示例测试不碰 DB)。

- [ ] **Step 5: 确认无 BOM(Laravel 文件本应无 BOM,抽查关键文件)**

Run:
```bash
cd "C:/Users/User/Documents/apg"
for f in artisan bootstrap/app.php config/app.php; do head -c 3 "$f" | od -An -tx1; done
```
Expected: 每行不以 `ef bb bf` 开头(即无 BOM)。

- [ ] **Step 6: Commit**

Run:
```bash
cd "C:/Users/User/Documents/apg"
git add -A
git commit -m "M1P1 安装 Laravel 12 骨架"
```

---

### Task 2: 环境配置(MariaDB 连接 / UTC / 建库 / 测试库)

**Files:**
- Modify: `.env`
- Modify: `.env.example`
- Modify: `config/app.php`(时区)
- Modify: `phpunit.xml`(测试连 MariaDB `apg_test`、`APP_DEBUG=false`)
- 创建数据库 `apg`(开发)与 `apg_test`(测试)

**Interfaces:**
- Consumes: Laravel 骨架
- Produces: `config('app.timezone') === 'UTC'`;开发连 `apg`、测试连 `apg_test`;`artisan migrate` 可跑通默认迁移。

- [ ] **Step 1: 创建本地数据库(MariaDB)**

Run:
```bash
"C:/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS apg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS apg_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; SHOW DATABASES LIKE 'apg%';"
```
Expected: 列出 `apg` 与 `apg_test`。

- [ ] **Step 2: 配置 `.env` 数据库与时区**

把 `.env` 中数据库与时区相关行改为(其余保持 Laravel 生成的默认,`APP_KEY` 不要动):
```dotenv
APP_TIMEZONE=UTC

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=apg
DB_USERNAME=root
DB_PASSWORD=
```

- [ ] **Step 3: 同步 `.env.example`(不含真实密钥,供他人复制)**

把 `.env.example` 中对应行改为与上一步相同(`DB_PASSWORD=` 留空,不写真实值;`APP_KEY=` 留空)。

- [ ] **Step 4: 确认时区配置**

`config/app.php` 的 `'timezone'` 应读取 env。确认其为:
```php
    'timezone' => env('APP_TIMEZONE', 'UTC'),
```
若 Laravel 默认是 `'UTC'` 硬编码,改成上面这行以让 `.env` 生效。

- [ ] **Step 5: 让测试连 MariaDB 测试库并关调试**

编辑 `phpunit.xml`,在 `<php>` 段内确保有(存在则改值,注释掉的 SQLite 行删除或注释):
```xml
        <env name="APP_ENV" value="testing"/>
        <env name="APP_DEBUG" value="false"/>
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_DATABASE" value="apg_test"/>
```

- [ ] **Step 6: 跑默认迁移验证连接(开发库)**

Run:
```bash
cd "C:/Users/User/Documents/apg"
"C:/xampp/php/php.exe" artisan migrate --force
"C:/xampp/mysql/bin/mysql.exe" -u root apg -e "SHOW TABLES;"
```
Expected: 迁移成功;`apg` 库出现 `users`、`migrations`、`cache`、`jobs` 等 Laravel 默认表。

- [ ] **Step 7: 写并跑一个配置健全性测试**

Create `tests/Feature/ConfigTest.php`(继承 `Tests\TestCase` 以启动 Laravel,断言解析后的配置):
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

// 环境配置健全性:时区必须 UTC
class ConfigTest extends TestCase
{
    public function test_app_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }
}
```

Run:
```bash
"C:/xampp/php/php.exe" artisan test --filter=ConfigTest
```
Expected: PASS。

- [ ] **Step 8: Commit**

Run:
```bash
cd "C:/Users/User/Documents/apg"
git add .env.example config/app.php phpunit.xml tests/Feature/ConfigTest.php
git commit -m "M1P1 环境配置:MariaDB 连接、UTC 时区、测试库"
```
(注:`.env` 已被 `.gitignore` 忽略,不入库。)

---

### Task 3: 稳定错误码体系 + 统一 JSON 响应(TDD)

**Files:**
- Create: `app/Support/ErrorCode.php`
- Create: `app/Support/ApiResponse.php`
- Test: `tests/Unit/ApiResponseTest.php`

**Interfaces:**
- Consumes: Laravel 骨架、`Illuminate\Support\Facades\Context`
- Produces:
  - `App\Support\ErrorCode` — 稳定错误码常量:`INTERNAL_ERROR`、`VALIDATION_ERROR`、`AUTH_REQUIRED`、`NOT_FOUND`、`TOO_MANY_REQUESTS`(游戏业务码后续子计划追加)
  - `App\Support\ApiResponse::ok(array $data = [], int $status = 200): JsonResponse` — 输出 `{"success":true, ...$data}`
  - `App\Support\ApiResponse::fail(string $error, int $status = 400, array $extra = []): JsonResponse` — 输出 `{"success":false,"error":<code>,"requestId":<id>, ...$extra}`(`requestId` 取自 `Context::get('request_id')`,无则 `null`)

- [ ] **Step 1: 写失败测试 `tests/Feature/ApiResponseTest.php`**

`ApiResponse` 用到 `response()` 与 `Context` 门面,需启动 Laravel,故继承 `Tests\TestCase`:
```php
<?php

namespace Tests\Feature;

use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Tests\TestCase;

// ApiResponse 统一响应格式测试
class ApiResponseTest extends TestCase
{
    public function test_ok_wraps_data_with_success_true(): void
    {
        $res = ApiResponse::ok(['data' => ['status' => 'ok']]);
        $payload = json_decode($res->getContent(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('ok', $payload['data']['status']);
    }

    public function test_fail_includes_error_and_request_id_key(): void
    {
        $res = ApiResponse::fail(ErrorCode::NOT_FOUND, 404);
        $payload = json_decode($res->getContent(), true);

        $this->assertSame(404, $res->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('NOT_FOUND', $payload['error']);
        $this->assertArrayHasKey('requestId', $payload);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `"C:/xampp/php/php.exe" artisan test --filter=ApiResponseTest`
Expected: 失败,报 `Class "App\Support\ApiResponse" not found` 或 `ErrorCode not found`。

- [ ] **Step 3: 写 `app/Support/ErrorCode.php`**

```php
<?php

namespace App\Support;

// 稳定错误码:进入生产后保持不变(CLAUDE §32)。游戏业务码由后续子计划追加。
final class ErrorCode
{
    // 基础设施 / 通用
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const NOT_FOUND = 'NOT_FOUND';
    public const TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';
}
```

- [ ] **Step 4: 写 `app/Support/ApiResponse.php`**

```php
<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

// 统一 JSON 响应:成功 {success:true,...data};失败 {success:false,error,requestId,...extra}
final class ApiResponse
{
    // 成功响应:data 数组直接并入顶层(如 ['data'=>...]、['revision'=>...])
    public static function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true] + $data, $status);
    }

    // 失败响应:带稳定错误码与请求 ID,便于玩家截图后追查
    public static function fail(string $error, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json([
            'success'   => false,
            'error'     => $error,
            'requestId' => Context::get('request_id'),
        ] + $extra, $status);
    }
}
```

- [ ] **Step 5: 跑测试确认通过**

Run: `"C:/xampp/php/php.exe" artisan test --filter=ApiResponseTest`
Expected: 2 个测试 PASS。

- [ ] **Step 6: Commit**

Run:
```bash
cd "C:/Users/User/Documents/apg"
git add app/Support/ErrorCode.php app/Support/ApiResponse.php tests/Feature/ApiResponseTest.php
git commit -m "M1P1 稳定错误码与统一 JSON 响应"
```

---

### Task 4: Request ID 中间件(TDD)

每个请求生成/透传 `request_id`,写入 `Context`(供日志关联)并回写 `X-Request-ID` 响应头。

**Files:**
- Create: `app/Http/Middleware/EnsureRequestId.php`
- Modify: `bootstrap/app.php`(全局注册中间件)
- Modify: `routes/web.php`(加一个探针路由供测试)
- Test: `tests/Feature/RequestIdTest.php`

**Interfaces:**
- Consumes: `App\Support\ApiResponse`
- Produces:
  - 中间件 `App\Http\Middleware\EnsureRequestId`(全局)
  - 每个响应含 `X-Request-ID` 头;`Context::get('request_id')` 在请求周期内可用
  - 探针路由 `GET /api/_ping` → `ApiResponse::ok(['data' => ['pong' => true]])`

- [ ] **Step 1: 写失败测试 `tests/Feature/RequestIdTest.php`**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

// Request ID 中间件测试
class RequestIdTest extends TestCase
{
    public function test_response_has_request_id_header(): void
    {
        $res = $this->getJson('/api/_ping');

        $res->assertOk();
        $res->assertHeader('X-Request-ID');
        // 未带 X-Request-ID 时服务器应生成一个 UUID(36 位含连字符)
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $res->headers->get('X-Request-ID')
        );
    }

    public function test_incoming_request_id_is_preserved(): void
    {
        $res = $this->getJson('/api/_ping', ['X-Request-ID' => 'test-fixed-id-123']);

        $res->assertHeader('X-Request-ID', 'test-fixed-id-123');
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `"C:/xampp/php/php.exe" artisan test --filter=RequestIdTest`
Expected: 失败(路由 `/api/_ping` 不存在 → 404,或缺少 `X-Request-ID` 头)。

- [ ] **Step 3: 写中间件 `app/Http/Middleware/EnsureRequestId.php`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// 请求 ID:透传或生成 UUID,写入 Context 供日志关联,并回写响应头
class EnsureRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-ID');
        $requestId = ($incoming !== null && $incoming !== '') ? $incoming : (string) Str::uuid();

        // 供本次请求内的日志与 ApiResponse 读取
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
```

- [ ] **Step 4: 全局注册中间件(`bootstrap/app.php`)**

在 `->withMiddleware(function (Middleware $middleware) { ... })` 内追加(把请求 ID 加到全局链最前,确保后续都能读到):
```php
        $middleware->prepend(\App\Http\Middleware\EnsureRequestId::class);
```

- [ ] **Step 5: 加探针路由(`routes/web.php`)**

在文件末尾追加:
```php
use App\Support\ApiResponse;

// 基础设施探针(供中间件/健康检查测试)
Route::prefix('api')->group(function () {
    Route::get('/_ping', fn () => ApiResponse::ok(['data' => ['pong' => true]]));
});
```

- [ ] **Step 6: 跑测试确认通过**

Run: `"C:/xampp/php/php.exe" artisan test --filter=RequestIdTest`
Expected: 2 个测试 PASS。

- [ ] **Step 7: Commit**

Run:
```bash
cd "C:/Users/User/Documents/apg"
git add app/Http/Middleware/EnsureRequestId.php bootstrap/app.php routes/web.php tests/Feature/RequestIdTest.php
git commit -m "M1P1 Request ID 中间件"
```

---

### Task 5: 统一异常渲染 + 生产错误隐藏(TDD)

`api/*` 上的任何异常都返回稳定 JSON(带 requestId),生产环境(`APP_DEBUG=false`)不暴露异常信息与堆栈;完整异常写日志。

**Files:**
- Modify: `bootstrap/app.php`(`->withExceptions(...)` 渲染逻辑)
- Modify: `routes/web.php`(仅测试环境的抛错探针路由)
- Test: `tests/Feature/ExceptionRenderTest.php`

**Interfaces:**
- Consumes: `App\Support\ApiResponse`、`App\Support\ErrorCode`
- Produces:
  - `api/*` 未捕获异常 → `{"success":false,"error":"INTERNAL_ERROR","requestId":...}`,HTTP 500,`APP_DEBUG=false` 时不含异常消息/堆栈
  - `ValidationException` → `VALIDATION_ERROR` + `errors`,HTTP 422
  - `NotFoundHttpException` → `NOT_FOUND`,HTTP 404
  - 仅测试环境路由 `GET /api/_boom`(抛 `RuntimeException`)

- [ ] **Step 1: 写失败测试 `tests/Feature/ExceptionRenderTest.php`**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

// 异常渲染与错误隐藏测试(phpunit.xml 已设 APP_DEBUG=false)
class ExceptionRenderTest extends TestCase
{
    public function test_unhandled_api_exception_is_hidden_and_stable(): void
    {
        $res = $this->getJson('/api/_boom');

        $res->assertStatus(500);
        $res->assertJson(['success' => false, 'error' => 'INTERNAL_ERROR']);
        $res->assertJsonStructure(['success', 'error', 'requestId']);

        // 不得泄露异常原文与堆栈
        $body = $res->getContent();
        $this->assertStringNotContainsString('boom', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
    }

    public function test_not_found_api_route_returns_stable_json(): void
    {
        $res = $this->getJson('/api/_definitely_missing');

        $res->assertStatus(404);
        $res->assertJson(['success' => false, 'error' => 'NOT_FOUND']);
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `"C:/xampp/php/php.exe" artisan test --filter=ExceptionRenderTest`
Expected: 失败(`/api/_boom` 不存在 → 404 而非 500;或缺省 Laravel 404 页非我们的 JSON 结构)。

- [ ] **Step 3: 加仅测试环境的抛错探针路由(`routes/web.php`)**

在文件末尾的 `Route::prefix('api')->group(...)` 之外追加:
```php
// 仅测试环境:用于验证异常渲染,绝不在生产暴露
if (app()->environment('testing')) {
    Route::get('/api/_boom', function () {
        throw new \RuntimeException('boom');
    });
}
```

- [ ] **Step 4: 在 `bootstrap/app.php` 写异常渲染**

在 `->withExceptions(function (Exceptions $exceptions) { ... })` 内追加(文件顶部按需 `use` 相关类):
```php
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            // 只接管 api/* 或期望 JSON 的请求;其余走 Laravel 默认
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return \App\Support\ApiResponse::fail(
                    \App\Support\ErrorCode::VALIDATION_ERROR,
                    422,
                    ['errors' => $e->errors()]
                );
            }

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return \App\Support\ApiResponse::fail(\App\Support\ErrorCode::AUTH_REQUIRED, 401);
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
                || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return \App\Support\ApiResponse::fail(\App\Support\ErrorCode::NOT_FOUND, 404);
            }

            if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
                return \App\Support\ApiResponse::fail(\App\Support\ErrorCode::TOO_MANY_REQUESTS, 429);
            }

            // 其余未知异常:写日志,对外只给稳定错误码(生产隐藏细节)
            \Illuminate\Support\Facades\Log::error($e->getMessage(), ['exception' => $e]);

            return \App\Support\ApiResponse::fail(\App\Support\ErrorCode::INTERNAL_ERROR, 500);
        });
```

- [ ] **Step 5: 跑测试确认通过**

Run: `"C:/xampp/php/php.exe" artisan test --filter=ExceptionRenderTest`
Expected: 2 个测试 PASS。

- [ ] **Step 6: Commit**

Run:
```bash
cd "C:/Users/User/Documents/apg"
git add bootstrap/app.php routes/web.php tests/Feature/ExceptionRenderTest.php
git commit -m "M1P1 统一异常渲染与生产错误隐藏"
```

---

### Task 6: 健康检查路由 + 限流地基(TDD)

正式的 `/api/health` 端点,并给 `api` 分组挂上命名限流器(为 P2 登录限流铺路)。

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`(定义 `api` 限流器)
- Modify: `routes/web.php`(health 路由 + 给 api 分组挂 `throttle:api`)
- Test: `tests/Feature/HealthTest.php`

**Interfaces:**
- Consumes: `App\Support\ApiResponse`
- Produces:
  - `GET /api/health` → `{"success":true,"data":{"status":"ok","serverTime":<ISO8601>}}`,含 `X-Request-ID`
  - 命名限流器 `api`(每 IP 每分钟 60 次)

- [ ] **Step 1: 写失败测试 `tests/Feature/HealthTest.php`**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

// 健康检查端点测试
class HealthTest extends TestCase
{
    public function test_health_returns_ok_with_request_id(): void
    {
        $res = $this->getJson('/api/health');

        $res->assertOk();
        $res->assertJson(['success' => true, 'data' => ['status' => 'ok']]);
        $res->assertJsonStructure(['success', 'data' => ['status', 'serverTime']]);
        $res->assertHeader('X-Request-ID');
    }
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `"C:/xampp/php/php.exe" artisan test --filter=HealthTest`
Expected: 失败(`/api/health` 不存在 → 404)。

- [ ] **Step 3: 定义 `api` 限流器(`app/Providers/AppServiceProvider.php`)**

在 `boot()` 方法内追加(文件顶部按需 `use`):
```php
        // api 分组基础限流:每 IP 每分钟 60 次(登录等更严的限流在 P2 单独定义)
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->ip());
        });
```

- [ ] **Step 4: 加 health 路由并给 api 分组挂限流(`routes/web.php`)**

把 Task 4 中新增的 `Route::prefix('api')->group(...)` 改为带 `throttle:api`,并加入 health 路由:
```php
Route::prefix('api')->middleware('throttle:api')->group(function () {
    Route::get('/_ping', fn () => ApiResponse::ok(['data' => ['pong' => true]]));

    Route::get('/health', fn () => ApiResponse::ok([
        'data' => [
            'status'     => 'ok',
            'serverTime' => now()->toIso8601String(),
        ],
    ]));
});
```

- [ ] **Step 5: 跑测试确认通过**

Run: `"C:/xampp/php/php.exe" artisan test --filter=HealthTest`
Expected: PASS。

- [ ] **Step 6: Commit**

Run:
```bash
cd "C:/Users/User/Documents/apg"
git add app/Providers/AppServiceProvider.php routes/web.php tests/Feature/HealthTest.php
git commit -m "M1P1 健康检查端点与 api 限流地基"
```

---

### Task 7: 收尾——全量测试、语法检查、版本提交

- [ ] **Step 1: 全量测试**

Run:
```bash
cd "C:/Users/User/Documents/apg"
"C:/xampp/php/php.exe" artisan test
```
Expected: 全绿(ConfigTest、ApiResponseTest、RequestIdTest、ExceptionRenderTest、HealthTest 及 Laravel 默认示例测试全部 PASS)。

- [ ] **Step 2: 语法检查我们新增的文件**

Run:
```bash
cd "C:/Users/User/Documents/apg"
for f in app/Support/ErrorCode.php app/Support/ApiResponse.php app/Http/Middleware/EnsureRequestId.php bootstrap/app.php app/Providers/AppServiceProvider.php routes/web.php; do "C:/xampp/php/php.exe" -l "$f"; done
```
Expected: 每个 `No syntax errors detected`。

- [ ] **Step 3: BOM 抽查(新增 PHP 文件不应带 BOM)**

Run:
```bash
cd "C:/Users/User/Documents/apg"
for f in app/Support/ErrorCode.php app/Support/ApiResponse.php app/Http/Middleware/EnsureRequestId.php; do head -c 3 "$f" | od -An -tx1; done
```
Expected: 无 `ef bb bf`。

- [ ] **Step 4: 手动冒烟(可选,起本地服务器验证 health)**

Run:
```bash
cd "C:/Users/User/Documents/apg"
"C:/xampp/php/php.exe" artisan serve --port=8123 &
sleep 3
curl.exe -s "http://127.0.0.1:8123/api/health" -i | head -20
```
Expected:HTTP 200;响应头含 `X-Request-ID`;body 为 `{"success":true,"data":{"status":"ok","serverTime":"..."}}`。验证后停掉:`Get-Process php | Where-Object { $_.CommandLine -like '*artisan serve*' } | Stop-Process`(或结束该进程)。

- [ ] **Step 5: 版本提交**

Run:
```bash
cd "C:/Users/User/Documents/apg"
git add -A
git commit -m "v0.4.0 M1P1完成:Laravel 骨架与安全中间件地基"
```

---

## 自检对照(spec 覆盖)

- Laravel 12 + 本地 MariaDB 连接 + UTC:Task 1、2 ✅
- Request ID(X-Request-ID,Context 日志关联):Task 4 ✅
- 统一 JSON 响应 + 稳定错误码(CLAUDE §32):Task 3 ✅
- 生产错误隐藏(§78,无 Stack Trace 外泄):Task 5 ✅
- 会话/CSRF:Laravel `web` 中间件组默认启用(路由在 `web.php`,GET 探针不触发 CSRF;POST 的 CSRF 在 P2 登录时验证)✅
- 限流地基(§48):Task 6 ✅
- 测试框架一键跑通:Task 1、7 ✅
- 双库差异约束:Global Constraints 引用 `docs/ops/db-mariadb-vs-mysql57.md` ✅

## 不在本计划范围(后续子计划)

- 注册/登录/登出、Session 认证细节、`audit_logs` 表 → P2
- `app/Game/*` 各业务模块目录 → 首次使用它们的子计划创建
- Definition 迁移与 Seed → P3
