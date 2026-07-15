<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("CLI uniquement.\n");
$source = __DIR__ . '/../ia/deminium/ia_accounts.json';
$targetDir = getenv('APP_CONFIG_DIR') ?: null;
if (!$targetDir) exit("Définissez APP_CONFIG_DIR.\n");
if (!is_file($source)) exit("Aucun ancien fichier à migrer.\n");
if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true)) exit("Impossible de créer le répertoire sécurisé.\n");
$target = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ia_accounts.json';
if (is_file($target)) exit("La destination existe déjà. Migration annulée.\n");
$data = json_decode((string) file_get_contents($source), true, 32, JSON_THROW_ON_ERROR);
file_put_contents($target, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
@chmod($target, 0640);
if (!unlink($source)) exit("Secret copié, mais suppression de l’ancien fichier impossible.\n");
echo "Secrets IA migrés vers $target.\n";
