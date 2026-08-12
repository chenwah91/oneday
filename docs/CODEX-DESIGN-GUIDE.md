# codex 视觉设计协同规范(apg 项目)

> 给 codex:你负责本项目的**视觉设计与界面美化**。游戏逻辑、后端、数据契约由另一位 AI(Claude)维护。
> 这份文档是两边不打架的边界线——**先通读一遍再动第一行代码**。
> 项目架构详见根目录 `/CLAUDE.md`(必读 §1 技术栈、§5 UI/规则分离、§21-22 手机布局、§26 PWA)。

---

## 一、项目是什么

城市/基地建设经营游戏。前端 **Vanilla HTML/CSS/JS(ES Modules)+ PixiJS**,后端 Laravel(你不用碰)。
**无构建工具**——浏览器直接加载 `public/game/js/main.js`,写什么就跑什么。

本地运行:XAMPP 环境,`C:\xampp\php\php.exe artisan serve --port=8127`,浏览器开 `http://127.0.0.1:8127/game/`,注册个测试账号(用户名请用 `codex` 前缀,方便日后清理)。

---

## 二、铁律(违反任何一条 = 整批改动退回)

1. **禁止引入任何框架、构建工具、第三方库**:不要 React/Vue/Tailwind/TypeScript/SCSS/npm 包/CDN 外链。图标用内联 SVG,字体用系统字体栈或放 `assets/` 的本地字体文件。唯一的第三方是已内置的 PixiJS。
2. **只准改这个清单里的文件**:
   - `public/game/css/**`(所有样式,随便改)
   - `public/game/assets/**`(图片/图标/字体/纹理素材,随便加)
   - `public/game/index.html`(可为视觉重排结构,**但保留现有 id/class 钩子**——改名前全局搜索确认无 JS 引用)
   - `public/game/js/ui/**`(面板的 **DOM 结构与展示逻辑**可改;里面的 API 调用、数据处理、状态更新逻辑**不可改**)
   - `public/game/js/core/enum-names.js`(中文显示名总表,可增改文案)
   - `public/game/js/renderer/**`(地图视觉:纹理、着色、动效可改;镜头/分块/拾取逻辑不动)
   - `public/game/service-worker.js`(只改 CACHE 版本号与预缓存清单)
   - `tests/Feature/Definition/EnumCodeTest.php`(**唯一可动的 PHP 文件**,只改 SW 版本断言,见第 4 条)
3. **绝不碰**:`app/`、`routes/`、`database/`、`tests/`(上面那个文件除外)、`public/admin/`、`js/core/api.js`、`js/core/state.js`、`js/modules/**`、任何 `fetch` 调用的 URL 与参数、任何 API 字段名。**缺数据/缺接口不要自己造**——记进需求清单(见第六节)。
4. **Service Worker 版本纪律**:任何静态资源改动,交付时必须把 `service-worker.js` 里的 `const CACHE = 'apg-vN'` 加一(一批改动只加一次);新增的 js/css 文件要加进预缓存清单;同步把 `tests/Feature/Definition/EnumCodeTest.php` 里 `test_service_worker_precaches_enum_names` 的版本断言改成新值,并照上方注释块的格式补一段本次 bump 理由。改完跑一次:`C:\xampp\php\php.exe artisan test --filter=EnumCodeTest`,必须全绿。
5. **服务器是唯一权威**:界面显示的一切数值来自 API 响应,前端不自己算经济结果、不硬编码任何游戏数值(产量/价格/成本/概率)。`Math.random()` 只准用于纯装饰动画(粒子、闪烁),不准影响任何显示的数据。
6. **文件规范**:UTF-8 无 BOM、换行 LF、注释一律中文。

---

## 三、设计自由度(这些放手做,不用问)

- 全部视觉语言:配色、字阶、间距、圆角、阴影、渐变、CSS 动效(transition/animation)、暗色主题
- HUD、四个底部导航面板(科技/NPC/市场/工具)、事件弹窗、建筑面板的**外观全部重做都可以**——只要保住功能与 DOM 钩子
- 新增 CSS 文件可以(记得挂进 `index.html` 与 SW 清单);拆分重组现有 CSS 也可以
- 地图素材:PixiJS 纹理/tile/建筑贴图放 `assets/`,**按时代分目录**(`assets/era-1/`…`assets/era-10/`),只加载当前时代 + 下一时代(别一次全载)
- 空状态、加载态、toast、无障碍(对比度/焦点态)——欢迎补强

## 四、移动端硬要求

- **400px 宽零横向溢出**(交付前用 400×800 视口过一遍)
- 触控目标 **≥44px**;不依赖 hover;底部导航 + 面板从底部升起的模式已就位,沿用
- PWA:SW 只缓存静态资源,**绝不缓存 API 响应**

## 五、现有结构导览(30 秒版)

```
public/game/
├── index.html          入口(main.js 装配一切)
├── css/                base / layout / hud / panels / mobile / components
├── js/core/            api(请求)/ state(状态)/ enum-names(中文表)/ error-messages(错误文案)
├── js/ui/              hud / building-panel / technology-panel / npc-panel /
│                       market-panel / item-panel / event-dialog(面板类:mount/open/render/close)
├── js/renderer/        PixiJS 地图(等距 tile + 建筑 sprite)
├── js/utils/format.js  数字格式化(千分位/小数)
└── service-worker.js   PWA 缓存(当前 apg-v11)
```

界面现状是「功能完整的简单版」:玩家链路 = 注册 → 地图建造 → 派工 → 研究 → 招募 NPC → 市场买卖 → 装备工具 → 处理事件。HUD 有电力/国防/治理三个状态块与事件角标。

## 六、协作流程(与 Claude 并行不打架的规矩)

1. **开工前 `git pull`**,确认本地是最新 main。
2. 只动第二节清单内的文件。**改动范围外的需求**(要新数据字段、要新 API、发现逻辑 bug)一律写进 `docs/plans/design-requests.md`(没有就建一个,一行一条:要什么、给哪个界面用),由 Claude 排期实现——**不要自己写后端或改数据逻辑**。
3. **大改先出预览**:整体风格/全面板重做这种量级,先出一版静态 HTML 或截图(放 `docs/design-previews/`)给用户确认方向,再铺开。
4. 交付自检清单:
   - [ ] 浏览器实测:登录 → 建造 → 四面板开合 → 事件弹窗,console **零 error**
   - [ ] 400×800 视口无溢出、无遮挡
   - [ ] SW 版本已 bump + EnumCodeTest 全绿
   - [ ] 没碰清单外文件(`git status` 自查)
5. git:commit message 用**中文简短说明**(仓库惯例,例:`设计:HUD 重绘与暗色主题`);推 main 前先 pull;**绝不 `git push -f`**。`CHANGELOG.md`/`docs/STATUS.md` 不用你维护。

---

*本规范由 Claude 维护(2026-08-12 v1)。规则冲突或拿不准时:在 design-requests.md 里提问,或让用户转达。*
