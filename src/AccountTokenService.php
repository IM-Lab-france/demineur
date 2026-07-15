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
            if ($purpose === 'reset_password') {
                $delete = $this->pdo->prepare('DELETE FROM account_tokens WHERE user_id=:user_id AND purpose=:purpose');
                $delete->execute(['user_id' => $userId, 'purpose' => $purpose]);
            } else {
                $delete = $this->pdo->prepare('DELETE FROM account_tokens WHERE user_id=:user_id AND purpose=:purpose AND expires_at<CURRENT_TIMESTAMP');
                $delete->execute(['user_id' => $userId, 'purpose' => $purpose]);
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO account_tokens(token_hash,user_id,purpose,expires_at) '
                . 'VALUES(:hash,:user_id,:purpose,FROM_UNIXTIME(:expires_at))'
            );
            $insert->execute(['hash' => hash('sha256', $token), 'user_id' => $userId, 'purpose' => $purpose, 'expires_at' => time() + $lifetime]);
            if ($ownsTransaction) $this->pdo->commit();
            error_log(sprintf('account_token_issued purpose=%s user=%d ref=%s', $purpose, $userId, substr(hash('sha256', $token), 0, 12)));
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
        if (!$row) {
            $diagnostic = $this->pdo->prepare(
                'SELECT purpose,used_at IS NOT NULL AS is_used,expires_at<CURRENT_TIMESTAMP AS is_expired '
                . 'FROM account_tokens WHERE token_hash=:hash LIMIT 1'
            );
            $diagnostic->execute(['hash' => hash('sha256', $token)]);
            $state = $diagnostic->fetch(PDO::FETCH_ASSOC);
            $reason = !$state ? 'absent' : ((string) $state['purpose'] !== $purpose ? 'wrong_purpose' : ((int) $state['is_used'] === 1 ? 'used' : ((int) $state['is_expired'] === 1 ? 'expired' : 'unknown')));
            error_log('account_token_lookup_miss purpose=' . $purpose . ' reason=' . $reason . ' ref=' . substr(hash('sha256', $token), 0, 12));
        }
        return $row ?: null;
    }

    public function verifyEmail(string $token): bool {
        $account = $this->find($token, 'verify_email');
        if (!$account) {
            if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
            $verified = $this->pdo->prepare(
                "SELECT 1 FROM account_tokens t JOIN users u ON u.id=t.user_id "
                . "WHERE t.token_hash=:hash AND t.purpose='verify_email' AND t.used_at IS NOT NULL "
                . "AND u.email_verified_at IS NOT NULL LIMIT 1"
            );
            $verified->execute(['hash' => hash('sha256', $token)]);
            return (bool) $verified->fetchColumn();
        }
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
            $invalidate = $this->pdo->prepare("UPDATE account_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=:id AND purpose='verify_email' AND used_at IS NULL");
            $invalidate->execute(['id' => $account['id']]);
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
