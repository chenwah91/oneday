# 实现计划索引

> 技术栈:PHP + **Laravel 12** + **MySQL 5.7** + Vanilla JS/PixiJS(见 `/CLAUDE.md`)
> 数值权威:`docs/templates/v3.1.md`;安全权威:`/SECURITY.md`

## 当前里程碑:M1 核心循环版(Laravel 重写)

- **整体路线图**:`2026-08-09-m1-laravel-roadmap.md`(v0.4 草案,待 review)

| 子计划 | 内容 | 详细计划 | 状态 |
|--------|------|----------|------|
| P1 | Laravel 骨架 + 安全中间件地基 + 测试框架 | 待写(路线图批准后) | 规划中 |
| P2 | 账号(Session Auth)+ Authorization + 审计地基 | — | 待写 |
| P3 | Definition Migration + Seed(v3.1)+ 数据版本 | — | 待写 |
| P4 | 城市 Runtime + Snapshot + Time Delta 地基 | — | 待写 |
| P5 | 建造/升级/拆除(全安全链) | — | 待写 |
| P6 | 生产结算 + 人口粮食 + 存储 + 离线 | — | 待写 |
| P7 | 前端最小可玩(Vanilla JS + PixiJS + PWA 壳) | — | 待写 |
| P8 | 管理后台雏形(Definition 调整 + 审计查看) | — | 待写 |
| P9 | M1 收尾(回归测试 + 发布前安全检查 + test 部署) | — | 待写 |

## 历史(已废弃)

- 旧 vanilla PHP 版 M1-P1(无框架 + 自制 token)已于 commit `379ca1d` 移除,不再沿用。
