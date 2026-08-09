<?php
// 登录与锁定测试
reset_schema();
AuthService::register('登录测试员', 'password123');

$r = AuthService::login('登录测试员', 'password123', '127.0.0.1');
assert_true(isset($r['token']), '正确密码登录成功');

$r2 = AuthService::login('登录测试员', 'wrongpass', '127.0.0.1');
assert_eq('BAD_CREDENTIALS', $r2['error'] ?? '', '错误密码被拒绝');

$r3 = AuthService::login('不存在的人', 'password123', '127.0.0.1');
assert_eq('BAD_CREDENTIALS', $r3['error'] ?? '', '不存在的用户名同样返回 BAD_CREDENTIALS');

// 已失败 1 次,再失败 4 次凑满 5 次
for ($i = 0; $i < 4; $i++) {
    AuthService::login('登录测试员', 'wrongpass', '127.0.0.1');
}
$r4 = AuthService::login('登录测试员', 'password123', '127.0.0.1');
assert_eq('TOO_MANY_ATTEMPTS', $r4['error'] ?? '', '15分钟内失败5次后即使密码正确也锁定');
