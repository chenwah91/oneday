# 部署指南(M1 v1.0.0)

⚠️ **上传前先备份(数据库 + 现有代码)**——本次发布涉及数据库结构变更(迁移)与定义数据(seed),上传前必须先在 cPanel/phpMyAdmin 对生产数据库做一次完整导出备份,并保留一份现有线上代码的副本,确认可回退后再继续。

> 本文档只讲「怎么把这份代码库正确地部署到生产环境」。发布前的检查项(自动 + 人工)见 `docs/ops/release-checklist.md`,先过完那份清单再执行本文档的步骤。

---

## 一、前置环境

- 线上 PHP **8.3**(与开发一致,`composer.json` 要求 `^8.2`)
- 线上 MySQL **5.7.39**(⚠️ 本地开发用的是 MariaDB 10.4,两者有差异,见下方「MySQL 5.7 差异核对」一节,**不能只在本地验证就直接上线**)
- Composer(cPanel 通常已内置,或用项目自带 `composer.phar`)
- 字符集:`utf8mb4` / 排序规则:`utf8mb4_unicode_ci`(连接、库、表、字段全程一致)
- 时区:服务器 / PHP / 应用统一 `UTC`(`APP_TIMEZONE=UTC`,业务时间统一存 UTC,不用马来西亚时区存库)

---

## 二、部署步骤

### 1. 拉代码

将本仓库(git `main` 分支)完整同步到生产环境的项目目录(git pull 或整包上传均可,内容以 git 仓库为准)。

**哪些文件参与部署 / 哪些不参与:**

| 内容 | 是否随本仓库部署 | 说明 |
|---|---|---|
| 应用代码(`app/`、`routes/`、`resources/`、`database/`、`config/`、`bootstrap/`、`public/` 等) | ✅ 随仓库 | git 内容即部署内容 |
| `public/game/vendor/pixi.min.js` | ✅ 已提交入库 | PixiJS 前端依赖,已随仓库一起部署,不需要额外下载 |
| `vendor/`(Composer 依赖) | ❌ 不随仓库(`.gitignore` 排除) | 本步骤第 2 步用 `composer install` 在生产环境现装 |
| `node_modules/`、`public/build/`、`public/hot/` | ❌ 不随仓库 | M1 前端(`public/game/`、`public/admin/`)是纯静态 HTML/JS,不走 Vite 构建产物,无需 `npm install`/`npm run build` |
| `.env` | ❌ 不随仓库(`.gitignore` 排除) | 每个环境各自维护,见第 3 步 |

### 2. 安装 PHP 依赖

```bash
composer install --no-dev --optimize-autoloader
```

- `--no-dev`:不装 `phpunit`/`pail`/`sail` 等开发依赖
- `--optimize-autoloader`:生成优化过的 classmap,生产环境常规做法

### 3. 配置 `.env`

若生产环境没有 `.env`,从 `.env.example` 复制一份,然后按下表修改:

| 变量 | 生产值 | 说明 |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | **必须**,否则报错会把 Stack Trace 吐给玩家(见 `SECURITY.md`) |
| `APP_KEY` | (生成) | 跑 `php artisan key:generate`(见下),不要手工复制开发环境的 key |
| `APP_TIMEZONE` | `UTC` | 与代码约定一致,不要改成本地时区 |
| `APP_URL` | `https://你的域名` | HTTPS |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | 生产 MySQL 5.7 实际连接信息 | |
| `DB_CHARSET` | `utf8mb4` | |
| `DB_COLLATION` | `utf8mb4_unicode_ci` | |
| `SESSION_DRIVER` | `database` | 与开发一致 |
| `SESSION_SECURE_COOKIE` | `true` | **必须**,生产是 HTTPS,Cookie 必须标记 Secure(`.env.example` 里有中文注释提示,开发环境默认 `false`) |

生成 `APP_KEY`:

```bash
php artisan key:generate
```

> `php artisan release:check` 会自动确认 `.env` 未被 git 跟踪、`.env.example` 里没有真实 `APP_KEY`——但生产 `.env` 本身的值(`APP_DEBUG`/`SESSION_SECURE_COOKIE` 等)是环境项,不在代码库里,该命令不检查,必须按上表人工核对,或对照 `docs/ops/release-checklist.md`「二、人工检查」逐条勾。

### 4. 跑迁移

```bash
php artisan migrate --force
```

`--force` 是必须的:生产环境(`APP_ENV=production`)下 Artisan 默认会为破坏性命令弹交互确认,`--force` 跳过确认(不是跳过检查,仍会正常执行迁移)。

### 5. 灌入定义数据(seed)

```bash
php artisan db:seed --force
```

会依次灌入(见 `database/seeders/DatabaseSeeder.php`):`EraSeeder` → `ResourceDefinitionSeeder` → `TechnologyDefinitionSeeder` → `BuildingDefinitionSeeder` → `BuildingLevelDefinitionSeeder` → `GameDataVersionSeeder`,对应 10 个时代 / 31 种资源 / 50 项科技 / 94 种建筑 / 282 条建筑等级定义,以及初始 `game_data_version` 记录。

⚠️ **该命令是纯插入(定义表数据),首次部署直接跑即可。若是二次发布只更新了部分定义数据,先确认 seeder 是否幂等/会不会产生重复行,必要时改用针对性的 upgrade 脚本,而不是重新整表 seed。**

### 6. 设置管理员账号

先正常在 `/game/` 完成一次注册,得到一个真实用户,然后把该账号提升为管理员:

```bash
php artisan admin:promote <用户名>
```

（对应 `app/Console/Commands/AdminPromote.php`,按 `username` 查找用户并把 `role` 设为 `admin`,可重复执行、幂等。）

### 7. Web 服务器配置

- Web 根目录(document root)指向本仓库的 `public/` 目录,**不是仓库根目录**。
- 确认 `.htaccess`(Apache/cPanel 默认自带 Laravel 的 `public/.htaccess`)生效,所有请求经 `public/index.php` 分发。
- `/game/`(玩家前端)与 `/admin/`(管理后台前端)是 `public/` 下的纯静态目录,随 `public/` 一起可直接访问,无需额外配置。

### 8. 启用 HTTPS

- 生产域名配置 SSL 证书(cPanel 常见走 AutoSSL / Let's Encrypt),并强制 HTTP → HTTPS 跳转。
- 确认 `.env` 的 `APP_URL` 是 `https://` 开头,`SESSION_SECURE_COOKIE=true`(见第 3 步)。

### 9. 缓存配置与路由

```bash
php artisan config:cache
php artisan route:cache
```

⚠️ **`route:cache` 必须在生产环境(`APP_ENV=production`)执行,严禁在 `testing` 环境跑这一步。** 原因:`routes/web.php` 末尾有一段 `if (app()->environment('testing')) { ... }`,注册了 `/api/_boom`、`/api/_forbidden`、`/api/_csrf` 三条仅供自动化测试用的路由(会主动抛异常/403/CSRF 错误,用来验证异常渲染)。`route:cache` 会把**当前执行环境下实际生效的路由表**固化进缓存文件;如果在 `testing` 环境下跑这条命令,这三条会抛异常的测试路由会被永久烤进缓存,即使之后用生产 `.env` 启动也会继续暴露它们。正常按上面顺序在生产环境执行不会有这个问题(此时 `app()->environment()` 已经是 `production`,这三条路由根本不会被注册,自然也不会进缓存)。

若之后修改了 `.env` 或路由,需要先 `php artisan config:clear && php artisan route:clear`,改完再重新 `config:cache`/`route:cache`。

---

## 三、⚠️ MySQL 5.7 差异核对(部署前必读)

本地开发用的是 **MariaDB 10.4**,线上是 **MySQL 5.7.39**,两者不完全兼容。详见 `docs/ops/db-mariadb-vs-mysql57.md`,部署时至少确认以下几点:

1. **JSON 列**:`building_definition`/`building_level_definition`/`technology_definition` 等表用了 Laravel 的 `->json()` 字段。MariaDB 把它实现为 `LONGTEXT` + 自动加 `CHECK (json_valid(col))`;MySQL 5.7 是原生 `JSON` 类型,没有该 CHECK。**必须在真实 MySQL 5.7 环境把全部 16 个迁移文件实际跑一遍**(不能只信任本地 MariaDB 跑过的结果),迁移完成后随手 `SELECT` 几行确认 JSON 字段能正常读写、内容与本地一致。
2. **禁用窗口函数 / CTE**:MySQL 5.7 不支持 `ROW_NUMBER() OVER`、`WITH ... AS` 等语法;本项目代码里未使用,迁移到生产前如有新增原生 SQL,先自查一遍。
3. **不依赖 `CHECK` 约束的强制执行**:MySQL 5.7 会解析 `CHECK` 但不强制执行,业务校验都在应用层(Laravel Validation / 模型逻辑),不依赖数据库层 CHECK,迁移后无需担心这点导致数据变松。
4. **字符集/排序规则**:确认 MySQL 5.7 数据库、表、连接三处都是 `utf8mb4` / `utf8mb4_unicode_ci`,避免和本地默认值不同导致排序或比较行为差异。
5. 部署前对照 `docs/ops/db-mariadb-vs-mysql57.md`「上线前 DB 核对清单」逐条勾选完毕。

---

## 四、部署后验证

### 1. 自动检查

```bash
php artisan release:check
```

应全部 ✓(`.env` 未被跟踪、`.env.example` 无真实 `APP_KEY`、全部 `.php` git blob 纯 LF 无 BOM),并报告迁移文件数量(应为 16)与当前 `game_data_version`(seed 后应能查到,如 `V3.1.1`)。

### 2. 玩家端冒烟测试

- 打开 `https://你的域名/game/`,完成一次注册 → 自动建城 → 进入地图。
- 选择一种建筑(如初始可建的 `F02` 农田)执行建造,确认资源正确扣除、建筑正常渲染在地图上。
- 刷新页面确认 Time Delta 结算正常(离开一段时间再回来,资源按时间正确增长/消耗,不会重复结算)。

### 3. 管理后台冒烟测试

- 打开 `https://你的域名/admin/`,用第 6 步提升过的管理员账号登录。
- 确认能看到玩家列表、审计日志列表;非管理员账号访问应被拒绝(403)。

### 4. 人工检查清单

过一遍 `docs/ops/release-checklist.md`「二、人工检查」与「三、数据库」两节(`APP_DEBUG`/HTTPS/Cookie/CSRF/限流/Audit/依赖漏洞检查/PWA 缓存版本等),全部确认完再对外正式开放。

---

## 五、回滚

若部署后发现问题需要回退:

1. **数据库先行**:优先从本次部署前做的备份直接恢复数据库(最快、最安全),而不是依赖 `migrate:rollback` 反向跑迁移(反向迁移可能因为期间已产生的新数据而失败或丢数据)。
2. 若必须用迁移回滚(例如只想撤销最后一批迁移,且确认没有依赖新结构的数据产生):

   ```bash
   php artisan migrate:rollback
   ```

   不带参数默认回滚最后一批(most recent batch);如需回滚多批,加 `--step=N`。执行前务必确认这是当前会话最后一次 `migrate` 产生的批次,避免误回滚到更早的结构。
3. 代码回滚:将 Web 根目录/仓库切回上一个已知良好的 git commit 或此前备份的代码副本。
4. 回滚后重新执行「四、部署后验证」确认恢复正常。

⚠️ 任何回滚操作前,若涉及数据库结构或数据变化,同样先确认已有当次的备份可用——先备份再动手,回滚也不例外。
