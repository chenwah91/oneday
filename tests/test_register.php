<?php
// 注册测试
reset_schema();

$r = AuthService::register('测试玩家1', 'password123', 'a@b.com');
assert_true(isset($r['token']) && isset($r['player_id']), '注册成功返回 player_id 和 token');
assert_eq($r['player_id'] ?? null, Auth::playerIdFromToken($r['token'] ?? ''), '注册返回的 token 有效');

$r2 = AuthService::register('测试玩家1', 'password123');
assert_eq('USERNAME_TAKEN', $r2['error'] ?? '', '重复用户名被拒绝');

$r3 = AuthService::register('player2', 'short');
assert_eq('PASSWORD_TOO_SHORT', $r3['error'] ?? '', '短密码被拒绝');

$r4 = AuthService::register('ab', 'password123');
assert_eq('INVALID_USERNAME', $r4['error'] ?? '', '过短用户名被拒绝');

$r5 = AuthService::register('player3', 'password123', 'not-an-email');
assert_eq('INVALID_EMAIL', $r5['error'] ?? '', '非法邮箱被拒绝');

$r6 = AuthService::register('player4', 'password123', '');
assert_true(isset($r6['token']), '空邮箱视为不填,注册成功');
