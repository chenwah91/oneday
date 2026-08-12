# R1-B 性能粗查(2026-08-12)

> 范围:`docs/plans/2026-08-12-launch-r1.md` 的 R1-B 第 3 项「性能粗查一轮:慢查询 / N+1 / 快照响应体积」。
> **只查只记,本轮一行业务代码都没改。**
> 口径按用户 2026-08-12 拍板的「不过度开发」:能给出基线数字、上线后有玩家量再看的,一律进 🟡。

## 怎么测的

- 自建测试库 `apg_test_r1b`(migrate + seed 全新库),**开发库 `apg` 一个字没动**;测完即 `DROP`。
- 计数法:`DB::listen` 逐条抓 SQL,按「归一化后的 SQL 模板」聚合,记条数 + DB 累计耗时 + 墙钟。
- 判 N+1 的方式不是读代码猜,是**同一条路径在四档规模下跑一遍看条数怎么长**:
  条数随规模线性上涨 = N+1;条数不变 = 一次查完。
- 造的城:时代 VIII、人口 4000、资金 500 万、31 种资源全铺满、20 条科技已解锁、
  3 条 active 事件 + 7 条历史、8 条生效中的 modifier;建筑 / NPC / 工具三样按档位放大。
- `audit_logs` 灌 6 万行(分散在 50 个 user/city 上),`city_building_instances` 灌 6 万行(300 座城)
  —— 单城数据量测不出索引选择,必须有「多玩家共用一张大表」的背景数据。
- 环境:本地 XAMPP MariaDB 10.4 + PHP 8.3(**不是**线上 MySQL 5.7,绝对值只作相对比较用)。

---

## 一、分档结论

### 🔴 上线前该修

**无。** 本轮没有测到会在 R1 首发规模(几十到几百玩家)上出事的项。

### 🟡 观察(记下基线,上线后有玩家量再看)

| # | 项 | 现场 | 基线数字 | 触发再看的条件 |
|---|---|---|---|---|
| 1 | **懒结算的逐行写循环**(唯一真正的 N+1,在**写**侧不在读侧)| `NpcRuntimeService::stepMorale()` / `stepXp()` 逐个 NPC 一条 `UPDATE`;`ItemRuntimeService` 逐件装备中工具一条 `UPDATE` | 每次快照写 ≈ **1.5 × NPC 数 + 工作中的装备工具数** 行:5 NPC→21 写 / 20→39 / 60→110 / 120→215 | 单城 NPC 超过 ~50,或在线同时人数 × 每 10 秒一次轮询把写入压上来时 |
| 2 | **`hasTable()` 探测走 information_schema** | 运行时共 **15 处**:`ConsumptionPoint` 8 / `DefenseService` 3 / `EventCondition` 1 / `EventService` 1 / `EventMultiplierProvider` 1 / `PowerMultiplierProvider` 1(另有 `GameDataVersion` 的校验和一处,不在请求热路径)| 一次快照 **11 条**、一次市场成交 **15 条**、一次建造 11 条 information_schema 查询(占快照 57 条里的 19%)| 线上 MySQL 5.7 的 information_schema 比本地慢很多(共享主机 + 表多时尤其),部署后按真实耗时再判 |
| 3 | **快照体积随建筑 / NPC / 工具线性长** | `CityController::show` 的 `buildings` / `npcs` / `items` 三块全量下发 | 40 栋 = **19.9 KB**;120 栋 = 54.5 KB;**240 栋 + 120 NPC + 80 工具 = 105.1 KB**(首次越过 100 KB)| 出现「建满一张 20×20 地图 + 上百 NPC」的玩家时 |
| 4 | **一个请求内的重复查询** | 同一条 SQL 在一次快照里发多次:NPC `trait_json` ×3、工具 `effect_json` ×2、`city_active_modifiers` ×2、`cities … FOR UPDATE` ×4 | 快照 57 条里约 **15 条**是重复模板 | 与第 2 项一起看:两项合计约占快照 SQL 的四成 |
| 5 | **后台审计列表 `SELECT *`** | `AdminReadController::audit()` 取 `SELECT *`,但 mapper 只用 10 个标量列 | 一次最多 200 行 × 4 个 `longtext`(`before/after/delta/metadata_json`)全被拉回内存 | `audit_logs` 长起来之后(它是 append-only,只增不减) |
| 6 | **审计按城市查会 filesort** | `audit_logs` 只有 `idx_audit_chain (city_id, id)`,没有 CLAUDE §54 建议的 `idx_audit_city_time (city_id, occurred_at)` | 6 万行下 `WHERE city_id=? ORDER BY occurred_at DESC LIMIT 50` = 3.79 ms + `Using filesort` | 现在**没有任何代码**这么查(后台只按 `action` 筛、按 `id` 排),等真要按城市翻审计时再加索引 |

### 🟢 没问题

- **读路径没有 N+1**:快照 SQL 条数**恒定 57 条**,建筑从 10 栋加到 240 栋一条都不多(见下表)。
  建筑列表、NPC 列表、工具列表、事件列表全部是「一次查完 + 内存里拼」。
- **运行时表的索引齐**:`city_building_instances` / `city_items` / `city_npcs` / `city_technologies` /
  `city_events` / `city_market_orders` / `city_market_quota` / `idempotency_keys` 的常用查询在 6 万行背景下
  全部走索引(`type=ref`,无全表扫)。`city_resources` 的主键就是 `(city_id, resource_id)`,取整城资源直接走 PK。
- **定义端点**:全是 1~3 条 SQL 的整表读,热态 0.5~4 ms。
- **市场四道反套利**没有引入慢查询:窗口成交聚合走 `idx_market_orders_window_resource`,单城配额走 PK。

---

## 二、N+1 实测(规模四档)

同一座城,只放大建筑 / NPC / 工具三样,各跑一次「冷启快照(elapsed≈0)」与「离线 6 小时快照」:

| 规模(建筑/NPC/工具) | 冷启快照 SQL | 冷启墙钟 | 响应体积 | 离线 6h SQL | 其中写语句 | 离线 6h 墙钟 |
|---|---:|---:|---:|---:|---:|---:|
| 10 / 5 / 3 | **57** | 29 ms | 7.7 KB | 94 | 21 | 74 ms |
| 40 / 20 / 12 | **57** | 31 ms | 19.9 KB | 113 | 39 | 59 ms |
| 120 / 60 / 40 | **57** | 36 ms | 54.5 KB | 184 | 110 | 96 ms |
| 240 / 120 / 80 | **57** | 42 ms | 105.1 KB | 289 | 215 | 134 ms |

**读侧结论**:57 恒定 —— 快照链(`SimulationService::applyLocked` → 各 Provider `prepare` → 三条懒结算
→ 各 snapshot 方法)没有「循环里查库」。规模上去时长的只有**返回的行数**和**要写的行数**,不是查询次数。

**写侧结论**:写语句数 21 → 39 → 110 → 215,与 `1.5 × NPC + 装备工具数` 线性吻合 —— 这就是第 🟡1 项。
现场逐字:

- `app/Game/NPC/NpcRuntimeService.php:107` — `stepXp()` 的 `foreach ($npcs as $n)` 里
  `DB::table('city_npcs')->where('id', $n->id)->update([...])`,**每个已派驻 NPC 一条 UPDATE**;
- `app/Game/NPC/NpcRuntimeService.php:164` — `stepMorale()` 同款循环,**每个在册 NPC 一条 UPDATE**,
  且这一条**没有「变化量太小就跳过」的闸**(XP 那条有:`$gain <= 0` 直接 return),
  所以哪怕两次快照只隔 10 秒、士气只动 0.08,也照写一行;
- `app/Game/Item/ItemRuntimeService.php:110 / 118` — 逐件装备中工具扣耐久 / 判损毁,同款循环。

在线轮询档实测(前端 `CONFIG.pollMs = 10000`,即每 10 秒一次 `GET /api/city`),240/120/80 那座城:

| 两次快照间隔 | SQL 总数 | 写语句 | 其中 city_npcs | 其中 city_items | 墙钟 |
|---|---:|---:|---:|---:|---:|
| 10 s | 286 | 215 | 180 | 30 | 153 ms |
| 60 s | 286 | 215 | 180 | 30 | 148 ms |
| 600 s | 286 | 215 | 180 | 30 | 143 ms |

即**间隔多短都照写**。一个 20 NPC 的普通城是每 10 秒 ~30 行写入(≈3 行/秒/在线玩家),R1 首发量级无压力;
真要收紧,最小改动是给 `stepMorale` 加一条与 `stepXp` 同款的「本段变化量 < 0.01 就不写」的闸,
**但那会改变士气/耐久的落账时机,属于数值行为改动,不在本轮范围**(记「待下一步」)。

---

## 三、各端点的 SQL 条数 / 体积基线

规模 40 建筑 / 20 NPC / 12 工具(接近首发玩家的正常城):

| 端点 | SQL | DB 累计 | 墙钟 | 响应体积 | 备注 |
|---|---:|---:|---:|---:|---|
| `GET /api/city`(elapsed≈0) | 57 | 42 ms | 67 ms | **19.9 KB** | 最贵的 GET,已单独限流 `throttle:snapshot` |
| `GET /api/city`(离线 6h = 12 段) | 113 | 75 ms | 146 ms | — | 多出来的全是懒结算的写 |
| `POST /api/city/build` | 57 | 23 ms | 48 ms | 小 | 含一次完整结算 + 占地/上限/资源校验 + 审计 |
| `POST /api/market/buy` | 64 | 28 ms | 45 ms | 小 | 含定价懒求值 + 四道反套利 + 审计 |
| `GET /api/market/prices` | 12 | 5 ms | 9 ms | 9.5 KB | 28 资源价目表 |
| `GET /api/city/events` | 35 | 18 ms | 28 ms | 4.4 KB | 内含一次快照级结算 |
| `GET /api/definitions/buildings` | 1 | 4 ms(热) | — | 25.6 KB | 首次 20 ms 是冷启 |
| `GET /api/definitions/technologies` | 1 | 3 ms(热) | — | 11.5 KB | |
| `GET /api/definitions/npcs` | 3 | 3.6 ms | — | **61.8 KB** | 150 原型,最大的一个响应;前端只拉一次并缓存 |
| `GET /api/definitions/items`(本波新增) | 2 | 0.9 ms | — | 11.4 KB | |
| `GET /api/definitions/resources` | 1 | 1.4 ms | — | 3.0 KB | |

### 快照体积按块拆(40 建筑 / 20 NPC / 12 工具,共 19.9 KB)

| 块 | 字节 | 占比 | 单位成本 |
|---|---:|---:|---|
| `city.npcs` | 6431 | 32% | ≈ 320 B / NPC |
| `city.buildings` | 5770 | 29% | ≈ 144 B / 栋 |
| `city.items` | 4441 | 22% | ≈ 370 B / 件 |
| `city.era` | 918 | 5% | 固定 |
| `city.resources` | 510 | 3% | 固定(31 种) |
| `city.events` | 393 | 2% | 固定(摘要,详情走独立端点) |
| `city.technologies` | 366 | 2% | 随解锁数长 |
| 其余(defense / power / logistics / governance / trade / finance …)| ~950 | 5% | 全是标量块 |

三个列表块占 **83%**。100 KB 的线在「240 栋 + 120 NPC + 80 工具」处越过 —— 这个规模在 20×20 的地图上
需要几乎摆满,R1 首发不会有。真到那天的处置方向(**不是现在做**):
`npcs` / `items` 两块拆成独立端点(与市场价目表被挡在快照外是同一条理由,见 `CityController` 的 M3-MARKET 锚点注释),
或者给快照加「只回变化」的 diff 模式(CLAUDE §15 本来就是这么写的)。

---

## 四、慢查询候选 EXPLAIN(`audit_logs` 6 万行 / `city_building_instances` 6 万行)

| 查询 | 实测 | type | 用到的索引 | 判定 |
|---|---:|---|---|---|
| 后台审计列表(无筛选)`ORDER BY id DESC LIMIT 50` | 0.68 ms | index | PRIMARY | 🟢 |
| 后台审计列表(热门 `action`) | 1.03 ms | index | PRIMARY(倒扫 119 行命中 50) | 🟢 现在够快;`action` 越冷门倒扫越长,但有 `idx_audit_action_time` 兜底(见下行) |
| 后台审计列表(冷门 `action`) | 0.37 ms | ref | `idx_audit_action_time` | 🟢(带 filesort,50 行的量级无所谓) |
| 按 user 取审计 `ORDER BY occurred_at DESC` | 0.91 ms | ref | `idx_audit_user_time` | 🟢 索引本身就带 `occurred_at`,不 filesort |
| 按 city 取审计 `ORDER BY occurred_at DESC` | 3.79 ms | ref | `idx_audit_chain (city_id,id)` + **filesort** | 🟡 见 🟡6,当前无调用方 |
| 审计链头 `WHERE city_id ORDER BY id DESC LIMIT 1` | 0.35 ms | ref | `idx_audit_chain` | 🟢 链头表 `audit_chain_heads` 另有 PK 兜底 |
| 快照建筑列表(join L 级定义) | 0.98 ms | ref + eq_ref | `city_building_instances_city_id_index` + PK | 🟢 |
| 建造占地重叠检查 | 0.74 ms | ref + eq_ref | 同上 | 🟢 |
| 同类建筑上限计数 | 0.33 ms | ref | 同上 | 🟢 |
| 城市资源全取 | 0.33 ms | ref | PRIMARY `(city_id, resource_id)` | 🟢 |
| 生效中的 modifier | 0.37 ms | ALL(表小,优化器主动放弃索引) | `idx_active_mod_city_ends` 存在 | 🟢 |
| 事件 active 列表 / 全服过期扫描 | 0.38 / 0.35 ms | ref / range | `idx_city_events_city_status` / `_status_expires` | 🟢 |
| 市场窗口成交聚合 | 0.35 ms | range | `idx_market_orders_window_resource` | 🟢 |
| 市场单城配额 | 0.28 ms | const | PK `(city_id, resource_id, window_index)` | 🟢 |
| 幂等键查重 | 0.33 ms | const | `(user_id, key)` 唯一索引 | 🟢 |
| 工具耐久结算取装备中 | 0.49 ms | ref + eq_ref | `idx_city_item_status` + PK | 🟢 |

> 单城数据量下 `city_building_instances` 的两条查询会显示 `type=ALL`(240 行全属同一座城,走索引不划算);
> 灌到 6 万行 / 300 座城并 `ANALYZE TABLE` 之后,三条查询全部转为 `type=ref` 走 `city_id` 索引 —— **索引是齐的**。

---

## 五、待下一步(不在本轮做)

1. `stepMorale` / 耐久扣减的「变化量太小不写库」闸 —— 会动落账时机,属数值行为改动,要用户点头。
2. 16 处 `hasTable()` 运行时探测能不能收敛成「一次请求探一次」(放 `Context` 缓存,与 `ItemDefinition::all()` 同款),
   或干脆在有迁移保证的表上去掉探测。**改之前要先确认那 16 处的 Fail Closed 语义不受影响。**
3. `AdminReadController::audit()` 改成显式列清单(不再 `SELECT *`)。
4. 快照 `npcs` / `items` 拆独立端点或加 diff 模式 —— 等真有「地图快摆满」的玩家再说。
5. 线上 MySQL 5.7 复测:本轮全部数字来自本地 MariaDB 10.4,information_schema 与 filesort 的表现在 5.7 上会不同。
