<?php
class Database {
    private $pdo;

    private function getRequiredConfig(string $name): string {
        $value = $_ENV[$name] ?? getenv($name);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("Variable de configuration manquante: {$name}");
        }
        return $value;
    }

    public function __construct() {
        $configuredPath = getenv('APP_CONFIG_DIR') ?: null;
        $paths = array_filter([$configuredPath, __DIR__, '/var/www/secure']);
        $loaded = false;
        foreach ($paths as $path) {
            if (is_dir($path) && is_file($path . DIRECTORY_SEPARATOR . '.env')) {
                Dotenv\Dotenv::createImmutable($path)->safeLoad();
                $loaded = true;
                break;
            }
        }
        if (!$loaded && getenv('DB_HOST') === false && !isset($_ENV['DB_HOST'])) {
            throw new RuntimeException('Configuration de base de données introuvable.');
        }

        $host = $this->getRequiredConfig('DB_HOST');
        $database = $this->getRequiredConfig('DB_NAME');
        $username = $this->getRequiredConfig('DB_USER');
        $password = $_ENV['DB_PASS'] ?? getenv('DB_PASS');
        if (!is_string($password)) {
            throw new RuntimeException('Variable de configuration manquante: DB_PASS');
        }

        $dsn = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4';
        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    // Fonction pour récupérer un utilisateur par son nom d'utilisateur
    public function getUserByUsername($username) {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC); // Retourne un tableau associatif contenant les infos de l'utilisateur
    }

    // Nouvelle méthode pour obtenir l'instance PDO
    public function getPDO() {
        return $this->pdo;
    }
} 
