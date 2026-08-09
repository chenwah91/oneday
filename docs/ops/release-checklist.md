# 发布前检查清单

> 依据:`SECURITY.md`「发布前最低检查」。上线前从上到下过一遍。
> 自动项跑 `php artisan release:check`(见 `app/Console/Commands/ReleaseCheck.php`);
> 其余是**人工/环境项**——真实生产 `.env`、服务器配置不在代码库里,无法靠 grep 代码库自动验证,必须手工确认。
> 数据库相关另见「DB 核对」一节,引用 `docs/ops/db-mariadb-vs-mysql57.md`。

## 一、自动检查:`php artisan release:check`

命令覆盖(全部基于 git 仓库内容,不读真实生产 `.env`):

| 检查项 | 说明 |
|---|---|
| `.env` 未被 git 跟踪 | 防止真实密钥/密码随仓库泄露 |
| `.env.example` 无真实 `APP_KEY` | 模板文件必须是占位,不能是真实生成的密钥 |
| 全部跟踪的 `.php` git blob 为纯 LF | 防 CRLF 混入(cPanel/Linux 环境、`.gitattributes` 一致性) |
| 全部跟踪的 `.php` git blob 无 BOM | BOM 会导致 `header()` 报错、莫名空行(见 CLAUDE.md 编码约定) |
| 报告迁移文件数量 | 仅提示,核对 `database/migrations/` 是否有遗漏未提交 |
| 报告最新 `game_data_version` | 仅提示,核对当前连接的数据库处于哪个数值版本 |

退出码:任一「✓/✗」检查项失败则非 0(CI/发布脚本可直接拿退出码把关);`ℹ` 开头的报告项不计入失败。

**跑法:**

```bash
php artisan release:check
```

全部 ✓ 才继续下一节。若有 ✗,先修好再发布——不允许带着失败项上线。

## 二、人工检查(对照 SECURITY.md「发布前最低检查」)

以下项目 `release:check` **不检查**(依赖真实生产环境,不在代码库里),必须人工逐条确认:

- [ ] **`APP_DEBUG=false`**:登录生产环境确认 `.env` 里 `APP_DEBUG=false`(`config/app.php` 未设置时默认值本身就是 `false`,但一旦 `.env` 里被误写成 `true` 就会覆盖默认值并把详细报错吐给玩家)。
- [ ] **HTTPS**:生产 `APP_URL` 为 `https://` 开头;服务器/cPanel 已配置 SSL 证书并强制 HTTP → HTTPS 跳转。
- [ ] **Secure / HttpOnly Cookie**:生产 `.env` 设置 `SESSION_SECURE_COOKIE=true`(`.env.example` 里已有中文注释提示)。`config/session.php` 中 `http_only` 默认已为 `true`,`same_site` 默认 `lax`,无需改动。
- [ ] **CSRF**:确认 `bootstrap/app.php` 未新增任何 CSRF 豁免路径(目前没有任何 `validateCsrfTokens(except: ...)` 配置,Laravel 默认 `web` 中间件组的 `VerifyCsrfToken` 对所有 `web.php` 路由生效,保持现状)。
- [ ] **限流(Rate Limit)**:确认 `routes/web.php` 中 `throttle:auth`(登录)、`throttle:register`(注册)、`throttle:api`(建造/升级/拆除/管理后台等)仍然生效,未被临时注释掉调试。
- [ ] **Audit**:抽查 `SECURITY.md`「必须审计的 Action」列表中的关键操作(如 `BUILDING.BUILD`、`ADMIN.RESOURCE_ADJUST`)在生产库 `audit_logs` 表能查到记录。
- [ ] **Backup(备份)**:上线前对生产数据库做一次完整备份(cPanel/phpMyAdmin 导出),尤其当本次发布包含数据库结构变更或数据迁移时——对照项目 `CLAUDE.md` 红线规则「先备份再上传」。
- [ ] **代码/JS 中无密钥**:`release:check` 已保证 git 内 `.env` 未跟踪、`.env.example` 无真实 `APP_KEY`;另外人工确认 `public/` 下前端 JS/HTML 没有硬编码任何密码、Token、第三方 API Key。
- [ ] **玩家侧不出现 Stack Trace**:`APP_DEBUG=false` 前提下,`bootstrap/app.php` 的 `withExceptions` 已对 `api/*` 及期望 JSON 的请求统一转成 `ApiResponse::fail(...)` 的稳定错误码 JSON,5xx 会写日志但不回传异常细节;非 API 的 Web 请求走 Laravel 默认错误页(`APP_DEBUG=false` 时同样不含 Stack Trace)。发布前实际触发一次 5xx 确认响应体干净。
- [ ] **Admin 权限**:确认 `/api/admin/*` 路由组仍套着 `['auth:web', 'admin', 'throttle:api']`(`routes/web.php`),`admin` 别名指向 `app/Http/Middleware/EnsureAdmin.php`;用非管理员账号实测访问被拒绝(403)。
- [ ] **依赖安全检查**:发布前跑 `composer audit`(PHP 依赖,`composer.json` 无框架外重依赖);若本次改动涉及 `package.json`(Vite/Tailwind 等前端构建依赖),补跑 `npm audit`。
- [ ] **PWA 缓存版本**:`public/game/service-worker.js` 中的 `const CACHE = 'apg-v1';` ——只要本次发布改动了任何被 `PRECACHE_URLS` 预缓存的静态资源,必须同步把这个版本号往上加一(如 `apg-v2`),否则玩家端 Service Worker 会继续用旧缓存,更新对已安装 PWA 的玩家不生效。

## 三、数据库(生产 MySQL 5.7 vs 本地 MariaDB)

发布前额外过一遍 `docs/ops/db-mariadb-vs-mysql57.md` 的「上线前 DB 核对清单」小节(全部 Migration 在 MySQL 5.7 环境实跑一遍、硬约束逐条 grep 确认无违反、`utf8mb4` 一致性等)。该文档的「具体差异记录」表如有新发现,发布时一并处理。

## 四、发布流程建议顺序

1. `php artisan release:check` 全部 ✓。
2. 过完本文件「二、人工检查」全部勾选。
3. 过完 `docs/ops/db-mariadb-vs-mysql57.md`「上线前 DB 核对清单」。
4. 如涉及数据库结构变更或线上数据改动:**先备份再上传**(cPanel/phpMyAdmin),然后再覆盖代码 / 跑迁移。
5. 上传后再跑一次 `php artisan release:check`(在生产环境跑,进一步确认生产 `.env` 未被跟踪、迁移数量符合预期)。
