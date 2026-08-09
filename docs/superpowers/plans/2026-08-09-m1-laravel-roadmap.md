# M1 核心循环版(Laravel)整体路线图

> 版本:v0.4 草案(待 review)
> 依据:`/CLAUDE.md`(架构)+ `/SECURITY.md`(安全)+ `docs/templates/v3.1.md`(数值)
> 前身:旧 vanilla PHP 版 M1-P1 已废弃移除(commit `379ca1d`),本路线图为 Laravel 重写版。

---

## 0. 技术基线(已据规范锁定)

| 项 | 决定 | 说明 |
|----|------|------|
| 后端 | **Laravel 12** | PHP 8.2+ 兼容(本地 8.2.12 / 线上 8.3);Laravel 13 需 PHP 8.3,故不选 |
| 数据库 | **MySQL 5.7.39** | Laravel 官方支持 5.7+;⚠️ 5.7 无窗口函数/CTE,建表与查询避开 |
| 前端 | Vanilla HTML/CSS/JS ES Modules + PixiJS | 无框架、无构建工具 |
| 架构 | Modular Monolith,`app/Game/*` 模块 | 见 CLAUDE §11 |
| 认证 | **Laravel Session Auth**(Cookie HttpOnly + CSRF) | 非 token;取代旧的自制 sha256 token |
| 模拟 | Time Delta Simulation | 不做 5 秒扫全表 |
| Composer | 项目内 `composer.phar` 2.10.2(已装) | 不入 git |

---

## 1. M1 目标(一句话)

> 玩家可注册登录、拥有一座城市,在等距地图上**建造/升级建筑、生产资源、消耗粮食养活人口**(含离线结算);所有经济操作走**事务 + 幂等 + Revision + Audit** 安全链;并有一个**管理后台雏形**能查看审计、按 v3.1 调整游戏 Definition。

对应 CLAUDE §34 Phase 1 + v3.1 §0.1 优先级 1~2,并前置铺好强制安全地基。

### M1 明确**不做**(留待 M2+)
- NPC / 工具道具 / 市场 / 随机事件 / 国防(CLAUDE Phase 3;v3.1 §0.1 优先级 3~4)
- 科技**研究流程**(消耗知识解锁)→ M2;M1 只加载科技 Definition 并做**建造前置校验**(TECH_NOT_UNLOCKED)
- 幸福 / 治理 / 物流 / 电力**完整系统**→ M2;M1 先做占位字段与最简规则(建造前置需要的 `population_min / governance_ratio_min / happiness_min` 用默认/最简值)
- PWA 离线优化深化 / Capacitor / 性能压测 / 反作弊评分 → M3(CLAUDE Phase 4)
- 安全 Phase 3(Admin MFA、Audit Hash Chain、集中监控)→ 后期

---

## 2. 子计划分解(9 段,按序、各自独立可测)

> 每段独立交付、独立测试;完成即 commit,段末带小版本号。

### P1 — Laravel 骨架 + 安全中间件地基 + 测试框架
- `composer create-project laravel/laravel` 到项目,整理 `app/Game/*` 模块目录
- `.env` 连 MySQL 5.7、`APP_KEY`、`APP_DEBUG=false` 基线、时区 UTC
- **中间件地基**:Request ID(`X-Request-ID`)、统一 JSON 响应、稳定 Error Code 体系(CLAUDE §32)、生产错误隐藏(§78)
- Session/CSRF/RateLimit 中间件挂好(先空跑)
- PHPUnit/Pest 跑通;健康检查路由
- **交付**:Laravel 起得来、health 返回带 requestId;`php artisan test` 绿
- 安全触点:Request ID、Error Hiding、Debug off 基线

### P2 — 账号系统(Session Auth)+ Authorization 骨架 + 审计地基
- 注册 / 登录 / 登出(Laravel Auth + `Hash` bcrypt;Session 重生成;Secure/HttpOnly/SameSite Cookie)
- 登录失败 **Rate Limit** + `AUTH.LOGIN_SUCCESS/FAILED` 审计
- `audit_logs` 表落地(SECURITY §54 字段)+ 最小 Audit 写入服务
- Policy/Gate 骨架(Ownership 校验入口)
- **交付**:注册/登录/登出 + 限流;审计有记录
- 覆盖 §15 测试:登录审计、Secret 不泄露

### P3 — Definition 数据层 + Seed 管道 + 数据版本
- Migration(MySQL 5.7 语法):`era / resource_definition / building_definition / building_level_definition / technology_definition`(+ 预建 `market_resource_definition / random_event_definition / npc_archetype` 空表)
- `game_data_versions` 表
- **Seeder(数据源 = v3.1.md 表)**:10 时代、26 资源、94 建筑、282 Level、50 科技
- 校验命令:数量对齐(94/282/50/26)与外键完整性
- **交付**:`php artisan migrate --seed` 后数量校验通过;`game_data_version = V3.1.0`
- 覆盖 §15:Seed 完整性

### P4 — 城市 Runtime + Snapshot + Time Delta 地基
- Migration:`cities`(含 `revision / last_simulated_at / 各容量字段`)、`city_resources`、`city_building_instances`(v3.1 §12.1)
- 新玩家开局:建城 + 初始资源(数值待定,见开放问题)
- **City Snapshot API**(聚合只读,v3.1 §1.1 字段)
- Time Delta Simulation 骨架:`actual = ratePerMin × elapsedSeconds/60`,先只接生产 + 粮食消耗
- **交付**:登录后拿到城市 Snapshot;Time Delta 守恒
- 覆盖 §15:Time Delta、粮食守恒(基础)

### P5 — 建造 / 升级 / 拆除(核心 Mutation + 全套安全链)
- `POST build / upgrade / demolish`
- 安全链(SECURITY §42 完整流程):Auth→CSRF→RateLimit→Validation→Ownership→**Idempotency**→**Revision**→GameRules(占地/数量上限/科技前置/资源足额)→**Transaction+RowLock**→扣资源+建实体→**Invariant**→**Audit**→Commit→返回 Diff
- 新表:`idempotency_keys`(SECURITY §49)
- L1→L2→L3 升级(占地不变、升级期间产出暂停)
- **交付**:建造/升级/拆除全链路
- 覆盖 §15:建造升级、上限、科技前置、幂等建造、Revision 冲突、并发余额、事务回滚、Audit、Ownership(A 不能建 B 的城)

### P6 — 生产结算 + 人口粮食 + 存储 + 离线批量
- 生产懒结算:工人率 `MIN(1,已分配/需求)`、存储上限停产、断电率占位=1
- 人口粮食消耗、粮食黄/红预警(v3.1 §10.1)、幸福最简规则
- **离线批量结算**:分段(生产/消耗/维护/仓储),最大离线时长封顶
- **交付**:离线一段时间后结算正确、爆仓停产、缺粮预警
- 覆盖 §15:粮食守恒(完整)、离线 8h、断电产出=0(占位)

### P7 — 前端最小可玩(Vanilla JS + PixiJS + PWA 壳)
- `public/game/` 目录(CLAUDE §6):`core/{api,state,config,events}`、`renderer/{pixi-app,camera,map,buildings}`、`ui/{hud,building-panel}`
- 登录界面 → PixiJS 等距地图 → 建造交互 → HUD → 综合面板(只读 Snapshot)
- `api.js` 统一 fetch + CSRF token;**只提交意图**,服务器权威
- 手机布局基础(Compact HUD + Bottom Sheet)+ `manifest.json` + 基础 `service-worker.js`
- **交付**:浏览器可玩完整核心循环;手机可加主屏
- 安全触点:前端不信任、CSP 基线评估(§73)

### P8 — 管理后台雏形(Definition 调整 + 审计查看)【对应 Q5】
- 角色分离(`PLAYER / ADMIN`,CLAUDE §63)+ Admin 路由权限保护
- 后台:查看玩家/城市、查看 `audit_logs`
- **Definition 调整**:改 building/level 等数值 → 强制 `reason` + **Definition 修改审计**(§64)+ `game_data_version` 递增
- `ADMIN.*` 审计
- **交付**:管理员可在后台安全调整数值并留痕;普通玩家访问 Admin API 被拒
- 覆盖 §15:普通玩家不能访问 Admin API、管理员修改有 Audit

### P9 — M1 收尾(回归测试 + 发布前安全检查 + test 部署)
- v3.1 §15 全部测试案例过一遍(经济回归 + 安全)
- SECURITY「发布前最低检查」清单逐项(APP_DEBUG=false、HTTPS、Cookie、CSRF、限流、Audit、备份、无 Secret 入 git/JS、无 Stack Trace)
- **⚠️ 上传 test 前先备份**;test 环境冒烟
- **交付**:M1 v1.0.0 上 test

---

## 3. 依赖顺序

```
P1 → P2 → P3 → P4 → P5 → P6 → P7
                          ↘ P8(依赖 P2 权限 + P3 Definition)
所有段 → P9 收尾
```
P7 前端可与 P8 后台并行(都在 P6 之后)。

---

## 4. M1 期间新增/涉及的数据表

- Definition:`era / resource_definition / building_definition / building_level_definition / technology_definition`(+ 预建空表 3 张)
- Runtime:`cities / city_resources / city_building_instances`
- 玩家/认证:Laravel `users` + Session
- 安全:`audit_logs / idempotency_keys / game_data_versions`
- (M2 才用:`security_flags / npc_instance / market_* / event_*`)

---

## 5. 需要你拍板的开放问题(动 P1 前最好先定)

1. **Laravel 版本**:按 **Laravel 12**(不动本地 PHP)?还是你要本地 PHP 升 8.3 用 Laravel 13?
2. **MySQL 5.7.39 在哪**:是**本地**装的,还是**线上 cPanel** 的?本地开发要连的那台的 host/port/账号密码给我(本地 XAMPP 现在是 MariaDB,若本地无 MySQL 需先装或直接用 MariaDB 本地开发、线上才 MySQL)。
3. **开局初始数值**:新玩家建城给多少初始资源/人口/地图尺寸?v3.1 未明确规定,需要你定(或我先给一版草案你改)。
4. **P8 管理后台范围**:M1 只做「查看审计 + 调 Definition」够吗?还是要连「给玩家补资源(ADMIN.COMPENSATION)」也进 M1?
5. **地图与开局玩法**:等距地图初始大小、建造网格规则,先用最简(固定小地图)可以吗?

---

## 6. 下一步

你 review 本路线图 + 回答第 5 节开放问题后,我按 `superpowers:writing-plans` 展开 **P1 的逐步详细计划**给你 review,再开始写代码。
