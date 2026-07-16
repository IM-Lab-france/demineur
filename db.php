<?php
class Database {
    private $pdo;
    private string $dsn;
    private string $username;
    private string $password;
    private int $generation = 0;

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

        $this->dsn = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4';
        $this->username = $username;
        $this->password = $password;
        $this->connect();
    }

    private function connect(): void {
        $this->pdo = new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->generation++;
    }

    private function isLostConnection(PDOException $e): bool {
        return (int) ($e->errorInfo[1] ?? 0) === 2006
            || (int) ($e->errorInfo[1] ?? 0) === 2013
            || str_contains(strtolower($e->getMessage()), 'server has gone away')
            || str_contains(strtolower($e->getMessage()), 'lost connection');
    }

    public function reconnectIfNeeded(): bool {
        $generation = $this->generation;
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            if (!$this->isLostConnection($e)) throw $e;
            $this->connect();
        }
        return $this->generation !== $generation;
    }

    // Fonction pour récupérer un utilisateur par son nom d'utilisateur
    public function getUserByUsername($username) {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username');
                $stmt->execute(['username' => $username]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                if ($attempt > 0 || !$this->isLostConnection($e)) throw $e;
                $this->connect();
            }
        }
        return false;
    }

    // Nouvelle méthode pour obtenir l'instance PDO
    public function getPDO() {
        return $this->pdo;
    }
} 
