<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';

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
