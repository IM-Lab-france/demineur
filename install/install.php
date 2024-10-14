<?php
session_start();

// Afficher les erreurs pour les connexions locales
ini_set('display_errors', 1);
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
            $server = $data['server'];
            $username = $data['username'];
            $password = $data['password'];
            $database = $data['database'];
            
            $envContent = "DB_HOST=$server\nDB_USER=$username\nDB_PASS=$password\nDB_NAME=$database\n";
            file_put_contents('../.env', $envContent);
            $response = [
                'success' => true,
                'message' => 'Fichier .env créé avec succès.',
            ];
            break;

        case 'verify_env':
            $response = [
                'success' => file_exists('../.env'),
                'message' => file_exists('../.env') ? 'Fichier .env vérifié.' : 'Le fichier .env est manquant.',
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
            
                $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
                $dotenv->load();
            
                // Afficher les valeurs chargées depuis le .env
                $envContent = print_r($_ENV, true); // Obtenez les valeurs dans un format lisible
            
                try {
                    $pdo = new PDO("mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}", $_ENV['DB_USER'], $_ENV['DB_PASS']);
                    $response = [
                        'success' => true, 
                        'message' => "Connexion à la base de données réussie.<br>Variables .env chargées:<br><pre>$envContent</pre>"
                    ];
                } catch (PDOException $e) {
                    $response = [
                        'success' => false, 
                        'message' => "Erreur de connexion : " . $e->getMessage() . "<br>Variables .env chargées:<br><pre>$envContent</pre>"
                    ];
                }
                break;

        case 'check_db_exists':
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }

            $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
            $dotenv->load();
        
            // Afficher les valeurs chargées depuis le .env
            $envContent = print_r($_ENV, true); // Obtenez les valeurs dans un format lisible

            $pdo = new PDO("mysql:host={$_ENV['DB_HOST']}", $_ENV['DB_USER'], $_ENV['DB_PASS']);
            $result = $pdo->query("SHOW DATABASES LIKE '{$_ENV['DB_NAME']}'")->fetch();
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

            $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
            $dotenv->load();
        
            // Afficher les valeurs chargées depuis le .env
            $envContent = print_r($_ENV, true); // Obtenez les valeurs dans un format lisible

            $pdo = new PDO("mysql:host={$_ENV['DB_HOST']}", $_ENV['DB_USER'], $_ENV['DB_PASS']);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS {$_ENV['DB_NAME']}");
            $pdo->exec("USE {$_ENV['DB_NAME']}");
            $installSQL = file_get_contents('./install.sql');
            $pdo->exec($installSQL);
            $response = ['success' => true, 'message' => "Base de données créée et tables importées."];
            break;

        case 'create_admin':
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }
        
            $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
            $dotenv->load();
        
            // Afficher les valeurs chargées depuis le .env
            $envContent = print_r($_ENV, true); // Obtenez les valeurs dans un format lisible
        
            $pdo = new PDO("mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']}", $_ENV['DB_USER'], $_ENV['DB_PASS']);
            $adminUsername = htmlspecialchars($data['admin_username']);
            $adminPasswordHash = password_hash(htmlspecialchars($data['admin_password']), PASSWORD_BCRYPT);
        
            // Insertion de l'utilisateur avec le champ 'password_hash' et 'is_admin' à 1
            $pdo->prepare("INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, 1)")
                ->execute([$adminUsername, $adminPasswordHash]);
        
            // Supprimer le répertoire "install" après création de l'admin
            $installDir = __DIR__;
            $deletionSuccess = deleteDirectory($installDir);

            $response = ['success' => true, 'message' => "Compte administrateur créé."];
            break;
            
    }

    echo json_encode($response);
}
