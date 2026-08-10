# 当前进度快照

> 最后更新:2026-08-10(M2 收官)
> 用途:关闭会话后直接读这份就能续上。**数值权威 `docs/templates/v3.2.md`(2026-08-10 用户定稿,取代 v3.1)**;架构 `/CLAUDE.md`;安全 `/SECURITY.md`;计划索引 `docs/superpowers/plans/README.md`;M2 清单 `docs/superpowers/plans/2026-08-10-m2-backlog.md`。

---

## 一句话状态

**M2 完成(v1.1.0,379 测试),M3 规划中。**

版本坐标:代码 `v1.1.0` · 数值 `game_data_version = V3.1.3` · PWA 缓存 `apg-v9` · 迁移 28 支 · 测试 379 passed。
变更明细见 `CHANGELOG.md` 的 `[v1.1.0]` 条目,部署步骤见 `docs/deploy.md`。

---

## 技术栈(已锁定,2026-08-09 转向)

- 后端:PHP + **Laravel 12** · 数据库 **MySQL 5.7.39(线上)/ MariaDB 10.4(本地)**
- 前端:Vanilla HTML/CSS/JS ES Modules + **PixiJS**(无框架、无构建)
- 架构:Modular Monolith(`app/Game/*`)· 认证:Laravel Session(Cookie HttpOnly + CSRF)
- 模拟:Time Delta(懒结算 + 30 分钟分段,12h 封顶)· 经济 Mutation 强制 事务+行锁+幂等+Revision+审计
- 本地:PHP=`C:/xampp/php/php.exe`;库 `apg`(开发)/`apg_test`(测试),root 无密码
- M2 期间**未新增任何 Composer / 前端依赖**(`composer.lock` 自 M1P1 起未变)

## M1 已完成(v1.0.0 / v1.0.1,88 测试)

| 阶段 | 内容 |
|------|------|
| P1 | Laravel 骨架 + 安全中间件地基 + 测试框架 |
| P2 | Session 认证 + Authorization + 审计地基 |
| P3 | Definition Seed(10 时代 / 31 资源 / 94 建筑 / 282 等级 / 50 科技),数据文件 `database/data/*.json` |
| P4 | 城市 Runtime + Snapshot + Time Delta 模拟 |
| P5 | 建造 / 升级 / 拆除 全安全链 |
| P7 | 前端最小可玩:Vanilla + PixiJS 等距地图 + 建造 + PWA 壳(`public/game/`) |
| P8 | 管理后台:角色隔离 + 审计查看 + Definition 调整(`public/admin/`) |
| P9 | 收尾:§15 回归测试 + `artisan release:check` + `docs/deploy.md` + `CHANGELOG.md` |

`v1.0.1` 修复 5 个 M1 遗留缺陷(2 个可刷资源的经济漏洞),详见 CHANGELOG。

## M2 已完成(v1.1.0,八个波次)

| 波次 | 内容 | 累计测试 |
|------|------|---------|
| 1 | 结算内核 **per-instance 化** + 七乘区占位 + 分段结算;安全可观测性(冲突/可疑审计移到事务外的全局 render);前端建筑详情面板 | 102 |
| 2 | **资源 ID 全英文化(V3.1.2)** + 人均粮耗 0.1→0.03;`game_data_version` 全链贯通(cities / audit_logs);五级管理员角色 | 137 |
| 3 | **定义表枚举值英文化(V3.1.3)**(category/series/cost_type/branch,断链 36 条置 NULL);**M2-C1 人口/劳动力**(工人分配 API + 增长/迁出/饥荒三级后果,存档人口 10→30) | 172 |
| 4 | **API 契约全 snake_case**(请求+响应+前端+测试)+ 前端派工 UI;**管理员补偿 `ADMIN.COMPENSATION`**;`game_settings` 后台规则开关 | 202 |
| 5 | **M2-C2 幸福/健康/治安**(快落慢升 ±0.5/-1.0、缺粮 5 分钟起扣、happinessFactor 进增长);**M2-B1 科技研究**(懒完成解锁 + 50 节点科技面板) | 242 |
| 6 | **M2-C3 财政/治理**(税收 0.02×1.5^时代 × 治理四档效率、维护欠费半停工 ×0.5);**M2-B6 时代升级**(era 列 + 八维门槛逐项校验 + 建造/研究时代闸门) | 281 |
| 7 | **M2-C5 建筑生命周期**(constructing/upgrading 三态计时、升级取消退 70%、拆除返还 50%、B4 建造科技闸门);**M2-C4 物流乘数**(§10.7 分档,时代 I 不计需求)+ §13 硬上限 2.75×/3.25× + 财政预警黄红两档 | 329 |
| 8 | **M2-B3 科技乘数接线**(同分支每条 +2%,满分支 1.20×,纯数据驱动)+ 住宅升级保留 50% 人口容量;**M2-C6 安全回归**(23 类攻击场景实测,修复 1 个高危:超长 `X-Request-ID` 压制审计的 1406 漏洞,新增 31 条攻击测试) | 376 |
| 收官 | `/api/me`·`/api/csrf-cookie`·`/api/auth/logout` 补挂限流 + 全路由结构性限流测试;统一越权 Security Log 口径(去掉 WorkerService 的重复写入);浏览器全链 E2E;CHANGELOG v1.1.0 / deploy.md / STATUS 定稿 | **379** |

M2 期间的用户拍板(2026-08-10):①没派工人就不生产是预期玩法,不自动派工;②API 字段一律小写 snake_case;③工人「只减不增」放行宽松执行,开关进后台 `game_settings`。

## 怎么运行 / 测试

```bash
# 启动(先确保 MariaDB 已开)
C:/xampp/php/php.exe artisan serve --port=8127
```

- 游戏:`http://127.0.0.1:8127/game/` → 注册 → 建城
  - ⚠️ **新号目前一栋建筑都建不了**:时代 I 全部建筑都有前置科技,而新城初始资源不含 `knowledge`、时代 I 也没有产知识的建筑。冒烟/联调时先用后台补偿发一笔 `knowledge`,再走「研究 `生存采集` → 建 `F01` → 派工」。详见下方「待用户拍板」。
- 后台:先授权管理员 `C:/xampp/php/php.exe artisan admin:promote <用户名>`,再开 `http://127.0.0.1:8127/admin/`
- 测试:`C:/xampp/php/php.exe artisan test`(库 `apg_test`,当前 **379 passed**)
- 发布前自检:`C:/xampp/php/php.exe artisan release:check`(应报迁移 28 支、`V3.1.3`)

> 开发库 `apg` 的已知脏数据:`F02` 农田工人被手工从 4 改成 6(生成 V3.1.1);`chengzhu001` 已被授予 admin;实测账号 `uitest0810a` / `techsmoke` / `m2e2e0810`(玩家)/ `m2admin0810`(管理员)。清理待用户点头。

## 未做(M3+,勿擅自开工,等用户点名)

- **D 轨道(M3 主体)**:NPC / 工具 / 市场 / 随机事件 / 国防结算。
- **电力系统**:`power` 乘区目前恒 1.0,发电建筑的产出/耗电尚未真正参与结算。
- **NPC / 工具 / 事件三个乘区**同样是占位(恒 1.0),等 D 轨道接线。
- **物流距离惩罚**:`distanceFactor` 在 M2 恒 1.0,大地图分区路网留 M3。
- **建筑等级化的产出展示**:`/api/definitions/buildings` 只回传 L1,前端建筑面板的产出行固定显示 L1 数值。
- Capacitor 打包、离线优化、性能 Profiling(CLAUDE §34 Phase 4)。

## 待用户拍板(不阻塞,但 M3 开工前最好定)

1. **新号开局硬锁(优先级最高)**:新城没有 `knowledge`,时代 I 的 5 项科技都要 20–30 知识,时代 I 又没有产知识的建筑 → 新注册账号研究不了任何科技,也就建不了任何建筑。可选解法:送初始知识 / 时代 I 科技改免费或预解锁 / 给时代 I 加一栋产知识的建筑。**涉及数值与玩法设计,不擅自改。**
2. **定义表三列双口径**:`governance_bonus` / `defense_score` / `happiness_bonus` 与 `output_json` 不一致共 150+ 行。现按 `output_json` 单一来源,这三列被忽略且后台可编辑无效 —— 删列还是改口径?
3. **两份数据映射草案审批**:无来源资源补链、跨代升级链重映射(commit `ad75283`)。
4. **`docs/templates/v3.2.md` 文档同步**:字段名改 snake_case、科技 branch 换英文 code,文档还是旧写法。
5. **物流「升代产量断崖」可调参**:时代 I→II 开始计运输需求时的手感,要不要给缓冲。
6. **开发库脏数据清理**:上面列的实测账号与 `F02` 手改值。
7. **导出归档根目录**:尚未问过用户(首次正式交付前要问)。

## 上线前必须(用户在 cPanel 亲自做)

按 `docs/deploy.md` 执行(⚠️ **先备份**——v1.1.0 含两支会改写存档的数据迁移),并在**真 MySQL 5.7** 上实跑全部 28 支迁移,核对 `docs/ops/db-mariadb-vs-mysql57.md` 的差异(5.7 无窗口函数/CTE、JSON 列不能带 DEFAULT、无 INSTANT ADD COLUMN 等)。

## 规则提醒

- 改动完成先问是否 push(本会话用户已授权一路 push,新会话需重新确认)。
- 涉及 DROP / 无 WHERE 的 DELETE / 线上数据:先索取批准。
- 不擅自改游戏数值(CLAUDE §33):数值调整先改 `docs/templates/v3.2.md` 并 bump `game_data_version`。
