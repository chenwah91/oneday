# 本地 MariaDB ↔ 线上 MySQL 5.7.39 差异追踪

> 目的:本地开发用 XAMPP **MariaDB 10.4**,线上生产用 **MySQL 5.7.39**。
> 两者不完全兼容。凡是「本地能跑但线上可能不同/报错」的点都记在这里,**上线时逐条核对调整**。
> 维护规则:每次写 Migration / 原生 SQL / 依赖 DB 行为的代码时,若涉及下列风险点,更新本表。

## 硬约束(编码时必须遵守,避免上线才发现)

| # | 约束 | 原因 | 状态 |
|---|------|------|------|
| 1 | **禁用窗口函数**(`ROW_NUMBER() OVER`、`RANK()` 等) | MySQL 5.7 不支持;本地 MariaDB 10.4 支持,易误用 | 强制 |
| 2 | **禁用 CTE**(`WITH ... AS`,含递归 CTE) | 同上,MySQL 5.7 不支持 | 强制 |
| 3 | **禁用 `CHECK` 约束依赖** | MySQL 5.7 解析但**不强制执行** CHECK;MariaDB 10.4 强制。业务校验放应用层,勿依赖 DB CHECK | 强制 |
| 4 | JSON 列用 Laravel `->json()`;JSON 函数尽量走 Laravel/PHP 侧处理 | MariaDB 与 MySQL 5.7 的 JSON 实现与函数细节有差异(MariaDB JSON 实为 LONGTEXT 别名) | 强制 |
| 5 | 字符集统一 `utf8mb4`;排序规则显式写 `utf8mb4_unicode_ci` | 避免两库默认 collation 不同导致行为差异 | 强制 |
| 6 | 时间统一存 UTC;`DATETIME` 精度显式声明 | 两库默认精度/时区处理差异 | 强制 |
| 7 | 不依赖 MariaDB 独有语法(`RETURNING`、Sequence、`INSERT ... RETURNING` 等) | MySQL 5.7 无 | 强制 |

## 已发现的具体差异记录(开发中随时补充)

| 日期 | 位置(Migration/文件) | 差异 | 本地(MariaDB)表现 | 线上(MySQL 5.7)预期 | 上线动作 |
|------|----------------------|------|----------------------|------------------------|----------|
| 2026-08-09 | 定义表迁移(building_level_definition 等 JSON 列) | MariaDB 把 `json()` 实现为 `longtext` + 引擎自动加 `CHECK (json_valid(col))` | 列类型显示为 longtext,带 json_valid CHECK | MySQL 5.7 为原生 `JSON` 类型,无该 CHECK | 上线在 MySQL 5.7 实跑迁移确认 JSON 列正常;应用层不依赖该 CHECK |

## 上线前 DB 核对清单(P9 收尾用)

- [ ] 全部 Migration 在 MySQL 5.7 环境实跑一遍(不能只在本地 MariaDB 验证)
- [ ] 本表「硬约束」逐条 grep 代码确认无违反
- [ ] 本表「具体差异记录」逐条确认已处理
- [ ] `utf8mb4` 连接/表/字段一致
- [ ] Laravel `config/database.php` 的 `mysql` 连接参数(strict/engine/collation)在两环境验证
