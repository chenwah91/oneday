// 公共 DOM 工具:所有面板共用一份,不在各自文件里重复写 escapeHtml / 数字格式化(CLAUDE §28)

export const el = (id) => document.getElementById(id);

// 所有插进 innerHTML 的外部数据必须过它 —— 后台会显示玩家用户名 / 停用原因 / 审计 JSON,
// 那些都是别人写进库里的字符串
export function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

// 数字显示:去掉浮点尾巴(1000.0000 → 1000),但保留真正的小数
export function formatAmount(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return String(value ?? '-');
    return String(Math.round(n * 10000) / 10000);
}

// 大数字加千分位(仪表盘用):1234567 → 1,234,567
export function formatCount(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return String(value ?? '-');
    return n.toLocaleString('en-US');
}

// 值是否为「空对象 / 空数组」之外的可展示值
export function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

// 稳定序列化(键排序):设定页判断「当前值是否等于默认值」要用它,
// 否则 {a:1,b:2} 与 {b:2,a:1} 会被判成不同
export function stableJson(value) {
    if (value === null || typeof value !== 'object') return JSON.stringify(value);
    if (Array.isArray(value)) return '[' + value.map(stableJson).join(',') + ']';
    return '{' + Object.keys(value).sort().map((k) => JSON.stringify(k) + ':' + stableJson(value[k])).join(',') + '}';
}

// ---------- 轻量 Toast ----------
// 面板内的结果提示仍写在各自的结果条里;toast 只用于「切走了也该看到」的全局反馈
// (触发事件成功、封禁成功等)
let toastHost = null;

export function toast(message, type = 'info') {
    if (!toastHost) {
        toastHost = document.createElement('div');
        toastHost.className = 'toast-host';
        document.body.appendChild(toastHost);
    }
    const box = document.createElement('div');
    box.className = 'toast toast-' + type;
    box.textContent = message;
    toastHost.appendChild(box);
    setTimeout(() => { box.classList.add('toast-out'); }, 4000);
    setTimeout(() => { box.remove(); }, 4600);
}
