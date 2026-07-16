<?php
require_once __DIR__ . '/../../admin/bootstrap.php';
require_once __DIR__ . '/ai_config.php';
require_admin();
header('Content-Type: application/json');
// status.php
$pluginsDir = './plugins';
$iaList = array_filter(glob($pluginsDir . '/*'), function ($path) {
    return is_dir($path) && !str_starts_with(basename($path), '.');
});
$status = [];

foreach ($iaList as $iaPath) {
    $iaName = basename($iaPath);
    $initialized = file_exists("$iaPath/env");
    $pidFile = "$iaPath/pid";
    $pid = file_exists($pidFile) ? trim((string) file_get_contents($pidFile)) : '';
    $running = ctype_digit($pid) && (int) $pid > 1 && posix_kill((int) $pid, 0);
    $unit = 'minesweeper-ai@' . $iaName . '.service';
    $serviceState = [];
    exec('/usr/bin/systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null', $serviceState, $serviceCode);
    $serviceRunning = $serviceCode === 0 && trim(implode('', $serviceState)) === 'active';
    $running = $running || $serviceRunning;
    if (!$running && is_file($pidFile)) @unlink($pidFile);
    $secureLog = '/var/log/minesweeper/ai/' . $iaName . '/run.log';
    $fallbackLog = $iaPath . '/logs/run.log';
    $logFile = is_readable($secureLog) ? $secureLog : $fallbackLog;
    $lastLog = '';
    if (is_readable($logFile)) {
        $contents = (string) file_get_contents($logFile);
        $lastLog = mb_substr($contents, -4000);
    }
    $stateFile = dirname($logFile) . '/state.json';
    $runtimeState = is_readable($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
    $inGame = $running && is_array($runtimeState) && !empty($runtimeState['inGame']);
    $memory = ['games' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'moves' => 0, 'decision_ms_total' => 0, 'decision_errors' => 0];
    $memoryFile = $iaPath . '/memory.json';
    if (is_readable($memoryFile) && filesize($memoryFile) <= 65536) {
        $loaded = json_decode((string) file_get_contents($memoryFile), true);
        if (is_array($loaded)) {
            foreach ($memory as $key => $unused) $memory[$key] = max(0, (int) ($loaded[$key] ?? 0));
        }
    }
    $status[$iaName] = [
        'initialized' => $initialized,
        'running' => $running,
        'pid' => ctype_digit($pid) ? (int) $pid : null,
        'managedBySystemd' => $serviceRunning,
        'inGame' => $inGame,
        'lastLog' => $lastLog,
        'config' => read_ai_config($iaName),
        'stats' => $memory + [
            'winRate' => $memory['games'] > 0 ? round(100 * $memory['wins'] / $memory['games'], 1) : 0,
            'averageDecisionMs' => $memory['moves'] > 0 ? round($memory['decision_ms_total'] / $memory['moves']) : 0,
        ],
    ];
}

echo json_encode($status);
