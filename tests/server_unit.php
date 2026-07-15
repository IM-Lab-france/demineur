<?php
declare(strict_types=1);
require_once __DIR__ . '/../server.php';

function assert_true(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$reflection = new ReflectionClass(MinesweeperServer::class);
$server = $reflection->newInstanceWithoutConstructor();
$generate = $reflection->getMethod('generateBoard');
$generate->setAccessible(true);
$mask = $reflection->getMethod('maskMinesForPlayer');
$mask->setAccessible(true);

$board = $generate->invoke($server, 10, 10, 10);
assert_true(count($board) === 10 && count($board[0]) === 10, 'Dimensions invalides');
$mineCount = 0;
foreach ($board as $row) foreach ($row as $cell) if ($cell['mine']) $mineCount++;
assert_true($mineCount === 10, 'Nombre de mines invalide');

$masked = $mask->invoke($server, $board);
foreach ($masked as $row) foreach ($row as $cell) {
    assert_true(!array_key_exists('mine', $cell), 'Une mine a fui dans le plateau masqué');
    assert_true(!array_key_exists('adjacentMines', $cell), 'Un indice caché a fui');
}

echo "Tests serveur réussis.\n";
