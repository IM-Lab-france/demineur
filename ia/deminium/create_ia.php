<?php
// create_ia.php

require '../../vendor/autoload.php'; // Assurez-vous que le chemin vers autoload.php est correct

use Dotenv\Dotenv;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$iaName = $_POST['iaName'] ?? '';
$iaPassword = $_POST['iaPassword'] ?? '';

if (empty($iaName) || empty($iaPassword)) {
    echo json_encode(['success' => false, 'message' => 'Le nom de l\'IA et le mot de passe sont requis.']);
    exit;
}

// Validation du nom de l'IA
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $iaName)) {
    echo json_encode(['success' => false, 'message' => 'Nom d\'IA invalide.']);
    exit;
}

$username = 'ia_' . $iaName;
$accountsFile = './ia_accounts.json';

// Vérifier si le nom d'utilisateur existe déjà dans ia_accounts.json
$accountsData = [];

if (file_exists($accountsFile)) {
    $accountsData = json_decode(file_get_contents($accountsFile), true);
    if ($accountsData === null) {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la lecture du fichier des comptes : JSON invalide ou fichier inaccessible.']);
        exit;
    }

    foreach ($accountsData as $account) {
        if ($account['username'] === $username) {
            echo json_encode(['success' => false, 'message' => 'Une IA avec ce nom existe déjà.']);
            exit;
        }
    }
}

// ** Chargement des variables d'environnement **
try {
    $dotenv = Dotenv::createImmutable('/var/www/secure');
    $dotenv->load();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors du chargement des variables d\'environnement : ' . $e->getMessage()]);
    exit;
}

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

// Vérifier si le nom d'utilisateur existe déjà dans la base de données
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
$stmt->execute(['username' => $username]);

if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Un utilisateur avec ce nom existe déjà dans la base de données.']);
    exit;
}

// Hacher le mot de passe
$passwordHash = password_hash($iaPassword, PASSWORD_BCRYPT);

// Insérer le nouvel utilisateur dans la base de données
$stmt = $pdo->prepare("INSERT INTO users (username, password_hash, is_ai) VALUES (:username, :password_hash, :is_ai)");
$inserted = $stmt->execute([
    'username' => $username,
    'password_hash' => $passwordHash,
    'is_ai' => 1
]);

if (!$inserted) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'insertion dans la base de données.']);
    exit;
}

// ** Création du répertoire de l'IA **
$pluginsDir = './plugins';
$newIaDir = $pluginsDir . '/' . $iaName;
$templateDir = $pluginsDir . '/.template';

if (is_dir($newIaDir)) {
    echo json_encode(['success' => false, 'message' => 'Le répertoire de l\'IA existe déjà.']);
    exit;
}

// Créer le nouveau répertoire
if (!mkdir($newIaDir, 0755)) {
    echo json_encode(['success' => false, 'message' => 'Impossible de créer le répertoire de l\'IA. Vérifiez les permissions du système de fichiers.']);
    exit;
}

// Fonction pour copier récursivement les fichiers du template
function copyDirectory($src, $dst) {
    if (!is_dir($src)) {
        echo json_encode(['success' => false, 'message' => 'Le répertoire de modèle est introuvable.']);
        exit;
    }

    $dir = opendir($src);
    @mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                copyDirectory($src . '/' . $file, $dst . '/' . $file);
            } else {
                if (!copy($src . '/' . $file, $dst . '/' . $file)) {
                    echo json_encode(['success' => false, 'message' => "Erreur lors de la copie du fichier {$file} dans le répertoire {$dst}."]);
                    exit;
                }
            }
        }
    }
    closedir($dir);
}

// Copier les fichiers du template dans le nouveau répertoire
copyDirectory($templateDir, $newIaDir);

// Ajouter l'IA dans ia_accounts.json
$newAccount = [
    'model_name' => $iaName,
    'username' => $username,
    'password' => $iaPassword
];

$accountsData[] = $newAccount;

// Sauvegarder le fichier ia_accounts.json
if (file_put_contents($accountsFile, json_encode($accountsData, JSON_PRETTY_PRINT)) === false) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la sauvegarde du fichier des comptes. Vérifiez les permissions d\'écriture.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'IA créée avec succès.']);