<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || (int) posix_geteuid() !== 0) {
    fwrite(STDERR, "Ce script doit être exécuté par root en ligne de commande.\n");
    exit(1);
}

$backupRoot = getenv('BACKUP_DIR') ?: '/var/backups/minesweeper';
$statusDir = getenv('STATUS_DIR') ?: '/var/log/minesweeper';
$backups = [];
foreach (glob($backupRoot . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
    $id = basename($directory);
    if (!preg_match('/^\d{8}T\d{6}Z$/', $id)) continue;
    $database = $directory . '/database.sql.gz';
    $checksums = $directory . '/SHA256SUMS';
    if (!is_file($database) || !is_file($checksums)) continue;
    $date = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $id, new DateTimeZone('UTC'));
    $backups[] = [
        'id' => $id,
        'createdAt' => $date ? $date->format(DATE_ATOM) : null,
        'databaseBytes' => filesize($database) ?: 0,
        'configIncluded' => is_file($directory . '/secure-config.tar.gz'),
        'checksum' => hash_file('sha256', $database),
    ];
}
usort($backups, static fn(array $a, array $b): int => strcmp($b['id'], $a['id']));
$payload = json_encode(['updatedAt' => gmdate('c'), 'backups' => $backups], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$temporary = $statusDir . '/backups.json.tmp';
file_put_contents($temporary, $payload . "\n", LOCK_EX);
chown($temporary, 'root');
chgrp($temporary, 'minesweeper');
chmod($temporary, 0640);
rename($temporary, $statusDir . '/backups.json');
