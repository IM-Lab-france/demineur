<?php
class Database {
    private $pdo;

    public function __construct() {
        $dotenv = Dotenv\Dotenv::createImmutable('/var/www/secure');
        $dotenv->load();

        $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'];
        $this->pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
