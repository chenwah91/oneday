# CHANGELOG

本文件记录 `apg`(城市建设经营游戏)的版本变更,遵循 [语义化版本](https://semver.org/lang/zh-CN/)(`主版本.次版本.修订号`)。

> 本文件是项目第一份正式 CHANGELOG——在此之前各阶段版本号只体现在 git commit message 里(`vX.Y.Z ...`),未集中记录。下方「更早里程碑」一节按 commit 顺序补录这些历史版本供追溯,详细条目从 `v1.0.0` 开始按标准 CHANGELOG 格式维护。

## [v1.5.1] — W11 后台全面化(界面)(2026-08-13)

后台前端全面改版,v1.5.0 的全部后端能力至此**界面可达**。991 行单文件 `admin.js` 拆成 ES modules(core/ui/panels 三层,3615 行),浏览器实测 45 步 + 复验 7 步全过,console 零 JS 错误。

- **tab 导航 + hash 路由 + 按权限显隐 + 懒加载**:14 个面板,首屏只加载仪表盘;support 角色只见其权限内的 3 个 tab。
- **通用定义表渲染器**:可编辑列一律取接口下发的 `editable`(后端 allowlist 加减字段后台自动跟随),行内改 + 强制理由 + 逐格审计 + 就地更新行与版本号;8 个定义面板退化成纯配置对象(最短 25 行)。
- **设定页强化**:154 项按 14 组分组、过滤框、「只看已改动」、整数键 step=1、跨键约束小字提示、废弃键只读置底、每行「恢复默认」;保存只更新该行(不再整表重绘丢输入)。
- **建筑等级面板**:三级整表 + 行展开逐格编辑产出/配方/造价现值(后端 GET 补发三列现值与 editable);空列明确提示「新增条目走迁移」。
- **运营面板**:仪表盘 10 张数字卡 + 资源 Top;玩家搜索/详情展开/封禁解禁(理由必填);审计多维筛选 + 游标翻页 + 行展开 JSON 详情;事件行内「触发到城市」、停用必填原因;补偿面板嵌该玩家最近补偿历史(带红绿 delta,`with_delta=1` 选择性下发,不破坏列表不带大 JSON 的纪律)。
- NPC 定义由单条表单改为 150 行整表(保留按 id 定位);修掉三个存量 bug(补偿提示被刷新擦掉/操作列被挤出屏幕/事件上限报错无原因)。

## [v1.5.0] — W11 后台全面化(后端)(2026-08-12)

「后台功能需要强大,系统规则数据需要可以通过后台做调整」原则的全面兑现。四路并行审计盘出全部缺口后三线实施:测试 **930 → 1018**,迁移 69 → **74 支**,`game_data_version` → **V3.8.0**。**默认值一律 = 原常量值,升级当天零行为变化。**

### 规则参数 88 → 154 项(后台设置页自动出现,数据驱动)

- **内核整章开放**:人均粮耗、劳动力比例、人口增长、饥荒三级惩罚(4 键)、住房/幸福两条因子曲线(5 拐点)、幸福全套(基线/快落慢升/住房曲线/医疗与治安拆分/食物品质四档/赤字惩罚,17 键)、税收两键、治理四档表(7 键)、维护欠费率 + **维护总开关**(止血阀)、物流全套(与电力对称,含总开关)、离线封顶与分段长度、初始人口、基础仓储。
- **倍率类总旋钮**:建造工期/建造成本/升级成本、科技时长/知识成本、市场波动率、事件效果全局倍率——「全服建造提速 10 倍看后段内容」从此一个数字搞定。
- **NPC 帽进后台**:`npc_total_cap`(上次 1.90→1.50 靠发版,今后后台改)、岗位不匹配折扣;研究并行上限(运营活动旋钮)。
- **机制补丁**:设定项支持整数约束(25 键)与**跨键约束**(11 组,如拆除退款≤取消退款、威胁档 tense≤safe——改反直接 422);反套利下限收紧(滑点/费率倍率 min 0→0.01,§13 四机制不可被后台关停);死键标 deprecated。
- 刻意不开放(写明理由):§13 生产帽 2.75(改它=换一套经济,走迁移)、各安全夹取下限、量纲区间、无消费点的死配置。

### 定义编辑器 5 组新增(全部照 11 步流水线:allowlist+强制理由+行锁+审计+GDV bump)

- **建筑产量/配方/造价逐格可编辑**(`output_json`/`input_json`/`cost_json` 条目级,审计定位到 `output_json.wood` 这一格)——「后台强大」原则此前最大的洞。
- 科技(知识成本/研究时长)、建筑数量上限、NPC 等级曲线(XP/加成/维护减免帽)。
- **时代升级门槛搬进数据库**:新表 `era_upgrade_requirement` 九档×七维逐格可调(反射读原常量灌入,零誊写;与国防威胁需求同源,响应带联动警告;表空 Fail Closed)。
- NPC 特性强度倍率 `trait_multiplier`(N001 治理+10%×2.0→+20%,只乘 NPC 来源不连坐工具)。
- 小补漏:停用事件强制填 `disabled_reason`;市场单资源可停市/复市(trade_mode 限 spot↔non_tradeable 互切)。

### 运营端点(后台页面在 W11-2,本版 API 就绪)

- **全服仪表盘**(固定 7 条聚合 SQL,测试钉死零 N+1)、玩家搜索/角色过滤/游标分页、审计多维筛选(玩家/城市/request_id/action 前缀/时间段)+ 单条 JSON 详情。
- **封禁/解禁全链**:users 加两列(⚠结构变更)、双闸 Fail Closed(登录拒 + 在途会话踢)、后台人员豁免防反锁、绝不删数据、审计成对。
- **手动触发事件到指定城市**(测试/复现):复用自然触发同一条落地路径,只跳权重与冷却,双审计可区分。

### 已知边界

- 新增 66 键在已迁移过的库中无数据行(改过才落行),`get()` 回退默认值,功能正常。
- `MULTIPLIER_CAP_ENDGAME`(3.25)保留:生产代码零调用,测试在用,等终局建筑标记列落地一并接线。
- `market_price_inertia` 拒绝登记:实现早已偏离 §8.1 的 AR(1) 公式(PriceEngine 顶部有记录),登记即死配置。
- building_definition 六个零引用死列(建造前置门槛等)保持只读,待用户裁决补实现或删列。

## [v1.4.1] — 拍板落地版(R1 第一基础版本定稿)(2026-08-12)

用户 2026-08-12 拍板清单的全部落地。新增 2 支迁移(69 支)、`game_data_version` **V3.7.0**,测试 **920 → 930**;只动定义表,不动玩家存档。

- **N001~N030 中文拟名回填**(伯衡 / 原朴 / 猎风…天工阙):程序核对全表 **150 个中文名互异**;快照与定义端点即刻带出。
- **三条事件选项修正**(全部用现有机制,零新增代码):贪腐案选项A「50% 解决」改**确定性立即解决**(两条减益归零,代价 900 资金 + 50 知识不变);选项B 事后补偿净额折算为当期 **−5%**;港口拥堵选项A 补上「拥堵立即解除」(原先只有代价没有好处)。EVT_CRIME 3%~8% 补数经复核批准,数值未动。
- **6 件工具挂制作建筑**(IT003/IT005→P02 烘焙坊、IT004→P04 砖窑、IT013→P05 铁匠铺、IT016→K03 大学、IT019→P08 机械厂):制作闸门早已在 `ItemService` 就位(要求 active 实例),挂上即生效。**已知边界**:v3.2 §7 写的「木工作坊/石工作坊」等建筑在 94 栋里不存在,本组是 backlog 提案的近似映射;**IT004 时代倒挂**(工具 era II、砖窑 era III → 实际 era III 才能制作),裁决维持不改数值,体验等同科技前置。
- **本地开发库冒烟账号清理**(用户授权):6 账号 + 6 城市 + 152 行关联数据事务内删净;审计表按 append-only 纪律保留,`audit:verify-chain` 复跑断链 0 处。
- 修复 `docs/deploy.md` 编码事故(PowerShell 5.1 对无 BOM UTF-8 的 ANSI 误读导致双重乱码,v1.4.0 提交曾受影响;已程序化逆转还原并更新至 v1.4.1)。
- codex 视觉设计协同规范入库:`docs/CODEX-DESIGN-GUIDE.md`(文件白名单 / SW 版本纪律 / design-requests 协作通道)。

## [v1.4.0] — R1-B 上线准备版(2026-08-12)

R1 上线计划第二波:部署侧准备 + 走查揪出的安全补漏。无迁移、无 GDV bump、无新依赖,测试 **909 → 920**。

### 新增

- **玩家侧 `GET /api/definitions/items`**:24 件工具的制作目录(成本 / 时代 / 制作建筑 / 耐久 / 效果),与前端工具面板制作区的期望字段逐个对上,**前端零改动点亮制作区**;`effect_json` specs / `trade_value` 不下发(后者 = 承诺一个不存在的卖出价,B5 已批 M3 不做工具交易)。
- **HTTP 安全响应头中间件**(CLAUDE §73):`X-Content-Type-Options: nosniff` / `Referrer-Policy: same-origin` 全响应,`Strict-Transport-Security` 仅 HTTPS 响应;**CSP 刻意未加**(PixiJS 的 blob:/data: 白名单需真实页面实测,盲加会打挂渲染,记「待下一步」)。静态文件由 Web 服务器直接吐,需在服务器配置补同样的头(见 deploy.md)。
- 根路径 `GET /` 302 → `/game/`(玩家不再看到 Laravel 骨架 welcome 页)。

### 安全补漏(§82 走查产出)

- **两个后台写端点补 `throttle:admin_write`**:`POST /api/admin/definitions/building-level` 与 `/npc` 原先只有组级限流,是管理员账号被盗时唯一能高频批量改全服数值的口子;`M2SurfaceTest` 的 admin_write 名单扩成**全部 7 个后台写端点**锁死。
- §82 十七项走查:**✅9 / ⚠️8 / ❌0**(⚠️ 全是只能在生产机上确认的项),走查表进 `docs/deploy.md`;`composer audit` 零漏洞;`.env` 全历史 104 commit 核查从未入库。

### 文档与基线

- **`.env.example` 生产配置说明书化**:补 7 个缺失键,每键中文注释「生产该填什么 / 怎么生成」,三密钥生成命令统一。
- **`docs/deploy.md` 定稿**:「首次上线(路径 A,67 支全跑)」与「增量升级(路径 B,Nothing to migrate,代码覆盖即可)」分开成表;路径 B 回滚只需切代码不碰库。
- **性能基线入档** `docs/plans/2026-08-12-perf-audit.md`:🔴 上线前该修 **0** 项;读路径零 N+1(快照恒 57 条 SQL,建筑 10→240 栋一条不多);🟡 观察 6 项带基线数字(懒结算写循环随 NPC 数线性、快照 240 栋+120 NPC 时 105KB 等),有玩家量后再看。
- 修理机制查证:v3.2 §7 **无修理机制**(耐久归零 = 损毁消失,B4 裁决),前端不渲染修理按钮是正确行为,不实现修理端点。

## [v1.3.0] — R1-A 收尾版:契约补齐 + 最后两条 target 接线 + 前端面板二 (2026-08-12)

R1 第一基础版本上线计划(`docs/plans/2026-08-12-launch-r1.md`)的第一波:把「后端有数据、前端拿不到」和「登记了、没生效」两类欠账清零,前端补上工具 / 事件 / 城市状态三块简单界面 —— 界面只做可用版,视觉设计保留给 codex。

测试 **829 → 909**(W7 契约与接线 +63,W8 diff 形状 +17);Service Worker `apg-v10` → **`apg-v11`**;无迁移、无 GDV bump(纯代码与前端改动,定义数据一字未动)。

### 后端:7 条前端契约缺口补齐(W7)

- **玩家侧 `GET /api/definitions/npcs`**:150 原型 + 12 技能 + 10 级成长曲线,招募池预览自此可做;`trait_json` 内部 specs 一律不下发(客户端只拿中文描述)。附用例钉死「定义端点不得夹带玩家数据」(两个玩家响应逐字节相同)。
- **快照 `city.npcs` 块**补 `morale_leave_threshold`(前端不再硬编码离职线 30)。
- **`GET /api/market/prices`** 下发预估所需全部参数:`slippage_coefficient` / `max_slippage_rate` / `effective_liquidity`(已乘全局倍率)/ `market_max_order_quantity` / 逐资源 `buy_price_pct`(事件价格冲击只读口径,**绝不乘进全服价格字段**;查不到城一律 0;别人的事件不可见)。
- **map 型字段空值形状统一**(`ApiResponse::map()`):`{}` 恒为对象,不再退化成 `[]` —— 快照侧(W7:`resources` / `rates_per_min` / `npcs.assignments` / `items.equipment`)与 **diff 侧 10 处**(W8:建造 / 升级 / 取消 / 拆除 / 研究 / 时代 / NPC / 工具 / 市场 / 事件的 `resources` / `delta` / `truncated`)。形状用例断言全部打在**原始响应文本**上(assoc 解码分不清两种形状,等于没验)。

### 后端:最后两条死 target 接线(W7)——17 条登记 target 至此无死角

- **`market_fee_pct`**(7 位商人 NPC 投稿,合计上限 −56%):消费点在 `TradeService` 成交事务行锁内,买卖两侧**共用同一费率**(只减单侧 = 送一个套利方向盘);`有效费率 = 定义费率 × 全局倍率 × max(0, 1+Σpct)`,**下限 0 绝不为负**(负费率 = 交易所倒贴,同窗往返当场转正)。反套利闭式在 f'=0 时仍成立:净额 = −2·P·q·(s+f'),滑点独自兜底 —— 假失败实测把「允许负费率 → 往返变盈利」精确抓红。
- **`research_speed_pct`**(6 位学者 NPC 投稿):消费点在 `TechService` 开研处,口径与施工加速逐字一致 —— `时长 ÷ (1+Σpct)`(速度式,乘 `(1−pct)` 的时长式在 Σ≥1 时会把工期打成 0 或负数),下限 0.1 倍速;**开研锁内取值一次算死完工时刻,不追溯在研项目**。审计照 BuildService 先例记实际 / 基础双时长。
- 登记表纠错:`market_fee_pct` 的 consumer 原登记为**从不存在的** `MarketService` 类,改为 `TradeService` 并加 `class_exists` 守门断言。

### 前端:面板二 + 契约消费(W7/W8,浏览器实测)

- **工具面板** `item-panel.js`:库存 / 耐久条 / 装备 / 卸下;修理与制作端点后端尚缺 → 按钮**不渲染 / 显示缺口提示**,不给死入口(端点上线自动生效)。
- **事件弹窗** `event-dialog.js`:活跃事件自动弹出(开着面板时只亮 HUD 角标不打断)、选项代价文案、resolve 结果展示资源 delta;`unmapped` 效果带「部分效果尚未承接」提示。
- **HUD 三状态块**:电力 factor / 国防三档威胁(常备 vs 有效分数)/ 治理负载,实测与快照逐项一致。
- **契约消费**:士气警示线改读快照阈值;市场买卖预估改用服务器下发参数,公式与 `TradeService` 逐项对齐(实测预估 = 实际成交,误差 0);数量上限提示;招募池预览(150 行,按时代置灰,`name_zh` 空回落 `npc_id`);NPC 卡片「距下级还需 XP」。
- 修复两处面板溢出(flex 子项 `min-height:auto` 挤出面板 / 收起态标题被压出滚动条)。

### 已知边界(记入 R1 计划「待下一步」,本版不修)

- 玩家侧 `GET /api/definitions/items` 与修理端点未做(工具制作区暂不可用);商人减费不进前端预估(价目表 `fee_rate` 不含城市侧减免,预估偏高属已知偏差);事件 contract 的 `rolled` / `applied.resources` 空值仍是 `[]`(被列表与快照复用,单独排期);取消升级幂等重放缺 `truncated` 字段;后台补偿接口 `resources` 与玩家侧同名不同形状。

## [v1.2.0] — M3 深度内容版 (2026-08-12)

M2 把核心循环填成了有经营深度的**系统**;M3 往这些系统里填**内容**,并把 M2 留下的四个恒 `1.0` 的乘数槽真正点亮。NPC(150 个原型)、工具(24 件)、市场(28 种资源的确定性定价与四道反套利)、随机事件(30 条 DSL)、电力、国防威胁六个系统全部按 `docs/templates/v3.2.md` 接入同一套分段结算内核 —— 而接法本身是这一版最重要的产出:**七乘区 Provider 化 + 非产量 target 登记制**,新系统只新增 Provider / 只登记 target,结算内核一个字不改。

同期清掉了一整层「数据早就写好了、缺的只是一条 target 与一个消费点」的欠账:七条悬空 target 接线(登记表 17 条里 15 条已接,剩 2 条见「已知边界」),原本 Fail Closed 停用的 15 条事件复活了 10 条。

按六个开发波次推进,测试从 **379 增至 829 个**(428 → 581 → 721 → 778 → 811 → 829)。数值版本 `game_data_version` 从 `V3.1.3` 递增到 **`V3.6.2`**(11 次 bump:V3.2.0 / V3.2.1 / V3.3.0 / V3.3.1 / V3.4.0 / V3.4.1 / V3.5.0 / V3.5.1 / V3.6.0 / V3.6.1 / V3.6.2);迁移文件从 28 支增至 **67 支**(M3 新增 39 支);后台可调规则参数从 2 项增至 **88 项**;Service Worker 缓存版本 `apg-v9` → **`apg-v10`**。

### 结算内核:乘数总线与 target 登记制(波次 1,D0)

- **七乘区 Provider 化**:`worker / power / logistics / tech / npc / tool / event` 每一格恰好由一个 `MultiplierProvider` 认领,取数统一在 `ModifierBus::prepare()`(锁内、分段循环之外)完成,逐实例的 `multipliersFor()` 是纯函数、循环内零查库。**名单固定不许扩**(§10.11 生产总公式),§13 的 `2.75× / 3.25×` 硬帽因此始终夹得住。
- **非产量 target 登记表**(`ModifierTarget::CONSUMPTION_POINTS`):§6 技能 / §7 工具 / §9 事件里作用于建造速度、维护成本、市场手续费、治理容量、研究速度、事件损失减免的那一半效果不进七乘区(一条产量管线接不住),各自登记一条 target 与**唯一一个消费点**。纪律:同一条 target 被两处读取 = 双计,与 M2 踩过的 `governance_bonus` / `output_json` 双口径是同一个坑。
- **flat 通道**(`happiness_flat` / `security_flat`):事件对幸福 / 治安的直接冲击走加法通道,不占乘区、不受 §13 帽约束。
- **三份共享文件预置波次锚点**(`CityController` / `routes/web.php` / `ModifierTarget`):并行波次只在自己的锚点块内增删,禁止重排与格式化他人行。

### 审计 Hash Chain(波次 1,E 区)

- `audit_logs` 补 `previous_hash` / `event_hash` 两列,`event_hash = HMAC(规范化事件体 + previous_hash, AUDIT_HMAC_SECRET)`(CLAUDE §58),历史行按 append-only 纪律**不回填**。
- **按 city 分域**并引入 `audit_chain_heads` 链头指针表。这不是优化而是**并发正确性所必需**:直接在 `audit_logs` 上取链尾,会在多个新城同时写第一条审计时因 gap 锁与 insert intention 锁成环报 1213 死锁(旧形状 96 次并发实测 10 次死锁,新形状 0 次)。
- 新增 `php artisan audit:verify-chain`(可按域校验),断链原因码区分 `CONTENT_TAMPERED` / `PREVIOUS_MISMATCH` / `CHAIN_HOLE` / `HALF_LINKED` / `HEAD_MISMATCH`。
- 规范化事件体先 decode 再排序,抵消 MySQL 5.7 原生 JSON 列「读回来的字节与写进去的不一样」的差异(本地 MariaDB 的 LONGTEXT 验不出这条)。

### 数据映射落地与初始资源后台化(波次 1,V3.2.0 / V3.2.1)

- **两份映射草案落地(V3.2.0)**:零来源资源由 5 种收敛到 1 种(电子元件按拍板留给 M3 市场解决),6 条跨代升级链重映射;`M01 医馆 → M02 医院` 的升级链按裁决置 NULL(唯一产出来源不可被升级掉)。
- **初始资源改后台可配(V3.2.1)**:`game_settings.initial_resources` 对象型设定,默认含 `knowledge: 100` —— v1.1.0「已知边界」里点名的**新号开局硬锁**(没知识 → 研究不了 → 建不了任何建筑)由此解除,不再需要管理员补偿垫知识。
- 定义表 `happiness_bonus` / `governance_bonus` / `defense_score` **三列物理删除**(与 `output_json` 双口径且 150+ 行不一致,用户裁决),单一来源永远是 `output_json`。

### NPC 系统(M3-D1,波次 2 / 5)

- 定义层三张表:**150 个原型**(波次 2 落地 30 个,波次 5 按用户提供的 120 个新原型扩充并补 `name_zh` 中文名)、12 条技能、10 级成长曲线。时代 I 新增 4 个可招募原型,**开局即可招募**。
- 运行时:招募(服务器掷点,稀有度权重 + 价格倍率)、派驻、撤下、辞退全部走完整安全链(所有权 / 幂等 / Revision / 行锁 / 不变量 / 审计)。「一 NPC 一岗」由**表形状**保证,不靠应用层判断。
- `NpcMultiplierProvider` 认领 `npc` 乘区,NPC 侧帽 **1.50**(§13 帽修正方向,由 1.90 下调)。
- 工资与口粮走总线的**通用支出通道**(`expense_money_per_min` / `expense_food_per_min`):内核只在一处读一次,并进全城维护速率与人口粮耗基线 —— 欠费判定、半停工、财政预警、前端读数四处自动同口径。
- XP / 士气 / 离职 / 自然增长各有独立懒结算时钟,不挤进主结算。

### 市场(M3-D3,波次 2 / 3)

- **确定性定价**:价格是 `HMAC(MARKET_PRICE_SECRET, 资源 + 窗口号)` 的纯函数,懒求值、不落库、可复现;新增 `.env` 键 `MARKET_PRICE_SECRET`(缺失时降级为价格恒不波动)。
- **§13 点名的四机制齐上**:手续费、成交量上限(单窗 + 小时)、滑点、移动平均。24 种可交易资源的 `volatility` 全部 ≥ 0.04 而买卖价差只有 6%,**纯噪声套利在数学上原本成立** —— 加滑点后给出闭式证明:任意窗口序列下的往返交易恒亏。
- 上市补全:电子元件(时代 X 唯一来源)、水泥 RS027、药品 RS028 —— 市场资源缺口清零。**电力现货刻意拒绝交易**(§8 `capacity_contract` 是产能合约不是库存)。
- 后台两套入口:12 项全局参数 + 逐资源定义编辑。

### 工具 / 道具(M3-D2,波次 3)

- 24 件工具逐行进定义表,制作 / 装备 / 耐久三条链各自独立懒结算;**四道「不工作不扣耐久」闸**(建筑未完工 / 未派工 / 欠费停工 / 升级停产)。
- `ToolMultiplierProvider` 认领 `tool` 乘区:**同类取最高、异类相乘**(§7),口径落在单栋建筑内。

### 随机事件(M3-D4,波次 3~6)

- **30 条事件全部结构化成 DSL 进表**:88 条可执行 specs + 79 条 `unmapped_zh`(承接不了的效果**原样保留**,不猜语义、不静默丢弃)。交付时 15 条启用 / 15 条 Fail Closed 停用**并带停用原因**;随后随各系统落地逐批复活,现为 **25 条启用 / 5 条停用**。
- **确定性掷点**:`HMAC(EVENT_SECRET, 城市 + 窗口号 + 标签)`,触发时掷一次并写进 `rolled_json`,resolve 只读不重掷(否则玩家可反复结算刷一个更轻的损失);新增 `.env` 键 `EVENT_SECRET`。
- **正向事件直接发资源**(§13 帽修正,用户拍板):「农业产量 +20% 持续 15 分钟」折算成一次性发放 `当前 gross 速率 × 加成率 × 分钟数` —— 满配城市的乘区早被 2.75 帽吃光,做成乘区等于正向事件对强城市 100% 无效。负向事件仍走 `event` 乘区(值恒 ≤ 0,惩罚方向本就不受帽约束)。
- **逐事件后台可设定**:开关 / 权重 / 冷却 / 时长 / 效果强度倍率,改动即刻生效并 bump `game_data_version`;另有 22 项全局参数。

### 电力(M.1,波次 4)

- 发电走**容量类装机**(与仓储 / 治理 / 运输容量同一条提取通道,不进 `city_resources`);耗电收敛到 `power_per_min` **单一口径**(`input_json` 里的 electricity 是同一件事的 V2 遗留写法,两处都读 = 双计)。
- `PowerMultiplierProvider` 认领 `power` 乘区,按 §3.3 专属曲线缺电线性打折、下限 0;`EVT_BLACKOUT` 复活(含「保工业 / 保民生」两个选项)。
- **存量电力折算清零**(迁移会改玩家存量数据):9.F4「电力做流量不做库存」,`city_resources` 里的 electricity 存量按交易值折算成资金后清零。
- 顺带接线两个消费点:`maintenance_cost_pct`(**折扣在前、欠费判定在后**;NPC 工资不吃这个折扣)与 `construction_speed_pct`(按速度口径 `工期 ÷ (1 + pct)`,防止大加成把工期算成瞬间完工)。

### 国防威胁(M3-D5,波次 4)

- 威胁等级 `low / medium / high` 由**覆盖率**派生,威胁需求直接复用 §5.1 已定稿的九档「国防最低」(单一来源,本模块里一个九档数字都没有),两个切点后台可调。
- 三条 target 一个消费点:`defense_score_flat` / `defense_score_pct` / `threat_demand_pct`,合成顺序固定 `有效国防值 = max(0, (建筑口径 + Σflat) × (1 + Σpct))`。
- `EVT_RAID`(按缺口率 × 档位倍率扣非资金库存,走损失减免链)与 `EVT_BORDER_TENSION` 复活;事件权重修正里的「国防达标」改读威胁档。
- **时代门槛刻意继续读建筑口径**:一个 20 分钟的事件 buff 不该把城市顶过升代门槛(buff 一过就"倒退")。时代要常备国防,威胁等级要此刻实力,两者不同源 —— 由测试钉死。

### 悬空 target 清偿(波次 5 / 6)

M3 期间反复出现同一种缺陷:**登记了 ≠ 生效**。定义数据早就写好,但 target 没有消费点,效果静默失效。这两个波次把它们一次清干净:

- **容量类三条 + 税收 + 市场价格(波次 5)**:`transport_capacity_pct`(含按语义并入的「铁路容量」)/ `trade_capacity_pct` / `finance_capacity_pct` / `tax_income_pct` / `market_price_pct`。六条事件因此复活(`EVT_ROUTE_BREAK` / `EVT_PORT_CONGESTION` / `EVT_CRIME` / `EVT_CORRUPTION` / `EVT_SPECULATION` / `EVT_OIL_SHOCK`),IT018 与 11 位 NPC 的特性由 `unmapped` 提升为 spec。
  - 贸易容量接上市场**单城成交量上限的分母**(含 200/窗基础额度,没建市场也不至于完全不能交易)—— C01~C04 / M01 / M02 六栋建筑从此不再是纯负债。
  - 价格冲击**只打该城买入侧**:两侧同步上抬会让「事件期间抛货、事件结束后买回」变成印钞机;全服定价一律不动(一座城市的事件不该推全服行情)。
- **治理容量两条(波次 6)**:`governance_capacity_pct` 是 M3 最后一条死 target —— 登记在册却全仓没有任何消费点,而 N013 / N051 / N111 三位写的是 `op=flat`,塞在 pct 这条 target 上,**就算 pct 接了消费点也读不到**。
  - 拆成 `governance_capacity_flat` + `governance_capacity_pct` 两条(口径镜像国防),迁移把三位 NPC 的 flat 投稿挪到 flat target(数值 30 / 20 / 22 一个没动)。
  - 唯一消费点在结算内核:`有效治理容量 = max(0, (建筑口径 + Σflat) × (1 + Σpct))`,作用面 = `governanceLoad → governanceEfficiency → taxIncome` 与快照的 `governance` 块。18 位行政 NPC 与 IT022「治理效率 +10%」由此**首次真的生效**。
  - 时代门槛(`DIM_GOVERNANCE`)与国防同一条纪律:继续读**建筑口径**,不吃临时加成。
  - `EVT_CORRUPTION` 选项 B「行政改革」的治理容量 −10% 由 `unmapped` 提升为可执行 modifier(「事件结束后 +5%」仍是 unmapped:事件系统没有延迟起效的 kind)。
  - 快照 `governance` 块与 `defense` 块同构:`capacity`(有效值)/ `capacity_base`(建筑口径)/ `flat` / `pct` 并列给出。

### 规则参数全设定化

后台可调规则参数由 v1.1.0 的 2 项增至 **88 项**(NPC 31 / 事件 22 / 市场 12+2 / 电力 / 国防 / 工具 6 等),覆盖各系统的开关、权重、冷却、倍率、阈值。新增 `TYPE_NUMBER` 通用数字控件与逐系统后台面板;Definition 数值改动 bump `game_data_version`,纯开关不 bump。用户开发原则(2026-08-11 拍板):**核心先行**(先做后端 API/快照,玩家面板后置到专门的前端波次)、**后台强大**(系统规则数据必须可后台调整,不许硬编码)。

### 其他

- 新增 `.env` 键三把:`AUDIT_HMAC_SECRET`(v1.1.0 已引入,M3 起为必填)、`MARKET_PRICE_SECRET`、`EVENT_SECRET`。三把刻意分开,一把泄露不会连带另一套系统全被预测。
- 新增 39 支迁移(`2026_08_10_900001` 起,逐支说明与执行顺序见 `docs/deploy.md`)。迁移文件总数 **67**。
- M3 期间**未新增任何 Composer / 前端第三方依赖**(`composer.lock` 自 M1P1 起未变)。

### 已知边界 / 待用户裁决(不阻塞发布)

- **登记表里还剩 2 条死 target**(W6 全仓核对,已在 `ModifierTarget` 里显式标注 `wired => false` 并写明接线落点,另有一条测试把这份名单钉死):
  - `market_fee_pct` —— 8 位商人类 NPC + 1 件贸易工具已投稿,`MarketService` 没有读取方(市场代码归并行波次所有,本波不碰);
  - `research_speed_pct` —— 7 位学者类 NPC 已投稿,`TechService` 算 `research_minutes` 时没有读取方(接线口径同建造加速:**除以 `(1 + pct)`**,不是乘 `(1 − pct)`)。
- **5 条事件仍 Fail Closed 停用**,各带停用原因:`EVT_NEW_DEPOSIT`(缺资源节点系统,backlog M.6)、`EVT_EPIDEMIC`(疫病:health 是派生值,没有可写的 target)、`EVT_OVERSEAS_ORDER`(缺市场订单玩法)、`EVT_GLOBAL_CRISIS`(跨多系统的复合效果)、`EVT_TAX_PROTEST`(条件「税率偏高」恒不成立 —— §10.5 明文税率固定不可调)。
- **电站名义发电口子**:电站不派工 / 没煤也照发电(容量类产出在乘区之前提取)。要收紧需要总线支持两阶段 prepare。
- **6 件工具的制作来源建筑不存在**(§7 写的木工作坊 / 石工作坊 / 工坊 / 研究院 / 现代工厂不在 94 栋内):暂按「不卡建筑只卡时代」放行,映射建议待用户点头。
- **§5.4 金融玩法未定**:`finance_capacity` 目前只作读数回传,接线是为了「有投稿就一定有人乘」,不是为了发明玩法。
- **120 个新 NPC 原型的中文名**由用户提供并已落库,但 30 个初版原型仍只有 `name_key` 没有 `name_zh`(前端按 key 回退显示)。
- 高波动资源(`electronic_components` v=0.10 / `rare_metals`、`advanced_materials` v=0.12)在苛刻条件下仍有跨窗套利边际,开市前建议按 `docs/deploy.md` 的「M3 追加上线专项」任选一项收紧。
- 玩家侧面板(NPC / 工具 / 市场 / 事件 / 综合)按「核心先行」原则后置到专门的前端波次,本版只交付 API 与快照数据。

## [v1.1.0] — M2 深度系统版 (2026-08-10)

M1 交付的是「能玩通一圈」的核心循环;M2 把这圈循环填成**有经营深度的系统**:人口与劳动力、幸福/健康/治安、财政与治理、物流、科技研究与时代升级、建筑生命周期,全部按 `docs/templates/v3.2.md` 的数值口径接入同一套分段结算内核。同期把数值主键与枚举值全面英文化、API 契约统一 snake_case,并做了一轮以 M2 新增端点为靶子的对抗式安全回归。

按八个开发波次推进,测试从 **88 增至 379 个**(102 → 137 → 172 → 202 → 242 → 281 → 329 → 376 → 379)。数值版本 `game_data_version` 从 `V3.1.1` 递增到 **`V3.1.3`**;Service Worker 缓存版本 `apg-v1` → **`apg-v9`**(每个波次前端有实质改动就递增一次)。

### 结算内核(波次 1)

- **per-instance 化**:结算从「按建筑类型汇总」改为**逐建筑实例**计算,每个实例带一组独立乘区 `worker / power / logistics / tech / npc / tool / event`(初值全 1.0),各系统只写自己那一格、互不覆盖。M2 依次点亮 worker(C1)、logistics(C4)、tech(B3),其余四格留给 M3。
- **分段结算**:离线时长按 `SEGMENT_MINUTES = 30` 切段(上限 24 段 = 12h 封顶),段内人口/幸福恒定、段末收敛,取代 v1.0.1 的「一整段算到底」。
- **安全可观测性**:`SECURITY.REVISION_CONFLICT` / `SECURITY.SUSPICIOUS_ACTIVITY` 的审计改由全局异常 render 在**事务外**补写(事务内写会被 ROLLBACK 一起抹掉);业务异常统一走 `GameRuleException` + 全局 render,Controller 不再各写 try/catch。
- 前端新增建筑详情面板(等级/位置/占地/产出/升级/拆除)。

### 资源与枚举英文化(波次 2 / 3)

- **资源 ID 全英文化(V3.1.2)**:`resource_definition.resource_id` 主键与 `city_resources` 存档从中文名迁到英文 code(`wood` / `stone` / `food` / `knowledge` …),中文仅作显示名,前端一律经 `/api/definitions/resources` 取名。同批把人均粮耗按 v3.1 §10.1 从 `0.1` 调到 **`0.03`/人/分**。
- **定义表枚举值英文化(V3.1.3)**:`building_definition.category`(12 项)、`series_key`(29 项)、`building_level_definition.cost_type`、`resource_definition.category`、`technology_definition.branch` 全部换成英文 code;迁移中发现的 36 条断链按「宁可置 NULL 不可乱猜」处理,映射表见 `docs/templates/enum-code-map.md`。
- **Game Data Version 全链贯通**:`cities.game_data_version`(这座城以哪一版数值开局)与 `audit_logs.game_data_version`(这条审计发生时线上跑哪一版)两列落地,快照响应带 `data_version`(§64 / §65)。
- **五级管理员角色**:`player / support / game_master / admin / super_admin` 有序梯度 + 权限最低角色表(`read_player` / `read_audit` / `adjust_resource` / `edit_definition` / `ban_player` / `manage_admin`),未知角色与未知权限一律 Fail Closed。

### 人口与劳动力(M2-C1,波次 3)

- 初始人口 10 → **30**(v3.2 §10.4 存档兼容条款),可用劳动力 `availableWorkers = floor(population × 0.60)`。
- 新端点 `POST /api/city/workers/assign`:**绝对值**设置该实例的工人数,受「实例 `worker_required` 上限」与「全城劳动力池」双重约束,走完整安全链(所有权 / 幂等 / Revision / 行锁 / 不变量 / 审计 `WORKER.ASSIGN`)。
- 产出接入 `workerFactor = min(1, assigned / worker_required)` —— **没派工人就不生产**(用户 2026-08-10 拍板为预期玩法,不自动派工)。
- 人口增长基准 `0.2%/分`,乘住房 / 粮食 / 幸福 / 健康四因子;粮食赤字三级后果(§10.1):库存低于 3 分钟消耗 → 迁出 `-0.5%/分`,库存归零满 10 分钟宽限 → 饥荒 `-1.0%/分`,损失方向的人口地板为 5。

### API 契约与派工 UI(波次 4)

- **游戏 API 字段一律 snake_case 全小写**(用户拍板):请求与响应全链改造,`buildingId` → `building_id`、`expectedRevision` → `expected_revision`、`idempotencyKey` → `idempotency_key` 等,前端与全部测试同步跟进。
- 前端建筑面板新增派工控件(撤空 / − / + / 补满)与 HUD 劳动力位;409 冲突自动拉一次权威快照恢复。
- **管理员补偿 `ADMIN.COMPENSATION`**(CLAUDE §80):后台按用户名/city_id 定位 → 查余额 → 单资源增减,强制填 `reason`(≥5 字)与可选工单号,走事务 + 幂等 + Revision + 审计(before/after/delta 齐全),写端点额外叠 `throttle:admin_write`。容量类资源不可调。
- **`game_settings` 规则开关**:`worker_assign_allow_decrease_always`(工人「只减不增」是否永远放行)与 `worker_gate_enabled`(用工闸门总开关,运营救急用),后台可切、改动写 `ADMIN.CONFIG_CHANGE`。与 Definition 数值区分:开关不 bump `game_data_version`。

### 幸福 / 健康 / 治安(M2-C2,波次 5)

- `cities.happiness` 列落地,基准 60,目标值由住房宽裕度、公共服务覆盖率、食物品质等分项合成;实际值向目标**快落慢升**(上行 `+0.5`/分、下行 `-1.0`/分)。
- 缺粮满 5 分钟起额外扣 `-1.0`/分;幸福低于 50 时人口增长因子归零,70 以上取满,中间线性。
- 健康 / 治安为派生值(按对应建筑的服务覆盖率算),与幸福一起进快照与 HUD(😊 / ❤️ / 🛡️)。

### 科技研究与时代升级(M2-B1 / B4 / B6 / B3,波次 5–8)

- **研究**:`POST /api/city/research` 一次性扣知识 + 按 `research_minutes` 计时,到点由懒结算翻成 `unlocked`(审计 `TECH.RESEARCH_START` / `TECH.UNLOCK`),同一时刻只允许一项在研;新增错误码 `TECH_NOT_UNLOCKED` / `RESEARCH_IN_PROGRESS` / `ERA_REQUIRED`。前端科技面板覆盖 50 个节点,三态(可研究 / 条件未满足并说明原因 / 已解锁)+ 在研倒计时。
- **时代升级**:`cities.era_key` / `era_order` 两列落地,`POST /api/city/era/upgrade` 按 v3.2 §5.1 逐维校验(人口 / 知识 / 粮食 / 资金 / 指定建筑数量 / 治理容量 / 幸福 / 国防),不达标返回 `422 ERA_REQUIRED` **并附逐维缺口清单**;快照带 `era` 区块,前端显示条件清单与置灰。
- **建造科技闸门(B4)**:`building_definition.tech_id` 必须已解锁才能建,检查顺序严格对齐 v3.2「时代 → 科技 → 占地 → 上限 → 材料」。研究同时收紧为**只开放当前时代**的节点。
- **科技乘数(B3)**:v3.2 §5 `effect_code` 口径 —— 同分支每解锁一条科技,该分支建筑产出 `+2%`;建筑按自身 `tech_id` 反查所属分支,纯数据驱动无硬编码,满分支上限 `1.20×`。
- 存档城全部回填时代 I(靠列默认值,不写无 WHERE 的 UPDATE)。

### 财政与治理(M2-C3,波次 6)

- **税收**进分段结算:人均 `0.02 × 1.5^(时代-1)`,再乘治理效率。
- **治理四档**:负载 = 人口 / 治理容量,`≤0.80 → 1.00`、`≤1.00 → 0.90`、`≤1.25 → 0.70`、超出 `→ 0.50`;治理容量统一以 `output_json` 为单一来源。
- **维护欠费半停工**:资金不足以付维护时,对应建筑产出 `×0.50`,取代此前「欠费照常生产」的白嫖口径。
- **财政预警**(§10.5):储备撑不满 10 分钟维护 → 黄色,撑不满 3 分钟 → 红色,进快照 `fiscal_warning` 与 HUD 变色。

### 物流(M2-C4)与产量硬上限(波次 7)

- 运输需求 = Σ(生产建筑每分钟输入 + 输出) × `distanceFactor`(M2 恒 1.0,地图距离惩罚留 M3);`transport_capacity` 真实参与,负载分档 `0.80 / 1.00 / 1.25` → 物流率 `1.00 → 0.70 → 最低 0.25`。
- **时代 I 不计运输需求**(`LOGISTICS_MIN_ERA_ORDER = 2`),开局不至于一上来就被物流卡死。
- **§13 产量硬上限**:全部乘区连乘后夹在 `2.75×`(常规)/ `3.25×`(终局)以内,防止多系统叠加突破设计天花板。

### 建筑生命周期(M2-C5,波次 7)

- 建筑三态 `constructing / active / upgrading` + `construction_finished_at` 计时列;完工由**懒结算**在窗口内翻牌,口径「宁可少产不可多产」。
- `POST /api/city/upgrade/cancel`:仅 `upgrading` 可取消,退还该次升级材料的 **70%**(资金不退)。
- 拆除返还按 v3.2 §10.9 分三态:`active` 退已完工等级累计材料 **50%**、`constructing` 退该次建造材料 70%、`upgrading` 两者相加。50% < 70% 是明文要求(防拆建套利)。
- **升级期间停产**(§3.2 明文);住宅升级期间保留 **50%** 人口容量,不至于一升级就把居民赶出城。

### 安全回归(M2-C6,波次 8)

- 以 M2 新增/大改的 9 个端点为靶子实测 23 类攻击场景(越权 / 幂等滥用 / Revision / 输入边界 / 经济不变量 / 时序组合 / 开关组合态),新增 **31 条攻击测试**(`tests/Feature/Security/M2AttackTest.php`)。
- **修复 1 个高危漏洞**:`X-Request-ID` 是客户端可控的,而 `audit_logs.request_id` 只有 `CHAR(36)`。发一个超长 ID 会让 `AuditLogger` 的 INSERT 在 `STRICT_TRANS_TABLES` 下报 1406 `Data too long`,于是后台越权探测被打成**零留痕**的 500、经济 Mutation 则因审计写不进去被整笔回滚。修复:`EnsureRequestId` 只接受 ≤36 字符的合法 ID,超长一律退回服务器生成的 UUID。
- 新增横切防线测试(`M2SurfaceTest`):每个 M2 端点都真的挂着 CSRF 与限流、审计不可被抑制、Security Log 只写 allowlist 字段、每笔成功 Mutation 恰好一条审计。

### 收官加固(本版)

- **补齐限流**:`GET /api/me`、`GET /api/csrf-cookie` 挂 `throttle:api`,`POST /api/auth/logout` 挂 `throttle:auth`(比读端点略严)。`/api/csrf-cookie` 是未登录也能打的公开端点,不限流等于给匿名用户一个免费的 session 生成器。同时加了一条**遍历路由表**的结构性测试:`api/*` 下每条路由都必须挂限流,豁免要显式登记(目前只有健康检查探针 `/api/health`)。
- **统一越权的 Security Log 口径**:`WorkerService` 在抛 `FORBIDDEN` 之前自己写过一条 `security.authorization_failed`,而全局 render 见到 `FORBIDDEN` 还会再补一条 —— 同一次越权在 security 通道里出现两遍,会把「短时间内多少次越权」这类异常检测阈值直接带偏一倍。现统一为:走全局 render 的(抛异常)由 render 写,直接 return 响应的(`DemolishController`)自己写,任一路径恰好一条,并加测试锁死。
- **前端**:手机窄屏下建筑详情面板的主操作(升级 / 拆除)会被顶到折叠线以下且没有滚动提示 —— 操作行改为 `position: sticky` 吸底,移动端面板高度上限 52% → 62%;科技面板的时代条件清单在指纹未变时改为**原地更新数值**,不再停留在上次重绘那一刻的数字(也不会因为每 10 秒轮询就整块重建、打断滚动位置与在研进度条)。

### 其他

- 新增 `php artisan idempotency:prune`:清理已过期的幂等键(只删 `expires_at` 已过期的行,`NULL` 的历史行一律保留)。
- v3.2 数值定稿入库,数值权威由 `docs/templates/v3.1.md` 切换到 `docs/templates/v3.2.md`。
- 新增 11 支迁移(`2026_08_10_200001` 起,逐支说明与执行顺序见 `docs/deploy.md`)。迁移文件总数:M1 的 16 支 + v1.0.1 的 1 支 + 本版 11 支 = **28**。

### 已知边界 / 待用户裁决(不阻塞发布)

- **新号开局硬锁**(本版 E2E 实测发现,**未修**):新城初始资源只有 wood / stone / food / money,**没有 knowledge**;而时代 I 的全部 5 项科技都要 20–30 知识,时代 I 又没有任何产知识的建筑(知识按 v3.2 §8 是时代 III 才登场的资源)。结果是新注册账号**一项科技都研究不了 → 一栋建筑都建不了**。改法涉及数值/设计取舍(送初始知识?时代 I 科技改为免费或预解锁?给时代 I 加产知识的建筑?),按「不擅自改游戏数值」的约定交由项目负责人拍板。当前唯一解法是管理员用 `ADMIN.COMPENSATION` 发放种子知识。
- 建筑详情面板的产出行固定显示 **L1** 数值(`/api/definitions/buildings` 目前只回传 L1),建筑升到 Lv2/Lv3 后该行不跟随当前等级。
- 定义表 `governance_bonus` / `defense_score` / `happiness_bonus` 三列与 `output_json` 存在双口径(150+ 行不一致),现按 `output_json` 单一来源,三列被忽略且后台编辑无效 —— 删列还是改口径待裁决。
- 未完工建筑可以派工并占用劳动力池(C6 裁决:预派 = 预留,维持现状);研究不受拆楼影响(维持现状)。
- 物流「升代产量断崖」的可调参数、两份数据映射草案的审批,均待用户定。

## [v1.0.1] — M1 缺陷修复(经济安全) (2026-08-10)

修复 M2 盘点时发现的 5 个 M1 遗留缺陷,其中 2 个为可刷资源的经济漏洞。测试从 76 增至 **88 个**(新增 12 个,均做过假失败验证,确认非假绿)。

### 修复(Fixed)

- **加工建筑缺料照样出货(可刷资源)**:原实现把产出/投入合并成净速率后逐资源独立夹 `max(0,…)`,原料为 0 时投入扣不动、产出照常累加,凭空造成品。现按「保守库存满足率」限流:每种原料 `globalRate = clamp01(库存/本段总需求)`,每栋加工建筑取其配方中最稀缺原料的满足率,产出与投入同比例缩放;多栋共享原料经需求汇总天然不超扣;维护费照付。保守性:不计入本区间内上游同时生产的原料(宁可少产不可多产),精确分段结算留 M2。
- **建造/升级/拆除事务内未先结算(可用过期快照消费)**:按 CLAUDE §51 把 Time Delta 结算移进 Mutation 的同一把 cities 行锁事务内(幂等/Revision 校验之后、余额校验之前),余额校验一律用结算后的最新值。附带修正:新建筑不再追溯生产建成之前的时段、被拆建筑不再丢失拆除前应得产出。`SimulationService` 重构出 `applyLocked(已锁城市行, now)`(锁内纯结算,不自开事务),`simulate()` 保留为只读快照的兼容包装。
- **离线结算无时长封顶**:新增 `SimConstants::MAX_OFFLINE_SECONDS = 43200`(12h,依据 CLAUDE §18,数值可调),收入与维护扣款同用封顶后时长;`last_simulated_at` 仍推进到当前时刻,不积压。
- **拆除缺幂等与 Revision**:`POST /api/city/demolish` 接入 `idempotencyKey` 与 `expectedRevision`(均可选,与 build/upgrade 对齐);重放不再二次删除,旧 revision 返回 409 REVISION_CONFLICT。
- **幂等键不校验 action 与请求参数**:`idempotency_keys` 新增 `city_id`、`request_hash` 列(迁移 `2026_08_10_100001`),统一入口 `App\Support\Idempotency`(hash/check/store);同一 key 复用于不同操作或不同参数返回 409 `IDEMPOTENCY_KEY_REUSED`(新错误码);`expires_at` 开始写入(24h,清理命令待批准后另行实现);旧数据(`request_hash` 为 NULL)只比对 action 兼容过渡。

### 已知边界(有意维持,记入 M2 backlog)

- 建造/升级失败时结算随事务回滚(时间未丢,下次结算重算),语义自洽。
- 只读快照路径仍每次开事务写库(M2 拆读写时处理)。
- 「有 input 且产容量」的建筑组合当前数据不存在,容量暂不随满足率打折(容量语义化属 M2)。

## [v1.0.0] — M1 核心循环版 (2026-08-10)

> 里程碑说明:`v1.0.0` 这个版本号此前曾在 P7(可玩前端)完成时短暂用过一次(commit `b590840`,当时只覆盖注册/建城/建造/生产/PWA 的可玩闭环)。本条目是 **M1 阶段完整收官**后的正式 `v1.0.0` 发布记录,覆盖 P1–P9 全部范围(新增了 P8 管理后台与 P9 发布加固/收尾,并对 P2/P5/P8 做了对抗式安全审查修复),以此条目为准。

M1 交付了「账号注册登录 → 建城 → 离线也能正确结算的资源生产 → 建造/升级/拆除 → 管理后台运营」的完整核心循环,并具备可执行的上线部署文档。

### 账号/认证(P2)

- Session Cookie 认证:注册、登录、登出、`/api/me`。
- 统一审计日志(`audit_logs`):`occurred_at`/`request_id`/`actor_type`/`action`/`before`/`after`/`delta` 等字段,覆盖登录成功/失败等关键动作。
- 登录、注册各自独立限流(`throttle:auth` / `throttle:register`),降低撞库与账号枚举风险。

### 定义数据(P3)

- 10 个时代(era)、31 种资源、94 种建筑、282 条建筑等级定义、50 项科技,全部通过 Seeder 灌入,数值版本号写入 `game_data_versions` 表(`game_data_version`,当前 `V3.1.1`)。
- 定义表 JSON 字段的 MariaDB/MySQL 5.7 差异已记录在 `docs/ops/db-mariadb-vs-mysql57.md`,上线前需在真实 MySQL 5.7 复核。

### 城市与模拟(P4)

- 注册即自动建城,初始资源/建筑到位。
- Time Delta 模拟:城市快照按离线时长一次性结算生产/消耗(粮食守恒、库存上限、断电等 M2 才做,M1 范围见 `docs/` 设计文档 §15),避免逐 tick 轮询。

### 建造/升级/拆除(P5)

- 建造(L1)、升级(L1→L2→L3)、拆除三个端点,完整走安全链:认证 → CSRF → 限流 → 幂等键 → 城市 Revision → 所有权校验 → 资源/占地/上限校验 → 数据库事务(含行锁)→ 审计。
- 越权操作(改别人城市)一律 403 并写审计。

### 前端可玩(P7)

- `public/game/`:Vanilla JS + PixiJS 等距地图,注册/登录、地图渲染、选建筑、建造交互、资源实时显示。
- PWA 壳:`manifest.json` + Service Worker 离线缓存,已提交 `public/game/vendor/pixi.min.js` 作为前端依赖(随仓库一起部署,无需额外下载/构建)。

### 管理后台(P8)

- `public/admin/`:管理员登录复用同一套 Session 认证,`role=admin` 才能访问。
- 只读:玩家列表、玩家详情(含城市摘要)、审计日志查询。
- 定义调整:建筑三级可编辑字段的读取与提交,写入操作走 allowlist 字段白名单 + 审计 + `game_data_version` 版本递增,全程事务包裹。
- `php artisan admin:promote <username>` 命令用于授予管理员角色。

### 发布收尾(P9)

- §15 范围回归测试(粮食守恒、Time Delta、离线结算、库存上限、事务回滚、审计完整性、Secret 不泄露)。
- `php artisan release:check`:自动核对 `.env` 未入库、`.env.example` 无真实 `APP_KEY`、全部 `.php` git blob 纯 LF 无 BOM,并报告迁移数量与当前数值版本。
- `docs/ops/release-checklist.md`:发布前人工检查清单(APP_DEBUG/HTTPS/Cookie/CSRF/限流/Audit/Backup/依赖漏洞/PWA 缓存版本等,对照 `SECURITY.md`)。
- `docs/deploy.md`:面向 cPanel + MySQL 5.7.39 生产环境的完整可执行部署指南(本文件同批新增)。

### 安全

M1 期间对 P2(账号/审计)、P5(建造/升级/拆除)、P8(管理后台)分别做过一轮对抗式安全审查,均已修复,无遗留 Critical/High 级问题。关键修复:

- **登录/注册限流与账号枚举**(P2):`unique` 校验会间接暴露账号是否存在,且注册端点当时仅有通用 `throttle:api`;加了 `register` 专用限流,降低批量探测账号存在性的可行性;登录侧 `username` 补 `max:190` 并在限流键/审计记录前截断。
- **结算并发覆盖(settlement concurrency clobber)**(P5 · High):Time Delta 结算 `simulate()` 原先没有城市锁且直接写绝对值,与并发的建造请求竞态时会整段抹掉刚才的扣费/产出;修复为加城市锁 + 改写为相对增量。
- **升级重复扣费(upgrade double-charge)**(P5 · High):升级端点原先没有幂等去重,且在加锁前就读取了建筑实例,存在双花/幻影升级窗口;修复为幂等键去重 + 加锁后在事务内重新读取实例 + 校验受影响行数。
- **管理员负值刷钱(admin negative-value money cheat)**(P8 · Medium):后台调整建筑维护成本等字段时,`value` 只校验了 `numeric`,没有下界,理论上可以把维护成本设成负数变相"造钱";修复为 `value` 加 `min:0`。
- **角色字段质量赋值(mass-assignment role)**(P8 · Low):`role` 字段一度可经由批量赋值(mass assignment)途径被写入,存在权限提升隐患;修复为将 `role` 移出 `$fillable`,授权命令改用显式 `forceFill`。

以上修复分别落在 commit `c900ceb`(P2)、`19a37e9`(P5)、`5c6dc48`(P8);详细审查记录见 `.superpowers/sdd/`(不入库,本地开发过程记录)。

---

## 更早里程碑(M1 开发过程,commit message 记录,供追溯)

| 版本 | 内容 |
|---|---|
| v0.1.0 | 项目设计文档与游戏内容填表模板 |
| v0.2.0 | 整合 V3 数值规格,确定架构适配与分期计划 |
| v0.3.0 | M1-P1:基础设施与账号系统起步 |
| v0.4.0 | M1-P1 完成:Laravel 骨架与安全中间件地基 |
| v0.5.0 | M1-P2 完成:Session 认证与审计地基 |
| v0.5.1 | M1-P2 安全补修:登录限流防绕过 + 审计脱敏 |
| v0.6.0 | M1-P3 完成:定义数据入库(10 时代 / 31 资源 / 94 建筑 / 282 等级 / 50 科技) |
| v0.7.0 | M1-P4 完成:城市 Runtime、快照与 Time Delta 模拟 |
| v0.8.0 | M1-P5 完成:建造/升级/拆除全安全链 |
| v0.8.1 | M1-P5 安全加固:结算并发防护 / 升级幂等 / 拆除幂等 |
| v1.0.0(首次使用,已被本文件顶部条目取代) | M1-P7 完成:浏览器可玩核心循环(注册/建城/建造/生产/PWA) |
| v0.9.0 | M1-P8 完成:管理后台(角色/审计查看/Definition 调整) |
| v0.9.1 | M1-P8 安全加固:Definition 值下界 / reason 长度 / 防质量赋值提权 |
| **v1.0.0**(本文件顶部条目,最终版本) | M1 完整版:核心循环 + 管理后台 + 发布文档(收尾) |
