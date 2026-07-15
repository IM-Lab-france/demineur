<?php
declare(strict_types=1);
require __DIR__ . '/src/PublicAccountSecurity.php';
start_public_account_session();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$error = '';
$success = false;
$pdo = (new Database())->getPDO();
$tokens = new AccountTokenService($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (!public_account_valid_csrf()) $error = 'Requête expirée. Rechargez la page.';
    elseif (strlen($password) < 10 || strlen($password) > 128) $error = 'Le mot de passe doit contenir entre 10 et 128 caractères.';
    elseif (!hash_equals($password, $confirmation)) $error = 'Les mots de passe ne correspondent pas.';
    else {
        $account = $tokens->resetPassword($token, password_hash($password, PASSWORD_DEFAULT));
        if (!$account) $error = 'Ce lien est invalide, expiré ou déjà utilisé.';
        else {
            $success = true;
            try { (new MailQueueRepository($pdo))->enqueue((string) $account['email'], 'password_changed', (string) $account['username']); }
            catch (Throwable $e) { error_log('Confirmation de mot de passe non envoyée: ' . get_class($e)); }
        }
    }
}
$valid = !$success && $tokens->find($token, 'reset_password');
$content = '<h1 class="h3 mb-4">Nouveau mot de passe</h1>';
if ($success) $content .= '<div class="alert alert-success">Votre mot de passe a été modifié et vos sessions ont été révoquées.</div><a class="btn btn-primary" href="/">Se connecter</a>';
elseif (!$valid) $content .= '<div class="alert alert-danger">Ce lien est invalide, expiré ou déjà utilisé.</div><a class="btn btn-primary" href="/forgot-password.php">Demander un nouveau lien</a>';
else {
    if ($error) $content .= '<div class="alert alert-danger">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
    $content .= '<form method="post" action="/reset-password.php"><input type="hidden" name="csrf_token" value="' . htmlspecialchars(public_account_csrf(), ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"><label class="form-label" for="password">Nouveau mot de passe</label><input class="form-control mb-3" id="password" name="password" type="password" minlength="10" maxlength="128" autocomplete="new-password" required><label class="form-label" for="password_confirmation">Confirmation</label><input class="form-control mb-3" id="password_confirmation" name="password_confirmation" type="password" minlength="10" maxlength="128" autocomplete="new-password" required><button class="btn btn-primary w-100">Modifier le mot de passe</button></form>';
}
public_account_page('Nouveau mot de passe', $content);
