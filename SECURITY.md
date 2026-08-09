# SECURITY.md

> 城市 / 基地建设经营游戏安全实施规范  
> 技术栈：Vanilla JS + PixiJS + Laravel + MySQL

## 安全目标

客户端视为完全不可信。Laravel 是游戏权威服务器。任何会修改经济、建筑、NPC、科技、市场、事件或管理员状态的操作，都必须经过认证、授权、验证、事务和审计。

最重要的目标：

1. 不能通过修改前端 JS 制造服务器资源。
2. 不能越权操作其他玩家城市。
3. 重复请求不能重复获得收益。
4. 并发请求不能造成负余额或重复结算。
5. 管理员修改必须留下完整来源。
6. 关键经济变化必须可以通过 Request ID / Audit Trail 追查。
7. 日志不能泄露密码、Token、Session、Secret。

## 强制请求链

```text
HTTPS
→ Authentication
→ CSRF
→ Rate Limit
→ Request ID
→ Validation
→ Authorization
→ Idempotency
→ Revision
→ Game Rules
→ DB Transaction / Row Lock
→ Mutation
→ Invariant Check
→ Audit
→ Commit
```

## 必须审计的 Action

```text
AUTH.LOGIN_SUCCESS
AUTH.LOGIN_FAILED

BUILDING.BUILD
BUILDING.UPGRADE
BUILDING.DEMOLISH

NPC.ASSIGN
NPC.UNASSIGN

TECH.UNLOCK

MARKET.BUY
MARKET.SELL

EVENT.RESOLVE
EVENT.REWARD

ERA.UPGRADE

ADMIN.LOGIN
ADMIN.RESOURCE_ADJUST
ADMIN.COMPENSATION
ADMIN.PLAYER_BAN
ADMIN.CONFIG_CHANGE

SECURITY.AUTHORIZATION_FAILED
SECURITY.RATE_LIMIT
SECURITY.REVISION_CONFLICT
SECURITY.INVARIANT_FAILED
SECURITY.SUSPICIOUS_ACTIVITY
```

## Audit 必备字段

```text
occurred_at
request_id
trace_id
idempotency_key

actor_type
actor_id

user_id
city_id

action
entity_type
entity_id

city_revision_before
city_revision_after

status
reason_code

IP / privacy-safe network identifier
user_agent_hash

before
after
delta
metadata

previous_hash（后期）
event_hash（后期）
```

## 经济操作额外要求

Build / Upgrade / Market / Reward 等：

```text
Transaction
+ Idempotency
+ Revision
+ Resource Delta
+ Audit
```

例如 Audit Delta：

```json
{
  "wood": -50,
  "stone": -20,
  "money": -100
}
```

## 管理员操作

禁止直接手工修改生产数据库作为日常客服方式。

必须通过 Admin Action：

```text
Admin
→ Permission
→ Reason
→ Transaction
→ Before / After
→ Delta
→ Audit
```

补偿统一使用：

```text
ADMIN.COMPENSATION
```

## 禁止记录

```text
Password
Password Hash
Session ID
CSRF Token
Access Token
Refresh Token
Authorization Header
APP_KEY
DB_PASSWORD
AUDIT_HMAC_SECRET
```

## 发布前最低检查

```text
APP_DEBUG=false
HTTPS
Secure / HttpOnly Cookie
CSRF
Authorization
Rate Limit
Audit
Backup
No Secret in Git
No Secret in JS
No Stack Trace to Player
Admin Permission
Dependency Security Check
PWA Cache Version
```

## 必须自动测试

```text
A 不能读取 B 城市
A 不能修改 B 建筑
A 不能操作 B NPC
重复 Build 不重复扣款
重复 Reward 不重复领奖
重复 Market Trade 不重复成交
并发请求不产生负余额
非法 ID / Position 被拒绝
Event 只能结算一次
旧 Revision 被拒绝
管理员修改产生 Audit
普通玩家不能访问 Admin API
```

## 安全阶段

Phase 1：

```text
Auth
Authorization
CSRF
Validation
Transaction
Ownership
Rate Limit
Request ID
Audit
Backup
Server Authority
```

Phase 2：

```text
Idempotency
Revision
Row Lock
Security Log
Security Flags
Game Data Version
CSP
Impossible Delta Detection
```

Phase 3：

```text
Admin MFA
Audit HMAC Hash Chain
Central Monitoring
Alerting
WAF / CDN
Advanced Abuse Detection
Offsite Backup
Incident Runbook
```

详细开发规则以项目根目录 `CLAUDE.md` 的安全章节为准。
