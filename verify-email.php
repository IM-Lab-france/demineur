<?php
declare(strict_types=1);
require __DIR__ . '/src/PublicAccountSecurity.php';
start_public_account_session();

$token = (string) ($_GET['token'] ?? '');
if ($token !== '') {
    try {
        $_SESSION['email_verification_result'] = (new AccountTokenService((new Database())->getPDO()))->verifyEmail($token);
    } catch (Throwable $e) {
        $_SESSION['email_verification_result'] = false;
        error_log('Validation e-mail impossible: ' . get_class($e));
    }
    header('Location: /verify-email.php');
    exit;
}
$valid = (bool) ($_SESSION['email_verification_result'] ?? false);
unset($_SESSION['email_verification_result']);
$message = $valid
    ? '<div class="alert alert-success">Votre adresse est validée. Vous pouvez maintenant vous connecter.</div>'
    : '<div class="alert alert-danger">Ce lien est invalide, expiré ou déjà utilisé.</div>';
public_account_page('Validation de l’adresse', '<h1 class="h3 mb-4">Validation de l’adresse e-mail</h1>' . $message . '<a class="btn btn-primary" href="/">Retour au jeu</a>');
