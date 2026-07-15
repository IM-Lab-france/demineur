<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_post();
require_csrf();

header('Content-Type: application/json; charset=utf-8');
$output = [];
exec('/usr/bin/sudo -n /usr/bin/systemctl stop minesweeper-websocket.service 2>&1', $output, $code);
if ($code !== 0) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Impossible d’arrêter le service WebSocket.']);
    exit;
}
file_put_contents(__DIR__ . '/locks/server.lock', date(DATE_ATOM), LOCK_EX);
echo json_encode(['status' => 'success', 'message' => 'Le serveur a été arrêté.']);
