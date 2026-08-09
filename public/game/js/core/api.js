// 统一 API 封装:同源 Session + CSRF;客户端只提交意图
const BASE = '';

// 读 cookie
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
    const headers = { 'Accept': 'application/json' };
    if (body) headers['Content-Type'] = 'application/json';
    const token = cookie('XSRF-TOKEN');
    if (method !== 'GET' && token) headers['X-XSRF-TOKEN'] = token;

    const res = await fetch(BASE + path, {
        method, headers, credentials: 'include',
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
