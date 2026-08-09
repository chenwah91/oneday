// 登录/注册表单:纯 DOM 渲染,无框架
import { api } from '../core/api.js';

// 与后端 RegisterRequest 保持一致:字母/数字/下划线/中文,3-20 位
const USERNAME_RE = /^[A-Za-z0-9_一-龥]{3,20}$/u;

// 后端错误码 → 中文提示
function mapError(err) {
    const code = err && err.error;
    const fieldErrors = (err && err.body && err.body.errors) || null;

    if (code === 'VALIDATION_ERROR' && fieldErrors) {
        if (fieldErrors.username) return '用户名不可用(格式有误,或已被占用)';
        if (fieldErrors.email) return '邮箱格式有误,或已被注册';
        if (fieldErrors.password) return '密码不符合要求(至少 8 位)';
        return '输入有误,请检查后重试';
    }

    switch (code) {
        case 'VALIDATION_ERROR':
            return '输入有误,请检查后重试';
        case 'BAD_CREDENTIALS':
            return '用户名或密码错误';
        case 'TOO_MANY_REQUESTS':
            return '尝试过多,请稍后再试';
        case 'CSRF_TOKEN_MISMATCH':
            return '登录状态已过期,请刷新页面后重试';
        case 'AUTH_REQUIRED':
            return '请先登录';
        case 'FORBIDDEN':
            return '没有权限执行该操作';
        case 'NOT_FOUND':
            return '请求的资源不存在';
        case 'INTERNAL_ERROR':
            return '服务器出错了,请稍后再试';
        default:
            return '网络异常,请稍后再试';
    }
}

// root:挂载容器;onAuthed:登录/注册成功后的回调(由 main.js 传入,负责拉取数据并渲染 HUD)
export function renderAuth(root, onAuthed) {
    let mode = 'login'; // 'login' | 'register'

    function makeField(name, type, label, placeholder) {
        const wrap = document.createElement('label');
        wrap.className = 'auth-field';

        const span = document.createElement('span');
        span.className = 'auth-label';
        span.textContent = label;

        const input = document.createElement('input');
        input.type = type;
        input.name = name;
        input.placeholder = placeholder;
        input.required = true;
        if (name === 'password') {
            input.autocomplete = mode === 'login' ? 'current-password' : 'new-password';
        } else {
            input.autocomplete = name;
        }

        wrap.appendChild(span);
        wrap.appendChild(input);
        return { wrap, input };
    }

    function render() {
        root.innerHTML = '';

        const wrap = document.createElement('div');
        wrap.className = 'auth-wrap';

        const card = document.createElement('div');
        card.className = 'auth-card';

        const title = document.createElement('h1');
        title.className = 'auth-title';
        title.textContent = '城市建设';
        card.appendChild(title);

        const subtitle = document.createElement('p');
        subtitle.className = 'auth-subtitle';
        subtitle.textContent = mode === 'login' ? '登录你的城市' : '创建新账号,开始建设';
        card.appendChild(subtitle);

        const form = document.createElement('form');
        form.className = 'auth-form';
        form.noValidate = true;

        const usernameField = makeField('username', 'text', '用户名', '3-20 位字母/数字/下划线/中文');
        form.appendChild(usernameField.wrap);

        let emailField = null;
        if (mode === 'register') {
            emailField = makeField('email', 'email', '邮箱', 'you@example.com');
            form.appendChild(emailField.wrap);
        }

        const passwordField = makeField('password', 'password', '密码', '至少 8 位');
        form.appendChild(passwordField.wrap);

        const errBox = document.createElement('div');
        errBox.className = 'auth-error';
        errBox.hidden = true;
        form.appendChild(errBox);

        function showError(msg) {
            errBox.textContent = msg;
            errBox.hidden = false;
        }

        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.className = 'auth-submit';
        submitBtn.textContent = mode === 'login' ? '登录' : '注册';
        form.appendChild(submitBtn);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            errBox.hidden = true;
            errBox.textContent = '';

            const username = usernameField.input.value.trim();
            const password = passwordField.input.value;

            // 客户端先做基本格式校验,减少无谓的请求
            if (!USERNAME_RE.test(username)) {
                showError('用户名需为 3-20 位字母/数字/下划线/中文');
                return;
            }
            if (password.length < 8) {
                showError('密码至少需要 8 位');
                return;
            }

            let email;
            if (mode === 'register') {
                email = emailField.input.value.trim();
                if (!email || email.indexOf('@') < 0) {
                    showError('请填写有效邮箱');
                    return;
                }
            }

            submitBtn.disabled = true;
            submitBtn.textContent = '请稍候...';
            try {
                if (mode === 'login') {
                    await api.post('/api/auth/login', { username, password });
                } else {
                    await api.post('/api/auth/register', { username, email, password });
                }
                await onAuthed();
            } catch (err) {
                showError(mapError(err));
                submitBtn.disabled = false;
                submitBtn.textContent = mode === 'login' ? '登录' : '注册';
            }
        });

        card.appendChild(form);

        const toggle = document.createElement('p');
        toggle.className = 'auth-toggle';
        const toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'auth-toggle-btn';
        toggleBtn.textContent = mode === 'login' ? '没有账号?去注册' : '已有账号?去登录';
        toggleBtn.addEventListener('click', () => {
            mode = mode === 'login' ? 'register' : 'login';
            render();
        });
        toggle.appendChild(toggleBtn);
        card.appendChild(toggle);

        wrap.appendChild(card);
        root.appendChild(wrap);

        usernameField.input.focus();
    }

    render();
}
