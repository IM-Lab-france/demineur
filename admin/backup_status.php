<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function read_admin_json(string $path, array $fallback): array {
    if (!is_readable($path)) return $fallback;
    try {
        $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

$inventory = read_admin_json('/var/log/minesweeper/backups.json', ['updatedAt' => null, 'backups' => []]);
$operation = read_admin_json('/var/log/minesweeper/backup-admin-result.json', []);
$unitState = trim((string) shell_exec('/usr/bin/systemctl is-active minesweeper-backup-admin.service 2>/dev/null'));
$running = in_array($unitState, ['active', 'activating', 'reloading'], true);
if (!$running && ($operation['status'] ?? null) === 'running') {
    $operation['status'] = 'error';
    $operation['message'] = 'L’opération a été interrompue. Consultez le journal systemd.';
}
echo json_encode([
    'success' => true,
    'running' => $running,
    'operation' => $operation,
    'updatedAt' => $inventory['updatedAt'] ?? null,
    'backups' => is_array($inventory['backups'] ?? null) ? $inventory['backups'] : [],
]);
