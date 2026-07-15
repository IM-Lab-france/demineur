<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../src/AdminLoginThrottle.php';

$forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
$secureRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secureRequest,
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}

function admin_is_authenticated(): bool {
    if (!isset($_SESSION['admin_user_id'], $_SESSION['admin_username'], $_SESSION['admin_last_activity'])) return false;
    if (time() - (int) $_SESSION['admin_last_activity'] > 1800) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['admin_last_activity'] = time();
    return true;
}

function require_admin(bool $json = true): void {
    if (admin_is_authenticated()) return;
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Authentification administrateur requise.']);
    } else {
        header('Location: /admin/login.php');
    }
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void {
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Jeton CSRF invalide.']);
        exit;
    }
}

function require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Méthode non autorisée.']);
        exit;
    }
}

function verify_totp(string $secret, string $code, ?int $timestamp = null): bool {
    $normalized = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
    if ($normalized === '' || !preg_match('/^\d{6}$/', $code)) return false;
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($normalized) as $character) {
        $position = strpos($alphabet, $character);
        if ($position === false) return false;
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $key .= chr(bindec($byte));
    }
    $counter = intdiv($timestamp ?? time(), 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        $value = $counter + $offset;
        $binaryCounter = pack('N2', ($value >> 32) & 0xffffffff, $value & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $index = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$index]) & 0x7f) << 24)
            | ((ord($hash[$index + 1]) & 0xff) << 16)
            | ((ord($hash[$index + 2]) & 0xff) << 8)
            | (ord($hash[$index + 3]) & 0xff);
        if (hash_equals(str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT), $code)) return true;
    }
    return false;
}

function generate_totp_secret(int $bytes = 20): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $data = random_bytes($bytes);
    $bits = '';
    foreach (str_split($data) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    $secret = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $secret .= $alphabet[bindec($chunk)];
    }
    return $secret;
}

function totp_provisioning_uri(string $username, string $secret): string {
    $label = rawurlencode('Démineur:' . $username);
    return "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode('Démineur') . '&algorithm=SHA1&digits=6&period=30';
}

function totp_encryption_key(): string {
    $encoded = (string) ($_ENV['APP_TOTP_KEY'] ?? getenv('APP_TOTP_KEY') ?: '');
    $key = base64_decode($encoded, true);
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('Clé de chiffrement TOTP absente ou invalide.');
    return $key;
}

function encrypt_totp_secret(string $secret): string {
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', totp_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext)) throw new RuntimeException('Chiffrement du secret TOTP impossible.');
    return 'v1:' . base64_encode($nonce . $tag . $ciphertext);
}

function decrypt_totp_secret(string $stored): string {
    if (preg_match('/^[A-Z2-7]{16,64}$/', $stored)) return $stored; // migration de l’ancien format
    if (!str_starts_with($stored, 'v1:')) return '';
    $payload = base64_decode(substr($stored, 3), true);
    if (!is_string($payload) || strlen($payload) < 29) return '';
    $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', totp_encryption_key(), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
    return is_string($plain) ? $plain : '';
}

function generate_totp_recovery_codes(int $count = 10): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(6)));
        $codes[] = implode('-', str_split($raw, 4));
    }
    return $codes;
}

function normalize_recovery_code(string $code): string {
    return strtoupper(preg_replace('/[^A-F0-9]/i', '', $code));
}

function validated_ia_name(): string {
    $name = trim((string) ($_POST['iaName'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Nom d’IA invalide.']);
        exit;
    }
    return $name;
}

function validated_ia_path(string $name): string {
    $base = realpath(__DIR__ . '/../ia/deminium/plugins');
    $path = realpath(__DIR__ . '/../ia/deminium/plugins/' . $name);
    if ($base === false || $path === false || !is_dir($path) || !str_starts_with($path . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'IA introuvable.']);
        exit;
    }
    return $path;
}

function ia_accounts_file(): string {
    $directory = getenv('APP_CONFIG_DIR') ?: '/var/www/secure';
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Répertoire sécurisé des comptes IA indisponible.');
    }
    return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ia_accounts.json';
}

function read_ia_accounts(): array {
    $file = ia_accounts_file();
    if (!is_file($file)) return [];
    $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
}

function write_ia_accounts(array $accounts): void {
    $file = ia_accounts_file();
    $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
    file_put_contents($temp, json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    // Le processus Apache lance aussi les IA. Le fichier doit donc rester
    // lisible par le groupe de service commun, sans devenir public.
    @chmod($temp, 0640);
    if (!rename($temp, $file)) {
        @unlink($temp);
        throw new RuntimeException('Écriture atomique des comptes IA impossible.');
    }
}
