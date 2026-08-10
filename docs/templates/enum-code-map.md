# 枚举值英文 code 映射表

> 目的:把定义表里仍在用中文的**程序值**(建筑类别 / 建筑系列 / 等级成本类型 / 资源类别 / 科技分支)
> 换成语义化英文 code(snake_case),中文只留在显示名与本文档。
> 依据:`docs/templates/v3.2.md` §0.2「Canonical English Game Data Standard」与 §0.2.1「Legacy Chinese Value Migration」。
>
> **代码侧单一来源:`app/Game/Definition/EnumCode.php`。前端显示名:`public/game/js/core/enum-names.js`。**
> 三者(本文档 / EnumCode / enum-names.js)必须保持一致,`tests/Feature/Definition/EnumCodeTest.php` 会逐行比对本文档与数据库。

命名规则:

- 一律小写 snake_case,只用 `[a-z_]`。
- v3.2 §0.2.1 已经点名的固定项照抄(资源类别 6 条、科技分支 5 条、成本类型 3 条),不得自行改名。
- 其余按语义直译;两条中文直译成同一个英文词时,加限定词区分
  (例:`粮食加工`=磨面/烘焙 → `grain_processing`,`食品加工`=加工食品厂 → `food_processing`)。
- **不同列各自是独立命名空间**:`building_definition.category` 的 `storage` 与 `series_key` 的 `storage`
  是两个不同列的值,互不冲突;同理 series 的 `wood` / `electricity` 与资源 code `wood` / `electricity` 也不冲突。

涉及的列:

| 表 | 列 | 条数 |
| --- | --- | --- |
| `building_definition` | `category` | 12 |
| `building_definition` | `series_key` | 29 |
| `building_definition` | `upgrade_to_building_id` | 见 §6 |
| `building_level_definition` | `cost_type` | 3 |
| `resource_definition` | `category` | 6 |
| `technology_definition` | `branch` | 5 |

`name` 列(建筑名 / 资源名 / 科技名 / 时代名)一律保持中文显示名,本次不动。

---

## 1. 建筑类别 building_definition.category(12)

<!-- enum:building_category -->

| 中文(旧值) | 英文 code | 建筑数 | 说明 |
| --- | --- | --- | --- |
| 居住 | `housing` | 10 | H 系列住宅 |
| 粮食生产 | `food_production` | 10 | F 系列农业 |
| 仓储 | `storage` | 10 | S 系列仓库 |
| 行政 | `administration` | 10 | A 系列治理 |
| 国防 | `defense` | 10 | D 系列城防 |
| 运输 | `transport` | 9 | T 系列道路 |
| 原料采集 | `raw_material_extraction` | 8 | R 系列采集/矿场 |
| 加工 | `processing` | 11 | P 系列加工厂 |
| 能源 | `energy` | 5 | E 系列燃料/发电 |
| 商贸 | `commerce` | 4 | C 系列市场/金融/贸易 |
| 科研教育 | `research_education` | 5 | K 系列学堂/科研 |
| 公共服务 | `public_service` | 2 | M 系列医疗 |

<!-- /enum -->

## 2. 建筑系列 building_definition.series_key(29)

<!-- enum:building_series -->

| 中文(旧值) | 英文 code | 建筑 | 说明 |
| --- | --- | --- | --- |
| 住宅 | `residence` | H01–H10 | |
| 农业 | `agriculture` | F01–F10 | |
| 仓储 | `storage` | S01–S10 | 与类别 `storage` 同名,不同列 |
| 治理 | `governance` | A01–A10 | |
| 城防 | `city_defense` | D01–D10 | 类别 `defense` 已占用「国防」,系列加限定词 `city_` |
| 陆路运输 | `land_transport` | T02–T10 | |
| 木材 | `wood` | R01 | |
| 石料 | `stone` | R02 | |
| 铜矿 | `copper_mine` | R03 | |
| 锡矿 | `tin_mine` | R04 | |
| 铁矿 | `iron_mine` | R05 | |
| 煤矿 | `coal_mine` | R06 | |
| 石油 | `oil` | R07 | |
| 稀有金属 | `rare_metals` | R08 | |
| 粮食加工 | `grain_processing` | P01–P02 | 磨坊→面粉、烘焙坊→面包,是谷物加工 |
| 金属加工 | `metal_processing` | P03/P05/P07 | |
| 建材加工 | `building_material_processing` | P04/P06 | |
| 机械制造 | `machinery_manufacturing` | P08 | |
| 食品加工 | `food_processing` | P09 | 食品加工厂→加工食品;与 `grain_processing` 区分 |
| 石化加工 | `petrochemical_processing` | P10 | |
| 高科技 | `high_tech` | P11 | |
| 基础能源 | `basic_energy` | E01–E02 | |
| 电力 | `electricity` | E03–E05 | |
| 市场 | `market` | C01–C02 | |
| 金融 | `finance` | C03 | |
| 全球贸易 | `global_trade` | C04 | |
| 教育 | `education` | K01–K03 | |
| 科研 | `research` | K04–K05 | |
| 医疗 | `medical` | M01–M02 | |

<!-- /enum -->

## 3. 等级成本类型 building_level_definition.cost_type(3)

<!-- enum:cost_type -->

| 中文(旧值) | 英文 code | 行数 | 说明 |
| --- | --- | --- | --- |
| 建造 | `build` | 94 | Level 1 = 从零建造 |
| L1→L2升级 | `upgrade_l1_l2` | 94 | Level 2 |
| L2→L3升级 | `upgrade_l2_l3` | 94 | Level 3 |

<!-- /enum -->

## 4. 资源类别 resource_definition.category(6)

v3.2 §0.2.1 固定项,不得改名。

<!-- enum:resource_category -->

| 中文(旧值) | 英文 code | 资源数 | 说明 |
| --- | --- | --- | --- |
| 原料 | `raw_material` | 10 | 木材/石料/矿石等一次原料 |
| 货币 | `currency` | 1 | 资金 |
| 知识 | `knowledge` | 1 | 知识 |
| 食物 | `food` | 6 | 浆果/粮食/面包等 |
| 能源 | `energy` | 2 | 燃料/电力 |
| 加工品 | `processed_good` | 11 | 面粉/青铜/钢铁等二次产物 |

<!-- /enum -->

## 5. 科技分支 technology_definition.branch(5)

v3.2 §0.2.1 固定项,不得改名。原值里的 `/` 在 code 里改成 `_`。

<!-- enum:tech_branch -->

| 中文(旧值) | 英文 code | 科技数 | 说明 |
| --- | --- | --- | --- |
| 生存/农业 | `survival_agriculture` | 10 | |
| 工业/加工 | `industry_processing` | 10 | |
| 治理/科研/商贸 | `governance_science_trade` | 10 | |
| 仓储/运输 | `storage_transport` | 10 | |
| 国防 | `defense` | 10 | 与建筑类别 `defense` 同名,不同表 |

<!-- /enum -->

## 6. 建筑升级去向 upgrade_to(94)

`database/data/buildings.json` 的 `upgrade_to` 原本存**中文建筑名**,由 Seeder 反查 `name → building_id`。
本次改成**直接存 `building_id` 或 `null`**,Seeder 不再做名称解析。

| 情况 | 条数 | 本次处理 |
| --- | --- | --- |
| 名称能查到对应建筑 | 58 | 换成对应 `building_id` |
| 原值是「终局」(该系列到头) | 10 | `null` |
| 名称在 94 栋里查无此名(断链) | 26 | `null` |

10 + 26 = 36 条置 `null`,与 `docs/templates/v3.2-building-upgrade-remap.md`(重映射草案)统计一致。
**草案尚未审批,本次一律置 `null`,不做任何猜测性映射**;审批通过后再按草案回填。

### 6.1 置 null 的 36 条清单

「终局」10 条:

| building_id | 名称 | 原值 |
| --- | --- | --- |
| H10 | 生态超级社区 | 终局 |
| F10 | 垂直农业中心 | 终局 |
| S10 | 全球物流枢纽 | 终局 |
| T10 | 智能运输网络 | 终局 |
| A10 | 世界议会中心 | 终局 |
| D10 | 联合防卫司令部 | 终局 |
| R08 | 稀有金属矿场 | 终局 |
| P11 | 先进材料工厂 | 终局 |
| E05 | 先进能源中心 | 终局 |
| K05 | 超级科研中心 | 终局 |

断链 26 条(原值在 94 栋 `name` 里查无此名):

| building_id | 名称 | 原值(查无此名) |
| --- | --- | --- |
| R01 | 伐木营地 | 伐木场 |
| R02 | 采石场 | 大型采石场 |
| R03 | 铜矿 | 深层铜矿 |
| R04 | 锡矿 | 深层锡矿 |
| R05 | 铁矿 | 深井铁矿 |
| R06 | 煤矿 | 机械煤矿 |
| R07 | 油井 | 自动油田 |
| P01 | 磨坊 | 水力磨坊 |
| P02 | 烘焙坊 | 城市烘焙所 |
| P03 | 青铜作坊 | 铸造工坊 |
| P04 | 砖窑 | 砖石工坊 |
| P05 | 铁匠铺 | 锻造工坊 |
| P06 | 玻璃工坊 | 玻璃厂 |
| P07 | 钢铁厂 | 自动钢铁联合厂 |
| P08 | 机械厂 | 自动化机械厂 |
| P09 | 食品加工厂 | 智能食品工厂 |
| P10 | 炼油厂 | 综合石化厂 |
| E02 | 木炭窑 | 煤炭作坊 |
| E03 | 燃煤发电厂 | 燃油/燃气电站 |
| E04 | 燃气联合电站 | 清洁能源中心 |
| C01 | 村落市场 | 城市市场 |
| C02 | 帝国市场 | 商贸中心 |
| C03 | 银行 | 中央银行 |
| C04 | 国际贸易中心 | 世界贸易中心 |
| K03 | 大学 | 研究院 |
| M02 | 医院 | 综合医疗中心 |

> 数据库 `building_definition.upgrade_to_building_id` 列本来就已经是 ID / NULL(旧 Seeder 解析后写入),
> 所以本次迁移对该列只做**校验**(非 NULL 值必须是合法 building_id),不改值。
> 真正的变化在 JSON 数据源与 Seeder:解析失败不再静默变 NULL,而是抛异常。

---

## 7. 影响面

- 数据源:`database/data/{buildings,building_levels,resources,technologies}.json`
- 迁移:`database/migrations/2026_08_10_200003_migrate_enum_values_to_english.php`(逐条 UPDATE,带覆盖率断言,可反向)
- Seeder:`BuildingDefinitionSeeder`(upgrade_to 不再按名字解析 + 合法性断言)
- 后端:没有任何 PHP 业务代码把这些中文值当判断条件(已 grep 确认),因此无逻辑改动
- 前端:`renderer/buildings.js` 的分类配色表键改英文;新增 `core/enum-names.js` 负责 code→中文显示;
  `ui/build-panel.js`、`ui/building-panel.js` 显示分类时经 `categoryName()` 翻译
- Service Worker:`CACHE` 版本 `apg-v3` → `apg-v4`,`PRECACHE_URLS` 增加 `core/enum-names.js`
