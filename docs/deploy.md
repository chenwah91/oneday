# 部署指南(R1 v1.5.0)

⚠️ **上传前先备份(数据库 + 现有代码)**——本仓库的完整部署涉及数据库结构变更(迁移)与定义数据(seed),上传前必须先在 cPanel/phpMyAdmin 对生产数据库做一次完整导出备份,并保留一份现有线上代码的副本,确认可回退后再继续。**v1.2.0 → v1.5.0 的增量升级会跑 7 支新迁移,其中 `2026_08_13_200002` 会改 `users` 表结构(加封禁两列,存量行保持 NULL)——结构变更,备份纪律不打折**(见下方「路径 B」)。

⚠️ **首次上线特别提醒**:M3 的 39 支迁移里有 **10 支会改写既有存量数据**(见第 4 步的迁移表「是否动存量数据」列)—— 其中 **9 支动的是定义表**(资源产出补链 / 删三列 / NPC 池 30→150 / 事件复活与特性提升等),**1 支动的是玩家存档**:`2026_08_11_800002_settle_electricity_stock_to_flow` 会**清空玩家的电力库存并折算成资金**(9.F4「电力做流量不做库存」)。这类迁移一旦跑到一半失败,靠 `migrate:rollback` 未必回得干净 —— **备份是唯一可靠的回退手段**,务必先备份再跑。

⚠️ **从 v1.0.x 直接升到 v1.5.0 的库**:M2 的两支中文 → 英文数据迁移(`2026_08_10_200002` / `200003`)也会在同一批里跑。它们同样改存量数据,同样只有备份能回退。

> 本文档只讲「怎么把这份代码库正确地部署到生产环境」。发布前的检查项(自动 + 人工)见 `docs/ops/release-checklist.md`,先过完那份清单再执行本文档的步骤;§82 发布前清单在本版的逐条走查结果见「四、4. §82 走查结果」。
>
> 版本对应关系:代码 `v1.5.0` · 数值 `game_data_version = V3.8.0` · PWA 缓存 `apg-v11` · 迁移文件 **74 支** · 后台规则参数 **154 项** · 测试 **1018 passed**。
> (下一个里程碑开发期每新增一支迁移把这里的数字同步 +1;PWA 缓存版本以 `public/game/service-worker.js` 里的实际值为准。)

---

## 〇、先确定你走哪条路径

v1.5.0 是 **R1 第一基础版本**。同一份代码有两种上线场景,步骤差别很大 —— **先对号入座,再往下读**:

| | **路径 A:首次上线(全新库)** | **路径 B:v1.2.0 → v1.5.0 增量** |
|---|---|---|
| 适用 | 生产库是空的,从零部署 | 线上已经跑着 v1.2.0 |
| 二、1 拉代码 | ✅ | ✅ |
| 二、2 `composer install` | ✅ | ✅(`composer.lock` 未变,等于重装一遍,可跳过但不建议) |
| 二、3 配 `.env` | ✅ **全新配置**(含三把密钥) | ✅ 只需**核对**已有值,无新增键 |
| 二、4 跑迁移 | ✅ **74 支全跑** | ✅ **跑 7 支新迁移**:`2026_08_12_400001/400002`(定义回填+GDV V3.7.0,纯 DML)、`2026_08_13_100001~100003`(era 门槛新表灌入 + npc trait_multiplier 列 + GDV V3.8.0)、`2026_08_13_200001`(audit_logs 加索引,纯 DDL)、**`200002`(⚠ users 表加 banned_at/ban_reason 两列,结构变更,存量行保持 NULL)**。全部不改玩家数值;down() 均实测可回退 |
| 二、5 `db:seed` | ✅ 必须跑 | ❌ **绝对不要跑**(定义 seeder 不幂等,会撞主键) |
| 二、5.1 SW 版本 | ✅ 确认 `apg-v11` | ✅ 确认 `apg-v11`(v1.2.0 是 `apg-v10`,**这一版必须变**,否则老玩家拿不到新前端) |
| 二、6 建管理员 | ✅ | ❌ 已有 |
| 二、7~9 Web/HTTPS/缓存 | ✅ | ✅ 只需重跑 `config:cache` / `route:cache` |
| 三、MySQL 5.7 差异核对 | ✅ **必做**(迁移要在 5.7 上真跑) | ➖ 2 支新迁移都是纯 DML(UPDATE 定义行),无新 DDL |
| 四、部署后验证 | ✅ 全套 | ✅ 全套(冒烟不能省) |

> **v1.5.0 = v1.4.1 + W11 后台全面化(后端部分)**:后台规则参数 88→**154 项**(粮耗/人口/幸福/税收/治理/物流/离线/建造与科技倍率/NPC 帽全面开放,默认值=原常量,升级当天零行为变化)、5 组新定义编辑器(建筑产量/配方/造价、科技、建筑上限、NPC 曲线、**时代门槛搬进数据库**)、运营端点(仪表盘/玩家搜索/封禁解禁/手动触发事件/审计多维筛选)。`game_data_version` → **V3.8.0**。
> 注意:154 项参数中新增的 66 项在老库里**没有数据行**(改过才落行),`get()` 回退代码默认值,功能完全正常——后台设置页这些行的「最后修改时间」为空属预期。
> 新运营界面(仪表盘/封禁按钮等)的**后台页面在下一版**(W11-2),本版 API 已就绪可用 curl 调。
> 路径 A(全新库)把 **74 支迁移全跑一遍**。

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

> v1.4.0 **没有新增任何 Composer / 前端第三方依赖**(CLAUDE §37)。`composer.lock` 自 M1P1 起未变,后端依赖只有 Laravel 框架本身,前端第三方只有随仓库提交的 `pixi.min.js`。这一步本质上是「在生产机上把 Laravel 框架装回来」,不是拉新东西。
> `composer audit` 于 2026-08-12 在 v1.4.0 的 `composer.lock` 上跑过:**No security vulnerability advisories found**。

### 3. 配置 `.env`

**`.env.example` 就是生产配置说明书。** 每个键的上方都写了「生产该填什么 / 怎么生成」的中文注释(v1.4.0 定稿),从它复制一份改成 `.env` 即可,不需要另外找文档:

```bash
cp .env.example .env
```

下表是**必须改动**的项(其余键照抄 `.env.example` 的默认值即可,里面标了「本项目未用到」的几组不用管):

| 变量 | 生产值 | 说明 |
|---|---|---|
| `APP_ENV` | `production` | 同时决定三件事:Artisan 破坏性命令要 `--force`、`/api/_ping` 探针不注册、框架各处 `isProduction()` 分支 |
| `APP_DEBUG` | `false` | **必须**,否则报错会把 Stack Trace 吐给玩家(见 `SECURITY.md` / CLAUDE §78) |
| `APP_KEY` | (生成) | 跑 `php artisan key:generate`(见下),不要手工复制开发环境的 key |
| `APP_TIMEZONE` | `UTC` | 与代码约定一致,不要改成本地时区(`tests/Feature/ConfigTest.php` 会断言这一项恒为 UTC;展示口径的时区换算是前端/报表层的事) |
| `APP_URL` | `https://你的域名` | HTTPS |
| `LOG_STACK` | `daily`(建议) | `single` 会让 `laravel.log` 无限增长,共享主机磁盘吃不消 |
| `LOG_LEVEL` | `warning`(建议) | 留 `debug` 既费空间也稀释真正的错误;安全日志走独立的 `security` 通道,不受这一项影响 |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | 生产 MySQL 5.7 实际连接信息 | 建议用最小权限的应用账号(CLAUDE §46) |
| `DB_CHARSET` | `utf8mb4` | |
| `DB_COLLATION` | `utf8mb4_unicode_ci` | |
| `SESSION_DRIVER` | `database` | 与开发一致 |
| `SESSION_SECURE_COOKIE` | `true` | **必须**,生产是 HTTPS,Cookie 必须标记 Secure(`.env.example` 里有中文注释提示,开发环境默认 `false`) |
| `SESSION_HTTP_ONLY` | `true` | 保持默认,禁止 JS 读 session cookie,XSS 也偷不走登录态 |
| `SESSION_SAME_SITE` | `lax` | 保持默认,CSRF 的第二道闸(第一道是 `web` 组的 `VerifyCsrfToken`);前后端同源部署,无需放宽成 `none` |
| `AUDIT_HMAC_SECRET` | (生成强随机值) | **必须**,审计 Hash Chain 的 HMAC 密钥(`CLAUDE.md` §58/§75)。见下方生成命令。**一经设定不可更改** |
| `MARKET_PRICE_SECRET` | (生成强随机值) | **必须**(M3 起),市场价格噪声的确定性随机密钥。缺失时价格恒不波动(保守降级);泄漏 = 玩家可预知未来价格。生成方式同 `AUDIT_HMAC_SECRET`,同级保密,但**可以轮换**(只影响未来窗口的噪声,不破坏任何历史数据) |
| `EVENT_SECRET` | (生成强随机值) | **必须**(M3 起),随机事件掷点的确定性随机密钥(触发与否 / 抽中谁 / 损失几个百分点都由它派生)。缺失时退化成「从 `APP_KEY` 派生」并写 warning;泄漏 = 玩家可预知未来会触发什么事件、能刷到多轻的损失。**与 `MARKET_PRICE_SECRET` 刻意分成两把独立密钥**:一把泄露不会连带另一套系统全被预测。同样**可以轮换**(只影响未来窗口) |

> `SESSION_HTTP_ONLY` / `SESSION_SAME_SITE` / `DB_CHARSET` / `DB_COLLATION` / `LOG_*` 这几项框架本来就有安全的默认值,v1.4.0 把它们**显式写进 `.env.example`** 是为了让生产配置一目了然、不必去翻 `config/*.php` 才知道当前生效的是什么。

⚠️ 三把密钥**一把都不能少**。核对方法(生产机上跑,只看有没有值、不打印值):

```bash
php -r '$e=parse_ini_file(".env"); foreach(["AUDIT_HMAC_SECRET","MARKET_PRICE_SECRET","EVENT_SECRET"] as $k){ printf("%-22s %s\n",$k, empty($e[$k])?"✗ 缺失":"✓ 已配置"); }'
```

生成 `APP_KEY`:

```bash
php artisan key:generate
```

生成 `AUDIT_HMAC_SECRET`(64 位十六进制随机串):

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

⚠️ **关于 `AUDIT_HMAC_SECRET`**:

- 只写进生产 `.env`,**绝不进数据库、绝不进 Git**;`.env.example` 里只留字段名不留值。
- **一经设定就不能再改**:换密钥后所有旧审计行的 `event_hash` 全部对不上,`audit:verify-chain` 会把整条链报成断链。要换只能在「链重置」的前提下由人工决策。
- 生产**不要留空**。留空时代码会退化成「从 `APP_KEY` 派生密钥」并写 warning 日志 —— 这只是本地开发的方便,生产等于把审计防篡改密钥绑死在另一个用途的密钥上。
- 密钥与 `APP_KEY`/`DB_PASSWORD` 同级:不进日志、不进审计、不进任何返回给客户端的响应。

部署后校验审计链完好(退出码非零表示有断链,可挂进 cron 定期跑):

```bash
php artisan audit:verify-chain              # 全库所有域
php artisan audit:verify-chain --city=12    # 只看 12 号城市这条链
php artisan audit:verify-chain --city=global # 只看全局链(登录 / 限流 / 后台配置等 city_id 为 NULL 的事件)
```

输出形如 `验证 N 条 / 跳过历史 M 条 / 断链 K 处`。首次上线时「跳过历史 M 条」会等于本次部署前已有的全部审计行数 —— 链从部署时刻开始,历史行按 append-only 纪律**不回填**,属正常现象。

断链原因码含义:`CONTENT_TAMPERED` 该行内容被改过 · `PREVIOUS_MISMATCH` 接不上上一条(中间有行被删或被插) · `CHAIN_HOLE` 链中间出现未挂链的行(多半是那段时间 `AUDIT_HMAC_SECRET` 没配) · `HALF_LINKED` 两列只有一个有值 · `HEAD_MISMATCH` 链头表 `audit_chain_heads` 记的链尾与实际链尾不符(有人绕过 `AuditLogger` 直写 `audit_logs`,或整域审计被删光)。

⚠️ **链尾删除检测不到**:删掉某个域最后一条审计,后面没有行来揭发它 —— 这是哈希链的固有边界。`HEAD_MISMATCH` 能兜住「整域删光」和「绕过 AuditLogger 直写」,但兜不住「精准删最后一条并同步改链头表」。要彻底堵住需要 CLAUDE §58 说的「定期把最新 Hash Anchor 存到独立存储」,尚未实现。

> `php artisan release:check` 会自动确认 `.env` 未被 git 跟踪、`.env.example` 里没有真实 `APP_KEY`——但生产 `.env` 本身的值(`APP_DEBUG`/`SESSION_SECURE_COOKIE` 等)是环境项,不在代码库里,该命令不检查,必须按上表人工核对,或对照 `docs/ops/release-checklist.md`「二、人工检查」逐条勾。

### 4. 跑迁移

```bash
php artisan migrate --force
```

`--force` 是必须的:生产环境(`APP_ENV=production`)下 Artisan 默认会为破坏性命令弹交互确认,`--force` 跳过确认(不是跳过检查,仍会正常执行迁移)。

> **v1.4.0 没有新增迁移**(仍是 67 支,与 v1.2.0 完全相同)。
> - **路径 B(v1.2.0 → v1.4.0)**:这条命令应当输出 `Nothing to migrate.` —— **代码覆盖即可,不用跑任何新 SQL**。跑一下是为了确认线上库确实已经在 67 支的状态;若它真的开始执行迁移,说明线上库不是 v1.2.0,**立刻停下**回到路径 A 的判断。
> - **路径 A(全新库)**:67 支**全部要跑**。下面几张表按批次列出这 67 支的来源,便于逐支核对与出问题时定位。

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
> 📌 M3 开发期新增的迁移不在上表 11 支之内(见下方小节)。其中审计 Hash Chain 的两支(`2026_08_10_900001` 补列 + `2026_08_11_300001` 建链头表)**必须成对上线、按序执行**:只跑前者会让「多个新城同时写第一条审计」触发 1213 死锁。历史审计行一律**不回填**(append-only,见第 3 步 `AUDIT_HMAC_SECRET` 说明)。
>
> ⚠️ 第 3、4 支是**数据迁移**(改内容,不只是改结构)。它们只处理「还是中文」的行,已经是英文 code 的行会跳过,所以对已升级过的库重复执行是安全的;但**中途失败仍可能留下改了一半的状态**,这是本文顶部强调「先备份」的直接原因。跑完后建议随手抽查:`SELECT resource_id FROM city_resources LIMIT 20;` 应全为英文 code。

#### M3(v1.2.0)新增的 39 支迁移

上表是 v1.1.0 的 11 支。下表按**波次分节**列出 M3 新增的 39 支,顺序即 Laravel 的文件名字典序执行顺序。
`28(M2 末) + 39 = 67`,与 `release:check` 报的迁移文件数量对得上。

**⚠️ 动存量数据的共 10 支**(9 支动定义表 + 1 支动玩家存档),已在表里逐支标出,跑之前必须已完成本文顶部要求的备份。

##### 波次 1 — D0 乘数总线 / 审计链 / 映射落地(7 支)

| 迁移文件 | 做什么 | 是否动存量数据 |
|---|---|---|
| `2026_08_10_900001_add_hash_chain_to_audit_logs` | `audit_logs` 补 `previous_hash` / `event_hash` 两列 + `idx_audit_chain (city_id, id)` 索引(CLAUDE §58)。历史行两列留 NULL,链从部署时刻开始 | 否(纯加列 + 加索引;MySQL 5.7 会重建整表,表大时选低峰期) |
| `2026_08_11_050001_seed_initial_resources_setting` | `game_settings` 补一行 `initial_resources`(建城初始资源,对象型设定,含 `money` / `knowledge`)。已有该行的库直接跳过,**不覆盖运营改过的值** | 否(补配置行) |
| `2026_08_11_100001_migrate_v320_resource_sources_and_upgrade_remap` | 两份已批准映射草案落地:零来源资源补链(5 种 → 1 种)+ 6 条跨代升级链重映射 + `M01 → M02` 升级链置 NULL | **是(改定义表)** |
| `2026_08_11_100002_bump_game_data_version_v320` | 递增数值版本 → `V3.2.0` | 否 |
| `2026_08_11_150001_drop_double_source_columns_from_building_level_definition` | ⚠️ **表结构变更**:删 `building_level_definition` 的 `happiness_bonus` / `governance_bonus` / `defense_score` 三列(与 `output_json` 双口径且不参与结算,用户裁决删列)。`down()` 只重建列结构**不回填数值** | **是(删列;MySQL 5.7 会重建整表,282 行代价可忽略)** |
| `2026_08_11_200001_bump_game_data_version_v321` | 递增数值版本 → `V3.2.1` | 否 |
| `2026_08_11_300001_create_audit_chain_heads_table` | 新建 `audit_chain_heads` 链头指针表(每域一行,主键 `global` / `city:<id>`)。**并发正确性所必需**:直接在 `audit_logs` 上取链尾会在多个新城同时写第一条审计时因 gap 锁与 insert intention 锁成环报 1213 Deadlock(已双进程实测)。**与 `2026_08_10_900001` 必须成对上线** | 否(建新表;会把已挂链域的链尾回填进新表,只读 `audit_logs`,不改任何审计行) |

##### 波次 2 — D1 NPC / D3 市场(8 支)

| 迁移文件 | 做什么 | 是否动存量数据 |
|---|---|---|
| `2026_08_11_400001_create_npc_definition_tables` | NPC 定义层三张表(原型 / 技能 / 等级曲线) | 否(新表) |
| `2026_08_11_400002_create_city_npcs_table` | NPC 运行时表 + `cities.npc_settled_at` 结算时钟。「一 NPC 一岗」由表形状(唯一索引)保证 | 否(新表 + 加列) |
| `2026_08_11_400003_seed_npc_rule_settings` | `game_settings` 补 31 项 NPC 规则参数 | 否(补配置行) |
| `2026_08_11_400004_bump_game_data_version_v330` | 递增数值版本 → `V3.3.0` | 否 |
| `2026_08_11_400005_backfill_npc_definition_data` | 补灌 NPC 定义数据(400001 早期版本只建表不灌数据,补一支保证两条路径一致) | 否(灌定义数据) |
| `2026_08_11_500001_create_market_tables` | 市场三张表(定义 / 订单 / 窗口成交量) | 否(新表) |
| `2026_08_11_500002_seed_market_settings` | `game_settings` 补 12 项市场参数(1 条开关 + 11 条数值) | 否(补配置行) |
| `2026_08_11_500003_bump_game_data_version_v331` | 递增数值版本 → `V3.3.1` | 否 |

##### 波次 3 — D2 工具 / D4 事件(8 支)

| 迁移文件 | 做什么 | 是否动存量数据 |
|---|---|---|
| `2026_08_11_600001_create_item_definition_table` | 工具 / 道具定义表(24 行) | 否(新表) |
| `2026_08_11_600002_create_city_items_table` | 工具运行时表 + `cities.item_settled_at` 耐久结算时钟 | 否(新表 + 加列) |
| `2026_08_11_600003_seed_item_settings` | `game_settings` 补 6 项工具参数(2 条开关 + 4 条数值) | 否(补配置行) |
| `2026_08_11_600004_list_cement_medicine_on_market` | RS027 水泥 / RS028 药品上市(已批草案 §7) | **是(改定义表)** |
| `2026_08_11_600005_bump_game_data_version_v340` | 递增数值版本 → `V3.4.0` | 否 |
| `2026_08_11_700001_create_event_tables` | 随机事件四张表 + `cities.event_settled_at` 事件结算时钟 | 否(新表 + 加列) |
| `2026_08_11_700002_seed_event_rule_settings` | `game_settings` 补 22 项事件规则参数 | 否(补配置行) |
| `2026_08_11_700003_bump_game_data_version_v341` | 递增数值版本 → `V3.4.1` | 否 |

##### 波次 4 — M.1 电力 / D5 国防(7 支)

| 迁移文件 | 做什么 | 是否动存量数据 |
|---|---|---|
| `2026_08_11_800001_seed_power_rule_settings` | `game_settings` 补电力规则参数 | 否(补配置行) |
| `2026_08_11_800002_settle_electricity_stock_to_flow` | ⚠️ **本支会改玩家存量数据**:9.F4「电力做流量不做库存」—— `city_resources` 里的 electricity **存量按交易值折算成资金后清零**。折算走审计留痕 | **是(改玩家存档:清电力库存 + 补资金)** |
| `2026_08_11_800003_enable_blackout_event` | 电力落地后复活 `EVT_BLACKOUT`(由 Fail Closed 停用转启用,效果 JSON 换成可执行 DSL) | **是(改定义表)** |
| `2026_08_11_800004_bump_game_data_version_v350` | 递增数值版本 → `V3.5.0` | 否 |
| `2026_08_11_900001_seed_defense_rule_settings` | `game_settings` 补国防规则参数(8 + 4 项) | 否(补配置行) |
| `2026_08_11_900002_enable_defense_events` | 复活 `EVT_RAID` / `EVT_BORDER_TENSION`,并把 IT008 / N010 / N016 / N027 的国防特性由 `unmapped` 提升为 spec | **是(改定义表)** |
| `2026_08_11_900003_bump_game_data_version_v351` | 递增数值版本 → `V3.5.1` | 否 |

##### 波次 5 — NPC 池扩充 / 五条 target 清偿(7 支)

| 迁移文件 | 做什么 | 是否动存量数据 |
|---|---|---|
| `2026_08_12_100001_add_name_zh_to_npc_definition` | `npc_definition` 加 `name_zh` 列(中文名) | 否(纯加列) |
| `2026_08_12_100002_expand_npc_pool_to_150` | NPC 原型池 **30 → 150**(新增 N031~N150 + 回填 name_zh + 10 行军事 NPC 国防特性提升)。全新库由 Seeder 一次灌满,本支只对已有数据的库做 | **是(改定义表:新增 120 行 + 改 10 行)** |
| `2026_08_12_100003_enable_brain_drain_event` | 复活 `EVT_BRAIN_DRAIN` 人才流失 | **是(改定义表)** |
| `2026_08_12_100004_bump_game_data_version_v360` | 递增数值版本 → `V3.6.0` | 否 |
| `2026_08_12_200001_seed_trade_capacity_quota_settings` | `game_settings` 补「贸易容量 → 成交量上限」两项参数 | 否(补配置行) |
| `2026_08_12_200002_wire_capacity_tax_price_targets` | 容量 / 税收 / 价格三组 target 接线后提升 19 行定义数据:6 条事件复活 + `EVT_TRADE_BOOM` 选项提升 + IT018 + 11 位 NPC 特性 + `SKILL_LOGISTICS` 补 `effect_target`(`EVT_TAX_PROTEST` 只刷说明,**维持停用**) | **是(改定义表)** |
| `2026_08_12_200003_bump_game_data_version_v361` | 递增数值版本 → `V3.6.1` | 否 |

##### 波次 6 — 治理容量 target 清偿(2 支)

| 迁移文件 | 做什么 | 是否动存量数据 |
|---|---|---|
| `2026_08_12_300001_wire_governance_capacity_targets` | 治理容量 target 拆成 `governance_capacity_flat` + `governance_capacity_pct` 后,把 4 行定义数据挪到正确的 target 上:N013 / N051 / N111 的 trait 由 pct target 改挂 flat target(数值 30 / 20 / 22 一个没动),`EVT_CORRUPTION` 选项 B 的治理容量 −10% 由 `unmapped` 提升为可执行 modifier。**不碰 `city_active_modifiers`**(历史上没有任何代码路径能写出需要搬家的行) | **是(改定义表)** |
| `2026_08_12_300002_bump_game_data_version_v362` | 递增数值版本 → `V3.6.2` | 否 |

> **动存量数据的 10 支汇总**(跑完逐类抽查一次):
> **动玩家存档(1 支,风险最高)**:`2026_08_11_800002` —— 清电力库存 + 按交易值折算补资金。
> **动定义表(9 支)**:`2026_08_11_100001`(资源产出补链 + 升级链重映射)· `2026_08_11_150001`(删三列)· `2026_08_11_600004`(水泥/药品上市)· `2026_08_11_800003`(EVT_BLACKOUT 复活)· `2026_08_11_900002`(两条国防事件复活 + 国防特性提升)· `2026_08_12_100002`(NPC 池 30→150)· `2026_08_12_100003`(EVT_BRAIN_DRAIN 复活)· `2026_08_12_200002`(19 行 target 提升)· `2026_08_12_300001`(4 行治理 target 提升)。
> 定义表这 9 支都是**幂等的定点写**(updateOrInsert / 按主键 update),重复跑等于把这些行刷回 `database/data/*.json` 的样子;`800002` 只处理 `amount > 0` 的行 —— 跑第二次时它们已经是 0,一行都不匹配,完全 no-op,不会重复补偿。
>
> 抽查建议:
> ```sql
> SELECT COUNT(*) FROM npc_definition;                        -- 应为 150
> SELECT COUNT(*) FROM item_definition;                       -- 应为 24
> SELECT COUNT(*) FROM event_definition;                      -- 应为 30
> SELECT COUNT(*) FROM event_definition WHERE enabled = 1;    -- 应为 25
> SELECT COUNT(*) FROM city_resources WHERE resource_id='electricity'; -- 应为 0(电力不做库存)
> SELECT COUNT(*) FROM game_settings;                         -- 应为 88
> SELECT trait_json FROM npc_definition WHERE npc_id='N013';  -- 应含 governance_capacity_flat
> ```
>
> 📌 审计 Hash Chain 的两支(`2026_08_10_900001` 补列 + `2026_08_11_300001` 建链头表)**必须成对上线、按序执行**:只跑前者会让「多个新城同时写第一条审计」触发 1213 死锁。历史审计行一律**不回填**(append-only,见第 3 步 `AUDIT_HMAC_SECRET` 说明)。

### 5. 灌入定义数据(seed)

```bash
php artisan db:seed --force
```

会依次灌入(见 `database/seeders/DatabaseSeeder.php`):时代 / 资源 / 科技 / 建筑 / 建筑等级 / **NPC / 工具 / 市场 / 事件** 定义,以及 `game_data_versions` 记录。v1.4.0 的定义数据规模(与 v1.2.0 完全相同 —— 本版定义数据一字未动):

| 定义表 | 行数 | 备注 |
|---|---:|---|
| `era` | 10 | 十个时代 |
| `resource_definition` | 31 | 资源(含容量类) |
| `technology_definition` | 50 | 科技 |
| `building_definition` / `building_level_definition` | 94 / 282 | 建筑与三级等级 |
| `npc_definition` / `npc_skill_definition` | **150** / 12 | M3 由 30 扩至 150 |
| `item_definition` | **24** | M3 新增 |
| `market_definition` | **28** | M3 新增 |
| `event_definition` | **30** | M3 新增(25 启用 / 5 Fail Closed 停用) |
| `game_settings` | **88** | 后台可调规则参数 |

**seed 要不要重跑?按库的状态分两种情况:**

| 场景 | 要不要跑 `db:seed` | 说明 |
|---|---|---|
| **全新库**(第一次部署,路径 A) | ✅ 跑,而且必须跑 | `database/data/*.json` 已经是最终形态,一次 seed 直接得到 `V3.6.2` 的定义数据,不需要再靠迁移去转换 |
| **已有数据的库**(从 v1.0.x / v1.1.0 / **v1.2.0** 升级上来,路径 B) | ❌ **不要重跑** | 定义 seeder 用的是裸 `insert`(**不幂等**),定义表已有数据时重跑会撞主键直接报错。这类库的定义数据由第 4 步的迁移就地转换与补灌,`game_data_version` 由各 bump 迁移递增到 `V3.6.2`,结果与全新库一致;**v1.2.0 → v1.4.0 更是连迁移都没有,定义数据本来就已经对了** |

⚠️ 判断方法:跑 `SELECT COUNT(*) FROM building_definition;`,返回 0 = 全新库(跑 seed),返回 94 = 已有数据(跳过 seed)。
`GameDataVersionSeeder` 本身用的是 `updateOrInsert`(幂等),但它跟其余 seeder 绑在同一条 `db:seed` 里,不能单独靠它来判断整条命令的安全性。

### 5.1 前端 PWA 缓存版本

`public/game/service-worker.js` 里的 `const CACHE = 'apg-v11';` 是本版的缓存版本号(v1.2.0 是 `apg-v10`)。它随代码一起部署,老客户端下次打开时会因为版本号变化清掉旧缓存、重新预缓存新的 HTML/CSS/JS。

**v1.4.0 改了大量前端文件(工具面板 / 事件弹窗 / HUD 三状态块 / 契约消费),这个版本号必须变到 `apg-v11`,否则已装 PWA 的老玩家会继续用 v1.2.0 的前端 —— 而后端契约已经变了。**

**部署时不需要做任何额外操作**,但要确认两件事:

1. 上传的 `service-worker.js` 里确实是 `apg-v11`(而不是被旧文件覆盖回 `apg-v10`);
2. Web 服务器**不要**给 `/game/service-worker.js` 设长缓存(`Cache-Control: max-age` 应为 0 或很小)—— SW 文件本身被 CDN/浏览器长缓存住的话,版本号改了客户端也拿不到新的。

> 版本号与测试是**结构性锁死**的:`tests/Feature/Definition/EnumCodeTest.php` 里有一条 `assertStringContainsString("const CACHE = 'apg-v11'", $sw)`。改了 SW 版本号而忘了同步这条断言(或反过来),`artisan test` 会直接红,不依赖人肉记性。

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

1. **JSON 列**:`building_definition`/`building_level_definition`/`technology_definition`/`game_settings` 等表用了 Laravel 的 `->json()` 字段。MariaDB 把它实现为 `LONGTEXT` + 自动加 `CHECK (json_valid(col))`;MySQL 5.7 是原生 `JSON` 类型,没有该 CHECK,而且**原生 JSON 列不能带 DEFAULT**(`game_settings.value_json` 因此声明为 NOT NULL 无默认,由迁移显式插入初始行)。**必须在真实 MySQL 5.7 环境把全部迁移文件实际跑一遍**(不能只信任本地 MariaDB 跑过的结果),迁移完成后随手 `SELECT` 几行确认 JSON 字段能正常读写、内容与本地一致。
2. **禁用窗口函数 / CTE**:MySQL 5.7 不支持 `ROW_NUMBER() OVER`、`WITH ... AS` 等语法;本项目代码里未使用,迁移到生产前如有新增原生 SQL,先自查一遍。
3. **不依赖 `CHECK` 约束的强制执行**:MySQL 5.7 会解析 `CHECK` 但不强制执行,业务校验都在应用层(Laravel Validation / 模型逻辑),不依赖数据库层 CHECK,迁移后无需担心这点导致数据变松。
4. **字符集/排序规则**:确认 MySQL 5.7 数据库、表、连接三处都是 `utf8mb4` / `utf8mb4_unicode_ci`,避免和本地默认值不同导致排序或比较行为差异。
5. **`ALTER TABLE` 会重建整张表**:MySQL 5.7 没有 INSTANT ADD COLUMN。M2 的补列迁移已按「同一张表的新列一次 ALTER 加齐」写好,但线上表如果已经很大,M2 的第 5 / 9 / 10 / 11 支与 M3 的 `2026_08_10_900001`(audit_logs 补两列 + 加索引,**最可能是全库最大的表**)/ `2026_08_11_150001`(删三列)仍可能耗时较久 —— **选低峰期执行,不要中途打断**。
6. **v1.1.0 的两支数据迁移在 5.7 上要重点看**:第 3 支(资源 ID 英文化)会逐行改写 `building_level_definition` 的三个 JSON 列;MariaDB 的 LONGTEXT 与 5.7 的原生 JSON 在写回时的转义/键序表现不同,跑完务必抽查 `SELECT building_id, level, cost_json FROM building_level_definition LIMIT 5;` 确认内容正常、能被应用正确解析。
7. **审计 Hash Chain 在 5.7 上要单独验一次**:`audit_logs` 的 `before_json`/`after_json`/`delta_json`/`metadata_json` 在 5.7 是原生 JSON,**读回来的字节与写进去的不一样**(5.7 会重排 key、压掉空白)。链的 `canonical_payload` 已经按「先 decode 再规范化」实现来抵消这一点(`app/Support/AuditChain.php`),但本地 MariaDB(LONGTEXT 原样保存)验不出这条差异。上线跑完迁移后,**先在 5.7 上真实产生几条带 JSON 的审计**(建一栋楼 / 做一次补偿),再跑 `php artisan audit:verify-chain` 确认 0 断链,才算这条过。
8. **别拿本地结构当线上结构**:动表的迁移执行前,先对目标表 `SHOW CREATE TABLE` 看一眼实际形态(列类型、collation、索引),确认与本地一致再跑;不一致的地方补记进 `docs/ops/db-mariadb-vs-mysql57.md` 的「具体差异记录」表。
9. 部署前对照 `docs/ops/db-mariadb-vs-mysql57.md`「上线前 DB 核对清单」逐条勾选完毕(该文件的「硬约束」表是编码期约束,发布时只需确认没有新代码违反)。

---

## 四、部署后验证

### 1. 自动检查

```bash
php artisan release:check
```

应全部 ✓(`.env` 未被跟踪、`.env.example` 无真实 `APP_KEY`、全部 `.php` git blob 纯 LF 无 BOM),并报告**迁移文件数量 = 67**(下一个里程碑开发期每新增一支迁移都要把这个数字同步 +1)、当前 `game_data_version = V3.6.2`。

v1.4.0 在本地(2026-08-12)的实际输出:

```text
✓ .env 未被 git 跟踪
✓ .env.example 无真实 APP_KEY
✓ 所有 .php git blob 为纯 LF
✓ 所有 .php git blob 无 BOM
ℹ 迁移文件数量: 67
ℹ 最新 game_data_version: V3.6.2

发布前检查全部通过
```

自动化测试全量 **1018 passed**(`php artisan test`)。上线前若只想跑安全相关的子集,`tests/Feature/Security`、`tests/Feature/Auth`、`tests/Feature/Admin` 三个目录共 **136 项**,约 24 秒。

### 2. 玩家端冒烟测试

- 打开 `https://你的域名/game/`,完成一次注册 → 自动建城 → 进入地图。
- ✅ **新号开局硬锁已解除**(V3.2.1):建城初始资源由 `game_settings.initial_resources` 控制,默认送 `knowledge: 100`(够研 3~4 条时代 I 科技),不再需要管理员补偿垫知识。**该默认值是测试期数值,正式开服前按运营口径另调。**
- 主链(与 `artisan test` 之外的 API 级冒烟同一条):
  研究 `TECH_I_SUST` → 建 `F01` 采集营地 → 派工 → 招募 NPC → 派驻 → 市场买卖一笔 → 等一个事件窗口 → 看 `era` 区块。
- 打开科技面板,确认 50 个节点渲染正常、顶部「时代 I · 部落时代」区块列出逐维升级条件。
- 建造后确认:资源正确扣除、地图上出现 ⏳ 施工中状态与倒计时、完工后转 `active`、派满工人后产出速率从 0 变成正数。
- 刷新页面确认 Time Delta 结算正常(离开一段时间再回来,资源按时间正确增长/消耗,不会重复结算)。
- 手机尺寸(≈400×800)打开一遍:HUD 两行不溢出、建筑详情面板的升级/拆除按钮可见可点、底部建造面板横向滚动正常。

**M3 新增系统的快照读数**(`GET /api/city` 的 `data.city` 下,面板没上线时直接看响应即可):

| 区块 | 关键字段 | 上线后该长什么样 |
|---|---|---|
| `governance` | `capacity` / `capacity_base` / `flat` / `pct` | 没招行政 NPC 时 `capacity === capacity_base`;招了之后 `capacity` 变大而 `capacity_base` 不动 |
| `defense` | `defense_score` / `defense_score_base` / `threat_level` | 同上口径;新城没建 D01 时 `threat_level = high` 属正常 |
| `power` | `capacity_per_min` / `demand_per_min` / `factor` | 没电站也没耗电建筑时 `factor = 1`(不缺电) |
| `logistics` | `capacity` / `load` / `factor` | 时代 I 不计运输需求,`load = 0` |
| `trade` / `finance` | `capacity` / `pct` | 没建 C 系列建筑时为 0 |
| `npcs` / `items` / `events` | 摘要 | 数量级为个位到几十,体积可控 |

### 3. 管理后台冒烟测试

- 打开 `https://你的域名/admin/`,用第 6 步提升过的管理员账号登录。
- 确认能看到玩家列表、审计日志列表;非管理员账号访问应被拒绝(403)。
- 审计日志按 `ADMIN.COMPENSATION` / `ADMIN.CONFIG_CHANGE` 过滤(**精确匹配完整 action 码**,不支持前缀)能查到刚才的操作,且 `reason` / `requestId` 齐全。
- 「规则开关」页能读到 `worker_assign_allow_decrease_always` 与 `worker_gate_enabled` 两项;切换一次再切回,确认两次都写了 `ADMIN.CONFIG_CHANGE`,并**确认最终值回到默认 `true`**。

### 4. §82 走查结果(v1.4.0,2026-08-12 实测)

> 这一节是 **R1-B 上线准备波**对 `/CLAUDE.md` §82「Security Check:发布前」**17 项**的逐条走查结论(旧版 deploy.md 把「HTTPS 正常」与「Secure Cookie 正常」并成一行记成 16 项,本次拆开对齐原文)。
> 图例:✅ = 代码库内已确认落地(附证据) · ⚠️ = 代码侧到位但**依赖生产环境操作**,上线时必须人工确认 · ❌ = 未达成。
> **⚠️ 项不是「已通过」** —— 它们是留给路径 A/B 执行者在生产机上勾的框。

| # | §82 检查项 | 结论 | 证据 / 上线时怎么确认 |
|---:|---|:---:|---|
| 1 | `APP_DEBUG=false` | ⚠️ | 代码侧安全:`config/app.php` 的 `'debug' => (bool) env('APP_DEBUG', false)` **默认就是 false**,只有 `.env` 里显式写 `true` 才会打开;测试环境由 `phpunit.xml` 钉死 `APP_DEBUG=false`。**生产 `.env` 的值不在代码库里,`release:check` 查不到**,必须按第 3 步人工核对 |
| 2 | HTTPS 正常 | ⚠️ | 代码侧无强制 HTTPS 逻辑(不做 `forceScheme`,也没配 `TrustProxies`),**跳转由 Web 服务器/cPanel 负责**(第 8 步)。上线后实测 `http://域名` 应 301 到 `https://`;并确认 `.env` 的 `APP_URL` 是 `https://` 开头 |
| 3 | Secure Cookie 正常 | ⚠️ | 三项都在 `.env.example` 里显式列出并带中文注释:`SESSION_SECURE_COOKIE`(**生产必须改 true**,开发默认 false)、`SESSION_HTTP_ONLY=true`、`SESSION_SAME_SITE=lax`。`config/session.php` 的 `http_only` / `same_site` 默认值本来就安全,唯一必须手改的是 `SESSION_SECURE_COOKIE` |
| 4 | CSRF 正常 | ✅ | `bootstrap/app.php` **没有任何 `validateCsrfTokens(except: ...)` 豁免**,`routes/web.php` 的全部路由都在 `web` 组内 → `ValidateCsrfToken` 对所有写路由生效。结构性锁死:`M2SurfaceTest::test_every_m2_mutation_route_is_csrf_protected`(直接断言中间件栈里有 `ValidateCsrfToken::class`);419 响应形状由 `ExceptionRenderTest::test_csrf_mismatch_maps_to_419` 覆盖 |
| 5 | Auth / Authorization 测试通过 | ✅ | `tests/Feature/Auth/`(Login / Register / Session / UserModel / Schema / AuditLogger)+ `tests/Feature/Security/M2AttackTest.php`(跨玩家实例越权、后台权限阶梯、玩家打后台)+ `tests/Feature/Admin/AdminAccessTest.php`(guest 401 / player 403 + 审计 / admin 200)。`tests/Feature/Security` + `Auth` + `Admin` 三目录全绿(2026-08-12 实跑,封禁全链与运营端点测试已并入);全量 **1018 passed** |
| 6 | Rate Limit 测试通过 | ✅ | 6 个限流器定义在 `app/Providers/AppServiceProvider.php`(`api` 60/min·IP、`auth` 20、`register` 10、`snapshot` 30/user、`market` 30/user、`admin_write` 20/user),触发时统一写 `SECURITY.RATE_LIMIT` 审计 + Security Log。**反向全覆盖**:`M2SurfaceTest::test_every_api_route_is_rate_limited` 遍历路由表,`api/*` 每条都必须挂限流,豁免必须显式登记 —— 目前唯一豁免是 `/api/health`(探活不该被节流) |
| 7 | Migration Review | ⚠️ | v1.4.1+v1.5.0 合计新增 **7 支**(定义回填×2 / era 新表 / trait 列 / GDV×2 / 审计索引 / **users 封禁两列=结构变更**,均幂等、down() 实测可回退,不改玩家数值);**路径 A 要把第 4 步的迁移表逐支过一遍**,重点是标了「动存量数据」的 10 支(尤其 `2026_08_11_800002` 动玩家存档) |
| 8 | DB Backup 完成 | ⚠️ | 本文顶部三段警示。**这一项只能在生产机上完成,代码库无法代劳** |
| 9 | Restore Procedure 可用 | ⚠️ | §79 明确「有备份文件 ≠ 能恢复」。上线前**实际拿备份恢复一次到临时库**再算过 |
| 10 | `.env` 未进入 Git | ✅ | `release:check` 自动查 → ✓。另做过**全历史**核查(104 个 commit):`git log --all --diff-filter=A` 里 `.env*` 只出现过 `.env.example` 一个文件,`.env` **从未被跟踪过**;全历史 `.env.example` 里没有真实 `APP_KEY`、没有非空的 `DB_PASSWORD`/`MAIL_PASSWORD`/`AWS_SECRET_ACCESS_KEY`(唯一命中是占位的 `MAIL_PASSWORD=null`) |
| 11 | 没有 Secret 写在 JS | ✅ | grep `public/game/js` 与 `public/admin/js` 的 `secret|key|token|password|hmac|apikey` 模式,命中项**全部是登录表单的 `password` 字段名与 `autocomplete` 属性**,零硬编码密钥。三把服务端密钥只经 `config/audit.php`/`market.php`/`event.php` 读取,不进任何响应体 |
| 12 | 没有 Debug Endpoint | ⚠️ | 代码侧已隔离:`/api/_ping` 用 `if (! app()->environment('production'))` 包住;`/api/_boom`、`/api/_forbidden`、`/api/_csrf` 用 `if (app()->environment('testing'))` 包住;`app/` 与 `routes/` 全库 grep **无任何 `dd()` / `dump()` / `var_dump()` / `print_r()` 残留**。⚠️ 的原因:**`route:cache` 必须在生产环境跑**(第 9 步),在 `testing` 环境跑会把三条抛异常的测试路由永久烤进缓存 |
| 13 | Admin Route 有权限保护 | ✅ | 实跑 `php artisan route:list` 逐条核对全部 **18 条 `api/admin/*`**(按方法计):每条都是 `web,auth:web,admin,throttle:api` 起步,其中 **17 条**再叠单端点最小权限(`admin:read_player` / `read_audit` / `edit_definition` / `adjust_resource`)。唯一不带具体权限的是 `GET /api/admin/me`(读自己的 username/role/permissions,**任意管理角色可读**,属刻意设计)。`EnsureAdmin` 对未知角色/未知权限一律 Fail Closed,拒绝时区分 `NOT_ADMIN` / `MISSING_PERMISSION` 并写审计 + Security Log。阶梯由 `M2AttackTest::test_admin_endpoint_privilege_ladder_is_enforced` 钉死 |
| 14 | Audit 正常写入 | ⚠️ | 代码侧有 `AuditChainTest` + `M2SurfaceTest::test_each_successful_m2_mutation_writes_exactly_one_audit`(每笔成功 Mutation 恰好一条审计)。上线后按第 2/3 小节冒烟,再查生产 `audit_logs` 应有 `AUTH.LOGIN_SUCCESS` / `CITY.CREATE` / `BUILDING.BUILD` / `MARKET.BUY` 等;并跑一次 `php artisan audit:verify-chain` 确认 0 断链 |
| 15 | Error Response 不泄露 Stack Trace | ✅ | `bootstrap/app.php` 的 `withExceptions` 把 `api/*` 与期望 JSON 的请求统一转成 `ApiResponse::fail(稳定错误码, status)`,未知异常一律 `INTERNAL_ERROR` + 500 并**只把细节写日志**。测试:`ExceptionRenderTest`(500 响应体断言**不含** `boom` 与 `RuntimeException` 字样、404/403/419 各有稳定错误码)+ `M2AttackTest::test_error_responses_never_leak_internals`。前提仍是 `APP_DEBUG=false`(第 1 项) |
| 16 | 依赖漏洞检查 | ✅ | `composer audit` 于 2026-08-12 在 v1.4.0 的 `composer.lock` 上实跑:**No security vulnerability advisories found**。前端第三方只有随仓库提交的 `public/game/vendor/pixi.min.js`(版本固定,不走 CDN),无 `npm` 运行时依赖 |
| 17 | PWA Cache Version 正确 | ✅ | `public/game/service-worker.js` 第 3 行 `const CACHE = 'apg-v11';`,与 `tests/Feature/Definition/EnumCodeTest.php:278` 的断言逐字一致(实跑通过)。v1.2.0 是 `apg-v10`,本版**必须**跟着代码一起变(见第 5.1 步) |

**汇总:17 项里 ✅ 9 项(代码库内已确认)、⚠️ 8 项(依赖生产环境,上线时逐条勾)、❌ 0 项。**

上线时把 ⚠️ 的 8 项当成 checklist 用:
`APP_DEBUG=false` → `HTTPS 跳转` → `SESSION_SECURE_COOKIE=true` → `迁移 review(仅路径 A)` → `备份` → `恢复演练` → `生产环境跑 route:cache` → 冒烟后查 `audit_logs`。

同时过一遍 `docs/ops/release-checklist.md`「二、人工检查」与「三、数据库」两节(内容与本表互为交叉验证)。

**M3 追加的上线专项**(不属 §82,但同级重要):

| 项 | 怎么做 |
|---|---|
| 市场灰度 | 上线时先把后台设定 `market_enabled` 关为 `false`,经济回归跑够(至少观察若干个价格窗口 + 抽查审计)再开市——改一条设定即可,不用发版 |
| 高波动资源套利收紧 | `electronic_components`(v=0.10)/`rare_metals`/`advanced_materials`(v=0.12)存在苛刻条件下的跨窗套利边际;开市前任选其一收紧:滑点系数 0.5→0.91 / 费率倍率 1→2.35 / 调低单窗额度或流动性倍率(均为后台设定) |
| `MARKET_PRICE_SECRET` 已配置 | 见第 3 步 `.env` 表;缺失时价格恒不波动(保守降级,不算故障但玩法变味) |

---

## 五、回滚

若部署后发现问题需要回退:

> **路径 B(v1.2.0 → v1.5.0)的回滚**:先 `php artisan migrate:rollback --step=7`(退回七支新迁移,down() 均已实测:拟名回 NULL、era 表与封禁列干净移除、事件与工具逐字复原),再把代码切回 v1.2.0 的 commit、`config:clear && route:clear` 重新 cache。玩家数值不受影响。另:若已在后台改过 154 项参数中的新键,回滚代码后那些 game_settings 行会失去读取方,无害但建议顺手删行。PWA 注意:已经拿到 `apg-v11` 的客户端会在回滚后重新装回 `apg-v10`,期间可能有一次强刷。
> 下面 1~4 步针对的是**路径 A(跑过迁移)**的回滚。

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
