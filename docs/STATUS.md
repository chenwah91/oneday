# 当前进度快照

> 最后更新:2026-08-12(R1-A 收尾波完成)
> 用途:关闭会话后直接读这份就能续上。**数值权威 `docs/templates/v3.2.md`(2026-08-10 用户定稿,取代 v3.1)**;架构 `/CLAUDE.md`;安全 `/SECURITY.md`;计划索引 `docs/superpowers/plans/README.md`;M2 清单 `docs/superpowers/plans/2026-08-10-m2-backlog.md`;M3 清单 `docs/superpowers/plans/2026-08-10-m3-backlog.md`。

---

## 一句话状态

**M3 完成 + R1 定稿 + W11 后台全面化完成(v1.5.1,1018 测试,GDV V3.8.0,74 支迁移,154 项后台参数 + 14 面板后台界面),下一步 R1-C 用户部署上线。**

> **v1.5.1(W11-2)**:后台前端改版收官——admin.js 拆 ES modules(core/ui/panels),tab 路由+权限显隐+懒加载,通用定义表渲染器(editable 数据驱动),设定页分组/搜索/恢复默认,建筑等级三 JSON 列逐格编辑,仪表盘/封禁/审计筛选/事件触发/补偿历史全部界面可达。浏览器实测 45+7 步全过。

> **v1.5.0(W11-1)**:规则参数 88→154(内核整章开放+倍率总旋钮+跨键约束机制,默认=原常量零行为变化)、定义编辑器 5 组新增(建筑产量/配方/造价逐格、科技、时代门槛搬库九档七维)、运营端点(仪表盘/封禁全链⚠users结构变更/手动触发事件/审计筛选)。审计报告与裁决记录见 CHANGELOG [v1.5.0]。
> **W11-2(下一波)**:后台前端改版——tab 导航+懒加载、通用定义表渲染器、市场/工具/科技/建筑/曲线/era 六个新面板、设定页分组搜索、仪表盘数字卡、玩家详情+封禁按钮、审计筛选界面。
> **新增待拍板**:building_definition 六个零引用死列(population_min 建造人口门槛等)+ resource_definition 两个死列——补实现(会收紧建造条件改变现有体验)还是删列?裁决前保持只读。

> **v1.4.1(W10 拍板落地)**:N001~N030 拟名回填(150 名全表互异)、三条事件选项修正(贪腐案A 确定性解决/B 净额-5%/港口A 补好处)、6 件工具挂制作建筑(IT004 时代倒挂已裁决维持,见 CHANGELOG)、冒烟账号清理(审计链完好)。codex 设计协同规范在 `docs/CODEX-DESIGN-GUIDE.md`。

> **v1.4.0(R1-B)**:玩家侧 `GET /api/definitions/items`(制作目录,前端零改动点亮)、安全响应头三条(CSP 待实测)、两个后台写端点补 `admin_write` 限流、根路径 302 `/game/`、`.env.example` 配置说明书化、deploy.md 路径 A/B 定稿、§82 走查 ✅9/⚠️8/❌0、性能基线 🔴0(读路径零 N+1,快照恒 57 SQL)。无迁移无 GDV bump。

> **R1 第一基础版本上线计划**(用户 2026-08-12 拍板)见 `docs/plans/2026-08-12-launch-r1.md`:R1-A 收尾(本波,已完成)→ R1-B 上线准备(生产 .env 模板 / §82 清单走查 / 性能粗查 / deploy.md 定稿 / R1 候选 tag)→ R1-C 用户部署上线。codex 视觉设计波与 M4 在 R1 之后。
> 同日拍板的工作模式:不频密测试(关联合并测,每波收尾统一全量)、不过度开发(问题记「待下一步」)、界面从简(视觉设计保留给 codex)、任务 ~90 分钟无进展要有检查点。
>
> **v1.3.0(W7/W8)新增**:7 条前端契约补齐(玩家侧 NPC 定义端点 / 预估参数下发 / map 空值形状统一含 diff 侧 10 处)+ 最后 2 条死 target 接线(`market_fee_pct` / `research_speed_pct`,**17 条登记 target 无死角**)+ 前端面板二(工具 / 事件弹窗 / HUD 三状态块 + 契约消费,浏览器实测,SW `apg-v11`)。无迁移、无 GDV bump。

M3 把 M2 留下的四个恒 1.0 的乘数槽真正填满,并补上 M2 因数据缺口被迫跳过的一整层内容:NPC(150 原型)/ 工具(24 件)/ 市场(28 资源确定性定价 + 四道反套利)/ 随机事件(30 条 DSL)/ 电力 / 国防威胁六个系统全部接入同一套分段结算内核。接法本身是最重要的产出 —— **七乘区 Provider 化 + 非产量 target 登记制**:新系统只新增 Provider、只登记 target,结算内核一个字不改。

同期清掉了「登记了 ≠ 生效」的一整批欠账:**七条悬空 target 接线**(登记表 17 条里 15 条已接,剩 `market_fee_pct` / `research_speed_pct` 两条,见「未做」),原本 Fail Closed 停用的 15 条事件复活 10 条(现 25 启用 / 5 停用)。

版本坐标:代码 `v1.2.0` · 数值 `game_data_version = **V3.6.2**` · PWA 缓存 `apg-v10` · 迁移 **67 支**(M3 新增 39)· 后台规则参数 **88 项** · 测试 **829 passed**。

> ⚠️ **上线必读**:M3 的 39 支里有 **10 支动存量数据**(9 支动定义表、**1 支动玩家存档**)—— `2026_08_11_800002` 会**清空玩家电力库存并折算成资金**(9.F4「电力做流量不做库存」);`2026_08_10_900001` 与 `2026_08_11_300001` 两支审计链迁移**必须成对上线**(只跑前者 = 带着并发死锁上线)。生产必须配齐三把密钥:`AUDIT_HMAC_SECRET` / `MARKET_PRICE_SECRET` / `EVENT_SECRET`。**先备份再上传。**
> 变更明细见 `CHANGELOG.md` 的 `[v1.2.0]` 条目,部署步骤见 `docs/deploy.md`。

---

## 技术栈(已锁定,2026-08-09 转向)

- 后端:PHP + **Laravel 12** · 数据库 **MySQL 5.7.39(线上)/ MariaDB 10.4(本地)**
- 前端:Vanilla HTML/CSS/JS ES Modules + **PixiJS**(无框架、无构建)
- 架构:Modular Monolith(`app/Game/*`)· 认证:Laravel Session(Cookie HttpOnly + CSRF)
- 模拟:Time Delta(懒结算 + 30 分钟分段,12h 封顶)· 经济 Mutation 强制 事务+行锁+幂等+Revision+审计
- 本地:PHP=`C:/xampp/php/php.exe`;库 `apg`(开发)/`apg_test`(测试),root 无密码
- M2 / M3 期间**未新增任何 Composer / 前端依赖**(`composer.lock` 自 M1P1 起未变)
- ⚠️ 本地 MySQL 会话时区是 **+08** 而应用是 **UTC**:直接写 SQL 造数据时一律用 `UTC_TIMESTAMP()`,用 `NOW()` 会把时间戳写到 8 小时后的未来

## M1 已完成(v1.0.0 / v1.0.1,88 测试)

| 阶段 | 内容 |
|------|------|
| P1 | Laravel 骨架 + 安全中间件地基 + 测试框架 |
| P2 | Session 认证 + Authorization + 审计地基 |
| P3 | Definition Seed(10 时代 / 31 资源 / 94 建筑 / 282 等级 / 50 科技),数据文件 `database/data/*.json` |
| P4 | 城市 Runtime + Snapshot + Time Delta 模拟 |
| P5 | 建造 / 升级 / 拆除 全安全链 |
| P7 | 前端最小可玩:Vanilla + PixiJS 等距地图 + 建造 + PWA 壳(`public/game/`) |
| P8 | 管理后台:角色隔离 + 审计查看 + Definition 调整(`public/admin/`) |
| P9 | 收尾:§15 回归测试 + `artisan release:check` + `docs/deploy.md` + `CHANGELOG.md` |

`v1.0.1` 修复 5 个 M1 遗留缺陷(2 个可刷资源的经济漏洞),详见 CHANGELOG。

## M2 已完成(v1.1.0,八个波次,379 测试)

| 波次 | 内容 |
|------|------|
| 1 | 结算内核 **per-instance 化** + 七乘区占位 + 分段结算;安全可观测性;前端建筑详情面板 |
| 2 | **资源 ID 全英文化(V3.1.2)** + 人均粮耗 0.1→0.03;`game_data_version` 全链贯通;五级管理员角色 |
| 3 | **定义表枚举值英文化(V3.1.3)**;**M2-C1 人口/劳动力**(工人分配 API + 增长/迁出/饥荒三级后果) |
| 4 | **API 契约全 snake_case** + 前端派工 UI;**管理员补偿 `ADMIN.COMPENSATION`**;`game_settings` 后台规则开关 |
| 5 | **M2-C2 幸福/健康/治安**(快落慢升);**M2-B1 科技研究**(懒完成解锁 + 50 节点科技面板) |
| 6 | **M2-C3 财政/治理**(税收 × 治理四档效率、维护欠费半停工);**M2-B6 时代升级**(八维门槛) |
| 7 | **M2-C5 建筑生命周期**(三态计时/取消退 70%/拆除退 50%);**M2-C4 物流乘数** + §13 硬上限 |
| 8 | **M2-B3 科技乘数接线**;**M2-C6 安全回归**(修 1 高危:超长 `X-Request-ID` 压制审计的 1406 漏洞) |

M2 期间的用户拍板(2026-08-10):①没派工人就不生产是预期玩法,不自动派工;②API 字段一律小写 snake_case;③工人「只减不增」放行宽松执行,开关进后台 `game_settings`。

## M3 已完成(v1.2.0,六个波次)

| 波次 | 内容 | GDV | 迁移 | 累计测试 |
|------|------|-----|:---:|---------|
| **1** | **D0 乘数总线 Provider 化**(七乘区各一个 Provider,后续系统接入不改内核)+ 非产量 target 登记制;**审计 Hash Chain**(按 city 分域 + `audit_chain_heads` 链头表,修空域并发 1213 死锁 + `audit:verify-chain`);**两份映射草案落地 V3.2.0**(零来源资源 5→1);**初始资源后台可配 V3.2.1**(解除新号开局硬锁)+ 定义表三列双口径物理删列 | V3.2.0 / V3.2.1 | 7 | 428 |
| **2** | **D1 NPC**(30 原型/12 技能/10 级曲线、招募服务器掷点、派驻、`NpcMultiplierProvider` 帽 1.50、工资口粮走总线支出通道、XP/士气/离职独立懒结算、31 项后台参数);**D3 市场**(28 资源懒求值确定性定价 `MARKET_PRICE_SECRET`、买卖 API §13 四机制齐上、噪声套利闭式证明恒亏、电子元件上市) | V3.3.0 / V3.3.1 | 8 | 581 |
| **3** | **D2 工具**(24 件定义 + 合成/装备/耐久三条独立懒结算 + 四道不工作不扣闸 + `tool` 乘区同类取最高异类相乘 + 水泥/药品上市);**D4 事件**(30 条结构化 DSL 全入表 = 88 specs + 79 unmapped;确定性掷点 `EVENT_SECRET`;正向直接发资源 / 负向走乘区;逐事件后台可设定) | V3.4.0 / V3.4.1 | 8 | 721 |
| **4** | **M.1 电力**(§3.3 缺电曲线、发电走容量类装机、耗电收敛 `power_per_min` 单一口径、存量电力折算清零、`EVT_BLACKOUT` 复活)+ 两消费点接线(维护费减免「折扣在前欠费在后」/ 建造加速按速度口径);**D5 国防威胁**(三档派生自 §5.1 九档单一来源、三 target 一个消费点、`EVT_RAID` / `EVT_BORDER_TENSION` 复活)。**七乘区全部接线** | V3.5.0 / V3.5.1 | 7 | 778 |
| **5** | **NPC 池 30→150**(用户 120 新原型 + `name_zh` 中文名零重名、10 个新军事 NPC 国防特性提升、`EVT_BRAIN_DRAIN` 复活、开局即可招募);**五条悬空 target 清偿**(transport/trade/finance 容量 pct + 税收 pct + 市场价格 pct;复活六条事件;贸易容量接市场限额分母;价格冲击只打买入侧防抛货套利) | V3.6.0 / V3.6.1 | 7 | 811 |
| **6** | **治理容量死 target 清偿**(拆成 `governance_capacity_flat` + `_pct` 两条,迁移把 N013/N051/N111 的 flat 投稿挪到 flat target;唯一消费点在内核 `有效容量 =(建筑口径+Σflat)×(1+Σpct)`;18 位行政 NPC 与 IT022 首次生效;时代门槛仍读建筑口径;`EVT_CORRUPTION` 选项 B 提升);**M3 收官文档 + API 级冒烟** | V3.6.2 | 2 | **829** |

M3 期间的用户拍板:
- **2026-08-10**:①新号开局硬锁「测试阶段都送 + 初始资源必须后台可设定」;②两份映射草案批准落地;③**所有事件必须后台可设定**,§13 帽按 NPC 帽 1.90→1.50 + 正向事件改直接发资源修正;④A~F 六区数值缺口全部按 backlog 建议默认值执行。
- **2026-08-11**:开发原则两条,M3 全程适用 —— ①**核心先行**(先做后端 API/快照,玩家面板后置到专门的前端波次);②**后台强大**(系统规则数据一律走 `game_settings` 或 Definition 后台编辑,不许硬编码)。

## 怎么运行 / 测试

```bash
# 启动(先确保 MariaDB 已开)
C:/xampp/php/php.exe artisan serve --port=8127
```

- 游戏:`http://127.0.0.1:8127/game/` → 注册 → 建城
  - ✅ **新号开局硬锁已解除**(V3.2.1):初始资源由后台设定 `game_settings.initial_resources` 控制,默认送 `knowledge: 100`(够研 3~4 条时代 I 科技)。注册完直接可走「研究 `TECH_I_SUST` → 建 `F01` → 派工 → 招 NPC → 市场买卖」。数值要调改后台设定(**测试期数值,正式上线前另调**),不改代码。
- 后台:先授权管理员 `C:/xampp/php/php.exe artisan admin:promote <用户名>`,再开 `http://127.0.0.1:8127/admin/`
- 测试:`C:/xampp/php/php.exe artisan test`(库 `apg_test`,当前 **829 passed**)
- 发布前自检:`C:/xampp/php/php.exe artisan release:check`(应报迁移 **67 支**、`V3.6.2`)
- 审计链校验:`C:/xampp/php/php.exe artisan audit:verify-chain`(应报 0 断链)

> 开发库 `apg` 脏数据已于 2026-08-11 按用户授权清理(脚本 `scripts/cleanup_dev_dirty_data.php`,留仓库供追溯)。2026-08-12 的 M3 收官 API 冒烟又留下 4 个 `m3smoke*` 测试账号及其城市 —— **未清理,等用户点头**(`audit_logs` 按 append-only 一律保留不动)。

## 未做(M4+,勿擅自开工,等用户点名)

- **登记表里还剩 2 条死 target**(已在 `ModifierTarget` 里显式标 `wired => false` + 写明接线落点,`GovernanceCapacityTest::test_remaining_unwired_targets_are_exactly_the_known_list` 把名单钉死):`market_fee_pct`(8 位商人 NPC + 1 件贸易工具已投稿,`MarketService` 无读取方)、`research_speed_pct`(7 位学者 NPC 已投稿,`TechService` 无读取方;接线口径同建造加速 —— **除以 `(1 + pct)`** 不是乘 `(1 − pct)`)。
- **M.6 资源节点系统**:`city_resource_nodes` 表 + 勘探 + 枯竭机制。`EVT_NEW_DEPOSIT` 因此停用。
- **5 条 Fail Closed 停用事件**,各带停用原因:
  - `EVT_NEW_DEPOSIT` — 缺资源节点系统(见上);
  - `EVT_EPIDEMIC` — 疫病:health 是 §10.8 的**派生值**(医疗覆盖率映射),没有可写的存量 target;
  - `EVT_OVERSEAS_ORDER` — 缺市场订单玩法(1.4×/1.7× 溢价没有落点);
  - `EVT_GLOBAL_CRISIS` — 跨市场/电力/国防的复合效果,单条事件承接不了;
  - `EVT_TAX_PROTEST` — 条件「税率偏高」恒不成立(§10.5 明文税率固定不可调),开放税率政策后再启用。
- **电站名义发电口子**:电站不派工 / 没煤也照发电(容量类产出在乘区之前提取)。要收紧需要总线支持两阶段 prepare。
- **6 件工具的制作来源建筑不存在**(§7 写的木工作坊 / 石工作坊 / 工坊 / 研究院 / 现代工厂不在 94 栋内):现按「不卡建筑只卡时代」放行,映射方案待批(见下)。
- **§5.4 金融玩法未定**:`finance_capacity` 只作读数回传,没有消费者。
- **物流距离惩罚**:`distanceFactor` 恒 1.0,大地图分区路网未做。
- **建筑等级化的产出展示**:`/api/definitions/buildings` 只回传 L1。
- Capacitor 打包、离线优化、性能 Profiling(CLAUDE §34 Phase 4)。

## 待用户拍板(不阻塞)

1. **30 个初版 NPC 原型的中文名**:N001~N030 目前只有 `name_key` 没有 `name_zh`(N031~N150 的 120 个已由用户提供并落库),前端按 key 回退显示。要补名单请给 30 个中文名。
2. **`EVT_CRIME` 的「随机库存损失」数值复核**:§9.2 原文没给区间,现按 backlog 建议默认 **3%~8%**。这是「补数」不是「抄数」,请复核后确认或改值。
3. **事件选项的数据缺口**:`EVT_CORRUPTION` 选项 A 的「50% 立即解决」缺一条「按概率二选一」的效果 kind(掷点框架有,但要新增 kind + 一套「掷不中什么都不发生」的审计口径);`EVT_CORRUPTION` 选项 B 的「事件结束后 +5% 持续 30 分钟」缺一条「延迟起效」的 kind。现状是**付了钱只承接一半效果**,平衡上建议要么补 kind、要么改文案。
4. **6 件工具的制作来源建筑映射**(Fable 2026-08-11 暂裁,待复核):建议照 §16.1「改挂现有不加建筑」先例映射 IT003/IT005→P02、IT004→P04 砖窑、IT013→P05 铁匠铺、IT016→K03、IT019→P08;或维持现状(只卡时代不卡建筑)。
5. **物流「升代产量断崖」可调参**:时代 I→II 开始计运输需求时的手感,要不要给缓冲。
6. **高波动资源套利收紧**:`electronic_components`(v=0.10)/ `rare_metals` / `advanced_materials`(v=0.12)在苛刻条件下仍有跨窗套利边际。开市前任选其一收紧(滑点系数 0.5→0.91 / 费率倍率 1→2.35 / 调低单窗额度),均为后台设定不用发版。
7. **导出归档根目录**:尚未问过用户(首次正式交付前要问)。
8. **开发库 `m3smoke*` 冒烟账号**要不要清理(见上)。

## 上线前必须(用户在 cPanel 亲自做)

按 `docs/deploy.md` 执行(⚠️ **先备份** —— v1.2.0 含 10 支会改写存量数据的迁移,其中一支清空玩家电力库存),并在**真 MySQL 5.7** 上实跑全部 **67 支**迁移,核对 `docs/ops/db-mariadb-vs-mysql57.md` 的差异(5.7 无窗口函数/CTE、JSON 列不能带 DEFAULT、无 INSTANT ADD COLUMN、原生 JSON 列读回字节与写入不同等)。三把密钥 `AUDIT_HMAC_SECRET` / `MARKET_PRICE_SECRET` / `EVENT_SECRET` 必须配齐。

## 规则提醒

- 改动完成先问是否 push(本会话用户已授权一路 push,新会话需重新确认)。
- 涉及 DROP / 无 WHERE 的 DELETE / 线上数据:先索取批准。
- 不擅自改游戏数值(CLAUDE §33):数值调整先改 `docs/templates/v3.2.md` 并 bump `game_data_version`。
