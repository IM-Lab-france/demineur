<?php
declare(strict_types=1);

final class AdminLoginThrottle {
    public function __construct(private PDO $pdo) {}

    public function retryAfter(string $identifier, ?int $now = null): int {
        $now ??= time();
        $stmt = $this->pdo->prepare('SELECT blocked_until FROM admin_login_attempts WHERE identifier_hash=:identifier');
        $stmt->execute(['identifier' => hash('sha256', $identifier)]);
        $blockedUntil = $stmt->fetchColumn();
        return $blockedUntil ? max(0, strtotime((string) $blockedUntil) - $now) : 0;
    }

    public function recordFailure(string $identifier, ?int $now = null): int {
        $now ??= time();
        $hash = hash('sha256', $identifier);
        $stmt = $this->pdo->prepare('SELECT attempts, window_started_at FROM admin_login_attempts WHERE identifier_hash=:identifier');
        $stmt->execute(['identifier' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $attempts = (!$row || strtotime((string) $row['window_started_at']) < $now - 900) ? 1 : (int) $row['attempts'] + 1;
        $blockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', $now + 900) : null;
        $upsert = $this->pdo->prepare(
            'INSERT INTO admin_login_attempts(identifier_hash,attempts,window_started_at,blocked_until,last_attempt_at) '
            . 'VALUES(:identifier,:attempts,FROM_UNIXTIME(:started),:blocked,FROM_UNIXTIME(:now)) '
            . 'ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),window_started_at=VALUES(window_started_at),blocked_until=VALUES(blocked_until),last_attempt_at=VALUES(last_attempt_at)'
        );
        $upsert->execute(['identifier' => $hash, 'attempts' => $attempts, 'started' => $attempts === 1 ? $now : strtotime((string) $row['window_started_at']), 'blocked' => $blockedUntil, 'now' => $now]);
        return $blockedUntil ? 900 : 0;
    }

    public function clear(string $identifier): void {
        $stmt = $this->pdo->prepare('DELETE FROM admin_login_attempts WHERE identifier_hash=:identifier');
        $stmt->execute(['identifier' => hash('sha256', $identifier)]);
    }

    public function purge(?int $now = null): void {
        $now ??= time();
        $stmt = $this->pdo->prepare('DELETE FROM admin_login_attempts WHERE last_attempt_at < FROM_UNIXTIME(:before)');
        $stmt->execute(['before' => $now - 86400]);
    }
}

final class SessionAdminLoginThrottle {
    public function retryAfter(string $identifier, ?int $now = null): int {
        $now ??= time();
        $attempt = $_SESSION['admin_login_throttle'][hash('sha256', $identifier)] ?? null;
        return is_array($attempt) ? max(0, (int) ($attempt['blocked_until'] ?? 0) - $now) : 0;
    }

    public function recordFailure(string $identifier, ?int $now = null): int {
        $now ??= time();
        $key = hash('sha256', $identifier);
        $attempt = $_SESSION['admin_login_throttle'][$key] ?? ['attempts' => 0, 'started' => $now];
        if ((int) $attempt['started'] < $now - 900) $attempt = ['attempts' => 0, 'started' => $now];
        $attempt['attempts']++;
        $attempt['blocked_until'] = $attempt['attempts'] >= 5 ? $now + 900 : 0;
        $_SESSION['admin_login_throttle'][$key] = $attempt;
        return $attempt['blocked_until'] ? 900 : 0;
    }

    public function clear(string $identifier): void {
        unset($_SESSION['admin_login_throttle'][hash('sha256', $identifier)]);
    }

    public function purge(?int $now = null): void {}
}
