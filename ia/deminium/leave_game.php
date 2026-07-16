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
$running = $serviceCode === 0 || (ctype_digit($pid) && (int) $pid > 1 && posix_kill((int) $pid, 0));
if (!$running) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'L’IA doit être démarrée.']);
    exit;
}

$secureLogDir = '/var/log/minesweeper/ai/' . $iaName;
$controlDir = is_dir($secureLogDir) && is_writable($secureLogDir) ? $secureLogDir : $iaPath . '/logs';
$statePath = $controlDir . '/state.json';
$state = is_readable($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
if (!is_array($state) || empty($state['inGame'])) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Cette IA n’est actuellement dans aucune partie.']);
    exit;
}
if (!is_dir($controlDir) && !mkdir($controlDir, 0750, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Répertoire de contrôle indisponible.']);
    exit;
}
$requestPath = $controlDir . '/leave.request';
if (file_put_contents($requestPath, date(DATE_ATOM) . "\n", LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Impossible d’envoyer la demande.']);
    exit;
}
@chmod($requestPath, 0640);
echo json_encode(['success' => true, 'message' => 'Demande envoyée. L’IA quittera sa partie en cours.']);
