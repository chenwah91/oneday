<?php
// Token 层测试
reset_schema();

// 直接插入一个玩家作为前置数据
$db = Db::get();
$now = gmdate('Y-m-d H:i:s');
$stmt = $db->prepare(
    'INSERT INTO player (username, password_hash, status, created_at, updated_at)
     VALUES (:username, :password_hash, 1, :created_at, :updated_at)'
);
$stmt->execute([
    ':username'      => 'tokenuser',
    ':password_hash' => password_hash('x', PASSWORD_BCRYPT),
    ':created_at'    => $now,
    ':updated_at'    => $now,
]);
$pid = (int)$db->lastInsertId();

$token = Auth::issueToken($pid);
assert_eq(64, strlen($token), 'token 为 64 位 hex');
assert_eq($pid, Auth::playerIdFromToken($token), '有效 token 解析出 player_id');
assert_eq(null, Auth::playerIdFromToken(str_repeat('0', 64)), '伪造 token 无效');
assert_eq(null, Auth::playerIdFromToken(''), '空 token 无效');

// 过期 token 无效
$stmt = $db->prepare('UPDATE player_token SET expires_at = :expires_at WHERE token_hash = :token_hash');
$stmt->execute([
    ':expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
    ':token_hash' => hash('sha256', $token),
]);
assert_eq(null, Auth::playerIdFromToken($token), '过期 token 无效');
