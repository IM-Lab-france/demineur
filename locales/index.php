<?php

declare(strict_types=1);

$supportedLanguages = ['fr', 'en', 'de', 'nl'];
$language = strtolower(trim((string) ($_GET['lang'] ?? '')));

if (!in_array($language, $supportedLanguages, true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Unsupported language'], JSON_UNESCAPED_SLASHES);
    exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . $language . '.json';
if (!is_readable($path)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Catalogue unavailable'], JSON_UNESCAPED_SLASHES);
    exit;
}

$catalogue = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (in_array($language, ['de', 'nl'], true)) {
    $fallback = json_decode((string) file_get_contents(__DIR__ . '/en.json'), true, 512, JSON_THROW_ON_ERROR);
    $catalogue = array_replace($fallback, $catalogue);
}
$payload = json_encode($catalogue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$modifiedAt = max((int) filemtime($path), (int) filemtime(__DIR__ . '/en.json'));
$etag = '"' . hash('sha256', $payload) . '"';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=3600, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modifiedAt) . ' GMT');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

echo $payload;
