<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/AdminLoginThrottle.php';
require_once __DIR__ . '/AccountTokenService.php';
require_once __DIR__ . '/MailService.php';
require_once __DIR__ . '/MailQueueRepository.php';

function start_public_account_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // Lax permet de conserver le résultat après une arrivée depuis le lien de
    // suivi d'un fournisseur d'e-mail, tout en bloquant les requêtes POST tierces.
    session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}

function public_account_csrf(): string {
    if (empty($_SESSION['public_account_csrf'])) $_SESSION['public_account_csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['public_account_csrf'];
}

function public_account_valid_csrf(): bool {
    return hash_equals(public_account_csrf(), (string) ($_POST['csrf_token'] ?? ''));
}

function public_account_throttle(PDO $pdo, string $purpose, string $subject): bool {
    $table = $pdo->query("SHOW TABLES LIKE 'admin_login_attempts'")->fetchColumn();
    $throttle = $table ? new AdminLoginThrottle($pdo) : new SessionAdminLoginThrottle();
    $identifier = 'public-account:' . $purpose . ':' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . hash('sha256', mb_strtolower($subject, 'UTF-8'));
    if ($throttle->retryAfter($identifier) > 0) return false;
    $throttle->recordFailure($identifier);
    return true;
}

function public_account_page(string $title, string $body): never {
    header('Cache-Control: no-store, private');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</title><link rel="icon" type="image/png" sizes="64x64" href="/favicon-64.png"><link rel="stylesheet" href="/assets/vendor/bootstrap/5.3.3/bootstrap.min.css"><link rel="stylesheet" href="/admin/admin.css"></head><body class="bg-light"><main class="container py-5 admin-login-container">'
        . $body . '</main></body></html>';
    exit;
}
