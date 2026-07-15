<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../src/MailService.php';
require __DIR__ . '/../src/MailQueueRepository.php';

$pdo = (new Database())->getPDO();
$queue = new MailQueueRepository($pdo);
try {
    $mailer = new MailService();
} catch (RuntimeException $e) {
    fwrite(STDOUT, "SMTP non configuré; file e-mail laissée en attente.\n");
    exit(0);
}
$sent = 0;
$failed = 0;
foreach ($queue->pending() as $row) {
    try {
        $payload = $queue->decode($row);
        $tokenReference = !empty($payload['token']) ? substr(hash('sha256', (string) $payload['token']), 0, 12) : 'none';
        fwrite(STDOUT, sprintf("Traitement du message %d (modèle %s, jeton %s).\n", (int) $row['id'], (string) $payload['template'], $tokenReference));
        match ($payload['template']) {
            'verify_email' => $mailer->sendVerification($payload['recipient'], $payload['username'], (string) $payload['token']),
            'reset_password' => $mailer->sendPasswordReset($payload['recipient'], $payload['username'], (string) $payload['token']),
            'password_changed' => $mailer->sendPasswordChanged($payload['recipient'], $payload['username']),
            default => throw new RuntimeException('Modèle e-mail inconnu.'),
        };
        $queue->markSent((int) $row['id']);
        $sent++;
    } catch (Throwable $e) {
        $error = preg_replace('#(smtps?://)[^\s/@:]+:[^\s/@]+@#i', '$1[identifiants-masques]@', $e->getMessage());
        $error = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $error));
        $diagnostic = substr(get_class($e) . ($error !== '' ? ': ' . $error : ''), 0, 190);
        $queue->markFailed((int) $row['id'], (int) $row['attempts'], $diagnostic);
        fwrite(STDERR, sprintf("Échec SMTP pour le message %d (tentative %d) : %s\n", (int) $row['id'], (int) $row['attempts'] + 1, $diagnostic));
        $failed++;
    }
}
if (random_int(1, 20) === 1) $queue->purge();
echo sprintf("%d e-mail(s) envoyé(s), %d en échec temporaire.\n", $sent, $failed);
