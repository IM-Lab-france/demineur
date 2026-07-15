<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$error = '';
if (admin_is_authenticated()) {
    header('Location: /admin/');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $totpCode = trim((string) ($_POST['totp_code'] ?? ''));
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Requête expirée. Rechargez la page.';
    } else {
        $attempt = $_SESSION['admin_login_attempt'] ?? ['count' => 0, 'since' => time()];
        if (time() - (int) $attempt['since'] > 900) $attempt = ['count' => 0, 'since' => time()];
        if ((int) $attempt['count'] >= 5) {
            $error = 'Trop de tentatives. Réessayez dans 15 minutes.';
        } else {
        $db = new Database();
        $totpColumns = $db->getPDO()->query("SHOW COLUMNS FROM users LIKE 'totp_secret'")->fetchAll();
        $totpFields = $totpColumns ? ', totp_secret, totp_enabled_at' : ", NULL AS totp_secret, NULL AS totp_enabled_at";
        $stmt = $db->getPDO()->prepare('SELECT id, username, password_hash' . $totpFields . ' FROM users WHERE username = :username AND is_admin = 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        $legacyTotpSecret = $totpColumns ? '' : (string) ($_ENV['ADMIN_TOTP_SECRET'] ?? getenv('ADMIN_TOTP_SECRET') ?: '');
        $totpSecret = $user && !empty($user['totp_enabled_at']) ? (string) $user['totp_secret'] : $legacyTotpSecret;
        if ($user && password_verify($password, $user['password_hash'])
            && ($totpSecret === '' || verify_totp($totpSecret, $totpCode))) {
            session_regenerate_id(true);
            $_SESSION['admin_user_id'] = (int) $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_last_activity'] = time();
            unset($_SESSION['admin_login_attempt']);
            unset($_SESSION['csrf_token']);
            header('Location: /admin/');
            exit;
        }
        $attempt['count'] = (int) $attempt['count'] + 1;
        $_SESSION['admin_login_attempt'] = $attempt;
        usleep(min(2000000, 250000 * (2 ** min(3, $attempt['count'] - 1))));
        $error = 'Identifiants incorrects.';
        }
    }
}
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administration</title><link rel="icon" type="image/png" sizes="64x64" href="/favicon-64.png"><link rel="stylesheet" href="/assets/vendor/bootstrap/5.3.3/bootstrap.min.css"><link rel="stylesheet" href="/admin/admin.css"></head>
<body class="bg-light"><main class="container py-5 admin-login-container"><h1 class="h3 mb-4">Administration</h1><?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><div class="mb-3"><label class="form-label">Utilisateur</label><input class="form-control" name="username" autocomplete="username" required></div><div class="mb-3"><label class="form-label">Mot de passe</label><input class="form-control" type="password" name="password" autocomplete="current-password" required></div><div class="mb-3"><label class="form-label">Code à six chiffres <span class="text-muted">(si MFA activé)</span></label><input class="form-control" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"></div><button class="btn btn-primary w-100">Connexion</button></form></main></body></html>
