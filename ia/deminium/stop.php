<?php
require_once __DIR__ . '/../../admin/bootstrap.php';
require_admin();
require_post();
require_csrf();
header('Content-Type: application/json');
// stop.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iaName = validated_ia_name();
    $iaPath = validated_ia_path($iaName);
    $pidFile = $iaPath . '/pid';
    $unit = 'minesweeper-ai@' . $iaName . '.service';
    exec('/usr/bin/systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null', $state, $stateCode);
    if ($stateCode === 0 && trim(implode('', $state)) === 'active') {
        exec('/usr/bin/sudo -n /usr/bin/systemctl stop ' . escapeshellarg($unit) . ' 2>&1', $output, $code);
        if ($code === 0) {
            @unlink($pidFile);
            echo json_encode(['success' => true, 'message' => 'Service IA arrêté.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Échec de l’arrêt du service IA.']);
        }
        exit;
    }

    // Vérifier si le fichier PID existe
    if (!file_exists($pidFile)) {
        echo json_encode(['success' => false, 'message' => 'IA non en cours d\'exécution.']);
        exit;
    }

    $pid = trim((string) file_get_contents($pidFile));
    if (!ctype_digit($pid) || (int) $pid < 2) {
        echo json_encode(['success' => false, 'message' => 'PID invalide.']);
        exit;
    }

    // Arrêter le processus
    exec('kill ' . escapeshellarg($pid), $output, $returnVar);

    if ($returnVar === 0) {
        // Supprimer le fichier PID
        unlink($pidFile);
        echo json_encode(['success' => true, 'message' => 'IA arrêtée avec succès.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Échec de l\'arrêt de l\'IA.']);
    }
}
