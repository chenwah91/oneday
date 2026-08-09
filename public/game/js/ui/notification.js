// 通知组件:多条排队/堆叠显示,分 info / success / error 三级
// 只负责展示,不做任何业务判断;调用方传入的必须是已本地化的文案

const LEVELS = ['info', 'success', 'error'];
const MAX_VISIBLE = 3; // 同时最多显示 3 条,超出时最早一条立即退场
const DEFAULT_MS = 2400; // 默认停留时长
const ERROR_MS = 3600; // 错误停留更久,避免一闪而过看不清
const FADE_MS = 260; // 与 CSS transition 时长一致,用于延迟移除节点

let stackEl = null;
const active = []; // 正在显示的通知 [{ el, timer }],先进先出

// 容器懒创建:被外部清空 DOM(如登录页重渲染)后能自愈
function ensureStack() {
    if (stackEl && stackEl.parentNode) return stackEl;
    stackEl = document.createElement('div');
    stackEl.id = 'toast-stack';
    stackEl.className = 'toast-stack';
    document.body.appendChild(stackEl);
    return stackEl;
}

function dismiss(item) {
    const idx = active.indexOf(item);
    if (idx < 0) return; // 已在退场中,避免重复移除
    active.splice(idx, 1);
    clearTimeout(item.timer);
    item.el.classList.remove('show');
    // 淡出动画结束后再移除节点;transitionend 在后台标签页可能不触发,这里用定时器兜底
    setTimeout(() => {
        if (item.el.parentNode) item.el.parentNode.removeChild(item.el);
    }, FADE_MS);
}

// message:已本地化文案;level:info | success | error;duration:停留毫秒(可选)
export function notify(message, level, duration) {
    const text = String(message == null ? '' : message);
    if (!text) return;

    const kind = LEVELS.indexOf(level) >= 0 ? level : 'info';
    const stack = ensureStack();

    const el = document.createElement('div');
    el.className = 'toast toast-' + kind;
    el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    el.textContent = text;
    stack.appendChild(el);

    const item = { el, timer: null };
    active.push(item);

    // 超出上限:最早的一条立即退场,保证屏幕上始终只有最近几条
    while (active.length > MAX_VISIBLE) {
        dismiss(active[0]);
    }

    el.addEventListener('click', () => dismiss(item)); // 点击可提前关闭

    // 先强制一次回流让初始态生效,再加 show 触发过渡
    // (不用 requestAnimationFrame:页面被遮挡时 rAF 会被节流,通知可能一直不显示)
    void el.offsetWidth;
    el.classList.add('show');

    const ms = typeof duration === 'number' ? duration : (kind === 'error' ? ERROR_MS : DEFAULT_MS);
    item.timer = setTimeout(() => dismiss(item), ms);
}

export function notifyInfo(message, duration) {
    notify(message, 'info', duration);
}

export function notifySuccess(message, duration) {
    notify(message, 'success', duration);
}

export function notifyError(message, duration) {
    notify(message, 'error', duration);
}
