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
