<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || (int) posix_geteuid() !== 0) exit(1);
require_once __DIR__ . '/../admin/bootstrap.php';

$requestPath = '/var/www/secure/backup-admin-request.json';
$resultPath = '/var/log/minesweeper/backup-admin-result.json';
$lock = fopen('/run/minesweeper-backup-admin.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(1);

function write_operation_result(string $path, array $result): void {
    $temporary = $path . '.tmp';
    file_put_contents($temporary, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    chown($temporary, 'root'); chgrp($temporary, 'minesweeper'); chmod($temporary, 0640);
    rename($temporary, $path);
}

function run_operation(array $command): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, '/var/www/demineur');
    if (!is_resource($process)) throw new RuntimeException('Démarrage de l’opération impossible.');
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) throw new RuntimeException(trim($stderr ?: $stdout) ?: 'Opération en échec.');
    return ['output' => substr(trim($stdout), -1000)];
}

$request = null;
try {
    $request = json_decode((string) file_get_contents($requestPath), true, 8, JSON_THROW_ON_ERROR);
    unlink($requestPath);
    if (!is_array($request) || !in_array($request['action'] ?? '', ['backup', 'verify', 'restore'], true)) throw new RuntimeException('Requête invalide.');
    if (!is_string($request['nonce'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $request['nonce'])) throw new RuntimeException('Nonce invalide.');
    if (abs(time() - (int) ($request['createdAt'] ?? 0)) > 120) throw new RuntimeException('Requête expirée.');
    $backupId = (string) ($request['backupId'] ?? '');
    if ($request['action'] !== 'backup' && !preg_match('/^\d{8}T\d{6}Z$/', $backupId)) throw new RuntimeException('Sauvegarde invalide.');
    if ($request['action'] === 'restore') {
        $credentials = $request['credentials'] ?? null;
        $requestedBy = (string) ($request['requestedBy'] ?? '');
        if (!is_array($credentials) || $requestedBy === '') throw new RuntimeException('Réauthentification de restauration absente.');
        $pdo = (new Database())->getPDO();
        $stmt = $pdo->prepare('SELECT password_hash,totp_secret,totp_enabled_at FROM users WHERE username=:username AND is_admin=1');
        $stmt->execute(['username' => $requestedBy]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        try {
            $secret = $admin && !empty($admin['totp_enabled_at']) ? decrypt_totp_secret((string) $admin['totp_secret']) : '';
        } catch (Throwable $e) {
            $secret = '';
        }
        if (
            !$admin
            || !password_verify((string) ($credentials['password'] ?? ''), (string) $admin['password_hash'])
            || !verify_totp($secret, (string) ($credentials['totpCode'] ?? ''))
        ) {
            throw new RuntimeException('Réauthentification de restauration refusée par le service privilégié.');
        }
        unset($credentials, $request['credentials']);
    }

    $baseResult = ['nonce' => $request['nonce'], 'action' => $request['action'], 'backupId' => $backupId ?: null];
    write_operation_result($resultPath, $baseResult + ['status' => 'running', 'startedAt' => gmdate('c')]);
    $archive = '/var/backups/minesweeper/' . $backupId . '/database.sql.gz';
    $result = match ($request['action']) {
        'backup' => run_operation(['/var/www/demineur/scripts/backup.sh']),
        'verify' => run_operation(['/var/www/demineur/scripts/verify-backup.sh', $archive]),
        'restore' => run_operation(['/var/www/demineur/scripts/restore-backup.sh', $backupId]),
    };
    run_operation(['/usr/bin/php', '/var/www/demineur/scripts/update-backup-index.php']);
    write_operation_result($resultPath, $baseResult + $result + ['status' => 'success', 'completedAt' => gmdate('c')]);
} catch (Throwable $e) {
    $safeMessage = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $e->getMessage()));
    write_operation_result($resultPath, [
        'nonce' => is_array($request) ? ($request['nonce'] ?? null) : null,
        'action' => is_array($request) ? ($request['action'] ?? null) : null,
        'status' => 'error', 'completedAt' => gmdate('c'),
        'message' => substr($safeMessage ?: 'Opération impossible.', 0, 500),
    ]);
    exit(1);
}
