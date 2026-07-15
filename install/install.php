<?php
session_start();
$configDir = getenv('APP_CONFIG_DIR') ?: '/var/www/secure';
$configFile = rtrim($configDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';

if (getenv('ALLOW_WEB_INSTALL') !== '1' || file_exists(__DIR__ . '/../.installed')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Installateur web désactivé. Utilisez la procédure CLI.']);
    exit;
}

// Afficher les erreurs pour les connexions locales
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Fonction pour supprimer un répertoire de manière récursive
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    return rmdir($dir);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Décoder le JSON brut reçu dans le corps de la requête
    $requestBody = file_get_contents("php://input");
    $data = json_decode($requestBody, true);

    $step = $data['step'] ?? null;
    $response = ['success' => false, 'message' => "Étape non reconnue : $step"];


    switch ($step) {
        case 'composer_dry_run':
            $output = [];
            $returnVar = 0;
            $projectDir = __DIR__ . '/../'; // Répertoire de la racine du projet
            exec("cd $projectDir && COMPOSER_HOME='$projectDir/vendor/composer' composer install --dry-run 2>&1", $output, $returnVar);

            $response = [
                'success' => $returnVar === 0,
                'message' => $returnVar === 0 ? 'Dry-run de Composer réussi.' : 'Erreur dans le dry-run de Composer :<br>' . implode("<br>", $output),
            ];
            break;

        case 'composer_install':
            $output = [];
            $returnVar = 0;
            $projectDir = __DIR__ . '/../'; // Répertoire de la racine du projet
            exec("cd $projectDir && COMPOSER_HOME='$projectDir/vendor/composer' composer install 2>&1", $output, $returnVar);

            $response = [
                'success' => $returnVar === 0,
                'message' => $returnVar === 0 ? 'Installation de Composer réussie.' : 'Erreur lors de l’installation de Composer :<br>' . implode("<br>", $output),
            ];
            break;

        case 'write_env':
            $server = trim((string) ($data['server'] ?? ''));
            $username = trim((string) ($data['username'] ?? ''));
            $password = (string) ($data['password'] ?? '');
            $database = trim((string) ($data['database'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._:-]+$/', $server) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $username) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) || str_contains($password, "\n") || str_contains($password, "\r")) {
                $response = ['success' => false, 'message' => 'Configuration de base de données invalide.'];
                break;
            }
            
            $envContent = "DB_HOST=$server\nDB_USER=$username\nDB_PASS=$password\nDB_NAME=$database\n";
            if (!is_dir($configDir) && !mkdir($configDir, 0700, true)) {
                $response = ['success' => false, 'message' => 'Répertoire sécurisé de configuration indisponible.'];
                break;
            }
            file_put_contents($configFile, $envContent, LOCK_EX);
            @chmod($configFile, 0640);
            $response = [
                'success' => true,
                'message' => 'Fichier .env créé avec succès.',
            ];
            break;

        case 'verify_env':
            $response = [
                'success' => file_exists($configFile),
                'message' => file_exists($configFile) ? 'Fichier .env vérifié.' : 'Le fichier .env est manquant.',
            ];
            break;

            case 'db_connection':
                // Charger l'autoloader de Composer pour Dotenv uniquement pour cette étape et au-delà
                if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                    require_once __DIR__ . '/../vendor/autoload.php';
                } else {
                    $response = ['success' => false, 'message' => "L'autoloader de Composer n'est pas disponible. Exécutez l'étape de Composer d'abord."];
                    break;
                }
            
                $dotenv = Dotenv\Dotenv::createImmutable($configDir);
                $dotenv->load();
            
                try {
                    $pdo = new PDO("mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}", $_ENV['DB_USER'], $_ENV['DB_PASS']);
                    $response = [
                        'success' => true, 
                        'message' => "Connexion à la base de données réussie."
                    ];
                } catch (PDOException $e) {
                    $response = [
                        'success' => false, 
                        'message' => "Connexion à la base de données impossible."
                    ];
                }
                break;

        case 'check_db_exists':
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }

            $dotenv = Dotenv\Dotenv::createImmutable($configDir);
            $dotenv->load();
        
            // Afficher les valeurs chargées depuis le .env
            $envContent = print_r($_ENV, true); // Obtenez les valeurs dans un format lisible

            $pdo = new PDO("mysql:host={$_ENV['DB_HOST']}", $_ENV['DB_USER'], $_ENV['DB_PASS']);
            $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :name');
            $stmt->execute(['name' => $_ENV['DB_NAME']]);
            $result = $stmt->fetch();
            if ($result && (!isset($data['overwrite']) || $data['overwrite'] !== 'true')) {
                $response = ['success' => true, 'message' => "La base de données existe déjà."];
            } else {
                $response = ['success' => false, 'message' => "Base de données prête pour installation."];
            }
            break;

        case 'create_db':
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }

            $dotenv = Dotenv\Dotenv::createImmutable($configDir);
            $dotenv->load();
        
            // Afficher les valeurs chargées depuis le .env
            $envContent = print_r($_ENV, true); // Obtenez les valeurs dans un format lisible

            $pdo = new PDO("mysql:host={$_ENV['DB_HOST']}", $_ENV['DB_USER'], $_ENV['DB_PASS']);
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $_ENV['DB_NAME'])) {
                throw new RuntimeException('Nom de base invalide.');
            }
            $quotedDatabase = '`' . str_replace('`', '``', $_ENV['DB_NAME']) . '`';
            $pdo->exec("CREATE DATABASE IF NOT EXISTS $quotedDatabase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE $quotedDatabase");
            $installSQL = file_get_contents('./install.sql');
            $pdo->exec($installSQL);
            $response = ['success' => true, 'message' => "Base de données créée et tables importées."];
            break;

        case 'create_admin':
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }
        
            $dotenv = Dotenv\Dotenv::createImmutable($configDir);
            $dotenv->load();
        
            // Afficher les valeurs chargées depuis le .env
            $envContent = print_r($_ENV, true); // Obtenez les valeurs dans un format lisible
        
            $pdo = new PDO("mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}", $_ENV['DB_USER'], $_ENV['DB_PASS']);
            $adminUsername = htmlspecialchars($data['admin_username']);
            $adminPasswordHash = password_hash(htmlspecialchars($data['admin_password']), PASSWORD_BCRYPT);
        
            // Insertion de l'utilisateur avec le champ 'password_hash' et 'is_admin' à 1
            $pdo->prepare("INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, 1)")
                ->execute([$adminUsername, $adminPasswordHash]);
        
            file_put_contents(__DIR__ . '/../.installed', date(DATE_ATOM), LOCK_EX);

            $response = ['success' => true, 'message' => "Compte administrateur créé."];
            break;
            
    }

    echo json_encode($response);
}
