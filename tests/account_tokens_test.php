<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../src/AccountTokenService.php';
require __DIR__ . '/../src/AuthSessionRepository.php';
require __DIR__ . '/../src/MailService.php';
require __DIR__ . '/../src/MailQueueRepository.php';

function assert_account(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$pdo = (new Database())->getPDO();
$username = 'mail_test_' . bin2hex(random_bytes(5));
$email = $username . '@example.test';
$stmt = $pdo->prepare('INSERT INTO users(username,email,password_hash) VALUES(:username,:email,:hash)');
$stmt->execute(['username' => $username, 'email' => $email, 'hash' => password_hash('Initial-Password!2026', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();
$tokens = new AccountTokenService($pdo);
try {
    $verification = $tokens->issue($userId, 'verify_email', 3600);
    $secondVerification = $tokens->issue($userId, 'verify_email', 3600);
    assert_account(strlen($verification) === 64, 'Le jeton brut doit avoir 256 bits.');
    $stored = $pdo->query('SELECT token_hash FROM account_tokens WHERE user_id=' . $userId)->fetchColumn();
    assert_account($stored === hash('sha256', $verification) && $stored !== $verification, 'Seul le hash du jeton doit être stocké.');
    assert_account($tokens->verifyEmail($verification), 'Le jeton doit valider l’adresse.');
    assert_account($tokens->verifyEmail($verification), 'Une seconde ouverture doit confirmer une validation déjà effectuée sans la rejouer.');
    assert_account($tokens->verifyEmail($secondVerification), 'Les autres liens du compte doivent afficher que la validation est déjà effectuée.');

    $sessionToken = bin2hex(random_bytes(32));
    (new AuthSessionRepository($pdo))->save($sessionToken, $userId, time() + 3600);
    $reset = $tokens->issue($userId, 'reset_password', 1800);
    $account = $tokens->resetPassword($reset, password_hash('Changed-Password!2026', PASSWORD_DEFAULT));
    assert_account((int) $account['id'] === $userId, 'Le mot de passe doit être modifiable avec un jeton valide.');
    assert_account((new AuthSessionRepository($pdo))->findValid($sessionToken) === null, 'La réinitialisation doit révoquer les sessions persistantes.');
    assert_account($tokens->resetPassword($reset, password_hash('Another-Password!2026', PASSWORD_DEFAULT)) === null, 'Le jeton de récupération doit être à usage unique.');

    $_ENV['MAILER_DSN'] = 'null://null';
    $_ENV['MAIL_FROM_ADDRESS'] = 'no-reply@example.test';
    $_ENV['MAIL_FROM_NAME'] = 'Démineur Test';
    $_ENV['APP_PUBLIC_URL'] = 'https://example.test';
    foreach (['MAILER_DSN', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'APP_PUBLIC_URL'] as $name) putenv($name . '=' . $_ENV[$name]);
    $mailer = new MailService();
    $mailer->sendVerification($email, $username, str_repeat('a', 64));
    $mailer->sendPasswordReset($email, $username, str_repeat('b', 64));
    $queue = new MailQueueRepository($pdo);
    $queue->enqueue($email, 'verify_email', $username, str_repeat('c', 64));
    $queued = $pdo->query('SELECT id,payload_encrypted,attempts FROM email_outbox ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    assert_account(!str_contains((string) $queued['payload_encrypted'], $email) && !str_contains((string) $queued['payload_encrypted'], str_repeat('c', 64)), 'Le contenu de la file doit être chiffré.');
    assert_account($queue->decode($queued)['recipient'] === $email, 'Le worker doit pouvoir relire le message chiffré.');
    $queue->markSent((int) $queued['id']);
    $queue->enqueue($email, 'password_changed', $username);
} finally {
    $pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id' => $userId]);
}
echo "Tests des comptes par e-mail réussis.\n";
