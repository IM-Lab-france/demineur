<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../src/MailService.php';
require __DIR__ . '/../src/MailQueueRepository.php';

$pdo = (new Database())->getPDO();
$queue = new MailQueueRepository($pdo);
$mailer = new MailService();
$sent = 0;
foreach ($queue->pending() as $row) {
    try {
        $payload = $queue->decode($row);
        match ($payload['template']) {
            'verify_email' => $mailer->sendVerification($payload['recipient'], $payload['username'], (string) $payload['token']),
            'reset_password' => $mailer->sendPasswordReset($payload['recipient'], $payload['username'], (string) $payload['token']),
            'password_changed' => $mailer->sendPasswordChanged($payload['recipient'], $payload['username']),
            default => throw new RuntimeException('Modèle e-mail inconnu.'),
        };
        $queue->markSent((int) $row['id']);
        $sent++;
    } catch (Throwable $e) {
        $queue->markFailed((int) $row['id'], (int) $row['attempts'], get_class($e));
    }
}
if (random_int(1, 20) === 1) $queue->purge();
echo $sent . " e-mail(s) envoyé(s).\n";
