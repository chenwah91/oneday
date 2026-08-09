# 实现计划索引

> 技术栈:PHP + **Laravel 12** + **MySQL 5.7** + Vanilla JS/PixiJS(见 `/CLAUDE.md`)
> 数值权威:`docs/templates/v3.1.md`;安全权威:`/SECURITY.md`

## 当前里程碑:M1 核心循环版(Laravel 重写)

- **整体路线图**:`2026-08-09-m1-laravel-roadmap.md`(v0.4 草案,待 review)

| 子计划 | 内容 | 详细计划 | 状态 |
|--------|------|----------|------|
| P1 | Laravel 骨架 + 安全中间件地基 + 测试框架 | `2026-08-09-m1-p1-laravel-foundation.md` | ✅ 已完成 (v0.4.0) |
| P2 | 账号(Session Auth)+ Authorization + 审计地基 | `2026-08-09-m1-p2-auth-audit.md` | ✅ 已完成 (v0.5.1) |
| P3 | Definition Migration + Seed(v3.1)+ 数据版本 | `2026-08-09-m1-p3-definitions-seed.md` | ✅ 已完成 (v0.6.0) |
| P4 | 城市 Runtime + Snapshot + Time Delta 地基 | `2026-08-09-m1-p4-city-runtime.md` | ✅ 已完成 (v0.7.0) |
| P5 | 建造/升级/拆除(全安全链) | `2026-08-09-m1-p5-build-upgrade.md` | 🚧 执行中 |
| P6 | 生产结算 + 人口粮食 + 存储 + 离线 | (已并入 P4 模拟) | ✅ 随 P4 |
| P7 | 前端最小可玩(Vanilla JS + PixiJS + PWA 壳) | `2026-08-09-m1-p7-frontend.md` | ✅ 已完成 (v1.0.0) |
| P8 | 管理后台雏形(Definition 调整 + 审计查看) | `2026-08-09-m1-p8-admin.md` | 🚧 执行中 |
| P9 | M1 收尾(回归测试 + 发布前安全检查 + test 部署) | — | 待写 |

## 历史(已废弃)

- 旧 vanilla PHP 版 M1-P1(无框架 + 自制 token)已于 commit `379ca1d` 移除,不再沿用。
