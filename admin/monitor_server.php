<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_post();
require_csrf();

header('Content-Type: application/json; charset=utf-8');
exec('/usr/bin/systemctl is-enabled --quiet minesweeper-websocket.service', $output, $code);
if ($code !== 0) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Le service systemd n’est pas activé.']);
    exit;
}
echo json_encode(['status' => 'success', 'message' => 'La supervision est assurée par systemd.']);
