// 顶部 HUD:资源(wood/stone/food)+ 资金 + 人口/容量 + 劳动力已用/可用 + 粮食速率 + revision
// 资源用英文 code 索引,显示文字一律走 resourceName(code)
// 读的字段一律是快照的 snake_case 契约字段(用户 2026-08-10 拍板)
import { fmt } from '../utils/format.js';
import { resourceName } from '../modules/resources.js';

const RESOURCE_ICONS = { wood: '🪵', stone: '🪨', food: '🌾' };
const RESOURCE_ORDER = ['wood', 'stone', 'food']; // 显示顺序:木材 / 石料 / 粮食(与改造前一致)
const FOOD = 'food';

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

    const revItem = document.createElement('div');
    revItem.className = 'hud-item hud-revision';
    revItem.title = '数据版本号';
    revItem.textContent = 'rev 0';
    bar.appendChild(revItem);

    el.appendChild(bar);

    refs = { resourceEls, moneyVal, popVal, laborVal, rateVal, revItem };
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

    refs.revItem.textContent = 'rev ' + fmt(city.revision);
}
