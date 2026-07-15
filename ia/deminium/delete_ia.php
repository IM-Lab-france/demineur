<?php
require_once __DIR__ . '/../../admin/bootstrap.php';
require_admin();
require_post();
require_csrf();
// delete_ia.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../../vendor/autoload.php';

use Dotenv\Dotenv;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$iaName = validated_ia_name();

if (empty($iaName)) {
    echo json_encode(['success' => false, 'message' => 'Le nom de l\'IA est requis.']);
    exit;
}

$username = 'ia_' . $iaName;
$accountsFile = ia_accounts_file();

if (!file_exists($accountsFile)) {
    echo json_encode(['success' => false, 'message' => 'Le fichier des comptes IA est introuvable.']);
    exit;
}

$accountsData = read_ia_accounts();

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

try {
    write_ia_accounts($accountsData);
} catch (Throwable $e) {
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

    return @rmdir($dir);
}

if (is_dir($iaDir)) {
    if (!deleteDirectory($iaDir)) {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression complète du répertoire de l\'IA.']);
        exit;
    }
}

$secureLogDir = '/var/log/minesweeper/ai/' . $iaName;
if (is_dir($secureLogDir)) {
    deleteDirectory($secureLogDir);
}

echo json_encode(['success' => true, 'message' => 'IA supprimée avec succès.']);
