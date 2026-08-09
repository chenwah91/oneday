# 当前进度快照

> 最后更新:2026-08-10
> 用途:关闭会话后直接读这份就能续上。数值权威 `docs/templates/v3.1.md`;架构 `/CLAUDE.md`;安全 `/SECURITY.md`;计划索引 `docs/superpowers/plans/README.md`。

---

## 一句话状态

**M1 核心循环版(P1–P9)已全部完成、浏览器实测通过、已推送到 `oneday.git`(HEAD `0adf40c`)。** 工作区干净,无未提交改动。等待用户测试与讨论,再决定 M2。

---

## 技术栈(已锁定,2026-08-09 转向)

- 后端:PHP + **Laravel 12** · 数据库 **MySQL 5.7.39(线上)/ MariaDB 10.4(本地)**
- 前端:Vanilla HTML/CSS/JS ES Modules + **PixiJS**(无框架、无构建)
- 架构:Modular Monolith(`app/Game/*`)· 认证:Laravel Session(Cookie HttpOnly + CSRF)
- 模拟:Time Delta(懒结算)· 经济 Mutation 强制 事务+幂等+Revision+审计
- 本地:PHP=`C:/xampp/php/php.exe`;库 `apg`(开发)/`apg_test`(测试),root 无密码

## M1 已完成内容(P1–P9)

| 阶段 | 内容 |
|------|------|
| P1 | Laravel 骨架 + 安全中间件地基 + 测试框架 |
| P2 | Session 认证(用户名+密码登录)+ Authorization + 审计地基 |
| P3 | Definition Seed(10 时代/31 资源/94 建筑/282 等级/50 科技),数据文件 `database/data/*.json` |
| P4 | 城市 Runtime + Snapshot + Time Delta 模拟(生产/粮食/存储/离线 P6 已并入) |
| P5 | 建造 / 升级 / 拆除 全安全链 |
| P7 | 前端最小可玩:Vanilla + PixiJS 等距地图 + 建造 + PWA 壳(`public/game/`) |
| P8 | 管理后台:角色隔离 + 审计查看 + Definition 调整(`public/admin/`) |
| P9 | 收尾:§15 回归测试 + `artisan release:check` + `docs/deploy.md` + `CHANGELOG.md` |

- **测试:76 passed。** P2/P5/P8 各做对抗式安全审查并修复(登录限流防绕过、结算并发 clobber、升级双花、管理员负值造钱、role 防质量赋值提权等)。
- 浏览器实测:游戏(注册→建城→建农田→粮食转正养人口)+ 后台(登录→看审计→改数值 V3.1.1)。

## 怎么运行 / 测试

```bash
# 启动(先确保 MariaDB 已开)
C:/xampp/php/php.exe artisan serve --port=8127
```

- 游戏:`http://127.0.0.1:8127/game/` → 注册 → 建农田
- 后台:先授权管理员 `C:/xampp/php/php.exe artisan admin:promote <用户名>`,再开 `http://127.0.0.1:8127/admin/`
- 测试库测试用 `apg_test`;`C:/xampp/php/php.exe artisan test`

> 注意:开发库 `apg` 里,我测试时把 F02 农田工人从 4 改成 6(生成 V3.1.1),`chengzhu001` 已被授予 admin。

## 未做(M2+,勿擅自开工,等用户点名)

科技研究 / 时代升级(M1 无科技门槛)、NPC / 工具 / 市场 / 事件 / 国防、幸福·治理·物流·电力完整系统、施工计时、人口增减、给玩家补资源(ADMIN.COMPENSATION)、Capacitor。

## 上线前必须(用户在 cPanel 亲自做)

按 `docs/deploy.md` 执行(⚠️ **先备份**),并在**真 MySQL 5.7** 上实跑迁移,核对 `docs/ops/db-mariadb-vs-mysql57.md` 的差异(5.7 无窗口函数/CTE、JSON 存储差异等)。

## 规则提醒

- 改动完成先问是否 push(本会话用户已授权一路 push,新会话需重新确认)。
- 涉及 DROP / 无 WHERE 的 DELETE / 线上数据:先索取批准。
- 导出归档根目录:**尚未问过用户**(首次正式交付前要问)。
