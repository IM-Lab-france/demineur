<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || (int) posix_geteuid() !== 0) exit(1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../db.php';

$action = $argv[1] ?? '';
$stateFile = $argv[2] ?? '/run/minesweeper-admin-auth.json';
$pdo = (new Database())->getPDO();

if ($action === 'export-admins') {
    $rows = $pdo->query(
        'SELECT username,password_hash,totp_secret,totp_enabled_at,totp_recovery_codes '
        . 'FROM users WHERE is_admin=1'
    )->fetchAll(PDO::FETCH_ASSOC);
    file_put_contents($stateFile, json_encode($rows, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($stateFile, 0600);
    exit(0);
}

if ($action !== 'reconcile' || !is_readable($stateFile)) exit(2);
$admins = json_decode((string) file_get_contents($stateFile), true, 16, JSON_THROW_ON_ERROR);
$pdo->beginTransaction();
try {
    $updateAdmin = $pdo->prepare(
        'INSERT INTO users(username,password_hash,is_admin,is_disabled,totp_secret,totp_enabled_at,totp_recovery_codes) '
        . 'VALUES(:username,:password_hash,1,0,:totp_secret,:totp_enabled_at,:totp_recovery_codes) '
        . 'ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),is_admin=1,is_disabled=0,'
        . 'totp_secret=VALUES(totp_secret),totp_enabled_at=VALUES(totp_enabled_at),totp_recovery_codes=VALUES(totp_recovery_codes)'
    );
    foreach ($admins as $admin) {
        $updateAdmin->execute([
            'password_hash' => $admin['password_hash'], 'totp_secret' => $admin['totp_secret'],
            'totp_enabled_at' => $admin['totp_enabled_at'], 'totp_recovery_codes' => $admin['totp_recovery_codes'],
            'username' => $admin['username'],
        ]);
    }

    $accountsFile = '/var/www/secure/ia_accounts.json';
    $accounts = is_readable($accountsFile) ? json_decode((string) file_get_contents($accountsFile), true, 16, JSON_THROW_ON_ERROR) : [];
    $upsertAi = $pdo->prepare(
        'INSERT INTO users(username,password_hash,is_ai,is_disabled) VALUES(:username,:password_hash,1,0) '
        . 'ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),is_ai=1,is_disabled=0'
    );
    foreach ($accounts as $account) {
        $username = (string) ($account['username'] ?? '');
        $password = (string) ($account['password'] ?? '');
        if (!preg_match('/^ia_[A-Za-z0-9_-]{1,32}$/', $username) || $password === '') throw new RuntimeException('Compte IA sécurisé invalide.');
        $upsertAi->execute(['username' => $username, 'password_hash' => password_hash($password, PASSWORD_BCRYPT)]);
    }

    // Une restauration ne doit jamais réactiver d’anciennes sessions ni liens secrets.
    $pdo->exec('DELETE FROM auth_sessions');
    $pdo->exec('DELETE FROM account_tokens');
    $pdo->exec('DELETE FROM email_outbox');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
