<?php

declare(strict_types=1);

function i18n_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ECHEC: $message\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$catalogues = [];
foreach (['fr', 'en', 'de', 'nl'] as $language) {
    $path = "$root/locales/$language.json";
    i18n_assert(is_readable($path), "Catalogue $language introuvable.");
    $catalogues[$language] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

$frKeys = array_keys($catalogues['fr']);
$enKeys = array_keys($catalogues['en']);
sort($frKeys);
sort($enKeys);
i18n_assert($frKeys === $enKeys, 'Les catalogues français et anglais doivent avoir les mêmes clés.');
foreach (['de', 'nl'] as $language) {
    $unknownKeys = array_diff(array_keys($catalogues[$language]), $enKeys);
    i18n_assert($unknownKeys === [], "Le catalogue $language contient des clés inconnues.");
    i18n_assert(isset($catalogues[$language]['language.changing']), "Le catalogue $language doit traduire le changement de langue.");
}

foreach (['index.php', 'scores.html'] as $page) {
    $source = (string) file_get_contents("$root/$page");
    preg_match_all('/data-i18n(?:-html|-placeholder|-title|-aria-label)?="([^"]+)"/', $source, $matches);
    foreach ($matches[1] as $key) {
        i18n_assert(array_key_exists($key, $catalogues['fr']), "Clé $key absente, utilisée dans $page.");
    }
}

foreach (['script.js', 'chat-ui.js', 'scores.js'] as $script) {
    $source = (string) file_get_contents("$root/$script");
    preg_match_all('/\bt\([\'\"]([^\'\"]+)[\'\"]/', $source, $matches);
    foreach ($matches[1] as $key) {
        i18n_assert(array_key_exists($key, $catalogues['fr']), "Clé $key absente, utilisée dans $script.");
    }
}

echo "Catalogues i18n cohérents (" . count($frKeys) . " clés).\n";
