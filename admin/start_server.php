<?php

//start_server.php

// Afficher les erreurs pour les connexions locales
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Fonction pour démarrer le serveur
function startServer($logger, $lockFile) {
    // Démarrer le serveur et rediriger les erreurs dans un fichier log pour debug
    exec('/usr/bin/php ../server.php > ../../logs/server_start.log 2>&1 &', $output, $returnVar);
    
    if ($returnVar === 0) {
        $logger->info('Le serveur a été démarré.');

        // Supprimer le fichier de verrouillage, car le serveur démarre normalement
        if (file_exists($lockFile)) {
            unlink($lockFile);  // Supprimer le fichier de verrouillage
            $logger->info('Fichier de verrouillage supprimé après démarrage du serveur.');
        }

        echo json_encode(['status' => 'success', 'message' => 'Le serveur a été démarré et le fichier de verrouillage a été supprimé.']);
    } else {
        $logger->error('Échec du démarrage du serveur.', ['output' => $output, 'returnVar' => $returnVar]);
        echo json_encode(['status' => 'error', 'message' => 'Échec du démarrage du serveur.']);
    }
}

// Démarrer le serveur
startServer($logger, $lockFile);
