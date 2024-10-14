<?php
// delete_ia.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../../vendor/autoload.php';

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

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $iaName)) {
    echo json_encode(['success' => false, 'message' => 'Nom d\'IA invalide.']);
    exit;
}

$username = 'ia_' . $iaName;
$accountsFile = './ia_accounts.json';

if (!file_exists($accountsFile)) {
    echo json_encode(['success' => false, 'message' => 'Le fichier des comptes IA est introuvable.']);
    exit;
}

$accountsData = json_decode(file_get_contents($accountsFile), true);

if ($accountsData === null) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la lecture du fichier des comptes.']);
    exit;
}

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

unset($accountsData[$iaIndex]);
$accountsData = array_values($accountsData);

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
    $stmt = $pdo->prepare("DELETE FROM users WHERE username = :username AND is_ai = 1");
    $stmt->execute(['username' => $username]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur de connexion ou suppression dans la base de données : ' . $e->getMessage()]);
    exit;
}

// Suppression du répertoire de l'IA
$pluginsDir = './plugins';
$iaDir = $pluginsDir . '/' . $iaName;

function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item == '.' || $item == '..') continue;

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        // Essayer de forcer les permissions de suppression
        if (!is_writable($path)) {
            @chmod($path, 0777); // Modifier les permissions si elles sont restreintes
        }

        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                echo json_encode(['success' => false, 'message' => "Impossible de supprimer le sous-répertoire : $path"]);
                exit;
            }
        } else {
            if (!@unlink($path)) {
                echo json_encode(['success' => false, 'message' => "Impossible de supprimer le fichier : $path"]);
                exit;
            }
        }
    }

    // Changer les permissions du répertoire principal avant suppression
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }

    return @rmdir($dir);
}

if (is_dir($iaDir)) {
    if (!deleteDirectory($iaDir)) {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression complète du répertoire de l\'IA.']);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'IA supprimée avec succès.']);
