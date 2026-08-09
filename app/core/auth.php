<?php
// Token 认证:登录后发放随机 token,数据库只存 sha256 哈希
final class Auth {
    public static function issueToken(int $playerId): string {
        $token = bin2hex(random_bytes(32));
        $ttlDays = (int)(App::config()['token_ttl_days'] ?? 30);
        $stmt = Db::get()->prepare(
            'INSERT INTO player_token (player_id, token_hash, expires_at, created_at)
             VALUES (:player_id, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            ':player_id'  => $playerId,
            ':token_hash' => hash('sha256', $token),
            ':expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlDays * 86400),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    public static function playerIdFromToken(?string $token): ?int {
        if ($token === null || $token === '') {
            return null;
        }
        $stmt = Db::get()->prepare(
            'SELECT player_id FROM player_token
             WHERE token_hash = :token_hash AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([
            ':token_hash' => hash('sha256', $token),
            ':now'        => gmdate('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch();
        return $row ? (int)$row['player_id'] : null;
    }

    public static function bearerToken(): ?string {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([0-9a-f]{64})$/i', $hdr, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function requirePlayerId(): int {
        $pid = self::playerIdFromToken(self::bearerToken());
        if ($pid === null) {
            Response::fail('AUTH_REQUIRED', ErrorText::of('AUTH_REQUIRED'), 401);
        }
        return $pid;
    }
}
