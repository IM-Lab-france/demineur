<?php
require_once __DIR__ . '/../../admin/bootstrap.php';
require_admin();
require_post();
require_csrf();
header('Content-Type: application/json');

// initialize.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iaName = validated_ia_name();
    $iaPath = validated_ia_path($iaName);
    $envPath = $iaPath . '/env';
    $secureLogRoot = '/var/log/minesweeper/ai';
    $logDir = is_dir($secureLogRoot) && is_writable($secureLogRoot)
        ? $secureLogRoot . '/' . $iaName
        : $iaPath . '/logs';

    // Vérifier si l'IA existe
    if (!is_dir($iaPath)) {
        echo json_encode(['success' => false, 'message' => 'IA non trouvée.']);
        exit;
    }

    // Créer le dossier des logs s'il n'existe pas
    if (!is_dir($logDir)) {
        mkdir($logDir, 0750, true);
    }

    $logFile = $logDir . '/initialize.log';

    // Effacer le fichier de log pour une nouvelle tentative d'initialisation
    file_put_contents($logFile, "Initialisation de l'IA : $iaName\n");

    // Créer l'environnement virtuel
    $command = 'python3 -m venv ' . escapeshellarg($envPath) . ' 2>&1';
    exec($command, $output, $returnVar);
    file_put_contents($logFile, "[ENV CREATION] " . implode("\n", $output), FILE_APPEND);

    if ($returnVar !== 0) {
        echo json_encode(['success' => false, 'message' => 'Échec de la création de l\'environnement virtuel.', 'log' => file_get_contents($logFile)]);
        exit;
    }

    // Installer les dépendances
    $requirementsFile = __DIR__ . '/requirements.lock';
    if (file_exists($requirementsFile)) {
        $command = escapeshellarg($envPath . '/bin/pip') . ' install --require-virtualenv -r ' . escapeshellarg($requirementsFile) . ' 2>&1';
        exec($command, $output, $returnVar);
        file_put_contents($logFile, "[DEPENDENCIES INSTALLATION] " . implode("\n", $output), FILE_APPEND);

        if ($returnVar !== 0) {
            echo json_encode(['success' => false, 'message' => 'Échec de l\'installation des dépendances.', 'log' => file_get_contents($logFile)]);
            exit;
        }
    }

    // Vérification des dépendances
    $command = escapeshellarg($envPath . '/bin/pip') . ' check 2>&1';
    exec($command, $output, $returnVar);
    file_put_contents($logFile, "[DEPENDENCIES CHECK] " . implode("\n", $output), FILE_APPEND);

    if ($returnVar !== 0) {
        echo json_encode(['success' => false, 'message' => 'Des dépendances sont manquantes ou incompatibles.', 'log' => file_get_contents($logFile)]);
        exit;
    }

    // Si tout s'est bien passé
    echo json_encode(['success' => true, 'message' => 'Dépendances installées avec succès.']);
}
