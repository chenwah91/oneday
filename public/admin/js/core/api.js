// 管理后台 API 封装:同源 Session + CSRF,客户端只提交意图(CLAUDE §5 / §47)。
// 与 /game/js/core/api.js 同一套口径:GET 不带 CSRF,写操作先确保 XSRF-TOKEN 已下发。

const BASE = '';

function cookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
}

let csrfReady = false;

async function ensureCsrf() {
    if (csrfReady) return;
    await fetch(BASE + '/api/csrf-cookie', { credentials: 'include' });
    csrfReady = true;
}

async function request(method, path, body) {
    if (method !== 'GET') await ensureCsrf();

    const headers = { Accept: 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    const token = cookie('XSRF-TOKEN');
    if (method !== 'GET' && token) headers['X-XSRF-TOKEN'] = token;

    const res = await fetch(BASE + path, {
        method,
        headers,
        credentials: 'include',
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.success === false) {
        throw { status: res.status, error: json.error || 'REQUEST_FAILED', body: json };
    }
    return json.data;
}

export const api = {
    get: (p) => request('GET', p),
    post: (p, b) => request('POST', p, b),
};

// 错误码 -> 中文提示(前端只按稳定 Error Code 显示本地文本,CLAUDE §32)
export const ERROR_MESSAGES = {
    BAD_CREDENTIALS: '用户名或密码错误',
    TOO_MANY_REQUESTS: '操作过于频繁,请稍后再试(后台写操作限流 20 次/分钟)',
    VALIDATION_ERROR: '提交的数据不符合要求',
    NOT_FOUND: '未找到对应记录',
    AUTH_REQUIRED: '登录已失效,请重新登录',
    FORBIDDEN: '无权限执行此操作',
    CSRF_TOKEN_MISMATCH: '会话校验失败,请刷新页面后重试',
    INSUFFICIENT_RESOURCE: '扣减后余额会变成负数,已拒绝',
    STORAGE_FULL: '补偿后会超过该城市的仓储上限,已拒绝(请拆分或先扩仓)',
    IDEMPOTENCY_KEY_REUSED: '同一幂等键被用于不同的补偿内容,已拒绝',
    REVISION_CONFLICT: '城市状态已变化,请重新查询后再提交',
    EVENT_DISABLED: '该事件当前处于停用状态(总开关或逐事件开关),请先启用再触发',
    EVENT_LIMIT_REACHED: '该城市的生效事件已达上限,或这条事件已经在生效中',
    INTERNAL_ERROR: '服务器内部错误,请把 request_id 交给后端排查',
};

// 错误信息:422 的逐字段中文说明优先(后端写得比前端猜得准),其次错误码表,最后兜底
export function errorMessage(err) {
    const body = err && err.body ? err.body : null;

    if (body && body.errors && typeof body.errors === 'object') {
        const first = Object.values(body.errors)[0];
        if (Array.isArray(first) && first.length) return String(first[0]);
        if (typeof first === 'string') return first;
    }
    if (err && err.error && ERROR_MESSAGES[err.error]) {
        return ERROR_MESSAGES[err.error];
    }
    if (err && err.status === 0) return '网络错误,请检查连接';
    if (err && err.error) return '操作失败:' + err.error;

    return '操作失败,请稍后再试';
}

// 查询串拼接:空串 / null / undefined 一律跳过,免得给后端送一堆空参数
// (玩家列表的「不带任何参数 = 兼容模式」全靠这条,见 AdminReadController::players)
export function query(params) {
    const parts = [];
    Object.keys(params || {}).forEach((k) => {
        const v = params[k];
        if (v === null || v === undefined || v === '') return;
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    });
    return parts.length ? '?' + parts.join('&') : '';
}
