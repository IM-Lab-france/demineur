<?php
header('Content-Type: application/json');

// initialize.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iaName = $_POST['iaName'];
    $pluginsDir = './plugins';
    $iaPath = $pluginsDir . '/' . $iaName;
    $envPath = $iaPath . '/env';
    $logDir = $iaPath . '/logs';

    // Vérifier si l'IA existe
    if (!is_dir($iaPath)) {
        echo json_encode(['success' => false, 'message' => 'IA non trouvée.']);
        exit;
    }

    // Créer le dossier des logs s'il n'existe pas
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $logFile = $logDir . '/initialize.log';

    // Effacer le fichier de log pour une nouvelle tentative d'initialisation
    file_put_contents($logFile, "Initialisation de l'IA : $iaName\n");

    // Créer l'environnement virtuel
    $command = "python3 -m venv $envPath 2>&1";
    exec($command, $output, $returnVar);
    file_put_contents($logFile, "[ENV CREATION] " . implode("\n", $output), FILE_APPEND);

    if ($returnVar !== 0) {
        echo json_encode(['success' => false, 'message' => 'Échec de la création de l\'environnement virtuel.', 'log' => file_get_contents($logFile)]);
        exit;
    }

    // Installer les dépendances
    $requirementsFile = $iaPath . '/requirements.txt';
    if (file_exists($requirementsFile)) {
        $command = "$envPath/bin/pip install -r $requirementsFile 2>&1";
        exec($command, $output, $returnVar);
        file_put_contents($logFile, "[DEPENDENCIES INSTALLATION] " . implode("\n", $output), FILE_APPEND);

        if ($returnVar !== 0) {
            echo json_encode(['success' => false, 'message' => 'Échec de l\'installation des dépendances.', 'log' => file_get_contents($logFile)]);
            exit;
        }
    }

    // Vérification des dépendances
    $command = "$envPath/bin/pip check 2>&1";
    exec($command, $output, $returnVar);
    file_put_contents($logFile, "[DEPENDENCIES CHECK] " . implode("\n", $output), FILE_APPEND);

    if ($returnVar !== 0) {
        echo json_encode(['success' => false, 'message' => 'Des dépendances sont manquantes ou incompatibles.', 'log' => file_get_contents($logFile)]);
        exit;
    }

    // Si tout s'est bien passé
    echo json_encode(['success' => true, 'message' => 'Dépendances installées avec succès.']);
}
