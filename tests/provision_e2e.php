<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../db.php';

$password = getenv('E2E_PASSWORD') ?: 'E2e-Password!2026';
$pdo = (new Database())->getPDO();
$stmt = $pdo->prepare('INSERT INTO users (username,password_hash) VALUES (:username,:hash) '
    . 'ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), is_disabled=0');
foreach (['e2e_player_1', 'e2e_player_2', 'e2e_logout'] as $username) {
    $stmt->execute(['username' => $username, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
}
$adminPassword = getenv('E2E_ADMIN_PASSWORD') ?: 'E2e-Admin-Password!2026';
$admin = $pdo->prepare('INSERT INTO users (username,password_hash,is_admin,totp_secret,totp_enabled_at,totp_recovery_codes) VALUES (:username,:hash,1,NULL,NULL,NULL) '
    . 'ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),is_admin=1,totp_secret=NULL,totp_enabled_at=NULL,totp_recovery_codes=NULL');
$admin->execute(['username' => getenv('E2E_ADMIN_USER') ?: 'e2e_admin', 'hash' => password_hash($adminPassword, PASSWORD_DEFAULT)]);
echo "Comptes E2E prêts.\n";
