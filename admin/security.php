<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_admin(false);

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

$db = new Database();
$pdo = $db->getPDO();
$userId = (int) $_SESSION['admin_user_id'];
$username = (string) $_SESSION['admin_username'];
$message = '';
$error = '';
$qrDataUri = null;
$pendingSecret = null;
$columnsAvailable = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'totp_secret'")->fetch();

if ($columnsAvailable && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Requête expirée. Rechargez la page.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'begin') {
            $_SESSION['pending_totp'] = ['secret' => generate_totp_secret(), 'expires' => time() + 600];
        } elseif ($action === 'cancel') {
            unset($_SESSION['pending_totp']);
        } elseif ($action === 'confirm') {
            $pending = $_SESSION['pending_totp'] ?? null;
            $code = trim((string) ($_POST['totp_code'] ?? ''));
            if (!is_array($pending) || (int) ($pending['expires'] ?? 0) < time()) {
                unset($_SESSION['pending_totp']);
                $error = 'La configuration a expiré. Générez un nouveau QR Code.';
            } elseif (!verify_totp((string) $pending['secret'], $code)) {
                $error = 'Le code est incorrect. Vérifiez l’heure du téléphone et réessayez.';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET totp_secret=:secret, totp_enabled_at=CURRENT_TIMESTAMP WHERE id=:id AND is_admin=1');
                $stmt->execute(['secret' => $pending['secret'], 'id' => $userId]);
                unset($_SESSION['pending_totp']);
                $message = 'L’authentification TOTP est activée.';
            }
        } elseif ($action === 'disable') {
            $password = (string) ($_POST['password'] ?? '');
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id AND is_admin=1');
            $stmt->execute(['id' => $userId]);
            $hash = $stmt->fetchColumn();
            if (!is_string($hash) || !password_verify($password, $hash)) {
                $error = 'Mot de passe incorrect.';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET totp_secret=NULL, totp_enabled_at=NULL WHERE id=:id AND is_admin=1');
                $stmt->execute(['id' => $userId]);
                unset($_SESSION['pending_totp']);
                $message = 'L’authentification TOTP est désactivée.';
            }
        }
    }
}

$enabled = false;
if ($columnsAvailable) {
    $stmt = $pdo->prepare('SELECT totp_enabled_at FROM users WHERE id=:id AND is_admin=1');
    $stmt->execute(['id' => $userId]);
    $enabled = (bool) $stmt->fetchColumn();
}

$pending = $_SESSION['pending_totp'] ?? null;
if (!$enabled && is_array($pending) && (int) ($pending['expires'] ?? 0) >= time()) {
    $pendingSecret = (string) $pending['secret'];
    $uri = totp_provisioning_uri($username, $pendingSecret);
    $qrDataUri = (new Builder(writer: new PngWriter(), data: $uri, size: 280, margin: 12))->build()->getDataUri();
} elseif (isset($_SESSION['pending_totp'])) {
    unset($_SESSION['pending_totp']);
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sécurité de l’administration</title><link rel="icon" type="image/png" sizes="64x64" href="/favicon-64.png"><link rel="stylesheet" href="/assets/vendor/bootstrap/5.3.3/bootstrap.min.css"><link rel="stylesheet" href="/admin/admin.css?v=<?= (int) filemtime(__DIR__ . '/admin.css') ?>"></head>
<body><main class="container py-5 admin-security-container">
<a href="/admin/" class="btn btn-sm btn-outline-secondary mb-4">← Administration</a>
<h1 class="h3">Authentification à deux facteurs</h1>
<p class="text-muted">Protégez le compte <strong><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></strong> avec une application Authenticator.</p>
<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if (!$columnsAvailable): ?>
<div class="alert alert-warning">La migration TOTP n’est pas encore appliquée. Lancez <code>sudo scripts/finalize-upgrade.sh</code>.</div>
<?php elseif ($enabled): ?>
<div class="alert alert-success">Le TOTP est activé pour ce compte.</div>
<form method="post" class="card card-body"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="disable"><label class="form-label" for="password">Mot de passe administrateur pour désactiver le TOTP</label><input class="form-control mb-3" id="password" name="password" type="password" autocomplete="current-password" required><button class="btn btn-danger">Désactiver le TOTP</button></form>
<?php elseif ($qrDataUri): ?>
<div class="card card-body text-center">
<p>Scannez ce QR Code avec votre application Authenticator.</p>
<img class="totp-qr mx-auto" src="<?= htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="QR Code de configuration TOTP" width="280" height="280">
<p class="mt-3 mb-1">Saisie manuelle :</p><code class="totp-secret"><?= htmlspecialchars($pendingSecret, ENT_QUOTES, 'UTF-8') ?></code>
<form method="post" class="mt-4"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="confirm"><label class="form-label" for="totp_code">Code à six chiffres affiché dans l’application</label><input class="form-control text-center mx-auto totp-code" id="totp_code" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required autofocus><button class="btn btn-primary mt-3">Vérifier et activer</button></form>
<form method="post" class="mt-2"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-link text-muted">Annuler</button></form>
</div>
<?php else: ?>
<div class="alert alert-secondary">Le TOTP n’est pas activé.</div><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="begin"><button class="btn btn-primary">Afficher le QR Code d’activation</button></form>
<?php endif; ?>
</main></body></html>
