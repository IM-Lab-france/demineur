<?php
require_once __DIR__ . '/../../admin/bootstrap.php';
require_admin();
header('Content-Type: application/json');
// status.php
$pluginsDir = './plugins';
$iaList = array_filter(glob($pluginsDir . '/*'), 'is_dir');
$status = [];

foreach ($iaList as $iaPath) {
    $iaName = basename($iaPath);
    $initialized = file_exists("$iaPath/env");
    $pidFile = "$iaPath/pid";
    $pid = file_exists($pidFile) ? trim((string) file_get_contents($pidFile)) : '';
    $running = ctype_digit($pid) && (int) $pid > 1 && posix_kill((int) $pid, 0);
    if (!$running && is_file($pidFile)) @unlink($pidFile);
    $secureLog = '/var/log/minesweeper/ai/' . $iaName . '/run.log';
    $fallbackLog = $iaPath . '/logs/run.log';
    $logFile = is_readable($secureLog) ? $secureLog : $fallbackLog;
    $lastLog = '';
    if (is_readable($logFile)) {
        $contents = (string) file_get_contents($logFile);
        $lastLog = mb_substr($contents, -4000);
    }
    $status[$iaName] = [
        'initialized' => $initialized,
        'running' => $running,
        'pid' => $running ? (int) $pid : null,
        'lastLog' => $lastLog,
    ];
}

echo json_encode($status);
