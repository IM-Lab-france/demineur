<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../db.php';

$password = getenv('E2E_PASSWORD') ?: 'E2e-Password!2026';
$pdo = (new Database())->getPDO();
$stmt = $pdo->prepare('INSERT INTO users (username,password_hash) VALUES (:username,:hash) '
    . 'ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), is_disabled=0');
foreach (['e2e_player_1', 'e2e_player_2'] as $username) {
    $stmt->execute(['username' => $username, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
}
echo "Comptes E2E prêts.\n";
