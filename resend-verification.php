<?php
declare(strict_types=1);
require __DIR__ . '/src/PublicAccountSecurity.php';
start_public_account_session();

$sent = false;
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!public_account_valid_csrf()) {
        $error = 'Requête expirée. Rechargez la page.';
    } else {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')), 'UTF-8');
        try {
            $pdo = (new Database())->getPDO();
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && public_account_throttle($pdo, 'verify', $email)) {
                $stmt = $pdo->prepare('SELECT id,username,email FROM users WHERE email=:email AND email_verified_at IS NULL AND is_disabled=0 LIMIT 1');
                $stmt->execute(['email' => $email]);
                $account = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($account) {
                    new MailService();
                    $token = (new AccountTokenService($pdo))->issue((int) $account['id'], 'verify_email', 86400);
                    (new MailQueueRepository($pdo))->enqueue((string) $account['email'], 'verify_email', (string) $account['username'], $token);
                }
            }
        } catch (Throwable $e) {
            error_log('Renvoi de validation impossible: ' . get_class($e));
        }
        $sent = true;
    }
}
$content = '<h1 class="h3 mb-4">Renvoyer l’e-mail de validation</h1>';
if ($sent) $content .= '<div class="alert alert-success">Si cette adresse correspond à un compte non validé, un nouvel e-mail vient d’être envoyé.</div>';
if ($error) $content .= '<div class="alert alert-danger">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
$content .= '<form method="post"><input type="hidden" name="csrf_token" value="' . htmlspecialchars(public_account_csrf(), ENT_QUOTES, 'UTF-8') . '"><label class="form-label" for="email">Adresse e-mail</label><input class="form-control mb-3" id="email" name="email" type="email" autocomplete="email" maxlength="254" required><button class="btn btn-primary w-100">Renvoyer l’e-mail</button></form><a class="btn btn-link mt-3" href="/">Retour à la connexion</a>';
public_account_page('Renvoi de validation', $content);
