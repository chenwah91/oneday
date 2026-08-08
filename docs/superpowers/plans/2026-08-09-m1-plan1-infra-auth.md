# M1-P1 基础设施与账号系统 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 搭起 PHP API 项目骨架(配置/数据库/日志/响应/路由),实现注册、登录、token 认证,并建立可一键运行的 PHP 断言测试框架。

**Architecture:** 前后端分离;`public/` 是 Web 根,`app/` 在 Web 根之外放业务代码;PHP 单入口 `public/api/index.php?r=路由`;token 认证(数据库存 sha256 hash);所有时间存 UTC。

**Tech Stack:** PHP 8.2(本地 XAMPP)/ 8.3(线上),MariaDB,PDO(具名占位符,EMULATE_PREPARES=true),无框架无 Composer。

## Global Constraints

- 所有 PHP 文件:UTF-8 无 BOM,LF 换行,`<?php` 前无任何字符
- 所有代码注释一律用中文
- PDO 具名占位符;同一条 SQL 中具名占位符不重复使用
- 表名/字段名 snake_case;字符集 utf8mb4
- 密码 bcrypt;错误写日志文件不外显(`app_debug` 控制)
- 交付任何 PHP 文件前必须 `php -l` 通过
- 本地 PHP 路径:`C:\xampp\php\php.exe`;MySQL:`C:\xampp\mysql\bin\mysql.exe -u root`(无密码)
- 避免使用 PHP 8.3 独有特性(本地是 8.2,如 `json_validate()` 不可用)
- git 提交信息:中文简短说明;子计划完成时的收尾提交带版本号
- ⚠️ 测试框架会 DROP 并重建 **apg_test 测试库**的表(仅测试库,已获用户批准后方可执行 Task 3)

---

### Task 1: 项目骨架与配置

**Files:**
- Create: `.gitignore`
- Create: `app/config/env.example.php`
- Create: `app/config/env.php`(不入 git)
- 创建数据库 `apg_dev`、`apg_test`

**Interfaces:**
- Produces: `require 'app/config/env.php'` 返回配置数组,键:`env`、`app_debug`、`db`、`db_test`、`token_ttl_days`

- [ ] **Step 1: 写 `.gitignore`**

```gitignore
app/config/env.php
logs/
*.log
```

- [ ] **Step 2: 写 `app/config/env.example.php`**

```php
<?php
// 环境配置样板:复制为 env.php 并按环境修改;env.php 不入 git
return [
    'env' => 'test',                    // test | prod
    'app_debug' => true,                // prod 必须 false
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'apg_dev',
        'user' => 'root',
        'pass' => '',
    ],
    'db_test' => [                      // 单元测试专用库,每次跑测试会清空重建!
        'host' => '127.0.0.1',
        'name' => 'apg_test',
        'user' => 'root',
        'pass' => '',
    ],
    'token_ttl_days' => 30,
];
```

- [ ] **Step 3: 复制为 `app/config/env.php`**(内容与样板相同,本地开发直接可用)

- [ ] **Step 4: 创建本地数据库**

Run:
```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS apg_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS apg_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; SHOW DATABASES LIKE 'apg%';"
```
Expected: 列出 `apg_dev` 和 `apg_test`

- [ ] **Step 5: Commit**

```powershell
git add .gitignore app/config/env.example.php
git commit -m "M1P1 项目骨架:环境配置与本地数据库"
```

---

### Task 2: 核心基础设施(配置加载/日志/响应/PDO)

**Files:**
- Create: `app/core/app.php`
- Create: `app/core/logger.php`
- Create: `app/core/response.php`
- Create: `app/core/db.php`
- Create: `app/core/error_text.php`
- Create: `app/core/bootstrap.php`
- Create: `tools/check_env.php`(冒烟检查)

**Interfaces:**
- Consumes: Task 1 的 `env.php`
- Produces:
  - `App::config(): array` — 配置数组,首次调用设 UTC 时区
  - `Db::get(): PDO` — 单例连接;`$GLOBALS['__db_cfg_key']='db_test'` 可切测试库(须在首次调用前设置)
  - `Logger::error(string $message, array $context = []): void` — 写 `logs/error-YYYY-MM-DD.log`
  - `Response::ok($data): never` / `Response::fail(string $errorCode, string $message, int $status = 400): never` — 输出 JSON 并退出
  - `ErrorText::of(string $code): string` — 错误码转中文文案
  - `require app/core/bootstrap.php` — 一次加载全部核心与服务

- [ ] **Step 1: 写 `app/core/app.php`**

```php
<?php
// 应用配置:加载 env.php,内部时间统一 UTC(显示层再转 Asia/Kuala_Lumpur)
final class App {
    private static ?array $config = null;

    public static function config(): array {
        if (self::$config === null) {
            self::$config = require dirname(__DIR__) . '/config/env.php';
            date_default_timezone_set('UTC');
        }
        return self::$config;
    }
}
```

- [ ] **Step 2: 写 `app/core/logger.php`**

```php
<?php
// 错误日志:写文件,带时间与上下文;任何环境都不把错误细节直接回给用户
final class Logger {
    public static function error(string $message, array $context = []): void {
        $dir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] %s %s\n",
            gmdate('Y-m-d H:i:s'),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );
        file_put_contents($dir . '/error-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
```

- [ ] **Step 3: 写 `app/core/response.php`**

```php
<?php
// 统一 JSON 响应格式:{ok:true,data} / {ok:false,error_code,message}
final class Response {
    public static function ok($data = null): never {
        self::send(['ok' => true, 'data' => $data]);
    }

    public static function fail(string $errorCode, string $message, int $status = 400): never {
        http_response_code($status);
        self::send(['ok' => false, 'error_code' => $errorCode, 'message' => $message]);
    }

    private static function send(array $payload): never {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
```

- [ ] **Step 4: 写 `app/core/db.php`**

```php
<?php
// PDO 连接工厂(单例)。测试框架在首次调用前设 $GLOBALS['__db_cfg_key']='db_test' 切换到测试库
final class Db {
    private static ?PDO $pdo = null;

    public static function get(): PDO {
        if (self::$pdo === null) {
            $key = $GLOBALS['__db_cfg_key'] ?? 'db';
            $cfg = App::config()[$key];
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => true,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return self::$pdo;
    }
}
```

- [ ] **Step 5: 写 `app/core/error_text.php`**

```php
<?php
// 错误码 → 用户可读中文文案
final class ErrorText {
    private const MAP = [
        'INVALID_USERNAME'   => '用户名需 3-20 位,只能包含字母、数字、下划线或中文',
        'PASSWORD_TOO_SHORT' => '密码至少 8 位',
        'INVALID_EMAIL'      => '邮箱格式不正确',
        'USERNAME_TAKEN'     => '用户名已被使用',
        'TOO_MANY_ATTEMPTS'  => '尝试次数过多,请 15 分钟后再试',
        'BAD_CREDENTIALS'    => '用户名或密码错误',
        'AUTH_REQUIRED'      => '请先登录',
        'NOT_FOUND'          => '接口不存在',
        'SERVER_ERROR'       => '服务器错误,请稍后再试',
    ];

    public static function of(string $code): string {
        return self::MAP[$code] ?? $code;
    }
}
```

- [ ] **Step 6: 写 `app/core/bootstrap.php`**(auth.php 与 auth_service.php 在 Task 4/5 才创建,先注释占位行,创建后取消注释——或在对应任务补 require)

```php
<?php
// 应用引导:按顺序加载核心与业务文件(不用 Composer 自动加载)
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/error_text.php';
// Task 4 创建后取消注释:
// require_once __DIR__ . '/auth.php';
// Task 5 创建后取消注释:
// require_once dirname(__DIR__) . '/services/auth_service.php';
App::config();
```

- [ ] **Step 7: 写 `tools/check_env.php`**

```php
<?php
// 环境冒烟检查:配置可读、数据库可连
require dirname(__DIR__) . '/app/core/bootstrap.php';
$cfg = App::config();
echo 'env=' . $cfg['env'] . PHP_EOL;
echo 'db=' . Db::get()->query('SELECT VERSION()')->fetchColumn() . PHP_EOL;
echo 'OK' . PHP_EOL;
```

- [ ] **Step 8: 语法检查 + 冒烟运行**

Run:
```powershell
Get-ChildItem -Recurse -Filter *.php app,tools | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
C:\xampp\php\php.exe tools\check_env.php
```
Expected: 每个文件 `No syntax errors detected`;冒烟输出 `env=test`、`db=10.4.x-MariaDB`、`OK`

- [ ] **Step 9: Commit**

```powershell
git add app/core tools/check_env.php
git commit -m "M1P1 核心基础设施:配置/日志/响应/PDO"
```

---

### Task 3: 数据库 Schema 与测试框架

⚠️ 本任务包含测试库 DROP 逻辑,执行前确认用户已批准。

**Files:**
- Create: `sql/001_init.sql`
- Create: `tests/bootstrap.php`
- Create: `tests/run.php`

**Interfaces:**
- Consumes: `Db::get()`、`$GLOBALS['__db_cfg_key']`
- Produces:
  - 表 `player`(id, username, email, password_hash, status, created_at, updated_at)
  - 表 `player_token`(id, player_id, token_hash, expires_at, created_at)
  - 表 `login_attempt`(id, username, ip, attempted_at, success)
  - `assert_true(bool $cond, string $label): void` / `assert_eq($expected, $actual, string $label): void`
  - `reset_schema(): void` — 清空重建 **apg_test** 的所有表(按 `sql/*.sql` 顺序执行)
  - `C:\xampp\php\php.exe tests\run.php` — 跑全部 `tests/test_*.php`,失败退出码 1

- [ ] **Step 1: 写 `sql/001_init.sql`**

```sql
-- 玩家与认证(时间一律存 UTC)
CREATE TABLE player (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(32) NOT NULL UNIQUE,
  email VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1=正常 0=停用',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE player_token (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  player_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE COMMENT 'sha256(token)',
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_player (player_id),
  CONSTRAINT fk_token_player FOREIGN KEY (player_id) REFERENCES player(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempt (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(32) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL,
  success TINYINT(1) NOT NULL,
  KEY idx_user_time (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: 写 `tests/bootstrap.php`**

```php
<?php
// 测试引导:切换到测试库(apg_test),提供断言与 schema 重建
$GLOBALS['__db_cfg_key'] = 'db_test'; // 必须在加载 bootstrap 前于 Db 首次使用前设置
require dirname(__DIR__) . '/app/core/bootstrap.php';

$GLOBALS['__tests_passed'] = 0;
$GLOBALS['__tests_failed'] = 0;

function assert_true(bool $cond, string $label): void {
    if ($cond) {
        $GLOBALS['__tests_passed']++;
        echo "  PASS  $label\n";
    } else {
        $GLOBALS['__tests_failed']++;
        echo "  FAIL  $label\n";
    }
}

function assert_eq($expected, $actual, string $label): void {
    $same = ($expected == $actual);
    if (!$same) {
        $label .= sprintf('(期望 %s,实际 %s)', var_export($expected, true), var_export($actual, true));
    }
    assert_true($same, $label);
}

// 清空并按 sql/*.sql 重建测试库 schema(只操作 apg_test!)
function reset_schema(): void {
    $db = Db::get();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $db->exec("DROP TABLE IF EXISTS `$t`");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (glob(dirname(__DIR__) . '/sql/*.sql') as $file) {
        $sqlText = file_get_contents($file);
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sqlText))) as $stmt) {
            $db->exec($stmt);
        }
    }
}
```

- [ ] **Step 3: 写 `tests/run.php`**

```php
<?php
// 一键跑所有测试:php tests/run.php
require __DIR__ . '/bootstrap.php';
foreach (glob(__DIR__ . '/test_*.php') as $file) {
    echo basename($file) . "\n";
    require $file;
}
printf("\n通过 %d,失败 %d\n", $GLOBALS['__tests_passed'], $GLOBALS['__tests_failed']);
exit($GLOBALS['__tests_failed'] > 0 ? 1 : 0);
```

- [ ] **Step 4: 验证测试框架跑通(0 个测试也应正常结束)**

Run:
```powershell
C:\xampp\php\php.exe tests\run.php
```
Expected: 输出 `通过 0,失败 0`,退出码 0

- [ ] **Step 5: 验证 reset_schema 能建表**

Run:
```powershell
C:\xampp\php\php.exe -r "require 'tests/bootstrap.php'; reset_schema(); print_r(Db::get()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));"
```
Expected: 数组含 `login_attempt`、`player`、`player_token`

- [ ] **Step 6: Commit**

```powershell
git add sql/001_init.sql tests/bootstrap.php tests/run.php
git commit -m "M1P1 数据库Schema与断言测试框架"
```

---

### Task 4: Token 认证层(TDD)

**Files:**
- Create: `app/core/auth.php`
- Modify: `app/core/bootstrap.php`(取消 auth.php 的注释)
- Test: `tests/test_token.php`

**Interfaces:**
- Consumes: `Db::get()`、`App::config()['token_ttl_days']`、表 `player`/`player_token`
- Produces:
  - `Auth::issueToken(int $playerId): string` — 生成 64 位 hex token,库存 sha256
  - `Auth::playerIdFromToken(?string $token): ?int` — 校验有效期,无效返回 null
  - `Auth::bearerToken(): ?string` — 从 `Authorization: Bearer` 头取 token
  - `Auth::requirePlayerId(): int` — 无效则 `Response::fail('AUTH_REQUIRED',...,401)`

- [ ] **Step 1: 写失败测试 `tests/test_token.php`**

```php
<?php
// Token 层测试
reset_schema();

// 直接插入一个玩家作为前置数据
$db = Db::get();
$now = gmdate('Y-m-d H:i:s');
$stmt = $db->prepare(
    'INSERT INTO player (username, password_hash, status, created_at, updated_at)
     VALUES (:username, :password_hash, 1, :created_at, :updated_at)'
);
$stmt->execute([
    ':username'      => 'tokenuser',
    ':password_hash' => password_hash('x', PASSWORD_BCRYPT),
    ':created_at'    => $now,
    ':updated_at'    => $now,
]);
$pid = (int)$db->lastInsertId();

$token = Auth::issueToken($pid);
assert_eq(64, strlen($token), 'token 为 64 位 hex');
assert_eq($pid, Auth::playerIdFromToken($token), '有效 token 解析出 player_id');
assert_eq(null, Auth::playerIdFromToken(str_repeat('0', 64)), '伪造 token 无效');
assert_eq(null, Auth::playerIdFromToken(''), '空 token 无效');

// 过期 token 无效
$stmt = $db->prepare('UPDATE player_token SET expires_at = :expires_at WHERE token_hash = :token_hash');
$stmt->execute([
    ':expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
    ':token_hash' => hash('sha256', $token),
]);
assert_eq(null, Auth::playerIdFromToken($token), '过期 token 无效');
```

- [ ] **Step 2: 跑测试确认失败**

Run: `C:\xampp\php\php.exe tests\run.php`
Expected: FAIL/致命错误 `Class "Auth" not found`

- [ ] **Step 3: 写 `app/core/auth.php`**

```php
<?php
// Token 认证:登录后发放随机 token,数据库只存 sha256 哈希
final class Auth {
    public static function issueToken(int $playerId): string {
        $token = bin2hex(random_bytes(32));
        $ttlDays = (int)(App::config()['token_ttl_days'] ?? 30);
        $stmt = Db::get()->prepare(
            'INSERT INTO player_token (player_id, token_hash, expires_at, created_at)
             VALUES (:player_id, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            ':player_id'  => $playerId,
            ':token_hash' => hash('sha256', $token),
            ':expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlDays * 86400),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    public static function playerIdFromToken(?string $token): ?int {
        if ($token === null || $token === '') {
            return null;
        }
        $stmt = Db::get()->prepare(
            'SELECT player_id FROM player_token
             WHERE token_hash = :token_hash AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([
            ':token_hash' => hash('sha256', $token),
            ':now'        => gmdate('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch();
        return $row ? (int)$row['player_id'] : null;
    }

    public static function bearerToken(): ?string {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([0-9a-f]{64})$/i', $hdr, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function requirePlayerId(): int {
        $pid = self::playerIdFromToken(self::bearerToken());
        if ($pid === null) {
            Response::fail('AUTH_REQUIRED', ErrorText::of('AUTH_REQUIRED'), 401);
        }
        return $pid;
    }
}
```

- [ ] **Step 4: 在 `app/core/bootstrap.php` 取消注释**

```php
require_once __DIR__ . '/auth.php';
```

- [ ] **Step 5: 跑测试确认通过**

Run: `C:\xampp\php\php.exe tests\run.php`
Expected: test_token.php 5 个断言全 PASS,`失败 0`

- [ ] **Step 6: Commit**

```powershell
git add app/core/auth.php app/core/bootstrap.php tests/test_token.php
git commit -m "M1P1 Token认证层"
```

---

### Task 5: 注册(TDD)

**Files:**
- Create: `app/services/auth_service.php`
- Modify: `app/core/bootstrap.php`(取消 auth_service.php 的注释)
- Test: `tests/test_register.php`

**Interfaces:**
- Consumes: `Db::get()`、`Auth::issueToken()`
- Produces: `AuthService::register(string $username, string $password, ?string $email = null): array`
  - 成功:`['player_id' => int, 'token' => string]`
  - 失败:`['error' => 'INVALID_USERNAME'|'PASSWORD_TOO_SHORT'|'INVALID_EMAIL'|'USERNAME_TAKEN']`

- [ ] **Step 1: 写失败测试 `tests/test_register.php`**

```php
<?php
// 注册测试
reset_schema();

$r = AuthService::register('测试玩家1', 'password123', 'a@b.com');
assert_true(isset($r['token']) && isset($r['player_id']), '注册成功返回 player_id 和 token');
assert_eq($r['player_id'] ?? null, Auth::playerIdFromToken($r['token'] ?? ''), '注册返回的 token 有效');

$r2 = AuthService::register('测试玩家1', 'password123');
assert_eq('USERNAME_TAKEN', $r2['error'] ?? '', '重复用户名被拒绝');

$r3 = AuthService::register('player2', 'short');
assert_eq('PASSWORD_TOO_SHORT', $r3['error'] ?? '', '短密码被拒绝');

$r4 = AuthService::register('ab', 'password123');
assert_eq('INVALID_USERNAME', $r4['error'] ?? '', '过短用户名被拒绝');

$r5 = AuthService::register('player3', 'password123', 'not-an-email');
assert_eq('INVALID_EMAIL', $r5['error'] ?? '', '非法邮箱被拒绝');

$r6 = AuthService::register('player4', 'password123', '');
assert_true(isset($r6['token']), '空邮箱视为不填,注册成功');
```

- [ ] **Step 2: 跑测试确认失败**

Run: `C:\xampp\php\php.exe tests\run.php`
Expected: 致命错误 `Class "AuthService" not found`

- [ ] **Step 3: 写 `app/services/auth_service.php`(本任务只写 register,login 在 Task 6 加)**

```php
<?php
// 账号业务逻辑:注册 / 登录
final class AuthService {
    public static function register(string $username, string $password, ?string $email = null): array {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', $username)) {
            return ['error' => 'INVALID_USERNAME'];
        }
        if (strlen($password) < 8) {
            return ['error' => 'PASSWORD_TOO_SHORT'];
        }
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'INVALID_EMAIL'];
        }
        $db = Db::get();
        $stmt = $db->prepare('SELECT id FROM player WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            return ['error' => 'USERNAME_TAKEN'];
        }
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO player (username, email, password_hash, status, created_at, updated_at)
             VALUES (:username, :email, :password_hash, 1, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':username'      => $username,
            ':email'         => ($email === '' ? null : $email),
            ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ':created_at'    => $now,
            ':updated_at'    => $now,
        ]);
        $playerId = (int)$db->lastInsertId();
        return ['player_id' => $playerId, 'token' => Auth::issueToken($playerId)];
    }
}
```

- [ ] **Step 4: 在 `app/core/bootstrap.php` 取消注释**

```php
require_once dirname(__DIR__) . '/services/auth_service.php';
```

- [ ] **Step 5: 跑测试确认通过**

Run: `C:\xampp\php\php.exe tests\run.php`
Expected: test_register.php 全 PASS,`失败 0`

- [ ] **Step 6: Commit**

```powershell
git add app/services/auth_service.php app/core/bootstrap.php tests/test_register.php
git commit -m "M1P1 注册功能"
```

---

### Task 6: 登录与失败锁定(TDD)

**Files:**
- Modify: `app/services/auth_service.php`(追加 login 方法)
- Test: `tests/test_login.php`

**Interfaces:**
- Consumes: `Db::get()`、`Auth::issueToken()`、表 `login_attempt`
- Produces: `AuthService::login(string $username, string $password, string $ip): array`
  - 成功:`['player_id' => int, 'token' => string]`
  - 失败:`['error' => 'BAD_CREDENTIALS'|'TOO_MANY_ATTEMPTS']`
  - 规则:同一用户名 15 分钟内失败满 5 次即锁定

- [ ] **Step 1: 写失败测试 `tests/test_login.php`**

```php
<?php
// 登录与锁定测试
reset_schema();
AuthService::register('登录测试员', 'password123');

$r = AuthService::login('登录测试员', 'password123', '127.0.0.1');
assert_true(isset($r['token']), '正确密码登录成功');

$r2 = AuthService::login('登录测试员', 'wrongpass', '127.0.0.1');
assert_eq('BAD_CREDENTIALS', $r2['error'] ?? '', '错误密码被拒绝');

$r3 = AuthService::login('不存在的人', 'password123', '127.0.0.1');
assert_eq('BAD_CREDENTIALS', $r3['error'] ?? '', '不存在的用户名同样返回 BAD_CREDENTIALS');

// 已失败 1 次,再失败 4 次凑满 5 次
for ($i = 0; $i < 4; $i++) {
    AuthService::login('登录测试员', 'wrongpass', '127.0.0.1');
}
$r4 = AuthService::login('登录测试员', 'password123', '127.0.0.1');
assert_eq('TOO_MANY_ATTEMPTS', $r4['error'] ?? '', '15分钟内失败5次后即使密码正确也锁定');
```

- [ ] **Step 2: 跑测试确认失败**

Run: `C:\xampp\php\php.exe tests\run.php`
Expected: 致命错误 `Call to undefined method AuthService::login()`

- [ ] **Step 3: 在 `app/services/auth_service.php` 的 register 方法后追加 login 方法**

```php
    public static function login(string $username, string $password, string $ip): array {
        $username = trim($username);
        $db = Db::get();
        // 15 分钟内失败满 5 次锁定
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS n FROM login_attempt
             WHERE username = :username AND success = 0 AND attempted_at > :since'
        );
        $stmt->execute([
            ':username' => $username,
            ':since'    => gmdate('Y-m-d H:i:s', time() - 900),
        ]);
        if ((int)$stmt->fetch()['n'] >= 5) {
            return ['error' => 'TOO_MANY_ATTEMPTS'];
        }

        $stmt = $db->prepare('SELECT id, password_hash FROM player WHERE username = :username AND status = 1 LIMIT 1');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        $okLogin = $row && password_verify($password, $row['password_hash']);

        $stmt = $db->prepare(
            'INSERT INTO login_attempt (username, ip, attempted_at, success)
             VALUES (:username, :ip, :attempted_at, :success)'
        );
        $stmt->execute([
            ':username'     => $username,
            ':ip'           => $ip,
            ':attempted_at' => gmdate('Y-m-d H:i:s'),
            ':success'      => $okLogin ? 1 : 0,
        ]);

        if (!$okLogin) {
            return ['error' => 'BAD_CREDENTIALS'];
        }
        return ['player_id' => (int)$row['id'], 'token' => Auth::issueToken((int)$row['id'])];
    }
```

- [ ] **Step 4: 跑测试确认通过**

Run: `C:\xampp\php\php.exe tests\run.php`
Expected: 全部测试文件 PASS,`失败 0`

- [ ] **Step 5: Commit**

```powershell
git add app/services/auth_service.php tests/test_login.php
git commit -m "M1P1 登录与失败锁定"
```

---

### Task 7: API 入口与手动验证

**Files:**
- Create: `public/api/index.php`

**Interfaces:**
- Consumes: 全部核心类与 `AuthService`
- Produces: HTTP 接口(路由经 `?r=` 参数):
  - `POST /api/index.php?r=auth/register` body `{username,password,email?}` → `{ok:true,data:{player_id,token}}`
  - `POST /api/index.php?r=auth/login` body `{username,password}` → 同上
  - `GET /api/index.php?r=me` 带 `Authorization: Bearer <token>` → `{ok:true,data:{player_id}}`

- [ ] **Step 1: 写 `public/api/index.php`**

```php
<?php
// API 单入口:路由经 ?r= 参数分发;所有异常写日志并返回统一错误
require dirname(__DIR__, 2) . '/app/core/bootstrap.php';

$route  = $_GET['r'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($method . ' ' . $route) {
        case 'POST auth/register':
            $res = AuthService::register(
                (string)($body['username'] ?? ''),
                (string)($body['password'] ?? ''),
                isset($body['email']) ? (string)$body['email'] : null
            );
            if (isset($res['error'])) {
                Response::fail($res['error'], ErrorText::of($res['error']));
            }
            Response::ok($res);
            // Response 内部 exit,不会落到下一 case

        case 'POST auth/login':
            $res = AuthService::login(
                (string)($body['username'] ?? ''),
                (string)($body['password'] ?? ''),
                $_SERVER['REMOTE_ADDR'] ?? ''
            );
            if (isset($res['error'])) {
                $status = ($res['error'] === 'TOO_MANY_ATTEMPTS') ? 429 : 401;
                Response::fail($res['error'], ErrorText::of($res['error']), $status);
            }
            Response::ok($res);

        case 'GET me':
            $pid = Auth::requirePlayerId();
            Response::ok(['player_id' => $pid]);

        default:
            Response::fail('NOT_FOUND', ErrorText::of('NOT_FOUND'), 404);
    }
} catch (Throwable $e) {
    Logger::error($e->getMessage(), ['route' => $route, 'file' => $e->getFile(), 'line' => $e->getLine()]);
    if (App::config()['app_debug']) {
        Response::fail('SERVER_ERROR', $e->getMessage(), 500);
    }
    Response::fail('SERVER_ERROR', ErrorText::of('SERVER_ERROR'), 500);
}
```

- [ ] **Step 2: 语法检查**

Run: `C:\xampp\php\php.exe -l public\api\index.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: 给开发库建表(reset_schema 只管测试库,开发库手动初始化一次)**

Run(不要用 PowerShell 管道导 SQL,PS 5.1 管道编码会弄乱中文,用 source 命令):
```powershell
C:\xampp\mysql\bin\mysql.exe -u root apg_dev --default-character-set=utf8mb4 -e "source sql/001_init.sql"
C:\xampp\mysql\bin\mysql.exe -u root apg_dev -e "SHOW TABLES;"
```
Expected: 列出 `login_attempt`、`player`、`player_token`

- [ ] **Step 4: 起本地服务器并手动验证**

后台启动:
```powershell
Start-Process -WindowStyle Hidden C:\xampp\php\php.exe -ArgumentList '-S','127.0.0.1:8123','-t','public'
```
验证三个接口:
```powershell
curl.exe -s -X POST "http://127.0.0.1:8123/api/index.php?r=auth/register" -H "Content-Type: application/json" -d '{\"username\":\"webuser\",\"password\":\"password123\"}'
curl.exe -s -X POST "http://127.0.0.1:8123/api/index.php?r=auth/login" -H "Content-Type: application/json" -d '{\"username\":\"webuser\",\"password\":\"password123\"}'
curl.exe -s "http://127.0.0.1:8123/api/index.php?r=me" -H "Authorization: Bearer <上一步返回的token>"
```
Expected 依次:
1. `{"ok":true,"data":{"player_id":1,"token":"..."}}`
2. `{"ok":true,"data":{"player_id":1,"token":"..."}}`(新 token)
3. `{"ok":true,"data":{"player_id":1}}`

再验证未带 token:`curl.exe -s "http://127.0.0.1:8123/api/index.php?r=me"` → `{"ok":false,"error_code":"AUTH_REQUIRED",...}`

- [ ] **Step 5: 停掉本地服务器**

```powershell
Get-Process php -ErrorAction SilentlyContinue | Where-Object { $_.Path -like 'C:\xampp\php*' } | Stop-Process
```

- [ ] **Step 6: Commit**

```powershell
git add public/api/index.php
git commit -m "M1P1 API入口与账号接口"
```

---

### Task 8: 收尾:全量语法检查与版本提交

- [ ] **Step 1: 全量 `php -l` + 全量测试**

Run:
```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
C:\xampp\php\php.exe tests\run.php
```
Expected: 全部 `No syntax errors detected`;测试 `失败 0`

- [ ] **Step 2: 检查文件编码(UTF-8 无 BOM)**

Run(列出任何带 BOM 的 PHP 文件,应无输出):
```powershell
Get-ChildItem -Recurse -Filter *.php | Where-Object { $bytes = [System.IO.File]::ReadAllBytes($_.FullName)[0..2]; $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF } | Select-Object FullName
```
Expected: 无输出

- [ ] **Step 3: 版本提交并推送**

```powershell
git add -A
git commit -m "v0.3.0 M1P1完成:基础设施与账号系统"
git push
```
