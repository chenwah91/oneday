# 部署指南(M2 v1.1.0)

⚠️ **上传前先备份(数据库 + 现有代码)**——本次发布涉及数据库结构变更(迁移)与定义数据(seed),上传前必须先在 cPanel/phpMyAdmin 对生产数据库做一次完整导出备份,并保留一份现有线上代码的副本,确认可回退后再继续。

⚠️ **v1.1.0 特别提醒**:M2 的迁移里有两支会**改写既有存档数据**(资源 ID 中文 → 英文、定义表枚举值中文 → 英文),不是纯加列。这类迁移一旦跑到一半失败,靠 `migrate:rollback` 未必回得干净 —— **备份是唯一可靠的回退手段**,务必先备份再跑。

> 本文档只讲「怎么把这份代码库正确地部署到生产环境」。发布前的检查项(自动 + 人工)见 `docs/ops/release-checklist.md`,先过完那份清单再执行本文档的步骤。
>
> 版本对应关系:代码 `v1.1.0` · 数值 `game_data_version = V3.1.3` · PWA 缓存 `apg-v9` · 迁移文件 28 支。

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
| `node_modules/`、`public/build/`、`public/hot/` | ❌ 不随仓库 | 前端(`public/game/`、`public/admin/`)是纯静态 HTML/CSS/JS(ES Modules 直接由浏览器加载),**没有前端构建步骤**,无需 `npm install`/`npm run build`。M2 新增的科技面板、派工控件等仍在这套无构建体系内 |
| `.env` | ❌ 不随仓库(`.gitignore` 排除) | 每个环境各自维护,见第 3 步 |

### 2. 安装 PHP 依赖

```bash
composer install --no-dev --optimize-autoloader
```

- `--no-dev`:不装 `phpunit`/`pail`/`sail` 等开发依赖
- `--optimize-autoloader`:生成优化过的 classmap,生产环境常规做法

> v1.1.0 **没有新增任何 Composer / 前端第三方依赖**(CLAUDE §37)。`composer.lock` 与 M1 相比未变,后端依赖只有 Laravel 框架本身,前端第三方只有随仓库提交的 `pixi.min.js`。这一步本质上是「在生产机上把 Laravel 框架装回来」,不是拉新东西。

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

Laravel 按**文件名排序**执行,不需要手工指定顺序;下表按实际执行顺序列出 **M2(v1.1.0)新增的 11 支**,便于逐支核对与出问题时定位。M1 的 16 支与 v1.0.1 的 `2026_08_10_100001`(幂等键补 `city_id` / `request_hash`)此处不再重复。

| # | 迁移文件 | 做什么 | 是否动存量数据 |
|---|---|---|---|
| 1 | `2026_08_10_200001_add_game_data_version_columns` | `cities` / `audit_logs` 各加一列 `game_data_version`(§64/§65),历史行留 NULL 不回填 | 否(纯加列) |
| 2 | `2026_08_10_200001_add_rs_code_to_resource_definition` | `resource_definition` 加 `rs_code`(对照 v3.1 §8 的 RS001–RS026 编号),未收录的资源留 NULL | 否(纯加列) |
| 3 | `2026_08_10_200002_migrate_resource_ids_to_english` | **资源 ID 中文 → 英文 code**:`resource_definition` 主键、`city_resources` 存档、`building_level_definition` 的 `cost_json`/`input_json`/`output_json`(逐条 decode→映射→encode,不做 SQL 字符串替换),并回填 `rs_code`;末尾把数值版本 bump 到 `V3.1.2`。审计表**有意不迁移**(§58 审计 Append-Only,改写等于篡改历史) | **是(改玩家存档 + 定义表)** |
| 4 | `2026_08_10_200003_migrate_enum_values_to_english` | **定义表枚举值中文 → 英文 code**:`building_definition.category` / `series_key`、`building_level_definition.cost_type`、`resource_definition.category`、`technology_definition.branch`。迁移前先断言库里每个 distinct 现值都在映射表里,**有漏网的直接抛异常中止**(宁可失败不可静默漏转) | **是(改定义表)** |
| 5 | `2026_08_10_300001_add_workers_and_food_deficit_columns` | M2-C1:`city_building_instances.assigned_workers` + `cities` 的两列粮食赤字计时,一张表一次 ALTER 加齐;并做一次性存档回填(人口 <30 拉到 30、已建建筑按劳动力池上限补满工人) | 是(补列 + 存档回填) |
| 6 | `2026_08_10_400001_bump_game_data_version_v313` | 枚举英文化后统一递增数值版本 → `V3.1.3`(带 exists 守卫,全新库由 seeder 直接写入时会跳过) | 否 |
| 7 | `2026_08_10_500001_create_game_settings_table` | 新建 `game_settings`(后台可配规则开关),并灌入两个默认开关 | 否(新表) |
| 8 | `2026_08_10_600001_create_city_technologies_table` | 新建 `city_technologies`(某座城在研 / 已解锁了哪些科技) | 否(新表) |
| 9 | `2026_08_10_610001_add_happiness_to_cities` | M2-C2:`cities.happiness`,`NOT NULL DEFAULT 60`,存档靠默认值填满 | 是(列默认值回填) |
| 10 | `2026_08_10_700001_add_era_to_cities` | M2-B6:`cities.era_key` / `era_order`,存档靠默认值一律回填到时代 I | 是(列默认值回填) |
| 11 | `2026_08_10_800001_add_construction_timing_to_building_instances` | M2-C5:`city_building_instances.construction_finished_at`,存量 `active` 建筑留 NULL 不受影响 | 否(纯加列) |

> 第 1、2 支文件名前缀同为 `2026_08_10_200001`,Laravel 按完整文件名字典序排,`add_game_data_version_columns` 在 `add_rs_code_to_resource_definition` 之前 —— 两支互不依赖,顺序如何都不影响结果。
>
> ⚠️ 第 3、4 支是**数据迁移**(改内容,不只是改结构)。它们只处理「还是中文」的行,已经是英文 code 的行会跳过,所以对已升级过的库重复执行是安全的;但**中途失败仍可能留下改了一半的状态**,这是本文顶部强调「先备份」的直接原因。跑完后建议随手抽查:`SELECT resource_id FROM city_resources LIMIT 20;` 应全为英文 code。

### 5. 灌入定义数据(seed)

```bash
php artisan db:seed --force
```

会依次灌入(见 `database/seeders/DatabaseSeeder.php`):`EraSeeder` → `ResourceDefinitionSeeder` → `TechnologyDefinitionSeeder` → `BuildingDefinitionSeeder` → `BuildingLevelDefinitionSeeder` → `GameDataVersionSeeder`,对应 10 个时代 / 31 种资源 / 50 项科技 / 94 种建筑 / 282 条建筑等级定义,以及 `game_data_versions` 记录。

**seed 要不要重跑?按库的状态分两种情况:**

| 场景 | 要不要跑 `db:seed` | 说明 |
|---|---|---|
| **全新库**(第一次部署 v1.1.0) | ✅ 跑,而且必须跑 | `database/data/*.json` 已经是英文 code 的最终形态,一次 seed 直接得到 `V3.1.3` 的定义数据,不需要再靠第 3/4 支迁移去转换 |
| **已有 M1 数据的库**(从 v1.0.x 升级上来) | ❌ **不要重跑** | 定义 seeder 用的是裸 `insert`(**不幂等**),定义表已有数据时重跑会撞主键直接报错。这类库的定义数据由第 3、4 支迁移就地转换,`game_data_version` 由第 6 支 bump 到 `V3.1.3`,结果与全新库一致 |

⚠️ 判断方法:跑 `SELECT COUNT(*) FROM building_definition;`,返回 0 = 全新库(跑 seed),返回 94 = 已有数据(跳过 seed)。
`GameDataVersionSeeder` 本身用的是 `updateOrInsert`(幂等),但它跟其余五个 seeder 绑在同一条 `db:seed` 里,不能单独靠它来判断整条命令的安全性。

### 5.1 前端 PWA 缓存版本

`public/game/service-worker.js` 里的 `const CACHE = 'apg-v9';` 是本版的缓存版本号。它随代码一起部署,老客户端下次打开时会因为版本号变化清掉旧缓存、重新预缓存新的 HTML/CSS/JS。

**部署时不需要做任何额外操作**,但要确认两件事:

1. 上传的 `service-worker.js` 里确实是 `apg-v9`(而不是被旧文件覆盖回 `apg-v8`);
2. Web 服务器**不要**给 `/game/service-worker.js` 设长缓存(`Cache-Control: max-age` 应为 0 或很小)—— SW 文件本身被 CDN/浏览器长缓存住的话,版本号改了客户端也拿不到新的。

对照 `docs/ops/release-checklist.md` 的「PWA Cache Version 正确」一项。

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

1. **JSON 列**:`building_definition`/`building_level_definition`/`technology_definition`/`game_settings` 等表用了 Laravel 的 `->json()` 字段。MariaDB 把它实现为 `LONGTEXT` + 自动加 `CHECK (json_valid(col))`;MySQL 5.7 是原生 `JSON` 类型,没有该 CHECK,而且**原生 JSON 列不能带 DEFAULT**(`game_settings.value_json` 因此声明为 NOT NULL 无默认,由迁移显式插入初始行)。**必须在真实 MySQL 5.7 环境把全部 28 个迁移文件实际跑一遍**(不能只信任本地 MariaDB 跑过的结果),迁移完成后随手 `SELECT` 几行确认 JSON 字段能正常读写、内容与本地一致。
2. **禁用窗口函数 / CTE**:MySQL 5.7 不支持 `ROW_NUMBER() OVER`、`WITH ... AS` 等语法;本项目代码里未使用,迁移到生产前如有新增原生 SQL,先自查一遍。
3. **不依赖 `CHECK` 约束的强制执行**:MySQL 5.7 会解析 `CHECK` 但不强制执行,业务校验都在应用层(Laravel Validation / 模型逻辑),不依赖数据库层 CHECK,迁移后无需担心这点导致数据变松。
4. **字符集/排序规则**:确认 MySQL 5.7 数据库、表、连接三处都是 `utf8mb4` / `utf8mb4_unicode_ci`,避免和本地默认值不同导致排序或比较行为差异。
5. **`ALTER TABLE` 会重建整张表**:MySQL 5.7 没有 INSTANT ADD COLUMN。M2 的补列迁移已按「同一张表的新列一次 ALTER 加齐」写好,但线上表如果已经很大,第 5 / 9 / 10 / 11 支迁移仍可能耗时较久 —— **选低峰期执行,不要中途打断**。
6. **v1.1.0 的两支数据迁移在 5.7 上要重点看**:第 3 支(资源 ID 英文化)会逐行改写 `building_level_definition` 的三个 JSON 列;MariaDB 的 LONGTEXT 与 5.7 的原生 JSON 在写回时的转义/键序表现不同,跑完务必抽查 `SELECT building_id, level, cost_json FROM building_level_definition LIMIT 5;` 确认内容正常、能被应用正确解析。
7. **别拿本地结构当线上结构**:动表的迁移执行前,先对目标表 `SHOW CREATE TABLE` 看一眼实际形态(列类型、collation、索引),确认与本地一致再跑;不一致的地方补记进 `docs/ops/db-mariadb-vs-mysql57.md` 的「具体差异记录」表。
8. 部署前对照 `docs/ops/db-mariadb-vs-mysql57.md`「上线前 DB 核对清单」逐条勾选完毕(该文件的「硬约束」表是编码期约束,发布时只需确认没有新代码违反)。

---

## 四、部署后验证

### 1. 自动检查

```bash
php artisan release:check
```

应全部 ✓(`.env` 未被跟踪、`.env.example` 无真实 `APP_KEY`、全部 `.php` git blob 纯 LF 无 BOM),并报告**迁移文件数量 = 28**、当前 `game_data_version = V3.1.3`。

### 2. 玩家端冒烟测试

- 打开 `https://你的域名/game/`,完成一次注册 → 自动建城 → 进入地图。
- 打开科技面板,确认 50 个节点渲染正常、顶部「时代 I · 部落时代」区块列出逐维升级条件。
- ⚠️ **新号目前建不了任何建筑**(时代 I 的建筑全部要前置科技,而新城没有知识、时代 I 也没有产知识的建筑 —— 见 `CHANGELOG.md` v1.1.0「已知边界」)。冒烟时用管理员补偿给该号发一笔 `knowledge`,再走「研究 `生存采集` → 建 `F01` 采集营地 → 派工人 → 看产出」这条链。
- 建造后确认:资源正确扣除、地图上出现 ⏳ 施工中状态与倒计时、完工后转 `active`、派满工人后产出速率从 0 变成正数。
- 刷新页面确认 Time Delta 结算正常(离开一段时间再回来,资源按时间正确增长/消耗,不会重复结算)。
- 手机尺寸(≈400×800)打开一遍:HUD 两行不溢出、建筑详情面板的升级/拆除按钮可见可点、底部建造面板横向滚动正常。

### 3. 管理后台冒烟测试

- 打开 `https://你的域名/admin/`,用第 6 步提升过的管理员账号登录。
- 确认能看到玩家列表、审计日志列表;非管理员账号访问应被拒绝(403)。
- 审计日志按 `ADMIN.COMPENSATION` / `ADMIN.CONFIG_CHANGE` 过滤(**精确匹配完整 action 码**,不支持前缀)能查到刚才的操作,且 `reason` / `requestId` 齐全。
- 「规则开关」页能读到 `worker_assign_allow_decrease_always` 与 `worker_gate_enabled` 两项;切换一次再切回,确认两次都写了 `ADMIN.CONFIG_CHANGE`,并**确认最终值回到默认 `true`**。

### 4. 人工检查清单(对齐 `/CLAUDE.md` §82)

过一遍 `docs/ops/release-checklist.md`「二、人工检查」与「三、数据库」两节。§82 的 16 项在本次发布中的落点:

| §82 检查项 | 本次怎么确认 |
|---|---|
| `APP_DEBUG=false` | 第 3 步 `.env` 表;`release:check` 不检查环境值,必须人工看 |
| HTTPS 正常 / Secure Cookie 正常 | 第 8 步 + `.env` 的 `APP_URL` / `SESSION_SECURE_COOKIE=true` |
| CSRF 正常 | 全部 POST 端点在 `web` 中间件组内(`M2SurfaceTest` 已结构性锁死);线上随手用无 token 的 POST 试一次应得 419 |
| Auth / Authorization 测试通过 | `artisan test` 全绿(379);线上用 player 账号打 `/api/admin/*` 应 403 |
| Rate Limit 测试通过 | `api/*` 每条路由都挂限流(`M2SurfaceTest::test_every_api_route_is_rate_limited`),唯一豁免是 `/api/health` |
| Migration Review | 本文第 4 步的 11 支迁移表逐支过一遍 |
| DB Backup 完成 / Restore Procedure 可用 | 本文顶部「先备份」,并**实际试一次恢复**(§79:有备份文件不等于能恢复) |
| `.env` 未进入 Git | `release:check` 自动检查 |
| 没有 Secret 写在 JS | `public/game/` 与 `public/admin/` 全是纯静态,不含任何密钥 |
| 没有 Debug Endpoint | `/api/_ping` 仅非生产注册,`/api/_boom` `/api/_forbidden` `/api/_csrf` 仅 testing 注册;**务必按第 9 步在生产环境跑 `route:cache`** |
| Admin Route 有权限保护 | `api/admin/*` 组挂 `auth:web` + `admin` + 单端点最小权限 |
| Audit 正常写入 | 冒烟后查 `audit_logs` 应有 `AUTH.REGISTER` / `CITY.CREATE` / `BUILDING.BUILD` 等 |
| Error Response 不泄露 Stack Trace | `APP_DEBUG=false` 前提下,故意打一个不存在的端点应只回 `{success,error,request_id}` |
| 依赖漏洞检查 | `composer audit`(前端依赖只有随仓库提交的 `pixi.min.js`,版本固定) |
| PWA Cache Version 正确 | 本文第 5.1 步:`apg-v9` |

---

## 五、回滚

若部署后发现问题需要回退:

1. **数据库先行**:优先从本次部署前做的备份直接恢复数据库(最快、最安全),而不是依赖 `migrate:rollback` 反向跑迁移(反向迁移可能因为期间已产生的新数据而失败或丢数据)。
2. 若必须用迁移回滚(例如只想撤销最后一批迁移,且确认没有依赖新结构的数据产生):

   ```bash
   php artisan migrate:rollback
   ```

   不带参数默认回滚最后一批(most recent batch);如需回滚多批,加 `--step=N`。执行前务必确认这是当前会话最后一次 `migrate` 产生的批次,避免误回滚到更早的结构。

   ⚠️ **v1.1.0 尤其不要指望迁移回滚**:第 3、4 支是数据迁移(中文 ↔ 英文 code)。它们的 `down()` 能把 code 换回中文,但期间玩家新产生的行、以及 `V3.1.2`/`V3.1.3` 之后写入的审计与快照都不会跟着回退,回滚后极易出现「一半中文一半英文」的混合态。**从备份恢复是这两支迁移唯一可靠的回退路径。**
3. 代码回滚:将 Web 根目录/仓库切回上一个已知良好的 git commit 或此前备份的代码副本。
   注意代码与数据库要**成对回退**:v1.1.0 的代码读英文 code,v1.0.x 的代码读中文 —— 只回代码不回库(或反过来)会直接跑不起来。
4. 回滚后重新执行「四、部署后验证」确认恢复正常。

⚠️ 任何回滚操作前,若涉及数据库结构或数据变化,同样先确认已有当次的备份可用——先备份再动手,回滚也不例外。
