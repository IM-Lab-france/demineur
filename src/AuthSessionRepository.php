<?php
declare(strict_types=1);

final class AuthSessionRepository {
    public function __construct(private PDO $pdo) {}

    public function save(string $token, int $userId, int $expiresAt): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_sessions (token_hash, user_id, expires_at, last_used_at) '
            . 'VALUES (:hash, :user_id, FROM_UNIXTIME(:expires_at), CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at), last_used_at=CURRENT_TIMESTAMP'
        );
        $stmt->execute(['hash' => $this->hash($token), 'user_id' => $userId, 'expires_at' => $expiresAt]);
    }

    public function findValid(string $token): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.username, u.is_ai, u.is_disabled, u.email, u.email_verified_at, u.preferred_language, UNIX_TIMESTAMP(s.expires_at) AS expires_at '
            . 'FROM auth_sessions s JOIN users u ON u.id=s.user_id '
            . 'WHERE s.token_hash=:hash AND s.expires_at >= CURRENT_TIMESTAMP LIMIT 1'
        );
        $stmt->execute(['hash' => $this->hash($token)]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session || !empty($session['is_disabled']) || (!empty($session['email']) && empty($session['email_verified_at']))) return null;
        return ['id' => (int) $session['id'], 'username' => (string) $session['username'], 'is_ai' => (bool) $session['is_ai'], 'preferred_language' => $session['preferred_language'] ?: null, 'expires_at' => (int) $session['expires_at']];
    }

    public function delete(string $token): void {
        $stmt = $this->pdo->prepare('DELETE FROM auth_sessions WHERE token_hash=:hash');
        $stmt->execute(['hash' => $this->hash($token)]);
    }

    public function purgeExpired(): int {
        return $this->pdo->exec('DELETE FROM auth_sessions WHERE expires_at < CURRENT_TIMESTAMP');
    }

    private function hash(string $token): string {
        return hash('sha256', $token);
    }
}
