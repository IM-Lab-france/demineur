<?php
require_once __DIR__ . '/bootstrap.php';
require_admin();

// server_status.php

header('Content-Type: application/json');

function isServerRunning() {
    $output = [];
    exec('/usr/bin/systemctl is-active --quiet minesweeper-websocket.service', $output, $code);
    return $code === 0;
}

function getRuntimeStatus(): array {
    $path = getenv('STATUS_PATH') ?: '/var/log/minesweeper/status.json';
    if (!is_readable($path)) return [];
    try {
        $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        return [];
    }
}

function readOperationalStatus(string $path): ?array {
    if (!is_readable($path)) return null;
    try {
        $status = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($status) || empty($status['completedAt'])) return null;
        $status['ageSeconds'] = max(0, time() - (strtotime((string) $status['completedAt']) ?: 0));
        return $status;
    } catch (Throwable $e) {
        return null;
    }
}

$runtime = getRuntimeStatus();
$status = [
    'server' => isServerRunning() ? 'online' : 'offline',
    'connectedPlayers' => $runtime['authenticatedPlayers'] ?? 0,
    'connections' => $runtime['connections'] ?? 0,
    'activeGames' => $runtime['activeGames'] ?? 0,
    'pendingReconnects' => $runtime['pendingReconnects'] ?? 0,
    'uptimeSeconds' => $runtime['uptimeSeconds'] ?? 0,
    'lastUpdate' => $runtime['updatedAt'] ?? null,
    'actions' => $runtime['actions'] ?? 0,
    'websocketErrors' => $runtime['websocketErrors'] ?? 0,
    'pendingMoveWrites' => $runtime['pendingMoveWrites'] ?? 0,
    'averageMoveSqlMs' => $runtime['averageMoveSqlMs'] ?? 0,
    'backupTimer' => trim((string) shell_exec('/usr/bin/systemctl is-active minesweeper-backup.timer 2>/dev/null')),
    'restoreTestTimer' => trim((string) shell_exec('/usr/bin/systemctl is-active minesweeper-backup-verify.timer 2>/dev/null')),
    'lastBackup' => readOperationalStatus('/var/log/minesweeper/backup-status.json'),
    'lastRestoreTest' => readOperationalStatus('/var/log/minesweeper/restore-status.json'),
    'health' => readOperationalStatus('/var/log/minesweeper/health-status.json'),
];

echo json_encode($status);
