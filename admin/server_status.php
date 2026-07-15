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

function getConnectedPlayers() {
    return null;
}

$status = [
    'server' => isServerRunning() ? 'online' : 'offline',
    'connectedPlayers' => getConnectedPlayers()
];

echo json_encode($status);
