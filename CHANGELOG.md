# CHANGELOG

本文件记录 `apg`(城市建设经营游戏)的版本变更,遵循 [语义化版本](https://semver.org/lang/zh-CN/)(`主版本.次版本.修订号`)。

> 本文件是项目第一份正式 CHANGELOG——在此之前各阶段版本号只体现在 git commit message 里(`vX.Y.Z ...`),未集中记录。下方「更早里程碑」一节按 commit 顺序补录这些历史版本供追溯,详细条目从 `v1.0.0` 开始按标准 CHANGELOG 格式维护。

## [v1.0.0] — M1 核心循环版 (2026-08-10)

> 里程碑说明:`v1.0.0` 这个版本号此前曾在 P7(可玩前端)完成时短暂用过一次(commit `b590840`,当时只覆盖注册/建城/建造/生产/PWA 的可玩闭环)。本条目是 **M1 阶段完整收官**后的正式 `v1.0.0` 发布记录,覆盖 P1–P9 全部范围(新增了 P8 管理后台与 P9 发布加固/收尾,并对 P2/P5/P8 做了对抗式安全审查修复),以此条目为准。

M1 交付了「账号注册登录 → 建城 → 离线也能正确结算的资源生产 → 建造/升级/拆除 → 管理后台运营」的完整核心循环,并具备可执行的上线部署文档。

### 账号/认证(P2)

- Session Cookie 认证:注册、登录、登出、`/api/me`。
- 统一审计日志(`audit_logs`):`occurred_at`/`request_id`/`actor_type`/`action`/`before`/`after`/`delta` 等字段,覆盖登录成功/失败等关键动作。
- 登录、注册各自独立限流(`throttle:auth` / `throttle:register`),降低撞库与账号枚举风险。

### 定义数据(P3)

- 10 个时代(era)、31 种资源、94 种建筑、282 条建筑等级定义、50 项科技,全部通过 Seeder 灌入,数值版本号写入 `game_data_versions` 表(`game_data_version`,当前 `V3.1.1`)。
- 定义表 JSON 字段的 MariaDB/MySQL 5.7 差异已记录在 `docs/ops/db-mariadb-vs-mysql57.md`,上线前需在真实 MySQL 5.7 复核。

### 城市与模拟(P4)

- 注册即自动建城,初始资源/建筑到位。
- Time Delta 模拟:城市快照按离线时长一次性结算生产/消耗(粮食守恒、库存上限、断电等 M2 才做,M1 范围见 `docs/` 设计文档 §15),避免逐 tick 轮询。

### 建造/升级/拆除(P5)

- 建造(L1)、升级(L1→L2→L3)、拆除三个端点,完整走安全链:认证 → CSRF → 限流 → 幂等键 → 城市 Revision → 所有权校验 → 资源/占地/上限校验 → 数据库事务(含行锁)→ 审计。
- 越权操作(改别人城市)一律 403 并写审计。

### 前端可玩(P7)

- `public/game/`:Vanilla JS + PixiJS 等距地图,注册/登录、地图渲染、选建筑、建造交互、资源实时显示。
- PWA 壳:`manifest.json` + Service Worker 离线缓存,已提交 `public/game/vendor/pixi.min.js` 作为前端依赖(随仓库一起部署,无需额外下载/构建)。

### 管理后台(P8)

- `public/admin/`:管理员登录复用同一套 Session 认证,`role=admin` 才能访问。
- 只读:玩家列表、玩家详情(含城市摘要)、审计日志查询。
- 定义调整:建筑三级可编辑字段的读取与提交,写入操作走 allowlist 字段白名单 + 审计 + `game_data_version` 版本递增,全程事务包裹。
- `php artisan admin:promote <username>` 命令用于授予管理员角色。

### 发布收尾(P9)

- §15 范围回归测试(粮食守恒、Time Delta、离线结算、库存上限、事务回滚、审计完整性、Secret 不泄露)。
- `php artisan release:check`:自动核对 `.env` 未入库、`.env.example` 无真实 `APP_KEY`、全部 `.php` git blob 纯 LF 无 BOM,并报告迁移数量与当前数值版本。
- `docs/ops/release-checklist.md`:发布前人工检查清单(APP_DEBUG/HTTPS/Cookie/CSRF/限流/Audit/Backup/依赖漏洞/PWA 缓存版本等,对照 `SECURITY.md`)。
- `docs/deploy.md`:面向 cPanel + MySQL 5.7.39 生产环境的完整可执行部署指南(本文件同批新增)。

### 安全

M1 期间对 P2(账号/审计)、P5(建造/升级/拆除)、P8(管理后台)分别做过一轮对抗式安全审查,均已修复,无遗留 Critical/High 级问题。关键修复:

- **登录/注册限流与账号枚举**(P2):`unique` 校验会间接暴露账号是否存在,且注册端点当时仅有通用 `throttle:api`;加了 `register` 专用限流,降低批量探测账号存在性的可行性;登录侧 `username` 补 `max:190` 并在限流键/审计记录前截断。
- **结算并发覆盖(settlement concurrency clobber)**(P5 · High):Time Delta 结算 `simulate()` 原先没有城市锁且直接写绝对值,与并发的建造请求竞态时会整段抹掉刚才的扣费/产出;修复为加城市锁 + 改写为相对增量。
- **升级重复扣费(upgrade double-charge)**(P5 · High):升级端点原先没有幂等去重,且在加锁前就读取了建筑实例,存在双花/幻影升级窗口;修复为幂等键去重 + 加锁后在事务内重新读取实例 + 校验受影响行数。
- **管理员负值刷钱(admin negative-value money cheat)**(P8 · Medium):后台调整建筑维护成本等字段时,`value` 只校验了 `numeric`,没有下界,理论上可以把维护成本设成负数变相"造钱";修复为 `value` 加 `min:0`。
- **角色字段质量赋值(mass-assignment role)**(P8 · Low):`role` 字段一度可经由批量赋值(mass assignment)途径被写入,存在权限提升隐患;修复为将 `role` 移出 `$fillable`,授权命令改用显式 `forceFill`。

以上修复分别落在 commit `c900ceb`(P2)、`19a37e9`(P5)、`5c6dc48`(P8);详细审查记录见 `.superpowers/sdd/`(不入库,本地开发过程记录)。

---

## 更早里程碑(M1 开发过程,commit message 记录,供追溯)

| 版本 | 内容 |
|---|---|
| v0.1.0 | 项目设计文档与游戏内容填表模板 |
| v0.2.0 | 整合 V3 数值规格,确定架构适配与分期计划 |
| v0.3.0 | M1-P1:基础设施与账号系统起步 |
| v0.4.0 | M1-P1 完成:Laravel 骨架与安全中间件地基 |
| v0.5.0 | M1-P2 完成:Session 认证与审计地基 |
| v0.5.1 | M1-P2 安全补修:登录限流防绕过 + 审计脱敏 |
| v0.6.0 | M1-P3 完成:定义数据入库(10 时代 / 31 资源 / 94 建筑 / 282 等级 / 50 科技) |
| v0.7.0 | M1-P4 完成:城市 Runtime、快照与 Time Delta 模拟 |
| v0.8.0 | M1-P5 完成:建造/升级/拆除全安全链 |
| v0.8.1 | M1-P5 安全加固:结算并发防护 / 升级幂等 / 拆除幂等 |
| v1.0.0(首次使用,已被本文件顶部条目取代) | M1-P7 完成:浏览器可玩核心循环(注册/建城/建造/生产/PWA) |
| v0.9.0 | M1-P8 完成:管理后台(角色/审计查看/Definition 调整) |
| v0.9.1 | M1-P8 安全加固:Definition 值下界 / reason 长度 / 防质量赋值提权 |
| **v1.0.0**(本文件顶部条目,最终版本) | M1 完整版:核心循环 + 管理后台 + 发布文档(收尾) |
