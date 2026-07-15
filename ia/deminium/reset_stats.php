<?php
declare(strict_types=1);
require_once __DIR__ . '/../../admin/bootstrap.php';
require_admin();
require_post();
require_csrf();
header('Content-Type: application/json; charset=utf-8');

$iaName = validated_ia_name();
$iaPath = validated_ia_path($iaName);
$unit = 'minesweeper-ai@' . $iaName . '.service';
exec('/usr/bin/systemctl is-active --quiet ' . escapeshellarg($unit), $output, $serviceCode);
$pid = is_readable($iaPath . '/pid') ? trim((string) file_get_contents($iaPath . '/pid')) : '';
$processRunning = ctype_digit($pid) && (int) $pid > 1 && posix_kill((int) $pid, 0);
if ($serviceCode === 0 || $processRunning) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Arrêtez l’IA avant de réinitialiser ses statistiques.']);
    exit;
}

$memory = ['games' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'moves' => 0, 'decision_ms_total' => 0, 'decision_errors' => 0];
$path = $iaPath . '/memory.json';
$temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
if (file_put_contents($temporary, json_encode($memory, JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($temporary, $path)) {
    @unlink($temporary);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Réinitialisation des statistiques impossible.']);
    exit;
}
@chmod($path, 0640);
echo json_encode(['success' => true, 'message' => 'Statistiques réinitialisées.']);

