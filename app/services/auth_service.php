<?php
// 账号业务逻辑:注册 / 登录
final class AuthService {
    public static function register(string $username, string $password, ?string $email = null): array {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{3,20}$/u', $username)) {
            return ['error' => 'INVALID_USERNAME'];
        }
        if (strlen($password) < 8) {
            return ['error' => 'PASSWORD_TOO_SHORT'];
        }
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'INVALID_EMAIL'];
        }
        $db = Db::get();
        $stmt = $db->prepare('SELECT id FROM player WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        if ($stmt->fetch()) {
            return ['error' => 'USERNAME_TAKEN'];
        }
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO player (username, email, password_hash, status, created_at, updated_at)
             VALUES (:username, :email, :password_hash, 1, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':username'      => $username,
            ':email'         => ($email === '' ? null : $email),
            ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ':created_at'    => $now,
            ':updated_at'    => $now,
        ]);
        $playerId = (int)$db->lastInsertId();
        return ['player_id' => $playerId, 'token' => Auth::issueToken($playerId)];
    }

    public static function login(string $username, string $password, string $ip): array {
        $username = trim($username);
        $db = Db::get();
        // 15 分钟内失败满 5 次锁定
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS n FROM login_attempt
             WHERE username = :username AND success = 0 AND attempted_at > :since'
        );
        $stmt->execute([
            ':username' => $username,
            ':since'    => gmdate('Y-m-d H:i:s', time() - 900),
        ]);
        if ((int)$stmt->fetch()['n'] >= 5) {
            return ['error' => 'TOO_MANY_ATTEMPTS'];
        }

        $stmt = $db->prepare('SELECT id, password_hash FROM player WHERE username = :username AND status = 1 LIMIT 1');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        $okLogin = $row && password_verify($password, $row['password_hash']);

        $stmt = $db->prepare(
            'INSERT INTO login_attempt (username, ip, attempted_at, success)
             VALUES (:username, :ip, :attempted_at, :success)'
        );
        $stmt->execute([
            ':username'     => $username,
            ':ip'           => $ip,
            ':attempted_at' => gmdate('Y-m-d H:i:s'),
            ':success'      => $okLogin ? 1 : 0,
        ]);

        if (!$okLogin) {
            return ['error' => 'BAD_CREDENTIALS'];
        }
        return ['player_id' => (int)$row['id'], 'token' => Auth::issueToken((int)$row['id'])];
    }
}
