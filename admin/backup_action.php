<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_admin();
require_post();
require_csrf();
header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_POST['action'] ?? '');
$backupId = trim((string) ($_POST['backup_id'] ?? ''));
if (!in_array($action, ['backup', 'verify', 'restore'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Action invalide.']);
    exit;
}
if ($action !== 'backup' && !preg_match('/^\d{8}T\d{6}Z$/', $backupId)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Sauvegarde invalide.']);
    exit;
}

$unitState = trim((string) shell_exec('/usr/bin/systemctl is-active minesweeper-backup-admin.service 2>/dev/null'));
if (in_array($unitState, ['active', 'activating', 'reloading'], true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Une opération de sauvegarde est déjà en cours.']);
    exit;
}

try {
    $restoreCredentials = null;
    if ($action === 'restore') {
        if ((string) ($_POST['confirmation'] ?? '') !== 'RESTAURER') {
            throw new InvalidArgumentException('Saisissez RESTAURER pour confirmer.');
        }
        $pdo = (new Database())->getPDO();
        $stmt = $pdo->prepare('SELECT password_hash,totp_secret,totp_enabled_at FROM users WHERE id=:id AND is_admin=1');
        $stmt->execute(['id' => (int) $_SESSION['admin_user_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        $password = (string) ($_POST['password'] ?? '');
        $totpCode = trim((string) ($_POST['totp_code'] ?? ''));
        try {
            $secret = $admin && !empty($admin['totp_enabled_at']) ? decrypt_totp_secret((string) $admin['totp_secret']) : '';
        } catch (Throwable $e) {
            $secret = '';
        }
        if (!$admin || !password_verify($password, (string) $admin['password_hash']) || !verify_totp($secret, $totpCode)) {
            usleep(400000);
            error_log(sprintf('admin_audit action=restore_auth_failed admin=%s backup=%s', $_SESSION['admin_username'], $backupId));
            throw new InvalidArgumentException('Mot de passe ou code Authenticator incorrect.');
        }
        $restoreCredentials = ['password' => $password, 'totpCode' => $totpCode];
    }

    $nonce = bin2hex(random_bytes(16));
    $request = json_encode([
        'nonce' => $nonce,
        'action' => $action,
        'backupId' => $backupId ?: null,
        'requestedBy' => (string) $_SESSION['admin_username'],
        'createdAt' => time(),
        'credentials' => $restoreCredentials,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $requestPath = '/var/www/secure/backup-admin-request.json';
    umask(0077);
    $requestHandle = @fopen($requestPath, 'x');
    if (!$requestHandle) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Une demande de sauvegarde est déjà en attente.']);
        exit;
    }
    if (fwrite($requestHandle, $request . "\n") === false) {
        fclose($requestHandle); @unlink($requestPath);
        throw new RuntimeException('Écriture de la demande impossible.');
    }
    fflush($requestHandle); fclose($requestHandle); chmod($requestPath, 0600);
    unset($password, $totpCode, $restoreCredentials);

    $output = [];
    exec('/usr/bin/sudo -n /usr/bin/systemctl start --no-block minesweeper-backup-admin.service 2>&1', $output, $code);
    if ($code !== 0) {
        @unlink($requestPath);
        throw new RuntimeException('Le service de sauvegarde n’a pas pu être démarré.');
    }
    error_log(sprintf('admin_audit action=backup_%s admin=%s backup=%s nonce=%s', $action, $_SESSION['admin_username'], $backupId ?: 'new', $nonce));
    http_response_code(202);
    echo json_encode(['success' => true, 'nonce' => $nonce, 'message' => 'Opération démarrée.']);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('admin_audit action=backup_request_failed type=' . get_class($e));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Impossible de démarrer l’opération de sauvegarde.']);
}
