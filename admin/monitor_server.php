<?php

// monitor_server.php

header('Content-Type: application/json');
date_default_timezone_set('Europe/Paris');

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;

// Configuration du logger
$logger = new Logger('monitor_logger');

// Handler pour les fichiers tournants avec une taille maximale de 5Mo par fichier
$logFilePath = '../../logs/monitor_server.log';
$rotatingHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
$consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);

$logger->pushHandler($rotatingHandler);
$logger->pushHandler($consoleHandler);

$lockFile = './locks/server.lock';

// Fonction pour vérifier si le processus server.php est actif
function isServerRunning() {
    global $logger;
    $output = [];
    exec("ps aux | grep '[/]server.php'", $output);
    return count($output) > 0;
}

// Fonction pour démarrer le serveur
function startServer($logger) {
    $logger->info("Démarrage du serveur...");
    exec('../server.php > /dev/null &');
}

// Fonction pour envoyer un email en cas de redémarrage
function sendEmailNotification($logger) {
    $to = 'cedric.hourde@gmail.com';
    $subject = 'Redémarrage du serveur détecté';
    $message = 'Le serveur a été redémarré automatiquement suite à une interruption.';
    $headers = 'From: no-reply@fozzy.fr';
    
    if (mail($to, $subject, $message, $headers)) {
        $logger->info("Notification de redémarrage envoyée à $to.");
    } else {
        $logger->error("Échec de l'envoi de l'email de notification à $to.");
    }
}

// Vérification de l'état du serveur et du fichier de verrouillage
$response = [];
try {
    if (file_exists($lockFile)) {
        $logger->info("Le fichier de verrouillage est présent. Le serveur a été arrêté manuellement.");
        http_response_code(403);
        $response = [
            "status" => "error",
            "message" => "Le serveur a été arrêté manuellement. Aucune action n'est nécessaire."
        ];
    } elseif (!isServerRunning()) {
        $logger->warning("Le serveur n'est pas en cours d'exécution. Tentative de redémarrage...");
        startServer($logger);
        sendEmailNotification($logger);
        $logger->info("Le serveur a été redémarré avec succès.");
        http_response_code(200);
        $response = [
            "status" => "success",
            "message" => "Le serveur a été redémarré."
        ];
    } else {
        $logger->info("Le serveur fonctionne correctement.");
        http_response_code(200);
        $response = [
            "status" => "success",
            "message" => "Le serveur fonctionne correctement."
        ];
    }
} catch (Exception $e) {
    $logger->error("Erreur lors de l'exécution du script : " . $e->getMessage());
    http_response_code(500);
    $response = [
        "status" => "error",
        "message" => "Une erreur interne est survenue."
    ];
}

echo json_encode($response);