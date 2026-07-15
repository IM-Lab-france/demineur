<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../src/AdminLoginThrottle.php';

$pdo = (new Database())->getPDO();
$throttle = new AdminLoginThrottle($pdo);
$identifier = 'ci-' . bin2hex(random_bytes(8));
if ($throttle->retryAfter($identifier, 1_700_000_000) !== 0) throw new RuntimeException('Un identifiant neuf ne doit pas être bloqué.');
for ($attempt = 1; $attempt <= 5; $attempt++) $throttle->recordFailure($identifier, 1_700_000_000 + $attempt);
if ($throttle->retryAfter($identifier, 1_700_000_006) < 890) throw new RuntimeException('Cinq échecs doivent bloquer durablement la connexion.');
$throttle->clear($identifier);
if ($throttle->retryAfter($identifier, 1_700_000_006) !== 0) throw new RuntimeException('Le succès doit lever le verrouillage.');
echo "Tests de limitation persistante réussis.\n";
