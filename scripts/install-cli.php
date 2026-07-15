<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Cette commande est réservée au terminal.\n");
    exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Exécutez cette commande avec sudo.\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$options = getopt('', ['db-host:', 'db-name:', 'db-user:', 'admin-user:']);
$host = trim((string) ($options['db-host'] ?? '127.0.0.1'));
$database = trim((string) ($options['db-name'] ?? ''));
$dbUser = trim((string) ($options['db-user'] ?? ''));
$adminUser = trim((string) ($options['admin-user'] ?? ''));
function readSecret(string $prompt, string $environmentName): string {
    $configured = getenv($environmentName);
    if (is_string($configured) && $configured !== '') return $configured;
    fwrite(STDOUT, $prompt);
    system('stty -echo');
    try {
        return trim((string) fgets(STDIN));
    } finally {
        system('stty echo');
        fwrite(STDOUT, "\n");
    }
}

$dbPassword = readSecret('Mot de passe MySQL : ', 'DB_PASS');
$adminPassword = readSecret('Mot de passe du compte administrateur : ', 'ADMIN_PASS');

if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)
    || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)
    || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $dbUser)
    || !preg_match('/^[A-Za-z0-9_-]{3,32}$/', $adminUser)
    || strlen($dbPassword) < 12 || strlen($adminPassword) < 12) {
    fwrite(STDERR, "Paramètres invalides. DB_PASS et ADMIN_PASS (12 caractères minimum) doivent être fournis via l’environnement.\n");
    exit(2);
}

$pdo = new PDO("mysql:host={$host};charset=utf8mb4", $dbUser, $dbPassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
]);
$quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
$pdo->exec("CREATE DATABASE IF NOT EXISTS {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE {$quotedDatabase}");

$tableExists = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")->fetchColumn();
if ((int) $tableExists === 0) {
    $sql = file_get_contents(__DIR__ . '/../install/install.sql');
    if ($sql === false) throw new RuntimeException('Schéma SQL introuvable.');
    $pdo->exec($sql);
}

$stmt = $pdo->prepare('INSERT INTO users (username, password_hash, is_admin) VALUES (:username,:hash,1) '
    . 'ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), is_admin=1');
$stmt->execute(['username' => $adminUser, 'hash' => password_hash($adminPassword, PASSWORD_DEFAULT)]);

$secureDir = '/var/www/secure';
if (!is_dir($secureDir) && !mkdir($secureDir, 02770, true)) throw new RuntimeException('Création du répertoire sécurisé impossible.');
$totpKey = base64_encode(random_bytes(32));
$config = "DB_HOST={$host}\nDB_USER={$dbUser}\nDB_PASS={$dbPassword}\nDB_NAME={$database}\nAPP_TOTP_KEY={$totpKey}\n";
$temp = $secureDir . '/minesweeper-service.env.tmp';
file_put_contents($temp, $config, LOCK_EX);
chmod($temp, 0640);
rename($temp, $secureDir . '/minesweeper-service.env');
copy($secureDir . '/minesweeper-service.env', $secureDir . '/.env');
chmod($secureDir . '/.env', 0640);
file_put_contents(__DIR__ . '/../.installed', date(DATE_ATOM), LOCK_EX);
fwrite(STDOUT, "Installation terminée. Lancez scripts/install-websocket-service.sh.\n");
