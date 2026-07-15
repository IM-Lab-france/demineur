<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');

$load = sys_getloadavg();
$cpuinfo = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES);
$cores = $cpuinfo === false ? 1 : max(1, count(array_filter($cpuinfo, fn($line) => str_starts_with($line, 'processor'))));
$cpu = $load === false ? 'N/A' : min(100, ($load[0] / $cores) * 100);
$memory = 'N/A';
$meminfo = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($meminfo !== false) {
    $values = [];
    foreach ($meminfo as $line) {
        if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $line, $match)) $values[$match[1]] = (int) $match[2];
    }
    if (!empty($values['MemTotal']) && isset($values['MemAvailable'])) {
        $memory = (($values['MemTotal'] - $values['MemAvailable']) / $values['MemTotal']) * 100;
    }
}
echo json_encode(['cpu' => $cpu, 'memory' => $memory]);
