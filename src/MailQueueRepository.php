<?php
declare(strict_types=1);

final class MailQueueRepository {
    public function __construct(private PDO $pdo) {}

    public function enqueue(string $recipient, string $template, string $username, ?string $token = null): void {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !in_array($template, ['verify_email', 'reset_password', 'password_changed'], true)) {
            throw new InvalidArgumentException('Message e-mail invalide.');
        }
        $payload = json_encode(['recipient' => $recipient, 'template' => $template, 'username' => $username, 'token' => $token], JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare('INSERT INTO email_outbox(payload_encrypted,next_attempt_at) VALUES(:payload,CURRENT_TIMESTAMP)');
        $stmt->execute(['payload' => $this->encrypt($payload)]);
    }

    public function pending(int $limit = 20): array {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query(
            'SELECT id,payload_encrypted,attempts FROM email_outbox WHERE sent_at IS NULL AND next_attempt_at<=CURRENT_TIMESTAMP '
            . 'AND attempts<10 ORDER BY id LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function decode(array $row): array {
        $json = $this->decrypt((string) $row['payload_encrypted']);
        $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || empty($payload['recipient']) || empty($payload['template']) || empty($payload['username'])) {
            throw new RuntimeException('Charge e-mail invalide.');
        }
        return $payload;
    }

    public function markSent(int $id): void {
        $stmt = $this->pdo->prepare('UPDATE email_outbox SET sent_at=CURRENT_TIMESTAMP,last_error=NULL WHERE id=:id AND sent_at IS NULL');
        $stmt->execute(['id' => $id]);
    }

    public function markFailed(int $id, int $attempts, string $errorClass): void {
        $delay = min(3600, 30 * (2 ** min(7, $attempts)));
        $stmt = $this->pdo->prepare('UPDATE email_outbox SET attempts=attempts+1,last_error=:error,next_attempt_at=FROM_UNIXTIME(:next) WHERE id=:id AND sent_at IS NULL');
        $stmt->execute(['error' => substr($errorClass, 0, 190), 'next' => time() + $delay, 'id' => $id]);
    }

    public function purge(): int {
        return $this->pdo->exec('DELETE FROM email_outbox WHERE sent_at<CURRENT_TIMESTAMP - INTERVAL 7 DAY');
    }

    private function key(): string {
        $encoded = (string) ($_ENV['APP_TOTP_KEY'] ?? getenv('APP_TOTP_KEY') ?: '');
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('Clé de chiffrement de la file e-mail invalide.');
        return $key;
    }

    private function encrypt(string $plain): string {
        $nonce = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($cipher)) throw new RuntimeException('Chiffrement de l’e-mail impossible.');
        return 'v1:' . base64_encode($nonce . $tag . $cipher);
    }

    private function decrypt(string $stored): string {
        if (!str_starts_with($stored, 'v1:')) throw new RuntimeException('Format d’e-mail chiffré inconnu.');
        $payload = base64_decode(substr($stored, 3), true);
        if (!is_string($payload) || strlen($payload) < 29) throw new RuntimeException('E-mail chiffré invalide.');
        $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
        if (!is_string($plain)) throw new RuntimeException('Déchiffrement de l’e-mail impossible.');
        return $plain;
    }
}
