// 顶部 HUD:资源(木材/石料/粮食)+ 资金 + 人口/容量 + 粮食速率 + revision
import { fmt } from '../utils/format.js';

const RESOURCE_ICONS = { '木材': '🪵', '石料': '🪨', '粮食': '🌾' };
const RESOURCE_ORDER = ['木材', '石料', '粮食'];

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
    RESOURCE_ORDER.forEach((key) => {
        resourceEls[key] = makeItem(bar, 'hud-resource', key, RESOURCE_ICONS[key] || '📦');
    });

    const moneyVal = makeItem(bar, 'hud-money', '资金', '💰');
    const popVal = makeItem(bar, 'hud-population', '人口 / 容量', '👤');
    const rateVal = makeItem(bar, 'hud-rate', '粮食速率(每分钟)', '📈');

    const revItem = document.createElement('div');
    revItem.className = 'hud-item hud-revision';
    revItem.title = '数据版本号';
    revItem.textContent = 'rev 0';
    bar.appendChild(revItem);

    el.appendChild(bar);

    refs = { resourceEls, moneyVal, popVal, rateVal, revItem };
}

// city:GET /api/city 返回的 city 对象
export function updateHud(city) {
    if (!refs || !city) return;

    const resources = city.resources || {};
    RESOURCE_ORDER.forEach((key) => {
        refs.resourceEls[key].textContent = fmt(resources[key]);
    });

    refs.moneyVal.textContent = fmt(city.money);
    refs.popVal.textContent = fmt(city.population) + ' / ' + fmt(city.populationCapacity);

    const rate = (city.ratesPerMin && city.ratesPerMin['粮食']) || 0;
    const sign = rate > 0 ? '+' : '';
    refs.rateVal.textContent = sign + fmt(rate) + '/分';

    refs.revItem.textContent = 'rev ' + fmt(city.revision);
}
