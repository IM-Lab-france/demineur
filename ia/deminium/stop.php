<?php
header('Content-Type: application/json');
// stop.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iaName = $_POST['iaName'];
    $pluginsDir = './plugins';
    $iaPath = $pluginsDir . '/' . $iaName;
    $pidFile = $iaPath . '/pid';

    // Vérifier si le fichier PID existe
    if (!file_exists($pidFile)) {
        echo json_encode(['success' => false, 'message' => 'IA non en cours d\'exécution.']);
        exit;
    }

    $pid = file_get_contents($pidFile);

    // Arrêter le processus
    exec("kill $pid", $output, $returnVar);

    if ($returnVar === 0) {
        // Supprimer le fichier PID
        unlink($pidFile);
        echo json_encode(['success' => true, 'message' => 'IA arrêtée avec succès.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Échec de l\'arrêt de l\'IA.']);
    }
}
