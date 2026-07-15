<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../src/AuthSessionRepository.php';

$pdo = (new Database())->getPDO();
$userId = $pdo->query("SELECT id FROM users WHERE username='e2e_player_1'")->fetchColumn();
if (!$userId) throw new RuntimeException('Compte E2E absent.');
$repository = new AuthSessionRepository($pdo);
$token = bin2hex(random_bytes(32));
$repository->save($token, (int) $userId, time() + 600);
$loaded = $repository->findValid($token);
if (!$loaded || (int) $loaded['id'] !== (int) $userId) throw new RuntimeException('Session persistante illisible.');
$repository->delete($token);
if ($repository->findValid($token) !== null) throw new RuntimeException('Session persistante non révoquée.');
echo "Tests des sessions persistantes réussis.\n";
