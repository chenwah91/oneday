# CLAUDE.md

> 项目：城市 / 基地建设经营游戏  
> 技术路线：Vanilla HTML/CSS/JavaScript + PixiJS + Laravel + MySQL  
> 移动端：PWA 优先，后期 Capacitor  
> 架构：Modular Monolith（模块化单体）  
> 核心原则：简单、模块化、数据驱动、可扩展、移动端友好、性能稳定。

---

# 1. 固定技术栈

本项目正式使用以下技术栈：

## 前端

- HTML5
- CSS3
- Vanilla JavaScript
- ES Modules
- PixiJS

前端不使用构建工具。

禁止主动引入：

- React
- Vue
- Angular
- Svelte
- TypeScript
- Vite
- Webpack
- Rollup
- Parcel
- Tailwind 构建链
- Node.js 前端框架

除非项目负责人明确批准。

前端必须能够直接通过浏览器加载运行：

```html
<script type="module" src="/game/js/main.js"></script>
```

---

# 2. 后端

使用：

- PHP
- Laravel
- MySQL

Laravel 负责：

- 用户账号
- API
- Validation
- 游戏服务器逻辑
- 数据库读写
- Migration
- Session / Auth
- 后台任务
- Scheduler
- Queue（后期需要时）
- 日志

MySQL 负责：

- 玩家
- 城市
- 建筑
- 资源
- NPC
- 科技
- 市场
- 随机事件
- 存档
- 游戏定义数据

---

# 3. 手机端

第一阶段：

```text
Web
+
PWA
```

目标：

- 浏览器直接运行
- 手机可以添加到主屏幕
- UI 支持手机屏幕
- 静态资源可缓存
- 网络恢复后重新同步

后期：

```text
同一套 HTML/CSS/JS
↓
Capacitor
↓
Android / iOS
```

不要创建第二套手机前端。

Web、PWA、Android、iOS 必须尽量共用同一套前端代码。

---

# 4. 项目核心架构

使用：

## Modular Monolith

不要使用微服务。

系统结构：

```text
Browser / PWA / Capacitor
│
├── Vanilla HTML/CSS/JS
│
├── DOM UI
│
└── PixiJS Renderer
        │
        │ fetch(JSON)
        ▼
Laravel API
│
├── City
├── Resource
├── Building
├── Production
├── Population
├── Storage
├── Logistics
├── Technology
├── NPC
├── Market
├── Event
├── Defense
└── Simulation
        │
        ▼
      MySQL
```

---

# 5. 最重要的原则

## UI、Renderer、游戏规则必须分离

不要写：

```js
function buildFarm() {
    food -= 100;
    wood -= 50;

    document.querySelector("#food").textContent = food;
}
```

应该：

```js
const result = await api.build({
    buildingId: "FARM_001",
    x: 10,
    y: 20
});

state.apply(result);

hud.update();
renderer.update();
```

真正资源扣除必须由 Laravel/Game 模块决定。

客户端不能成为游戏权威。

---

# 6. 前端目录

推荐：

```text
public/
└── game/
    ├── index.html
    │
    ├── css/
    │   ├── base.css
    │   ├── layout.css
    │   ├── hud.css
    │   ├── panels.css
    │   ├── mobile.css
    │   └── components.css
    │
    ├── js/
    │   ├── main.js
    │   │
    │   ├── core/
    │   │   ├── api.js
    │   │   ├── state.js
    │   │   ├── config.js
    │   │   ├── events.js
    │   │   └── router.js
    │   │
    │   ├── renderer/
    │   │   ├── pixi-app.js
    │   │   ├── camera.js
    │   │   ├── map.js
    │   │   ├── chunks.js
    │   │   ├── buildings.js
    │   │   ├── resources.js
    │   │   └── effects.js
    │   │
    │   ├── ui/
    │   │   ├── hud.js
    │   │   ├── building-panel.js
    │   │   ├── npc-panel.js
    │   │   ├── technology-panel.js
    │   │   ├── market-panel.js
    │   │   ├── event-dialog.js
    │   │   ├── notification.js
    │   │   └── bottom-sheet.js
    │   │
    │   ├── modules/
    │   │   ├── city.js
    │   │   ├── resources.js
    │   │   ├── buildings.js
    │   │   ├── population.js
    │   │   ├── technology.js
    │   │   ├── npc.js
    │   │   ├── market.js
    │   │   └── events.js
    │   │
    │   └── utils/
    │       ├── dom.js
    │       ├── format.js
    │       └── math.js
    │
    └── assets/
        ├── ui/
        ├── buildings/
        ├── terrain/
        ├── npc/
        └── effects/
```

---

# 7. JavaScript 模块规则

所有 JavaScript 使用 ES Modules：

```js
export function formatNumber(value) {
    return value.toLocaleString();
}
```

```js
import { formatNumber } from "../utils/format.js";
```

不要：

- 把所有代码放到 `game.js`
- 使用大量全局变量
- 把对象挂到 `window`
- 使用巨型万能 `utils.js`
- 使用巨型 `GameManager`

---

# 8. 前端 State

保持一个清晰的 Client State。

例如：

```js
export const state = {
    city: null,
    resources: {},
    buildings: new Map(),
    npc: new Map(),
    technologies: new Set(),
    market: {},
    events: [],
    ui: {
        selectedBuildingId: null,
        activePanel: null
    }
};
```

但不要所有组件每次都刷新整个 State。

模块只更新自己需要的 UI。

---

# 9. PixiJS 职责

PixiJS 只负责地图和视觉。

负责：

- 地图
- Tile
- 建筑 Sprite
- 道路
- 资源点
- NPC 可视单位
- Camera
- Zoom
- Selection
- 特效
- 动画

不负责：

- 资源计算
- 建筑成本
- 人口增长
- NPC 工资
- 科技条件
- 市场价格
- 随机事件结算

---

# 10. DOM UI 职责

DOM UI 负责：

- HUD
- 建筑菜单
- 建筑详情
- 建筑升级
- NPC
- 科技树
- 市场
- 综合面板
- 事件窗口
- 设置
- Bottom Sheet
- Notification

不要把每栋地图建筑创建成 HTML Element。

地图建筑全部由 PixiJS 渲染。

---

# 11. Laravel 项目结构

推荐：

```text
app/
├── Game/
│   ├── City/
│   ├── Resource/
│   ├── Building/
│   ├── Production/
│   ├── Population/
│   ├── Storage/
│   ├── Logistics/
│   ├── Technology/
│   ├── NPC/
│   ├── Market/
│   ├── Event/
│   ├── Defense/
│   └── Simulation/
│
├── Http/
│   ├── Controllers/
│   └── Requests/
│
├── Models/
│
└── Jobs/
```

Controller 必须保持简单。

例如：

```php
public function build(
    BuildRequest $request,
    BuildBuilding $action
) {
    return $action->execute(
        $request->user(),
        $request->validated()
    );
}
```

不要把游戏公式写在 Controller。

---

# 12. 游戏 Definition 与 Runtime 分离

静态游戏定义：

```text
resource_definitions
building_definitions
building_level_definitions
technology_definitions
npc_definitions
item_definitions
event_definitions
market_definitions
```

玩家运行数据：

```text
users
cities
city_resources
city_buildings
city_npcs
city_technologies
city_events
market_state
```

例如：

```text
building_definition
=
什么是农田
```

```text
city_building
=
某玩家在 x=10 y=20 的 Level 2 农田
```

不要混在一起。

---

# 13. 数据驱动

禁止：

```php
if ($building->name === '钢铁厂') {
    $output = 14;
}
```

应该：

```php
$output = $buildingLevel->output_amount;
```

新增建筑时应尽可能只需要：

1. 新 Definition
2. 新 Level Definition
3. 新图片
4. 科技关联
5. Seed
6. Test

而不需要修改大量业务代码。

---

# 14. API

前端统一使用：

```js
fetch()
```

例如：

```js
await api.post(`/api/cities/${cityId}/build`, {
    buildingId,
    x,
    y
});
```

推荐 API：

```text
GET  /api/cities/{city}
GET  /api/cities/{city}/snapshot

POST /api/cities/{city}/build
POST /api/cities/{city}/upgrade
POST /api/cities/{city}/demolish

POST /api/cities/{city}/workers/assign

POST /api/cities/{city}/research

POST /api/cities/{city}/npc/assign

POST /api/cities/{city}/market/buy
POST /api/cities/{city}/market/sell

POST /api/cities/{city}/events/{event}/resolve
```

---

# 15. API 返回

避免每次返回完整城市。

优先返回变化：

```json
{
    "success": true,
    "revision": 105,
    "resources": {
        "wood": 520,
        "stone": 240
    },
    "buildingsChanged": [],
    "population": {
        "total": 850
    },
    "notifications": []
}
```

客户端定期重新读取完整 Snapshot 校准。

---

# 16. 游戏模拟

不要让 Laravel 每 5 秒扫描所有玩家城市。

使用：

## Time Delta Simulation

保存：

```text
last_simulated_at
```

玩家操作时：

```text
当前时间
-
last_simulated_at
=
elapsedSeconds
```

然后统一模拟这段时间。

例如：

```php
$elapsedSeconds = now()->diffInSeconds($city->last_simulated_at);

$result = $simulation->run(
    $city,
    $elapsedSeconds
);
```

---

# 17. 在线视觉 Tick

浏览器可以：

```text
requestAnimationFrame
```

用于地图视觉。

HUD 可以每：

```text
500ms ~ 1000ms
```

视觉更新一次。

但是这些显示不是服务器权威数据。

服务器只在：

- 玩家操作
- Snapshot
- 必要同步
- 后台任务

时进行真实结算。

---

# 18. 离线收益

不要逐秒模拟。

例如离线：

```text
8 小时
```

不要：

```text
循环 28,800 次
```

应该分段结算：

```text
生产
人口消耗
仓储限制
能源
维护
事件
```

并限制最大离线模拟时间。

例如：

```text
12小时 / 24小时
```

实际数值以后再定。

---

# 19. MySQL 事务

涉及：

- 建造
- 升级
- 市场交易
- 科技解锁
- 事件领取
- NPC 分配

必须使用数据库事务。

例如：

```php
DB::transaction(function () {
    // 锁资源
    // 检查
    // 扣除
    // 创建建筑
});
```

防止重复点击或并发请求导致资源复制。

---

# 20. 并发与 Revision

City 建议拥有：

```text
revision
```

每次重要修改：

```text
revision + 1
```

客户端可以携带：

```text
expectedRevision
```

用于减少并发覆盖。

不要相信客户端库存数量。

---

# 21. 手机布局

Desktop：

```text
Top HUD
+
Side Panel
+
PixiJS Map
```

Mobile：

```text
Compact HUD
+
PixiJS Map
+
Bottom Navigation
+
Bottom Sheet
```

不要简单把桌面 UI 缩小。

---

# 22. 手机触控

必须支持：

- Tap
- Drag Map
- Pinch Zoom
- Bottom Sheet
- 大按钮
- 不依赖 Hover

重要按钮触控区域建议至少约：

```text
44px
```

---

# 23. PixiJS 性能

必须考虑：

- Camera Culling
- Chunk
- Texture Atlas
- Sprite 重用
- Object Pool（需要时）
- Lazy Asset Loading
- DPR 限制
- Zoom Level Detail

不要：

```js
function update() {
    new PIXI.Sprite();
}
```

不要每帧创建大量对象。

---

# 24. 地图 Chunk

地图推荐按 Chunk 管理。

初始建议：

```text
32x32 tiles
```

或者：

```text
64x64 tiles
```

实际通过性能测试决定。

只渲染：

```text
Camera Visible Chunks
+
周围 Buffer
```

---

# 25. Asset

不要一次加载全部 10 个时代素材。

推荐：

```text
assets/
├── core/
├── era-1/
├── era-2/
├── era-3/
...
└── era-10/
```

只加载：

```text
当前时代
+
下一时代必要素材
```

---

# 26. PWA

PWA 至少包含：

```text
manifest.json
service-worker.js
icons/
```

Service Worker 主要缓存：

- HTML
- CSS
- JS
- UI 图片
- 当前时代素材

不要缓存敏感 API Response 作为永久游戏存档。

玩家数据仍以服务器为准。

---

# 27. Capacitor

后期加入 Capacitor 时：

不要重写前端。

继续使用：

```text
HTML
CSS
JavaScript
PixiJS
```

Capacitor 只作为：

```text
Web App
↓
Native Container
```

需要时再接：

- Push Notification
- Haptics
- App Review
- Native Storage
- Share
- Status Bar

---

# 28. UI 模块规范

一个 UI 模块建议：

```js
export class BuildingPanel {
    constructor({ api, state }) {}

    mount(element) {}

    open(buildingId) {}

    render() {}

    close() {}

    destroy() {}
}
```

不要在不同文件随意：

```js
document.querySelector(...)
```

公共 DOM 操作尽量集中。

---

# 29. 前端事件

可以建立一个轻量 Event Bus。

只用于 UI / Renderer 通知。

例如：

```text
building:selected
building:updated
resource:changed
city:updated
panel:open
```

不要让 Event Bus 成为游戏服务器逻辑。

---

# 30. Random

随机事件必须由服务器决定。

客户端不能：

```js
Math.random()
```

决定：

- 掉落
- 市场核心价格
- 随机事件结果
- 奖励
- NPC 稀有度

客户端 Random 只能用于：

- 粒子
- 动画
- 装饰效果

---

# 31. 安全

客户端传来的全部数据不可信。

必须验证：

```text
buildingId
level
x
y
resourceAmount
npcId
technologyId
marketAmount
eventChoice
```

不要相信：

```text
客户端传入建筑成本
客户端传入生产结果
客户端传入余额
```

---

# 32. 错误 Code

API 使用稳定 Error Code：

```text
INSUFFICIENT_RESOURCE
BUILDING_LIMIT_REACHED
TECH_NOT_UNLOCKED
ERA_REQUIRED
LAND_OCCUPIED
INVALID_POSITION
WORKER_NOT_AVAILABLE
POWER_SHORTAGE
STORAGE_FULL
NPC_ALREADY_ASSIGNED
MARKET_LIMIT_REACHED
EVENT_EXPIRED
REVISION_CONFLICT
```

前端根据 Error Code 显示本地文本。

---

# 33. 游戏数值 Source of Truth

Claude Code 开发前必须读取：

```text
docs/game-design/
城市基地建设经营游戏_V3数值数据库开发规格.md
```

建筑、资源、NPC、科技、市场、事件数值以该文档为准。

不要擅自修改：

- 建筑 ID
- 资源 ID
- NPC ID
- 科技 ID
- 事件 ID
- 数值倍率

需要调整时先明确修改设计数据。

---

# 34. 开发顺序

## Phase 1

核心可玩：

```text
Login
City
Map
Resource
Building
Build
Storage
Production
Population
Food
Save
```

目标：

> 玩家可以建立城市、生产粮食并养活人口。

## Phase 2

```text
Building Level
Technology
Logistics
Happiness
Governance
Energy
Era Upgrade
```

## Phase 3

```text
NPC
Tools
Market
Random Events
Defense
```

## Phase 4

```text
PWA
Offline Optimization
Mobile UX
Performance
Capacitor
Push
Analytics
```

不要跳阶段做大量高级系统。

---

# 35. 测试

Laravel 核心游戏逻辑必须测试。

包括：

```text
建筑建造
建筑升级
资源扣除
占地
生产
人口粮食消耗
人口增长
仓储
科技
NPC
市场
事件
文明升级
离线模拟
并发重复请求
```

---

# 36. Claude Code 工作规则

每次修改前：

1. 阅读 `CLAUDE.md`
2. 阅读相关模块
3. 阅读 V3 数值规格相关章节
4. 检查已有实现
5. 使用最小改动完成任务

不要：

- 顺便大规模重构
- 修改无关代码
- 引入新框架
- 引入新构建系统
- 修改游戏数值
- 创建重复模块

---

# 37. Dependency 规则

前端尽量保持少依赖。

允许核心：

```text
PixiJS
```

其他前端第三方库必须有明确价值。

Laravel Composer Package 同样保持克制。

新 Dependency 必须回答：

```text
为什么原生能力不能完成？
这个 Package 是否长期维护？
会不会让部署复杂化？
```

---

# 38. 性能优化原则

先实现正确版本。

然后 Profiling。

优先排查：

```text
PixiJS Object 数量
Canvas Resolution
React（本项目不存在）
DOM 更新次数
Texture 大小
Chunk 范围
数据库 N+1
Simulation 全表扫描
高频 API
重复 JSON 数据
```

不要在没有数据时提前复杂优化。

---

# 39. 当前最终技术决策

```text
Frontend
= HTML5 + CSS3 + Vanilla JavaScript ES Modules

Renderer
= PixiJS

Frontend Framework
= NONE

Frontend Build Tool
= NONE

Backend
= PHP + Laravel

Database
= MySQL

Architecture
= Modular Monolith

Game Authority
= Laravel Server

Simulation
= Server Time Delta Simulation

Web
= Browser

Mobile Phase 1
= PWA

Mobile Phase 2
= Capacitor

Realtime Socket
= 暂时不需要

Redis
= 暂时不需要

Queue
= 有真实需求再启用

Full Game Engine
= 不需要
```

---

# 40. 最终判断

遇到架构选择时按照：

```text
简单
↓
稳定
↓
可测试
↓
模块清楚
↓
方便增加内容
↓
手机性能安全
↓
再考虑更复杂技术
```

本项目不追求技术炫技。

目标是：

> 用尽量简单的架构，长期维护并持续增加建筑、资源、时代、NPC、科技、市场与事件内容，同时保证网页与手机体验稳定。

---

# 41. 安全总体原则

安全属于核心架构，不是上线前才补的功能。

本项目采用：

```text
Client Is Untrusted
+
Server Is Authority
+
Every Important Mutation Is Validated
+
Every Important Mutation Is Traceable
```

核心要求：

1. 前端发送的任何值都视为不可信。
2. 所有经济、建筑、NPC、科技、市场、事件结果由 Laravel 决定。
3. 重要修改必须经过认证、授权、输入验证、游戏规则验证、并发验证、事务和审计。
4. 游戏运行日志与审计日志分开。
5. 审计日志采用 Append-Only 思路，业务代码不得修改历史记录。
6. 不在日志中保存密码、Session ID、Access Token、CSRF Token、数据库密码或其他 Secrets。
7. 安全失败必须 Fail Closed：无法确认权限时拒绝操作，而不是继续执行。

安全检查基线参考 OWASP ASVS Level 2，并根据项目实际复杂度逐步执行，不需要一开始实现企业级安全平台。

---

# 42. 请求安全处理链

所有会修改玩家状态的请求按照统一流程：

```text
HTTP Request
   ↓
HTTPS
   ↓
Authentication
   ↓
CSRF / Session Check
   ↓
Rate Limit
   ↓
Request ID
   ↓
Input Validation
   ↓
Authorization
   ↓
Idempotency Check
   ↓
City Revision Check
   ↓
Game Rule Validation
   ↓
Database Transaction
   ↓
Row Lock（必要时）
   ↓
Apply Mutation
   ↓
Invariant Check
   ↓
Audit Log
   ↓
Commit
   ↓
Return Diff
```

任何一步失败：

```text
Reject
+
记录必要的安全事件
+
不修改游戏状态
```

---

# 43. Authentication

Web / PWA 第一阶段优先使用 Laravel Session Authentication。

要求：

- Password 使用 Laravel 标准 Hash API。
- 不自行实现密码加密算法。
- 登录成功后可重新生成 Session。
- Cookie 必须使用 Secure（生产 HTTPS）、HttpOnly，并设置合理 SameSite。
- Session 到期后必须重新认证。
- 敏感操作可以要求近期重新认证。
- 管理员账号必须与普通玩家权限严格分离。
- 管理后台建议后续启用 MFA / 2FA。

Capacitor 阶段如果仍通过同源 Web Session 工作，可以继续使用现有认证模式；如果改为独立 Token 模式，必须另外设计 Token 生命周期和吊销机制，不要直接把长期 Token 写进普通 localStorage。

---

# 44. Authorization

Authentication 只表示：

> 你是谁。

Authorization 才表示：

> 你是否可以操作这座城市 / 这个 NPC / 这个建筑。

所有资源都必须校验 Ownership。

例如：

```php
if ($city->user_id !== $request->user()->id) {
    abort(403);
}
```

实际项目优先使用 Laravel Policy / Gate 或统一 Authorization Service。

必须检查：

```text
City 属于当前玩家
Building 属于该 City
NPC 属于该 City / Player
Event 属于该 City
Market Order 属于当前玩家
Technology 属于该 City
```

绝对不能只因为客户端知道一个：

```text
cityId
buildingId
npcId
```

就允许操作。

---

# 45. Input Validation

所有 API 输入必须使用 Allowlist Validation。

例如：

```text
buildingId
x
y
rotation
npcId
quantity
technologyId
eventChoice
```

都必须验证：

```text
类型
长度
范围
枚举
格式
数据库关联
业务条件
```

不要信任：

```text
客户端传来的建筑价格
客户端传来的资源余额
客户端传来的产量
客户端传来的建筑等级
客户端传来的市场成交价
客户端传来的事件奖励
客户端时间
```

例如客户端只发送：

```json
{
    "buildingId": "BLD_FARM_001",
    "x": 10,
    "y": 20
}
```

服务器自行读取：

```text
成本
建造时间
时代需求
科技需求
占地
建筑上限
```

---

# 46. SQL / Database 安全

数据库访问优先使用：

- Eloquent
- Query Builder
- Parameter Binding

不要拼接客户端字符串生成 SQL。

不要：

```php
DB::select("SELECT * FROM cities WHERE id = " . $_GET['id']);
```

数据库用户采用最小权限。

建议至少分：

```text
Application DB User
Migration / Deployment DB User
Backup User
```

生产 Application DB User 不应拥有不必要的：

```text
DROP DATABASE
CREATE USER
GRANT
```

权限。

---

# 47. CSRF

如果 Web / PWA 使用 Laravel Session Cookie Authentication：

所有修改状态的请求必须启用 CSRF 防护。

例如：

```text
POST
PUT
PATCH
DELETE
```

前端 API 模块统一处理 CSRF Token。

不要为了 API 方便直接关闭整个项目的 CSRF。

如果未来存在真正独立的 Token API，再按 Token API 模式单独设计。

---

# 48. Rate Limit

以下接口必须限流：

```text
Login
Register
Password Reset

Build
Upgrade
Demolish

NPC Assign

Research

Market Buy
Market Sell

Event Resolve

Snapshot Refresh
```

限流维度可以组合：

```text
IP
User ID
City ID
Action
```

不同操作使用不同限制。

例如市场、建筑、事件接口不能与普通 GET 使用完全一样的频率规则。

触发限流时记录：

```text
request_id
user_id
ip
route
action
timestamp
```

但不要把敏感凭证写入日志。

---

# 49. Idempotency

所有会改变经济状态的重要 API 建议支持：

```text
Idempotency-Key
```

例如：

```text
Build
Upgrade
Market Buy
Market Sell
Claim Reward
Resolve Event
Purchase
```

客户端为一次用户操作生成 UUID：

```text
7f8c...
```

重复提交相同 Key：

```text
不得再次扣资源
不得再次发奖励
不得再次创建建筑
```

数据库建议建立：

```text
idempotency_keys
```

字段：

```text
id
user_id
city_id
key
action
request_hash
response_status
response_body / response_reference
created_at
expires_at
```

唯一索引建议：

```text
(user_id, key)
```

---

# 50. City Revision

每座城市保存：

```text
revision BIGINT
```

重要 Mutation 成功：

```text
revision = revision + 1
```

客户端命令可带：

```text
expectedRevision
```

服务器发现：

```text
expectedRevision != currentRevision
```

返回：

```text
REVISION_CONFLICT
```

客户端重新获取 Snapshot / Diff 后再操作。

Revision 用于：

- 防止多个 Tab 覆盖。
- 防止重复点击。
- 防止旧页面覆盖新状态。
- 辅助审计追踪。

Revision 不能代替数据库事务。

---

# 51. Database Transaction 与 Row Lock

以下操作必须事务化：

```text
Build
Upgrade
Demolish
Research
NPC Assignment
Market Buy/Sell
Event Reward
Admin Resource Adjustment
```

涉及余额 / 库存 / 唯一状态时，根据实际情况使用：

```text
SELECT ... FOR UPDATE
```

Laravel 可通过：

```php
->lockForUpdate()
```

配合 Transaction。

典型流程：

```text
BEGIN

Lock City
Lock Relevant Resource Rows

Validate

Apply Time Delta Simulation

Revalidate Resource Balance

Deduct Resource

Create / Update Entity

Check Invariants

Write Audit Event

Increment Revision

COMMIT
```

失败：

```text
ROLLBACK
```

---

# 52. 游戏 Invariant 安全检查

每次重要 Mutation 后检查游戏不变量。

必须保证：

```text
resource.amount >= 0
resource.amount <= 合理系统上限

building.level BETWEEN 1 AND 3

building position 不重叠

building count <= era / city limit

NPC 不能同时被分配到两个互斥岗位

technology prerequisite 必须满足

event reward 只能领取一次

market quantity > 0

market cost 必须由服务器计算

city population >= 0

worker assigned <= available workers

storage used 不出现无效负数

power / logistics modifier 在允许范围

production multiplier 不超过设计硬上限
```

如果不变量失败：

```text
Rollback
+
Security Log
+
生成 request_id
```

不要自动“修正后继续”。

---

# 53. Audit Trail：可追溯审计记录

普通日志：

```text
用于 Debug / Error / Performance
```

审计日志：

```text
用于回答：
谁？
什么时候？
在哪个请求？
修改了什么？
修改前是什么？
修改后是什么？
为什么修改？
资源变化多少？
是否为管理员操作？
```

两者必须分开。

---

# 54. Audit Log 数据表

建议建立：

```sql
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    occurred_at DATETIME(6) NOT NULL,

    request_id CHAR(36) NOT NULL,
    trace_id CHAR(36) NULL,
    idempotency_key VARCHAR(100) NULL,

    actor_type VARCHAR(32) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,

    user_id BIGINT UNSIGNED NULL,
    city_id BIGINT UNSIGNED NULL,

    action VARCHAR(80) NOT NULL,

    entity_type VARCHAR(64) NULL,
    entity_id VARCHAR(64) NULL,

    city_revision_before BIGINT UNSIGNED NULL,
    city_revision_after BIGINT UNSIGNED NULL,

    status VARCHAR(24) NOT NULL,

    reason_code VARCHAR(80) NULL,

    ip_address VARCHAR(45) NULL,
    user_agent_hash CHAR(64) NULL,

    before_json JSON NULL,
    after_json JSON NULL,
    delta_json JSON NULL,
    metadata_json JSON NULL,

    previous_hash CHAR(64) NULL,
    event_hash CHAR(64) NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_audit_request (request_id),
    INDEX idx_audit_user_time (user_id, occurred_at),
    INDEX idx_audit_city_time (city_id, occurred_at),
    INDEX idx_audit_action_time (action, occurred_at),
    INDEX idx_audit_entity (entity_type, entity_id)
);
```

如果预计审计量非常大：

- `before_json / after_json` 不要无脑保存整个 City。
- 优先保存发生变化的字段。
- `delta_json` 保存资源 / 数值差异。
- 大日志后期可以归档。

---

# 55. Audit Action 命名

采用稳定 Action Code。

例如：

```text
AUTH.LOGIN_SUCCESS
AUTH.LOGIN_FAILED
AUTH.LOGOUT

CITY.CREATE

BUILDING.BUILD
BUILDING.UPGRADE
BUILDING.DEMOLISH

RESOURCE.ADMIN_ADJUST
RESOURCE.EVENT_REWARD

NPC.ASSIGN
NPC.UNASSIGN

TECH.RESEARCH_START
TECH.UNLOCK

MARKET.BUY
MARKET.SELL

EVENT.TRIGGER
EVENT.RESOLVE
EVENT.REWARD

ERA.UPGRADE

ADMIN.LOGIN
ADMIN.PLAYER_BAN
ADMIN.PLAYER_UNBAN
ADMIN.RESOURCE_ADJUST
ADMIN.CONFIG_CHANGE

SECURITY.RATE_LIMIT
SECURITY.AUTHORIZATION_FAILED
SECURITY.VALIDATION_FAILED
SECURITY.REVISION_CONFLICT
SECURITY.INVARIANT_FAILED
SECURITY.SUSPICIOUS_ACTIVITY
```

Action Code 进入生产后尽量保持稳定。

---

# 56. Audit Resource Delta

经济类日志不要只记录：

```text
BUILDING.BUILD
```

还要保存资源变化：

```json
{
    "wood": -50,
    "stone": -20,
    "money": -100
}
```

市场：

```json
{
    "resource": "iron",
    "quantity": 20,
    "unitPrice": 15.4,
    "moneyDelta": -308,
    "fee": 6
}
```

这样以后可以从：

```text
玩家资源异常
```

反查：

```text
具体是哪一个 Action 导致的。
```

---

# 57. Audit Before / After

不要保存整个对象的大型 Snapshot。

只保存关键变化。

例如建筑升级：

```json
before:
{
    "level": 1,
    "workers": 3
}

after:
{
    "level": 2,
    "workers": 3
}
```

资源变化放：

```text
delta_json
```

大量对象的完整历史使用独立 Snapshot / Backup 系统，不要用 Audit Log 代替 Backup。

---

# 58. Audit Tamper Evidence

审计日志应尽量做到：

```text
Append Only
```

应用代码禁止：

```text
UPDATE audit_logs
DELETE audit_logs
```

进一步可以使用 Hash Chain：

```text
previous_hash
event_hash
```

概念：

```text
event_hash =
HMAC(
    canonical_event_payload
    + previous_hash,
    server_audit_secret
)
```

这样可以检测历史审计记录被篡改的情况。

注意：

- `server_audit_secret` 不能存在数据库。
- Secret 放在部署 Secret / Environment 管理中。
- 不把 Secret 写进 Git。
- Hash Chain 是篡改检测手段，不是数据库备份。
- 更高等级时可以定期把最新 Hash Anchor 保存到独立存储。

前期如果觉得 HMAC Chain 实现成本高：

第一阶段至少实现：

```text
Append-Only Audit
+
DB 权限限制
+
Backup
```

Hash Chain 可以第二阶段增加。

---

# 59. Request ID / Trace ID

每一个进入 Laravel 的请求生成：

```text
request_id UUID
```

响应 Header 返回：

```text
X-Request-ID
```

所有：

```text
Application Log
Security Log
Audit Log
Exception Log
```

都带相同：

```text
request_id
```

复杂后台流程可增加：

```text
trace_id
```

例如：

```text
Market Trade
↓
Resource Change
↓
Achievement
↓
Notification
```

共用一个 Trace。

这样可以从玩家截图里的：

```text
Request ID
```

直接追查整个服务器处理链。

---

# 60. Security Log

建议单独 Security Channel：

```text
storage/logs/security.log
```

或结构化日志输出。

记录：

```text
Login Failure
Authorization Failure
Rate Limit
Suspicious Validation Failure
Revision Conflict Burst
Invariant Failure
Admin Action
Repeated Idempotency Conflict
Impossible Resource Delta
```

Security Log 与 Audit Log 可以部分重叠，但目的不同：

```text
Audit = 业务可追溯
Security Log = 异常检测
```

---

# 61. 不允许记录的敏感数据

日志中禁止出现：

```text
Password
Password Hash
Session ID
CSRF Token
Access Token
Refresh Token
Authorization Header
Database Password
APP_KEY
Encryption Key
Audit HMAC Secret
支付完整凭证
```

Request Body 不能默认全部 Dump。

必须：

```text
Allowlist Logging
```

而不是：

```text
Log Everything
```

---

# 62. IP 与隐私

IP 可以作为安全追踪数据。

但是：

- 只在确实有安全用途时保存。
- 设定 Retention。
- 普通业务日志不需要永久保存 IP。
- User Agent 可以保存 Hash 或截断后的识别信息。
- 后续正式商业化时根据运营地区隐私要求调整数据保留政策。

审计数据不要永久无限增长。

建议建立明确 Retention Policy。

---

# 63. 管理员安全

管理后台是最高风险区域。

管理员与普通玩家必须区分 Role / Permission。

至少：

```text
PLAYER
SUPPORT
GAME_MASTER
ADMIN
SUPER_ADMIN
```

权限按最小权限分配。

例如 Support 可以：

```text
查看玩家状态
查看 Audit
```

但默认不能：

```text
直接增加资源
修改游戏 Definition
封禁玩家
```

任何管理员修改玩家状态必须：

```text
Audit Log
+
Admin ID
+
Reason
+
Before
+
After
+
Delta
```

管理员调整资源必须强制输入：

```text
reason
ticket/reference（有则填）
```

---

# 64. Definition 修改审计

游戏 Definition 修改会影响大量玩家，因此必须追踪。

需要记录：

```text
谁修改
什么时候修改
Definition ID
旧版本
新版本
修改原因
版本号
```

不要直接在生产数据库：

```text
UPDATE building_definitions ...
```

而没有记录。

推荐：

```text
Definition Seed / Migration
+
Version
+
Admin Change Audit
```

长期可以增加：

```text
game_data_version
```

每次登录 / Snapshot 带：

```text
dataVersion
```

方便定位“玩家当时使用的是哪一版数值”。

---

# 65. Game Data Version

建立：

```text
game_data_versions
```

例如：

```text
V3.0.0
V3.0.1
V3.1.0
```

记录：

```text
version
checksum
deployed_at
deployed_by
notes
```

Audit Log 可以记录：

```text
game_data_version
```

这样半年以后仍能回答：

> 这个建筑当时为什么产出这个数值？

---

# 66. 反作弊：服务器权威

客户端只负责：

```text
显示
输入
预测动画
```

客户端不能决定：

```text
资源余额
产量
随机数
市场成交
事件奖励
建筑是否成功
科技是否解锁
NPC 是否升级
文明是否升级
```

所有重要 Random 由服务器执行。

玩家修改 JS：

```text
food = 999999999
```

只会改变自己的显示，不会改变服务器数据。

---

# 67. 反作弊：行为检测

前期不要建立复杂反作弊 AI。

先做简单规则。

例如：

```text
同一秒大量 Build 请求
异常大量 Revision Conflict
重复 Idempotency Key
市场成交速度异常
资源增长速度超理论最大值
NPC 同时出现在多个岗位
短时间大量登录失败
多城市请求 Ownership 失败
```

可以形成：

```text
security_flags
```

数据：

```text
user_id
flag_type
score
first_seen_at
last_seen_at
count
status
metadata
```

先：

```text
记录
↓
人工检查
```

不要一发现异常就自动永久封号。

---

# 68. 理论最大产量检查

服务器可计算玩家理论最大值。

例如：

```text
基础产量
× Building Level
× NPC
× Tool
× Technology
× Event
```

V3 已有生产倍率上限时，可以检查：

```text
实际 Resource Delta
>
理论最大 Resource Delta + tolerance
```

触发：

```text
SECURITY.IMPOSSIBLE_RESOURCE_DELTA
```

正常游戏逻辑不应该依赖此检查才能正确。

它是：

```text
Detection Layer
```

不是：

```text
Game Calculation Layer
```

---

# 69. Market 安全

市场是高风险经济模块。

必须：

- 服务器计算成交价。
- 服务器计算手续费。
- 校验 quantity。
- 校验余额。
- 校验库存。
- Transaction。
- Idempotency。
- Rate Limit。
- Audit。
- 防止相同订单重复结算。
- 防止负数 / NaN / 超大数字。
- 设置单笔和时间窗口成交量限制。

市场记录必须可以追踪：

```text
buyer
seller / system
resource
quantity
price
fee
timestamp
request_id
```

---

# 70. 随机事件安全

随机事件必须保存：

```text
event_instance_id
event_definition_id
city_id
triggered_at
expires_at
status
```

Resolve 时：

```text
检查属于该玩家
检查 ACTIVE
检查没有过期
检查 Choice 合法
检查未领取
```

结算后：

```text
status = RESOLVED
```

同一个 Instance 不允许再次领取。

---

# 71. PWA 安全

Service Worker 只缓存适合的静态资源。

不要永久缓存：

```text
用户完整 Snapshot
Auth Response
敏感 API
管理员 API
```

更新 Service Worker 时要有版本控制。

不要让旧版本 JS 长期与新 API 不兼容。

前端发现：

```text
clientVersion
<
minimumSupportedVersion
```

应该提示刷新 / 更新。

---

# 72. Capacitor 安全

Capacitor 阶段：

- 不把 Secret 写进 JS Bundle。
- App 内任何 API Key 都视为可被提取。
- 真正 Secret 放服务器。
- Native Storage 只用于需要的客户端信息。
- 如果保存认证 Token，使用更安全的系统存储方案，而不是普通 localStorage。
- Deep Link / Intent 输入同样视为不可信。
- WebView 与外部 URL 跳转使用明确 Allowlist。

---

# 73. Security Headers

生产环境应配置合理 HTTP Security Headers。

至少评估：

```text
Content-Security-Policy
X-Content-Type-Options
Referrer-Policy
Permissions-Policy
Strict-Transport-Security
```

CSP 要根据 PixiJS、图片、字体、API 的真实来源设置。

不要为了省事：

```text
script-src *
```

也不要默认使用大量：

```text
unsafe-inline
unsafe-eval
```

如果第三方库确实要求，再明确记录原因。

---

# 74. HTTPS

生产环境必须使用 HTTPS。

HTTP：

```text
Redirect → HTTPS
```

认证 Cookie：

```text
Secure
HttpOnly
SameSite
```

本地开发环境可以例外。

---

# 75. Secrets

Secret 不进入 Git。

包括：

```text
APP_KEY
DB_PASSWORD
MAIL_PASSWORD
API_SECRET
AUDIT_HMAC_SECRET
第三方服务 Key
```

使用：

```text
.env
Deployment Secret
Hosting Secret Manager
```

`.env` 必须加入：

```text
.gitignore
```

提交：

```text
.env.example
```

只保留字段名，不保留真实 Secret。

---

# 76. Dependency 安全

虽然前端依赖很少，也要管理 PixiJS 版本。

Laravel Composer Dependency：

- 不随意安装未知 Package。
- 新 Package 必须说明用途。
- 定期检查已知漏洞。
- 删除不再使用的 Package。
- `composer.lock` 必须提交版本控制。

第三方 JS 如果使用 CDN：

- 固定明确版本。
- 不使用 `latest`。
- 评估使用 Subresource Integrity（如果部署方式适用）。
- 更推荐将核心 PixiJS 版本作为项目静态资源管理，减少不可控 CDN 变化。

---

# 77. File Upload

如果以后支持：

```text
头像
城市徽章
联盟图片
```

上传文件必须：

- 验证真实 MIME。
- 限制大小。
- 限制允许扩展名。
- 重命名服务器文件。
- 不信任原始文件名。
- 不允许任意路径。
- 上传目录不能执行 PHP。
- 图片可重新编码处理。
- 后期高风险场景可加入恶意文件扫描。

第一版如果没有上传需求，不要提前建立复杂 Upload 系统。

---

# 78. Error Handling

生产环境不能向玩家显示：

```text
SQL Error
Stack Trace
Filesystem Path
.env
Server Internal Details
```

玩家得到：

```json
{
    "success": false,
    "error": "INTERNAL_ERROR",
    "requestId": "..."
}
```

服务器日志保存详细 Exception。

玩家可以把：

```text
requestId
```

交给管理员追查。

---

# 79. Backup 与 Audit 的区别

Audit：

```text
发生了什么
```

Backup：

```text
数据当时是什么
```

两者不能互相替代。

MySQL 必须建立：

- 定期数据库 Backup。
- Backup Retention。
- Restore 测试。
- Definition / Migration 存 Git。
- 重要生产备份与生产数据库隔离。

不要只“有 Backup 文件”。

必须测试：

```text
能否恢复。
```

---

# 80. 数据恢复与补偿

如果出现 Bug 导致玩家资源错误：

不要直接手动 SQL：

```text
UPDATE city_resources ...
```

推荐通过 Admin Compensation Action：

```text
Admin
↓
填写 Reason
↓
Compensation Service
↓
Transaction
↓
Resource Delta
↓
Audit
```

例如：

```text
ADMIN.COMPENSATION
```

这样所有人工补偿都有来源。

---

# 81. Security Check：开发阶段

Claude Code 每次涉及以下代码时必须额外检查安全：

```text
Auth
Authorization
Database Write
Market
Reward
Resource
Admin
File Upload
Session
API
Random
Game Definition Update
```

Code Review Checklist：

```text
[ ] 是否信任了客户端不该提供的数据？
[ ] 是否检查 Ownership？
[ ] 是否检查输入类型与范围？
[ ] 是否存在 SQL 拼接？
[ ] 是否需要 Transaction？
[ ] 是否存在重复请求问题？
[ ] 是否需要 Idempotency？
[ ] 是否需要 Rate Limit？
[ ] 是否产生重要经济变化？
[ ] 是否写 Audit？
[ ] 是否记录了 Secret？
[ ] 是否可能出现负数 / Overflow / NaN？
[ ] 是否可能越权访问其他 City / NPC / Building？
```

---

# 82. Security Check：发布前

每次正式发布至少执行：

```text
[ ] APP_DEBUG=false
[ ] HTTPS 正常
[ ] Secure Cookie 正常
[ ] CSRF 正常
[ ] Auth / Authorization 测试通过
[ ] Rate Limit 测试通过
[ ] Migration Review
[ ] DB Backup 完成
[ ] Restore Procedure 可用
[ ] .env 未进入 Git
[ ] 没有 Secret 写在 JS
[ ] 没有 Debug Endpoint
[ ] Admin Route 有权限保护
[ ] Audit 正常写入
[ ] Error Response 不泄露 Stack Trace
[ ] 依赖漏洞检查
[ ] PWA Cache Version 正确
```

---

# 83. 自动安全测试

Laravel 测试至少加入：

```text
玩家 A 不能读取玩家 B 的城市
玩家 A 不能升级玩家 B 的建筑
玩家 A 不能操作玩家 B 的 NPC
重复 Build Idempotency 不重复扣款
重复 Market Buy 不重复成交
事件奖励只能领取一次
余额不足 Transaction 回滚
并发购买不会产生负余额
非法 Building ID 返回 Validation Error
非法 Position 被拒绝
过期 Event 被拒绝
旧 Revision 被拒绝
管理员修改有 Audit
普通玩家不能访问 Admin API
```

这些属于核心测试，不是可选测试。

---

# 84. 安全记录保留策略

不要无限保存所有日志。

建议区分：

```text
Application Debug Logs
Security Logs
Audit Logs
Admin Audit
Market Transaction History
Backups
```

每类设置不同 Retention。

具体天数根据：

- 玩家规模
- 磁盘成本
- 商业需求
- 法律 / 隐私要求

上线前再确定。

但是：

```text
Admin Economic Adjustment
Purchase / Payment Related
重大处罚
```

通常需要比普通 Debug Log 更长的记录周期。

---

# 85. 安全等级规划

## Phase 1 — 必须

```text
HTTPS
Laravel Auth
Authorization
CSRF
Validation
DB Transaction
Ownership Check
Rate Limit
Request ID
Audit Log
Admin Audit
Server Authority
Backup
Error Hiding
```

## Phase 2 — 强化

```text
Idempotency
Revision
Row Lock
Security Log
Security Flags
Game Data Version
Impossible Delta Detection
Dependency Scan
CSP
```

## Phase 3 — 商业化 / 大规模

```text
Admin MFA
Audit Hash Chain
Independent Log Storage
Central Monitoring
Alerting
WAF / CDN Protection
Advanced Bot Detection
Automated Abuse Scoring
Backup Offsite
Incident Response Runbook
```

不要 Phase 1 都没完成就建立昂贵复杂的安全平台。

---

# 86. Claude Code 安全规则

Claude Code 不得：

- 为了调试关闭 CSRF 后忘记恢复。
- 把 `APP_DEBUG=true` 当生产配置。
- 在 Git 中写真实 Secret。
- 在前端写管理员 Secret。
- 信任客户端 Resource / Cost / Reward。
- 绕过 Ownership Check。
- 对 Audit Log 提供普通 Update / Delete API。
- 为修 Bug 直接修改玩家数据库而不留 Audit。
- 静默吞掉 Transaction Error。
- 在 Log 中输出整个 Request Header / Cookie。
- 使用 `Math.random()` 决定服务器经济结果。
- 用前端时间决定奖励。
- 用建筑名称代替稳定 Definition ID。

如果任务涉及经济状态修改，Claude Code 实现完成后必须说明：

```text
Validation
Authorization
Transaction
Idempotency
Audit
```

分别在哪里处理。

---

# 87. 安全架构最终数据流

```text
Player
  ↓
Vanilla JS / PixiJS
  ↓
HTTPS
  ↓
Laravel Middleware
  ├── Request ID
  ├── Auth
  ├── CSRF
  └── Rate Limit
  ↓
FormRequest Validation
  ↓
Policy / Authorization
  ↓
Game Action
  ├── Idempotency
  ├── Revision
  ├── Game Rules
  └── Time Delta Simulation
  ↓
MySQL Transaction
  ├── Row Lock
  ├── Mutation
  ├── Invariant Check
  ├── Audit Log
  └── Revision + 1
  ↓
Commit
  ↓
JSON Diff
  ↓
Client State
  ↓
DOM UI / PixiJS Renderer
```

这是项目后续安全相关开发默认遵循的流程。

---

# 88. 项目安全目标

本项目的安全目标不是：

> 让客户端无法被修改。

浏览器 JavaScript 永远应该被假设可以被玩家查看和修改。

真正目标是：

> 即使客户端被完全修改，玩家仍不能通过客户端直接制造服务器资源、越权修改其他玩家数据、重复领取奖励或修改游戏规则；同时任何关键经济变化都可以追查来源。
