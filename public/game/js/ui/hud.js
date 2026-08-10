// 顶部 HUD:资源(wood/stone/food)+ 资金 + 人口/容量 + 劳动力已用/可用 + 粮食速率
//          + 民生三值(幸福/健康/治安)+ revision
// 资源用英文 code 索引,显示文字一律走 resourceName(code)
// 读的字段一律是快照的 snake_case 契约字段(用户 2026-08-10 拍板)
import { fmt } from '../utils/format.js';
import { resourceName } from '../modules/resources.js';

const RESOURCE_ICONS = { wood: '🪵', stone: '🪨', food: '🌾' };
const RESOURCE_ORDER = ['wood', 'stone', 'food']; // 显示顺序:木材 / 石料 / 粮食(与改造前一致)
const FOOD = 'food';

// 幸福警示线(v3.2 §11 民生行「预警阈值建议 <55」;§10.3 低于 50 人口彻底停止增长)。
// 取 50:前端只在「已经开始伤害增长」时才变红,避免 55 附近长期红着让玩家脱敏
const HAPPINESS_ALERT = 50;

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
    const popVal = makeItem(bar, 'hud-population', '人口 / 容量', '👤');
    // 劳动力(§10.4):已派工 / 可用工人。没派工人就不生产,这里让玩家一眼看到还有多少人闲着
    const laborVal = makeItem(bar, 'hud-labor', '劳动力 已用 / 可用', '🛠️');
    const rateVal = makeItem(bar, 'hud-rate', resourceName(FOOD) + '速率(每分钟)', '📈');

    // 民生三值(§10.2 / §10.8):幸福 / 健康 / 治安,统一 0~100 整数显示。
    // 幸福是持久状态(影响人口增长),健康与治安是医疗/国防容量的覆盖率映射
    const happinessVal = makeItem(bar, 'hud-happiness', '幸福度 0-100(低于 50 停止人口增长)', '😊');
    const healthVal = makeItem(bar, 'hud-health', '健康度 0-100(医疗容量覆盖率)', '❤️');
    const securityVal = makeItem(bar, 'hud-security', '治安度 0-100(国防值覆盖率)', '🛡️');

    const revItem = document.createElement('div');
    revItem.className = 'hud-item hud-revision';
    revItem.title = '数据版本号';
    revItem.textContent = 'rev 0';
    bar.appendChild(revItem);

    el.appendChild(bar);

    refs = { resourceEls, moneyVal, popVal, laborVal, rateVal, happinessVal, healthVal, securityVal, revItem };
}

// city:GET /api/city 返回的 city 对象
export function updateHud(city) {
    if (!refs || !city) return;

    const resources = city.resources || {};
    RESOURCE_ORDER.forEach((code) => {
        refs.resourceEls[code].textContent = fmt(resources[code]);
    });

    refs.moneyVal.textContent = fmt(city.money);
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

    refs.revItem.textContent = 'rev ' + fmt(city.revision);
}
