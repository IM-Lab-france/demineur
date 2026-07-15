<?php
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_post();
require_csrf();

//start_server.php

// Afficher les erreurs pour les connexions locales
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Europe/Paris');

require __DIR__ . '/../vendor/autoload.php';  // Assurez-vous d'inclure Monolog via Composer

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

// Initialiser Monolog
$logger = new Logger('monitor_server');

// Conserver les tentatives de démarrage dans le même emplacement que le backend.
$logFilePath = getenv('MONITOR_LOG_PATH') ?: '/var/log/minesweeper/monitor.log';
try {
    $logDirectory = dirname($logFilePath);
    if ((!is_dir($logDirectory) && !@mkdir($logDirectory, 0770, true)) || !is_writable($logDirectory)) {
        throw new RuntimeException("Répertoire de logs non accessible: {$logDirectory}");
    }
    $logger->pushHandler(new RotatingFileHandler($logFilePath, 14, Logger::INFO));
} catch (Throwable $e) {
    // syslog reste disponible même si Apache ne peut pas écrire dans le fichier.
    $logger->pushHandler(new Monolog\Handler\SyslogHandler('minesweeper-admin'));
    $logger->warning('Journal fichier indisponible.', ['error' => $e->getMessage()]);
}

$lockFile = __DIR__ . '/locks/server.lock';

// Fonction pour démarrer le serveur
function startServer($logger, $lockFile) {
    $requestId = bin2hex(random_bytes(8));
    $logger->info('Demande de démarrage du backend.', [
        'request_id' => $requestId,
        'admin_user_id' => $_SESSION['admin_user_id'] ?? null,
    ]);

    // Démarrer le serveur et rediriger les erreurs dans un fichier log pour debug
    exec('/usr/bin/sudo -n /usr/bin/systemctl start minesweeper-websocket.service 2>&1', $output, $returnVar);
    
    if ($returnVar === 0) {
        $logger->info('Commande systemctl exécutée avec succès.', ['request_id' => $requestId]);

        // Supprimer le fichier de verrouillage, car le serveur démarre normalement
        if (file_exists($lockFile)) {
            unlink($lockFile);  // Supprimer le fichier de verrouillage
            $logger->info('Fichier de verrouillage supprimé après démarrage du serveur.');
        }

        echo json_encode(['status' => 'success', 'message' => 'Le serveur a été démarré.', 'requestId' => $requestId]);
    } else {
        $statusOutput = [];
        exec('/usr/bin/systemctl status minesweeper-websocket.service --no-pager --full 2>&1', $statusOutput);
        $diagnostic = array_slice(array_merge($output, $statusOutput), -40);
        $logger->error('Échec du démarrage du backend.', [
            'request_id' => $requestId,
            'exit_code' => $returnVar,
            'diagnostic' => $diagnostic,
        ]);
        echo json_encode([
            'status' => 'error',
            'message' => 'Échec du démarrage du backend. Consultez les journaux avec la référence ' . $requestId . '.',
            'requestId' => $requestId,
        ]);
    }
}

// Démarrer le serveur
startServer($logger, $lockFile);
