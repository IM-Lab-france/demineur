<?php
// start.php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iaName = $_POST['iaName'];
    $invite = isset($_POST['invite']) && $_POST['invite'] == 1 ? true : false;
    $pluginsDir = './plugins';
    $iaPath = $pluginsDir . '/' . $iaName;
    $envPath = $iaPath . '/env';
    $logDir = $iaPath . '/logs';

    // Vérifier si l'IA existe et si l'environnement est initialisé
    if (!is_dir($iaPath) || !is_dir($envPath)) {
        echo json_encode(['success' => false, 'message' => 'IA non initialisée.']);
        exit;
    }

    $logFile = $logDir . '/run.log';
    $pidFile = $iaPath . '/pid';

    // Construire la commande pour démarrer le script
    $mainScript = './main.py';
    $command = escapeshellcmd("$envPath/bin/python $mainScript --model=$iaName");
    
    // Ajouter l'option --invite si le mode invite est activé
    if ($invite) {
        $command .= ' --invite';
    }

    // Rediriger la sortie vers le fichier log et exécuter en arrière-plan
    $command .= " >> $logFile 2>&1 & echo $!";

    // Exécuter la commande et récupérer le PID
    $pid = shell_exec($command);
    if ($pid) {
        file_put_contents($pidFile, $pid);
        echo json_encode(['success' => true, 'message' => 'IA démarrée avec succès.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Échec du démarrage de l\'IA.']);
    }
}
?>
