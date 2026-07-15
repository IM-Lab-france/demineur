<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store, private');

$error = '';
if (admin_is_authenticated()) {
    header('Location: /admin/');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $secondFactor = trim((string) ($_POST['totp_code'] ?? ''));
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Requête expirée. Rechargez la page.';
    } else {
        $db = new Database();
        $pdo = $db->getPDO();
        $normalizedUsername = mb_strtolower($username, 'UTF-8');
        $identifiers = [
            'account:' . $normalizedUsername,
            'address-account:' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\0" . $normalizedUsername,
        ];
        $throttleTable = $pdo->query("SHOW TABLES LIKE 'admin_login_attempts'")->fetchColumn();
        $throttle = $throttleTable ? new AdminLoginThrottle($pdo) : new SessionAdminLoginThrottle();
        $retryAfter = max(array_map(static fn(string $identifier): int => $throttle->retryAfter($identifier), $identifiers));
        if ($retryAfter > 0) {
            $error = 'Trop de tentatives. Réessayez dans ' . max(1, (int) ceil($retryAfter / 60)) . ' minute(s).';
        } else {
            $totpColumns = $pdo->query("SHOW COLUMNS FROM users LIKE 'totp_secret'")->fetchAll();
            $recoveryColumns = $pdo->query("SHOW COLUMNS FROM users LIKE 'totp_recovery_codes'")->fetchAll();
            $totpFields = $totpColumns
                ? ', totp_secret, totp_enabled_at' . ($recoveryColumns ? ', totp_recovery_codes' : ', NULL AS totp_recovery_codes')
                : ", NULL AS totp_secret, NULL AS totp_enabled_at, NULL AS totp_recovery_codes";
            $stmt = $pdo->prepare('SELECT id, username, password_hash' . $totpFields . ' FROM users WHERE username=:username AND is_admin=1');
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();
            $passwordHash = $user ? (string) $user['password_hash'] : '$2y$10$YEo0gYzOaplASHTLobS39evL3TtEQq3ZdtSMep1qPd2AUCxEqcpOu';
            $passwordValid = password_verify($password, $passwordHash) && (bool) $user;
            $legacySecret = $totpColumns ? '' : (string) ($_ENV['ADMIN_TOTP_SECRET'] ?? getenv('ADMIN_TOTP_SECRET') ?: '');
            $storedSecret = $user && !empty($user['totp_enabled_at']) ? (string) $user['totp_secret'] : $legacySecret;
            try {
                $totpSecret = $storedSecret === '' ? '' : decrypt_totp_secret($storedSecret);
            } catch (Throwable $e) {
                $totpSecret = null;
            }

            $secondFactorValid = $storedSecret === '';
            $remainingRecoveryHashes = null;
            if ($passwordValid && is_string($totpSecret) && $totpSecret !== '') {
                $secondFactorValid = verify_totp($totpSecret, $secondFactor);
            }
            if ($passwordValid && !$secondFactorValid && $storedSecret !== '' && $user) {
                $recoveryHashes = json_decode((string) ($user['totp_recovery_codes'] ?? ''), true);
                $normalizedCode = normalize_recovery_code($secondFactor);
                if (is_array($recoveryHashes) && strlen($normalizedCode) === 12) {
                    foreach ($recoveryHashes as $index => $recoveryHash) {
                        if (is_string($recoveryHash) && password_verify($normalizedCode, $recoveryHash)) {
                            unset($recoveryHashes[$index]);
                            $remainingRecoveryHashes = array_values($recoveryHashes);
                            $secondFactorValid = true;
                            break;
                        }
                    }
                }
            }

            if ($passwordValid && $secondFactorValid) {
                if ($remainingRecoveryHashes !== null) {
                    $consume = $pdo->prepare('UPDATE users SET totp_recovery_codes=:codes WHERE id=:id');
                    $consume->execute(['codes' => json_encode($remainingRecoveryHashes, JSON_THROW_ON_ERROR), 'id' => $user['id']]);
                }
                if ($totpColumns && preg_match('/^[A-Z2-7]{16,64}$/', $storedSecret)) {
                    $migrate = $pdo->prepare('UPDATE users SET totp_secret=:secret WHERE id=:id');
                    $migrate->execute(['secret' => encrypt_totp_secret($storedSecret), 'id' => $user['id']]);
                }
                foreach ($identifiers as $identifier) $throttle->clear($identifier);
                if (random_int(1, 100) === 1) $throttle->purge();
                session_regenerate_id(true);
                $_SESSION['admin_user_id'] = (int) $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_last_activity'] = time();
                unset($_SESSION['csrf_token']);
                header('Location: /admin/');
                exit;
            }

            foreach ($identifiers as $identifier) $throttle->recordFailure($identifier);
            usleep(350000);
            $error = 'Identifiants incorrects.';
        }
    }
}
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administration</title><link rel="icon" type="image/png" sizes="64x64" href="/favicon-64.png"><link rel="stylesheet" href="/assets/vendor/bootstrap/5.3.3/bootstrap.min.css"><link rel="stylesheet" href="/admin/admin.css"></head>
<body class="bg-light"><main class="container py-5 admin-login-container"><h1 class="h3 mb-4">Administration</h1><?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><div class="mb-3"><label class="form-label">Utilisateur</label><input class="form-control" name="username" autocomplete="username" required></div><div class="mb-3"><label class="form-label">Mot de passe</label><input class="form-control" type="password" name="password" autocomplete="current-password" required></div><div class="mb-3"><label class="form-label">Code Authenticator ou code de récupération <span class="text-muted">(si MFA activé)</span></label><input class="form-control" name="totp_code" autocomplete="one-time-code"></div><button class="btn btn-primary w-100">Connexion</button></form></main></body></html>
