# 资源 ID 英文 code 映射表

> 目的:把资源主键从中文名改成语义化英文 code(snake_case),中文名保留为显示名。
> **code 以 `docs/templates/v3.2.md` §0.2.1「Canonical resource codes」权威表为准,本表已对齐(2026-08-10)。**
> 代码侧单一来源:`app/Game/Resource/ResourceCode.php`。本文档是评审依据,三者任一改动必须同步另外两处。
> rs_code 对照 `docs/templates/v3.1.md` §8「资源市场价格」的 RS001–RS026 编号;§8 未收录的资源留空。

命名规则:

- 一律小写 snake_case,只用 `[a-z_]`。
- v3.2 §0.2.1 已给出 code 的资源一律照抄权威表(如 浆果 `berries`、电子元件 `electronic_components`、
  高品质粮食 `high_quality_food`、国防值 `defense_score`),不得自行改名。
- 权威表未收录的资源(仅 水泥 `cement`)按语义直译,冲突时加限定词(石料 stone / 砂石 sand_gravel)。
- 容量类"资源"不是库存资源(不进 `city_resources`、不在 `resource_definition`),但会出现在建筑等级定义的 `output_json` 里,因此同样需要英文 code。

---

## 1. 库存资源(31 种,对应 `database/data/resources.json`)

| 中文名(显示名) | 英文 code | rs_code | 类别 | 首次时代 |
| --- | --- | --- | --- | --- |
| 资金 | `money` | RS024 | 货币 | I |
| 木材 | `wood` | RS003 | 原料 | I |
| 浆果 | `berries` | RS002 | 食物 | I |
| 燃料 | `fuel` | RS019 | 能源 | I |
| 石料 | `stone` | RS004 | 原料 | II |
| 面粉 | `flour` | RS007 | 加工品 | II |
| 粮食 | `food` | RS001 | 食物 | II |
| 面包 | `bread` | RS008 | 食物 | II |
| 铜 | `copper` | RS009 | 原料 | III |
| 锡 | `tin` | RS010 | 原料 | III |
| 黏土 | `clay` | RS005 | 原料 | II |
| 青铜 | `bronze` | RS011 | 加工品 | III |
| 砖 | `brick` | RS014 | 加工品 | III |
| 知识 | `knowledge` | RS023 | 知识 | III |
| 铁 | `iron` | RS012 | 原料 | IV |
| 煤炭 | `coal` | RS013 | 原料 | IV |
| 铁制工具 | `iron_tools` | (§8 无) | 加工品 | IV |
| 砂石 | `sand_gravel` | RS006 | 原料 | II |
| 玻璃 | `glass` | RS015 | 加工品 | VII |
| 钢铁 | `steel` | RS016 | 加工品 | VIII |
| 机械 | `machinery` | RS020 | 加工品 | VIII |
| 电力 | `electricity` | RS017 | 能源 | VIII |
| 石油 | `oil` | RS018 | 原料 | IX |
| 水泥 | `cement` | (§8 无) | 加工品 | IX |
| 电子元件 | `electronic_components` | RS022 | 加工品 | IX |
| 塑料 | `plastic` | RS021 | 加工品 | IX |
| 加工食品 | `processed_food` | (§8 无) | 食物 | IX |
| 药品 | `medicine` | (§8 无) | 食物 | IX |
| 稀有金属 | `rare_metals` | RS025 | 原料 | X |
| 先进材料 | `advanced_materials` | RS026 | 加工品 | X |
| 高品质粮食 | `high_quality_food` | (§8 无) | 食物 | X |

说明:

- §8 共 26 条(RS001–RS026),本表 31 种资源中有 26 种能对上;其余 5 种(铁制工具、水泥、加工食品、药品、高品质粮食)§8 未收录,`rs_code` 存 `NULL`。
- **V3.2.0(2026-08-10)**:黏土 / 砂石的首次时代由 III / VII 统一改为 **II**——两者在
  `v3.2-resource-source-mapping.md` §2/§3 挂到 R02 采石场(时代 II)成为其副产,首次可得时代随来源建筑走。
  水泥 / 药品的市场编号(草案 §7 提议的 RS027 / RS028)属 M3 市场模块,本次**不写入**,`rs_code` 仍为 `NULL`。
- §8 的 RS019「燃料」首次时代写作 `I/IX`,resources.json 取 `I`,以 resources.json 为准。
- `知识`、`资金`按 §8 属不可交易资源;`电力`按 §8 走容量合约,当前 M1/M2 仍按普通库存资源建模,后续市场模块再区分。

---

## 2. 容量类产出(8 种,以 `ResourceCode::CAPACITY` 为准)

这些值出现在 `building_level_definition.output_json` 的 `resource` 字段,但**不是库存资源**:
它们表示"每分钟折算出的容量",由结算内核提取成全城容量,不写进 `city_resources`,也不在 `resource_definition` 里。

| 中文名(显示名) | 英文 code | 结算处理 |
| --- | --- | --- |
| 人口容量 | `population_capacity` | 累加为全城人口上限 |
| 仓储容量 | `storage_capacity` | 累加到 `BASE_STORAGE` 之上,作为资源夹紧上限 |
| 治理容量 | `governance_capacity` | M1/M2 暂不结算,仅显示 |
| 运输容量 | `transport_capacity` | M1/M2 暂不结算,仅显示 |
| 国防值 | `defense_score` | M1/M2 暂不结算,仅显示 |
| 贸易容量 | `trade_capacity` | M1/M2 暂不结算,仅显示 |
| 金融容量 | `finance_capacity` | M1/M2 暂不结算,仅显示 |
| 医疗容量 | `medical_capacity` | M1/M2 暂不结算,仅显示 |

---

## 3. 影响范围(本次迁移触及的位置)

| 位置 | 处理方式 |
| --- | --- |
| `database/data/resources.json` | `resource_id` 换英文 code;`name` 保留中文;新增 `rs_code` 字段(无则 `null`) |
| `database/data/buildings.json` | `cost` 的键、`input`/`output` 的 `resource` 值换英文 code |
| `database/data/building_levels.json` | 同上 |
| `resource_definition` 表 | 主键 `resource_id` 换英文 code;新增 `rs_code VARCHAR(8) NULL` |
| `city_resources` 表 | `resource_id` 按本表逐条 UPDATE |
| `building_level_definition` 表 | `cost_json` / `input_json` / `output_json` 用 PHP json_decode → 映射 → json_encode 写回 |
| `audit_logs.delta_json` | **不迁移**(历史记录保持原样,历史就是历史) |
| 旧 code 残留 | 迁移内置 `LEGACY_CODES`:`berry`→`berries`、`electronics`→`electronic_components`、`premium_food`→`high_quality_food`、`defense_value`→`defense_score`(本迁移早期版本用过的 4 个非权威 code) |
| `idempotency_keys.request_hash` | **不迁移**(旧 key 24h 后过期,影响窗口极小) |
| PHP 代码字面量 | `SimConstants` / `SimulationService` / `BuildService` / `UpgradeService` / `CityFactory` 全部改引用 `ResourceCode` 常量 |
| 前端 | `hud.js` 的 `RESOURCE_ICONS` / `RESOURCE_ORDER` 用 code;显示名走 `GET /api/definitions/resources` |

---

## 4. 附带数值修正

- `SimConstants::FOOD_PER_CAPITA_PER_MIN`:`0.1` → `0.03`,依据 v3.1 §10.1「基础粮食消耗/分钟 = population × 0.03」。
  该常量此前偏离规格 3.3 倍,导致人口吃粮基线全部偏高,本次一并修正,所有粮食相关测试基线重算。
