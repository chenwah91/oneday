// 顶部 HUD:资源(wood/stone/food)+ 资金(带财政预警变色)+ 人口/容量 + 劳动力已用/可用
//          + 粮食速率 + 民生三值(幸福/健康/治安)+ 时代 + revision
//          + 电力 / 国防 / 治理三块状态(M3 前端波二,点击展开简要说明)+ 活跃事件角标
// 资源用英文 code 索引,显示文字一律走 resourceName(code)
// 读的字段一律是快照的 snake_case 契约字段(用户 2026-08-10 拍板)
import { fmt, fmtDec } from '../utils/format.js';
import { resourceName } from '../modules/resources.js';
import { threatLevelName } from '../core/enum-names.js';

const RESOURCE_ICONS = { wood: '🪵', stone: '🪨', food: '🌾' };
const RESOURCE_ORDER = ['wood', 'stone', 'food']; // 显示顺序:木材 / 石料 / 粮食(与改造前一致)
const FOOD = 'food';

// 幸福警示线(v3.2 §11 民生行「预警阈值建议 <55」;§10.3 低于 50 人口彻底停止增长)。
// 取 50:前端只在「已经开始伤害增长」时才变红,避免 55 附近长期红着让玩家脱敏
const HAPPINESS_ALERT = 50;

// 财政预警(v3.2 §10.5)三态 → 提示文字。级别由服务端派生(city.fiscal_warning),
// 前端只负责上色与话术,绝不自己拿资金除维护再判一次阈值
const FISCAL_TITLES = {
    yellow: '资金可支撑维护不足 10 分钟',
    red: '维护费即将付不出,建筑将半停工',
};

// 电力 / 国防 / 治理三块的展开说明:同时只展开一块(HUD 是常驻条,展开区一多就把地图挤没了)
let openDetail = null;

// 事件角标的点击回调:由 main.js 注入(HUD 不认识 event-dialog,避免与它互相 import)
let eventBadgeHandler = null;

export function setEventBadgeHandler(fn) {
    eventBadgeHandler = fn;
}

let refs = null;

function makeItem(bar, className, title, icon) {
    const item = document.createElement('div');
    item.className = 'hud-item ' + className;
    item.title = title;

    const iconEl = document.createElement('span');
    iconEl.className = 'hud-icon';
    iconEl.textContent = icon;

    const val = document.createElement('span');
    val.className = 'hud-value';
    val.textContent = '0';

    item.appendChild(iconEl);
    item.appendChild(val);
    bar.appendChild(item);
    return val;
}

// 可点开的状态块(电力 / 国防 / 治理):点一下在 HUD 下方展开三五行说明,再点收起。
// 刻意不做成完整面板 —— 这三块是「看一眼就够」的读数,做成面板反而多一层开关
function makeTapItem(bar, key, className, title, icon) {
    const item = document.createElement('button');
    item.type = 'button';
    item.className = 'hud-item hud-tap ' + className;
    item.title = title;
    item.setAttribute('aria-label', title);
    item.setAttribute('aria-expanded', 'false');

    const iconEl = document.createElement('span');
    iconEl.className = 'hud-icon';
    iconEl.textContent = icon;

    const val = document.createElement('span');
    val.className = 'hud-value';
    val.textContent = '-';

    item.appendChild(iconEl);
    item.appendChild(val);
    item.addEventListener('click', () => {
        openDetail = openDetail === key ? null : key;
        renderDetail();
    });
    bar.appendChild(item);

    return { item, val };
}

// 展开区:每次重画(行数随口径变化),内容全部取自当前快照
function renderDetail(city) {
    if (!refs || !refs.detailEl) return;

    const data = city || refs.lastCity;
    refs.detailEl.innerHTML = '';
    refs.detailEl.hidden = !openDetail || !data;

    if (refs.power) refs.power.item.setAttribute('aria-expanded', openDetail === 'power' ? 'true' : 'false');
    if (refs.defense) refs.defense.item.setAttribute('aria-expanded', openDetail === 'defense' ? 'true' : 'false');
    if (refs.governance) refs.governance.item.setAttribute('aria-expanded', openDetail === 'governance' ? 'true' : 'false');

    if (!openDetail || !data) return;

    const rows = DETAIL_BUILDERS[openDetail] ? DETAIL_BUILDERS[openDetail](data) : [];

    rows.forEach((row) => {
        const line = document.createElement('div');
        line.className = 'hud-detail-row' + (row[2] ? ' is-note' : '');

        const key = document.createElement('span');
        key.className = 'hud-detail-key';
        key.textContent = row[0];
        line.appendChild(key);

        const val = document.createElement('span');
        val.className = 'hud-detail-value';
        val.textContent = row[1];
        line.appendChild(val);

        refs.detailEl.appendChild(line);
    });
}

// 百分比显示:factor / rate / efficiency 这类比值统一按整数百分比给
function pct(n, digits) {
    return fmtDec((Number(n) || 0) * 100, typeof digits === 'number' ? digits : 0) + '%';
}

// 三块的展开内容。每一行都是 [标签, 值, 是否为说明行]。
// 数值一律直接读快照字段,前端不做任何二次推算(§5:客户端不算经济结果)
const DETAIL_BUILDERS = {
    power(city) {
        const p = city.power || {};
        const rows = [
            ['名义装机', fmtDec(p.capacity_per_min, 1) + ' /分'],
            ['可用发电', fmtDec(p.available_per_min, 1) + ' /分'],
            ['全城耗电', fmtDec(p.demand_per_min, 1) + ' /分'],
            ['余量', fmtDec(p.spare_per_min, 1) + ' /分'],
            ['使用率', pct(p.usage_rate)],
            ['产能系数', pct(p.factor)],
        ];
        if (Number(p.event_pct)) {
            rows.push(['事件影响', pct(p.event_pct, 1)]);
        }
        rows.push([p.shortage
            ? '缺电中:全城产量按产能系数打折,建电站或少开耗电建筑'
            : '电力充足:电力是流量不是库存,不进仓库也不能买卖', '', true]);
        return rows;
    },
    defense(city) {
        const d = city.defense || {};
        return [
            ['威胁等级', d.threat_level_zh || threatLevelName(d.threat_level)],
            ['有效国防值', fmtDec(d.defense_score, 1)],
            ['其中常备(建筑)', fmtDec(d.defense_score_base, 1)],
            ['威胁需求', fmtDec(d.threat_demand, 1)],
            ['覆盖率', pct(d.coverage)],
            ['国防值不足时,劫掠类事件的损失按缺口放大;时代升级看的是常备值,不含临时加成', '', true],
        ];
    },
    governance(city) {
        const g = city.governance || {};
        const rows = [
            ['治理负载', pct(g.load)],
            ['治理效率', pct(g.efficiency)],
            ['有效容量', fmtDec(g.capacity, 1)],
            ['其中常备(建筑)', fmtDec(g.capacity_base, 1)],
        ];
        if (Number(g.flat)) rows.push(['官员 / 工具加成', '+' + fmtDec(g.flat, 1)]);
        if (Number(g.pct)) rows.push(['百分比加成', pct(g.pct, 1)]);
        rows.push([(Number(g.efficiency) || 1) < 1
            ? '治理过载:负载 = 人口 ÷ 有效治理容量,超过 80% 就开始打折税收(80 / 100 / 125% 四档),建行政建筑或派行政 NPC 可以缓解'
            : '负载 = 人口 ÷ 有效治理容量,80% 以内税收不打折', '', true]);
        return rows;
    },
};

// el:HUD 挂载容器
export function mountHud(el) {
    el.innerHTML = '';
    const bar = document.createElement('div');
    bar.className = 'hud-bar';

    const resourceEls = {};
    RESOURCE_ORDER.forEach((code) => {
        resourceEls[code] = makeItem(bar, 'hud-resource', resourceName(code), RESOURCE_ICONS[code] || '📦');
    });

    const moneyVal = makeItem(bar, 'hud-money', resourceName('money'), '💰');
    // 资金栏整块的 title 要随财政预警改写(悬停在图标上也能看到原因),所以额外留一个外层引用
    const moneyItem = moneyVal.parentElement;
    const popVal = makeItem(bar, 'hud-population', '人口 / 容量', '👤');
    // 劳动力(§10.4):已派工 / 可用工人。没派工人就不生产,这里让玩家一眼看到还有多少人闲着
    const laborVal = makeItem(bar, 'hud-labor', '劳动力 已用 / 可用', '🛠️');
    const rateVal = makeItem(bar, 'hud-rate', resourceName(FOOD) + '速率(每分钟)', '📈');

    // 民生三值(§10.2 / §10.8):幸福 / 健康 / 治安,统一 0~100 整数显示。
    // 幸福是持久状态(影响人口增长),健康与治安是医疗/国防容量的覆盖率映射
    const happinessVal = makeItem(bar, 'hud-happiness', '幸福度 0-100(低于 50 停止人口增长)', '😊');
    const healthVal = makeItem(bar, 'hud-health', '健康度 0-100(医疗容量覆盖率)', '❤️');
    const securityVal = makeItem(bar, 'hud-security', '治安度 0-100(国防值覆盖率)', '🛡️');

    // 电力 / 国防 / 治理(M3 波二):三块都是派生读数,点开才看细节。
    // 电力用产能系数(七乘区里 power 那一格)做主数字 —— 玩家真正关心的是「产量被打了几折」
    const power = makeTapItem(bar, 'power', 'hud-power', '电力:产能系数(缺电时全城产量按它打折),点击展开', '⚡');
    const defense = makeTapItem(bar, 'defense', 'hud-defense', '国防:威胁等级与国防值 / 威胁需求,点击展开', '⚔️');
    const governance = makeTapItem(bar, 'governance', 'hud-governance', '治理:负载 = 人口 ÷ 治理容量,超过 80% 打折税收,点击展开', '⚖️');

    // 时代(M2-B6):独立小元素,显示当前时代 key(详细条件在科技面板的时代区块里看)
    const eraVal = makeItem(bar, 'hud-era', '当前文明时代', '🏛️');

    // 活跃事件角标:有事件才显示,点一下把事件弹窗调回来(收起过也能找回来)
    const eventItem = document.createElement('button');
    eventItem.type = 'button';
    eventItem.className = 'hud-item hud-tap hud-event';
    eventItem.title = '生效中的事件,点击查看';
    eventItem.setAttribute('aria-label', '生效中的事件');
    eventItem.hidden = true;

    const eventIcon = document.createElement('span');
    eventIcon.className = 'hud-icon';
    eventIcon.textContent = '🔔';
    eventItem.appendChild(eventIcon);

    const eventVal = document.createElement('span');
    eventVal.className = 'hud-value';
    eventVal.textContent = '0';
    eventItem.appendChild(eventVal);

    eventItem.addEventListener('click', () => {
        if (eventBadgeHandler) eventBadgeHandler();
    });
    bar.appendChild(eventItem);

    const revItem = document.createElement('div');
    revItem.className = 'hud-item hud-revision';
    revItem.title = '数据版本号';
    revItem.textContent = 'rev 0';
    bar.appendChild(revItem);

    el.appendChild(bar);

    // 展开区:跟在 HUD 条后面的普通块级元素(不用绝对定位 —— sticky 条下面的绝对层
    // 在窄屏会被地图 canvas 的层叠上下文裁掉)
    const detailEl = document.createElement('div');
    detailEl.className = 'hud-detail';
    detailEl.hidden = true;
    el.appendChild(detailEl);

    refs = {
        resourceEls, moneyVal, moneyItem, popVal, laborVal, rateVal,
        happinessVal, healthVal, securityVal, eraVal, revItem,
        power, defense, governance, eventItem, eventVal, detailEl, lastCity: null,
    };
}

// city:GET /api/city 返回的 city 对象
export function updateHud(city) {
    if (!refs || !city) return;

    const resources = city.resources || {};
    RESOURCE_ORDER.forEach((code) => {
        refs.resourceEls[code].textContent = fmt(resources[code]);
    });

    refs.moneyVal.textContent = fmt(city.money);

    // 财政预警(§10.5):黄 = 资金撑不到 10 分钟维护,红 = 撑不到 3 分钟(再欠费就半停工)。
    // 红色复用幸福那套 .hud-alert 警示态,黄色单开 .hud-warn;老响应里没有这个字段时按 none 处理
    const fiscal = city.fiscal_warning || 'none';
    refs.moneyVal.classList.toggle('hud-warn', fiscal === 'yellow');
    refs.moneyVal.classList.toggle('hud-alert', fiscal === 'red');
    refs.moneyItem.title = FISCAL_TITLES[fiscal] || resourceName('money');
    refs.popVal.textContent = fmt(city.population) + ' / ' + fmt(city.population_capacity);
    refs.laborVal.textContent = fmt(city.assigned_workers) + ' / ' + fmt(city.available_workers);

    const rate = (city.rates_per_min && city.rates_per_min[FOOD]) || 0;
    const sign = rate > 0 ? '+' : '';
    refs.rateVal.textContent = sign + fmt(rate) + '/分';

    // 民生三值:服务器的 happiness 是 float(收敛斜率需要小数),HUD 只显示整数
    const happiness = Math.round(Number(city.happiness) || 0);
    refs.happinessVal.textContent = happiness;
    // 幸福低于警示线变红:此时 happinessFactor 已经是 0,人口完全停止增长
    refs.happinessVal.classList.toggle('hud-alert', happiness < HAPPINESS_ALERT);
    refs.healthVal.textContent = Math.round(Number(city.health) || 0);
    refs.securityVal.textContent = Math.round(Number(city.security) || 0);

    // 时代:快照的 city.era(M2-B6);老响应里没有这块时留空,不显示 undefined
    refs.eraVal.textContent = city.era ? city.era.era_key : '-';

    refs.revItem.textContent = 'rev ' + fmt(city.revision);

    updateStatusBlocks(city);

    refs.lastCity = city;
    renderDetail(city);
}

// 电力 / 国防 / 治理 / 事件角标:四块都只读快照字段,阈值判定也全部来自服务端下发的值
// (shortage / threat_level / efficiency),前端不自己拿分子分母再判一次
function updateStatusBlocks(city) {
    // ---- 电力(§3.3):factor < 1 就是在缺电,全城产量按它打折 ----
    const p = city.power || {};
    const factor = Number(p.factor);
    const hasPower = !isNaN(factor) && p.factor !== undefined && p.factor !== null;
    refs.power.val.textContent = hasPower ? pct(factor) : '-';
    // shortage 是服务端派生的缺电标记;老响应里没有时退回按 factor 判
    const short = p.shortage === true || (hasPower && factor < 1);
    refs.power.val.classList.toggle('hud-alert', short);

    // ---- 国防(§11):三档中文 + 国防值 / 威胁需求 ----
    const d = city.defense || {};
    const level = d.threat_level || null;
    refs.defense.val.textContent = level
        ? (d.threat_level_zh || threatLevelName(level)) + ' ' + fmt(d.defense_score) + '/' + fmt(d.threat_demand)
        : '-';
    // 紧张 = 黄,危险 = 红;安全不上色(与财政预警同一套三态)
    refs.defense.val.classList.toggle('hud-warn', level === 'medium');
    refs.defense.val.classList.toggle('hud-alert', level === 'high');

    // ---- 治理(§10.6):常态只显示负载,效率被压下来时才把它摆出来 ----
    const g = city.governance || {};
    const hasGov = g.load !== undefined && g.load !== null;
    const efficiency = Number(g.efficiency);
    const overload = hasGov && !isNaN(efficiency) && efficiency < 1;
    refs.governance.val.textContent = hasGov
        ? pct(g.load) + (overload ? ' · 效率 ' + pct(efficiency) : '')
        : '-';
    refs.governance.val.classList.toggle('hud-alert', overload);

    // ---- 活跃事件角标:快照 summary 的 active_count(详情走独立端点)----
    const count = Number((city.events || {}).active_count) || 0;
    refs.eventVal.textContent = count > 99 ? '99+' : String(count);
    refs.eventItem.hidden = count <= 0;
}
