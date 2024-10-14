<?php
// delete_ia.php

require '/../../vendor/autoload.php'; // Vérifiez que le chemin est correct

use Dotenv\Dotenv;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$iaName = $_POST['iaName'] ?? '';

if (empty($iaName)) {
    echo json_encode(['success' => false, 'message' => 'Le nom de l\'IA est requis.']);
    exit;
}

// Validation du nom de l'IA
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $iaName)) {
    echo json_encode(['success' => false, 'message' => 'Nom d\'IA invalide.']);
    exit;
}

$username = 'ia_' . $iaName;
$accountsFile = './ia_accounts.json';

// Vérifier si l'IA existe dans ia_accounts.json
if (!file_exists($accountsFile)) {
    echo json_encode(['success' => false, 'message' => 'Le fichier des comptes IA est introuvable.']);
    exit;
}

$accountsData = json_decode(file_get_contents($accountsFile), true);

if ($accountsData === null) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la lecture du fichier des comptes.']);
    exit;
}

// Trouver l'index de l'IA à supprimer
$iaIndex = null;
foreach ($accountsData as $index => $account) {
    if ($account['username'] === $username) {
        $iaIndex = $index;
        break;
    }
}

if ($iaIndex === null) {
    echo json_encode(['success' => false, 'message' => 'L\'IA spécifiée n\'existe pas.']);
    exit;
}

// Supprimer l'IA du fichier ia_accounts.json
unset($accountsData[$iaIndex]);
// Réindexer le tableau
$accountsData = array_values($accountsData);

// Sauvegarder le fichier ia_accounts.json
if (file_put_contents($accountsFile, json_encode($accountsData, JSON_PRETTY_PRINT)) === false) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour du fichier des comptes.']);
    exit;
}

// ** Chargement des variables d'environnement **
$dotenv = Dotenv::createImmutable('/var/www/secure');
$dotenv->load();

$dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4';
$dbusername = $_ENV['DB_USER'];
$dbpassword = $_ENV['DB_PASS'];

try {
    $pdo = new PDO($dsn, $dbusername, $dbpassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Connexion à la base de données échouée : ' . $e->getMessage()]);
    exit;
}

// Supprimer l'utilisateur de la base de données
$stmt = $pdo->prepare("DELETE FROM users WHERE username = :username AND is_ai = 1");
$stmt->execute(['username' => $username]);

// Supprimer le répertoire de l'IA
$pluginsDir = './plugins';
$iaDir = $pluginsDir . '/' . $iaName;

if (is_dir($iaDir)) {
    // Fonction pour supprimer récursivement un répertoire
    function deleteDirectory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        return rmdir($dir);
    }
    if (!deleteDirectory($iaDir)) {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression du répertoire de l\'IA.']);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'IA supprimée avec succès.']);
