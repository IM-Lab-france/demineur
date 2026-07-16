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

$calculateFlagScores = $reflection->getMethod('calculateFlagScores');
$calculateFlagScores->setAccessible(true);
$scoredGame = ['players' => [10, 20], 'board' => [[
    ['mine' => true, 'flagged' => true, 'flaggedBy' => 1],
    ['mine' => false, 'flagged' => true, 'flaggedBy' => 1],
    ['mine' => false, 'flagged' => true, 'flaggedBy' => 2],
]]];
$scoreArguments = [&$scoredGame];
$flagScores = $calculateFlagScores->invokeArgs($server, $scoreArguments);
outcome_assert($flagScores[0]['score'] === 0, 'Un bon et un mauvais drapeau doivent produire un score nul.');
outcome_assert($flagScores[1]['score'] === -1, 'Un mauvais drapeau doit retirer un point à son propriétaire.');
outcome_assert($scoredGame['board'][0][1]['incorrectFlag'] === true, 'Un mauvais drapeau doit être marqué pour la révélation finale.');
outcome_assert($scoredGame['board'][0][0]['incorrectFlag'] === false, 'Un drapeau correctement placé ne doit pas être barré.');

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

$gamesProperty->setValue($server, ['resume' => [
    'players' => [10, 20],
    'inviter' => 10,
    'invitee' => 20,
    'currentTurn' => 10,
    'spectators' => [],
]]);
$replaceConnection = $reflection->getMethod('replaceGameConnection');
$replaceConnection->setAccessible(true);
$replaceConnection->invoke($server, 'resume', 10, 30);
$resumedGames = $gamesProperty->getValue($server);
outcome_assert($resumedGames['resume']['players'] === [30, 20], 'La reprise doit remplacer la connexion dans les joueurs.');
outcome_assert($resumedGames['resume']['inviter'] === 30, 'La reprise doit conserver le rôle d’inviteur.');
outcome_assert($resumedGames['resume']['currentTurn'] === 30, 'La reprise doit conserver le tour du joueur reconnecté.');

class TransferTestServer extends MinesweeperServer {
    public array $persisted = [];
    public function __construct() {
        $this->clients = new SplObjectStorage();
        $this->players = [
            10 => ['id' => 1, 'username' => 'Alice', 'session_token' => str_repeat('a', 64)],
            20 => ['id' => 2, 'username' => 'Bob', 'session_token' => str_repeat('b', 64)],
            30 => ['id' => 1, 'username' => 'Alice', 'session_token' => str_repeat('c', 64)],
        ];
        $this->games = ['transfer' => [
            'players' => [10, 20], 'inviter' => 10, 'invitee' => 20,
            'currentTurn' => 10, 'spectators' => [],
        ]];
        $this->pendingInvitations = ['pending' => [
            'inviter' => 20, 'invitee' => 10, 'createdAt' => time(),
        ]];
        $this->authSessions = [str_repeat('a', 64) => ['id' => 1]];
        $this->pendingReconnects = [];
        $this->actionWindows = [10 => []];
        $this->sessionValidationTimes = [10 => time(), 30 => time()];
        $this->logger = new class { public function info(...$arguments): void {} };
    }
    protected function persistGame(string $gameId): void { $this->persisted[] = $gameId; }
    protected function deletePersistedAuthSession(string $token): void {}
    public function transfer(): ?string { return $this->transferAuthenticatedConnection(10, 30, str_repeat('c', 64)); }
    public function state(): array { return [$this->players, $this->games, $this->pendingInvitations, $this->authSessions]; }
}

$transferServer = new TransferTestServer();
outcome_assert($transferServer->transfer() === 'transfer', 'Le transfert doit retrouver la partie active.');
[$transferredPlayers, $transferredGames, $transferredInvitations, $transferredSessions] = $transferServer->state();
outcome_assert(!isset($transferredPlayers[10]) && isset($transferredPlayers[30]), 'L’ancienne connexion doit perdre l’identité du joueur.');
outcome_assert($transferredGames['transfer']['players'] === [30, 20], 'La partie doit suivre le joueur sur le nouveau terminal.');
outcome_assert($transferredGames['transfer']['currentTurn'] === 30, 'Le tour courant doit être transféré.');
outcome_assert($transferredInvitations['pending']['invitee'] === 30, 'Les invitations en attente doivent suivre la nouvelle connexion.');
outcome_assert(!isset($transferredSessions[str_repeat('a', 64)]), 'L’ancien jeton de session doit être révoqué.');

class QuitTestConnection implements \Ratchet\ConnectionInterface {
    public array $messages = [];
    public function __construct(public int $resourceId) {}
    public function send($data) { $this->messages[] = json_decode((string) $data, true); return $this; }
    public function close() {}
}

class QuitTestServer extends MinesweeperServer {
    public bool $cancelled = false;
    public function __construct(QuitTestConnection $quitter, QuitTestConnection $opponent) {
        $this->clients = new SplObjectStorage();
        $this->clients->attach($quitter); $this->clients->attach($opponent);
        $this->players = [
            10 => ['id' => 1, 'username' => 'IA'],
            20 => ['id' => 2, 'username' => 'Bob'],
        ];
        $this->sessionValidationTimes = [10 => time(), 20 => time()];
        $this->games = ['quit' => ['players' => [10, 20]]];
        $this->logger = new class { public function info(...$arguments): void {} };
    }
    protected function cancelGameAfterDisconnect($gameId, $disconnectedPlayerId, $otherPlayerId): void {
        $this->cancelled = true;
        unset($this->games[$gameId]);
    }
    protected function broadcastConnectedPlayersList(int $sourceConnectionId): void {}
    public function quit(QuitTestConnection $connection): void {
        $this->handleQuitGame($connection, ['game_id' => 'quit']);
    }
}

$quitter = new QuitTestConnection(10);
$opponent = new QuitTestConnection(20);
$quitServer = new QuitTestServer($quitter, $opponent);
$quitServer->quit($quitter);
outcome_assert($quitServer->cancelled, 'Quitter doit annuler proprement la partie.');
outcome_assert(($quitter->messages[0]['type'] ?? null) === 'game_cancelled', 'L’IA doit recevoir la confirmation de sortie.');
outcome_assert(($opponent->messages[0]['type'] ?? null) === 'player_disconnected', 'L’adversaire doit être averti du départ volontaire.');

class UnlimitedFlagTestServer extends MinesweeperServer {
    public function __construct(QuitTestConnection $player, QuitTestConnection $opponent) {
        $this->clients = new SplObjectStorage();
        $this->clients->attach($player); $this->clients->attach($opponent);
        $this->players = [
            10 => ['id' => 1, 'username' => 'Alice'],
            20 => ['id' => 2, 'username' => 'Bob'],
        ];
        $this->games = ['flags' => [
            'players' => [10, 20], 'spectators' => [], 'currentTurn' => 10,
            'mineCount' => 1, 'moves' => 0,
            'board' => [[
                ['mine' => true, 'revealed' => false, 'flagged' => true, 'flaggedBy' => 1, 'adjacentMines' => 0],
                ['mine' => false, 'revealed' => false, 'flagged' => false, 'flaggedBy' => null, 'adjacentMines' => 1],
            ]],
        ]];
        $this->logger = new class { public function info(...$arguments): void {} };
    }
    protected function getValidatedGameAction(\Ratchet\ConnectionInterface $from, array $data, $requireTurn = true) { return ['flags', 0, 1]; }
    protected function persistGame(string $gameId): void {}
    protected function updateSpectators($gameId, ?array $maskedBoard = null): void {}
    public function addExtraFlag(QuitTestConnection $player): void { $this->handlePlaceFlag($player, []); }
    public function board(): array { return $this->games['flags']['board']; }
}

$flagPlayer = new QuitTestConnection(10);
$flagOpponent = new QuitTestConnection(20);
$unlimitedFlagServer = new UnlimitedFlagTestServer($flagPlayer, $flagOpponent);
$unlimitedFlagServer->addExtraFlag($flagPlayer);
outcome_assert($unlimitedFlagServer->board()[0][1]['flagged'] === true, 'Un joueur doit pouvoir dépasser le nombre de mines en drapeaux.');

echo "Tests des règles de fin de partie réussis.\n";
