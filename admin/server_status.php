<?php

// server_status.php

header('Content-Type: application/json');

function isServerRunning() {
    $output = [];
    exec("ps aux | grep 'server.php' | grep -v 'grep'", $output);
    return count($output) > 0;
}

function getConnectedPlayers() {
    return rand(0, 10); // Remplacer par une vraie logique
}

$status = [
    'server' => isServerRunning() ? 'online' : 'offline',
    'connectedPlayers' => getConnectedPlayers()
];

echo json_encode($status);