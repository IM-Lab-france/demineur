<?php

// stop_server.php

date_default_timezone_set('Europe/Paris');

require __DIR__ . '/../vendor/autoload.php';  // Assurez-vous d'inclure Monolog via Composer

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

// Initialiser Monolog
$logger = new Logger('monitor_server');

// Définir le chemin du fichier de log
$logFilePath = __DIR__ . '/../../logs/monitor_server.log';
$rotatingHandler = new RotatingFileHandler($logFilePath, 0, Logger::INFO);

// Ajouter le handler pour gérer les logs avec rotation
$logger->pushHandler($rotatingHandler);

$lockFile = './locks/server.lock'; // Fichier de verrouillage

// Fonction pour arrêter le serveur
function stopServer($logger, $lockFile) {
    // Utiliser un motif plus précis pour éviter de correspondre au processus pgrep lui-même
    $output = [];
    exec("ps aux | grep '[s]erver.php' | awk '{print $2}'", $output);
    $logger->info('Processus en cours (avant tentative de kill):', ['processes' => $output]);

    if (!empty($output)) {
        foreach ($output as $pid) {
            // Tenter de tuer le processus sans 'sudo'
            exec("sudo kill $pid", $killOutput, $killResultCode);
            if ($killResultCode === 0) {
                $logger->info("Processus $pid tué avec succès.");
            } else {
                $logger->warning("Échec de la tentative de tuer le processus $pid.", ['killOutput' => $killOutput]);
            }
        }

        // Attendre quelques secondes pour permettre l'arrêt complet des processus
        sleep(2);

        // Vérification après l'arrêt
        $remainingProcesses = [];
        exec("ps aux | grep '[s]erver.php' | awk '{print $2}'", $remainingProcesses);
        $logger->info('Processus restants après tentative de kill:', ['remaining_processes' => $remainingProcesses]);

        if (empty($remainingProcesses)) {
            $logger->info('Tous les processus server.php ont été tués avec succès.');
            echo json_encode(['status' => 'success', 'message' => 'Le serveur a été arrêté.']);
        } else {
            $logger->warning('Certains processus server.php sont toujours actifs après tentative de kill.', ['remaining_processes' => $remainingProcesses]);
            echo json_encode(['status' => 'partial_success', 'message' => 'Certains processus sont toujours actifs.']);
        }
    } else {
        $logger->info("Aucun processus 'server.php' trouvé.");
        echo json_encode(['status' => 'error', 'message' => 'Aucun processus server.php trouvé.']);
    }

    // Créer le fichier de verrouillage pour indiquer que le serveur est arrêté
    file_put_contents($lockFile, '');
}

// Arrêter le serveur
stopServer($logger, $lockFile);
