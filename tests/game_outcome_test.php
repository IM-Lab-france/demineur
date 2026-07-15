<?php
declare(strict_types=1);

require_once __DIR__ . '/../server.php';

function outcome_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$reflection = new ReflectionClass(MinesweeperServer::class);
$server = $reflection->newInstanceWithoutConstructor();

$playersProperty = $reflection->getProperty('players');
$playersProperty->setValue($server, [
    10 => ['id' => 1, 'username' => 'Alice'],
    20 => ['id' => 2, 'username' => 'Bob'],
]);

$outcome = $reflection->getMethod('determineGameOutcome');
$outcome->setAccessible(true);
$game = ['players' => [10, 20]];

[$winner, $name] = $outcome->invoke($server, $game, 10, false, null);
outcome_assert($winner === 20 && $name === 'Bob', 'Une explosion doit faire gagner l’adversaire.');

[$winner, $name] = $outcome->invoke($server, $game, null, true, null);
outcome_assert($winner === null && $name === 'Egalité', 'Un plateau sécurisé doit produire une égalité.');

$gamesProperty = $reflection->getProperty('games');
$allSafe = $reflection->getMethod('allSafeCellsRevealed');
$allSafe->setAccessible(true);

$gamesProperty->setValue($server, ['cascade' => ['board' => [
    [['mine' => false, 'revealed' => true], ['mine' => false, 'revealed' => true]],
    [['mine' => true, 'revealed' => false], ['mine' => false, 'revealed' => true]],
]]]);
outcome_assert($allSafe->invoke($server, 'cascade') === true, 'Une cascade révélant les dernières cases sûres doit terminer la partie.');

$gamesProperty->setValue($server, ['flags' => ['board' => [
    [['mine' => false, 'revealed' => true], ['mine' => true, 'revealed' => false, 'flagged' => true]],
]]]);
outcome_assert($allSafe->invoke($server, 'flags') === true, 'Une mine marquée peut rester cachée lorsque toutes les cases sûres sont révélées.');

$gamesProperty->setValue($server, ['unfinished' => ['board' => [
    [['mine' => false, 'revealed' => true], ['mine' => false, 'revealed' => false]],
]]]);
outcome_assert($allSafe->invoke($server, 'unfinished') === false, 'Une case sûre cachée doit empêcher la fin de partie.');

$moveMine = $reflection->getMethod('moveMineAwayFrom');
$moveMine->setAccessible(true);
$firstClickBoard = [
    [
        ['mine' => true, 'revealed' => false, 'flagged' => false, 'adjacentMines' => 1],
        ['mine' => false, 'revealed' => false, 'flagged' => false, 'adjacentMines' => 1],
    ],
    [
        ['mine' => false, 'revealed' => false, 'flagged' => false, 'adjacentMines' => 1],
        ['mine' => false, 'revealed' => false, 'flagged' => false, 'adjacentMines' => 1],
    ],
];
$arguments = [&$firstClickBoard, 0, 0];
$moveMine->invokeArgs($server, $arguments);
$mineCount = 0;
foreach ($firstClickBoard as $row) foreach ($row as $cell) if ($cell['mine']) $mineCount++;
outcome_assert($firstClickBoard[0][0]['mine'] === false, 'Le premier clic doit être protégé si une mine occupait la case.');
outcome_assert($mineCount === 1, 'Le déplacement du premier clic doit conserver le nombre de mines.');

class CancellationTestServer extends MinesweeperServer {
    public array $decremented = [];
    public function __construct() {}
    protected function decrementGamesPlayed($playerResourceId) {
        $this->decremented[] = $playerResourceId;
    }
    public function setTestGames(array $games): void {
        $this->games = $games;
    }
    public function cancelTestGame(string $gameId, int $disconnected, int $other): void {
        $this->cancelGameAfterDisconnect($gameId, $disconnected, $other);
    }
    public function hasTestGame(string $gameId): bool {
        return isset($this->games[$gameId]);
    }
}

$cancellationServer = new CancellationTestServer();
$cancellationServer->setTestGames(['cancelled' => ['players' => [10, 20]]]);
$cancellationServer->cancelTestGame('cancelled', 10, 20);
outcome_assert(!$cancellationServer->hasTestGame('cancelled'), 'Une partie interrompue doit être supprimée.');
outcome_assert($cancellationServer->decremented === [10, 20], 'Une partie interrompue ne doit rester comptée pour aucun joueur.');

echo "Tests des règles de fin de partie réussis.\n";
