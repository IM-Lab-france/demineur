<?php
header('Content-Type: application/json');
// status.php
$pluginsDir = './plugins';
$iaList = array_filter(glob($pluginsDir . '/*'), 'is_dir');
$status = [];

foreach ($iaList as $iaPath) {
    $iaName = basename($iaPath);
    $initialized = file_exists("$iaPath/env");
    $pidFile = "$iaPath/pid";
    $running = file_exists($pidFile) && posix_kill(file_get_contents($pidFile), 0);
    $status[$iaName] = [
        'initialized' => $initialized,
        'running' => $running
    ];
}

echo json_encode($status);