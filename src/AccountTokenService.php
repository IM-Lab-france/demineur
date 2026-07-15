<?php
declare(strict_types=1);

final class AccountTokenService {
    public function __construct(private PDO $pdo) {}

    public function issue(int $userId, string $purpose, int $lifetime): string {
        if (!in_array($purpose, ['verify_email', 'reset_password'], true)) throw new InvalidArgumentException('Type de jeton invalide.');
        $token = bin2hex(random_bytes(32));
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM account_tokens WHERE user_id=:user_id AND purpose=:purpose');
            $delete->execute(['user_id' => $userId, 'purpose' => $purpose]);
            $insert = $this->pdo->prepare(
                'INSERT INTO account_tokens(token_hash,user_id,purpose,expires_at) '
                . 'VALUES(:hash,:user_id,:purpose,FROM_UNIXTIME(:expires_at))'
            );
            $insert->execute(['hash' => hash('sha256', $token), 'user_id' => $userId, 'purpose' => $purpose, 'expires_at' => time() + $lifetime]);
            if ($ownsTransaction) $this->pdo->commit();
            if (random_int(1, 100) === 1) $this->purgeExpired();
            return $token;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function find(string $token, string $purpose): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $stmt = $this->pdo->prepare(
            'SELECT t.id AS token_id,u.id,u.username,u.email FROM account_tokens t '
            . 'JOIN users u ON u.id=t.user_id WHERE t.token_hash=:hash AND t.purpose=:purpose '
            . 'AND t.used_at IS NULL AND t.expires_at>=CURRENT_TIMESTAMP LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token), 'purpose' => $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function verifyEmail(string $token): bool {
        $account = $this->find($token, 'verify_email');
        if (!$account) return false;
        $this->pdo->beginTransaction();
        try {
            $consume = $this->pdo->prepare('UPDATE account_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=:id AND used_at IS NULL');
            $consume->execute(['id' => $account['token_id']]);
            if ($consume->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }
            $update = $this->pdo->prepare('UPDATE users SET email_verified_at=CURRENT_TIMESTAMP WHERE id=:id');
            $update->execute(['id' => $account['id']]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function resetPassword(string $token, string $passwordHash): ?array {
        $account = $this->find($token, 'reset_password');
        if (!$account) return null;
        $this->pdo->beginTransaction();
        try {
            $consume = $this->pdo->prepare('UPDATE account_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=:id AND used_at IS NULL');
            $consume->execute(['id' => $account['token_id']]);
            if ($consume->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }
            $update = $this->pdo->prepare('UPDATE users SET password_hash=:hash WHERE id=:id');
            $update->execute(['hash' => $passwordHash, 'id' => $account['id']]);
            $this->pdo->prepare('DELETE FROM auth_sessions WHERE user_id=:id')->execute(['id' => $account['id']]);
            $this->pdo->prepare("DELETE FROM account_tokens WHERE user_id=:id AND purpose='reset_password' AND id<>:token_id")->execute(['id' => $account['id'], 'token_id' => $account['token_id']]);
            $this->pdo->commit();
            return $account;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function purgeExpired(): int {
        return $this->pdo->exec('DELETE FROM account_tokens WHERE expires_at<CURRENT_TIMESTAMP OR used_at<CURRENT_TIMESTAMP - INTERVAL 1 DAY');
    }
}
