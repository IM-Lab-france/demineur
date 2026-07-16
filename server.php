<?php

// server.php

    // Afficher les erreurs pour les connexions locales
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    //error_reporting(E_ALL);

date_default_timezone_set('Europe/Paris');

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Http\OriginCheck;
use Psr\Http\Message\RequestInterface;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

require __DIR__ . '/db.php';
require __DIR__ . '/src/AuthSessionRepository.php';
require __DIR__ . '/src/AccountTokenService.php';
require __DIR__ . '/src/MailService.php';
require __DIR__ . '/src/MailQueueRepository.php';


// Le logger est initialisé avant le serveur et la base afin de conserver aussi
// les erreurs de bootstrap. stdout est toujours actif et sera repris par journald.
$logger = new Logger('minesweeper_backend');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$logFilePath = getenv('LOG_PATH') ?: '/var/log/minesweeper/backend.log';
$logDirectory = dirname($logFilePath);
try {
    if ((!is_dir($logDirectory) && !@mkdir($logDirectory, 0770, true)) || !is_writable($logDirectory)) {
        throw new RuntimeException("Répertoire de logs non accessible: {$logDirectory}");
    }
    $rotatingHandler = new RotatingFileHandler($logFilePath, 14, Logger::DEBUG);
    $rotatingHandler->setFilenameFormat('{filename}-{date}', 'Y-m-d');
    $logger->pushHandler($rotatingHandler);
} catch (Throwable $e) {
    // Ne pas empêcher le backend de démarrer pour une erreur de fichier de log.
    $logger->warning('Journal fichier indisponible; utilisation de stdout uniquement.', [
        'log_path' => $logFilePath,
        'exception' => get_class($e),
        'error' => $e->getMessage(),
    ]);
}

set_exception_handler(function (Throwable $e) use ($logger): void {
    $logger->critical('Exception non interceptée: arrêt du backend.', [
        'exception' => get_class($e),
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
});

/**
 * OriginCheck de Ratchet 0.4.4 ne fournit aucun diagnostic lors d'un refus.
 * Cette variante normalise les hôtes et journalise la valeur effectivement reçue.
 */
class LoggedOriginCheck extends OriginCheck {
    private Logger $originLogger;

    public function __construct(MessageComponentInterface $component, array $allowed, Logger $logger) {
        parent::__construct($component, array_values(array_unique(array_map(
            static fn(string $origin): string => strtolower(trim($origin)),
            $allowed
        ))));
        $this->originLogger = $logger;
    }

    public function onOpen(ConnectionInterface $conn, RequestInterface $request = null) {
        $originHeader = $request ? trim($request->getHeaderLine('Origin')) : '';
        $originHost = strtolower((string) (parse_url($originHeader, PHP_URL_HOST) ?: $originHeader));
        $allowed = in_array($originHost, $this->allowedOrigins, true);

        $this->originLogger->info('Contrôle de l’origine WebSocket.', [
            'origin_header' => $originHeader,
            'origin_host' => $originHost,
            'allowed_origins' => $this->allowedOrigins,
            'allowed' => $allowed,
        ]);

        if (!$allowed) {
            return parent::onOpen($conn, $request);
        }

        return $this->_component->onOpen($conn, $request);
    }
}



class MinesweeperServer implements MessageComponentInterface {
    protected $clients;
    protected $players;      // Liste des joueurs connectés
    protected $games;        // Liste des parties en cours

    protected $defaultSize = 10;
    protected $difficulty = 0.10;
    protected $defaultNbMines;
    protected $pendingInvitations = []; // Stocker les invitations en attente
    protected $authAttempts = [];
    protected $registrationAttempts = [];
    protected $authSessions = [];
    protected $sessionValidationTimes = [];
    protected $pendingReconnects = [];
    protected $recoverableGames = [];
    protected $actionWindows = [];
    protected $recordMoveStatement;
    protected $pendingMoves = [];
    protected $moveFlushScheduled = false;
    protected $moveSqlTotalMs = 0.0;
    protected $moveSqlBatches = 0;
    protected $actionCount = 0;
    protected $websocketErrors = 0;
    protected $allowedGridSizes = ['10x10', '20x20', '30x30'];
    protected $allowedDifficulties = [10, 15, 22];
    protected $startedAt;

    protected $logger; // Ajout d'une propriété pour le logger
    protected $db;
    protected $authSessionRepository;

    public function __construct($logger) {
        $this->clients = new \SplObjectStorage;
        $this->startedAt = time();
        $this->players = [];
        $this->games = [];
        $this->defaultNbMines = intval($this->defaultSize * $this->defaultSize * $this->difficulty);
        $this->logger = $logger; // Initialisation du logger
        $this->logger->info('Initialisation de la connexion à la base de données.');
        $this->db = new Database();
        $this->authSessionRepository = new AuthSessionRepository($this->db->getPDO());
        $this->loadRecoverableGames();
        register_shutdown_function(function (): void {
            if ($this->pendingMoves) $this->flushPendingMoves();
        });
        $this->logger->info('Backend du jeu initialisé.', [
            'php_version' => PHP_VERSION,
            'pid' => getmypid(),
        ]);
        

    }

    public function onOpen(ConnectionInterface $conn) {
        if (count($this->clients) >= 200) {
            $this->logger->warning('Connexion refusée : capacité maximale atteinte.');
            $conn->close();
            return;
        }
        $this->clients->attach($conn);
        $this->logger->info('Connexion WebSocket ouverte.', [
            'connection_id' => $conn->resourceId,
            'remote_address' => $conn->remoteAddress ?? 'unknown',
            'client_count' => count($this->clients),
        ]);
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $this->actionCount++;
        if (strlen($msg) > 65536) {
            $this->sendError($from, 'Message trop volumineux.');
            $from->close();
            return;
        }
        $data = json_decode($msg, true);
        if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
            $this->sendError($from, 'Message invalide.');
            return;
        }

        // Le processus WebSocket vit plus longtemps que le délai d'inactivité
        // MySQL. Renouveler aussi les objets qui conservaient l'ancien PDO.
        try {
            if ($this->db->reconnectIfNeeded()) {
                $this->authSessionRepository = new AuthSessionRepository($this->db->getPDO());
                $this->recordMoveStatement = null;
                $this->logger->info('Connexion MySQL rétablie après expiration.');
            }
        } catch (Throwable $e) {
            $this->logger->error('Base de données indisponible.', ['error' => $e->getMessage()]);
            $this->sendError($from, 'Base de données temporairement indisponible.');
            return;
        }

        // Ne jamais écrire les identifiants reçus dans les journaux.
        $loggedData = $data;
        unset($loggedData['password']);
        unset($loggedData['sessionToken']);
        unset($loggedData['email']);
        $this->logger->info('Action backend reçue.', [
            'action' => $data['type'],
            'connection_id' => $from->resourceId,
            'authenticated' => $this->isAuthenticated($from),
            'game_id' => $data['game_id'] ?? $data['gameId'] ?? null,
            'payload' => $loggedData,
        ]);

        $publicMessages = ['register', 'login', 'resume_session', 'logout', 'ping', 'get_scores', 'get_player_count', 'get_active_games'];
        if (!in_array($data['type'], $publicMessages, true) && !$this->isAuthenticated($from)) {
            $this->sendError($from, 'Authentification requise.');
            return;
        }

        try {
        switch ($data['type']) {
            case 'register':
                $this->handleRegister($from, $data);
                break;
            
            case 'login':
                $this->handleLogin($from, $data);
                break;
            case 'resume_session':
                $this->handleResumeSession($from, $data);
                break;

            case 'invite':
                $this->handleInvite($from, $data);
                break;

            case 'accept_invite':
                $this->handleAcceptInvite($from, $data);
                break;

            case 'decline_invite':
                $this->handleDeclineInvite($from, $data);
                break;

            case 'reveal_cell':
                $this->handleRevealCell($from, $data);
                break;

            case 'place_flag':
                $this->handlePlaceFlag($from, $data);
                break;

            case 'quit_game':
                $this->handleQuitGame($from, $data);
                break;

            case 'ready_for_new_game':
                $this->handleReadyForNewGame($from, $data);
                break;

            case 'logout':
                $this->handleLogout($from, $data);
                break;

            case 'refresh_players':
                $this->sendConnectedPlayersList($from);
                break;
            case 'get_scores':
                $this->handleGetScores($from, $data);
                break;
            case 'get_player_count':
                $this->handleGetPlayerCount($from);
                break;
            case 'ping':
                $from->send(json_encode([
                    'type' => 'pong',
                    'message' => 'Pong reçu'
                ]));
                $this->logger->info("OUT: Pong envoyé à {$from->resourceId}");
                break;
            case 'get_active_games':
                $this->handleGetActiveGames($from);
                break;
            case 'get_game_state':
                if (!isset($data['gameId']) || !is_string($data['gameId'])) {
                    $this->sendError($from, 'Identifiant de partie invalide.');
                    break;
                }
                $this->handleGetGameState($from, $data['gameId']);
                break;
            case 'add_spectator':
                $this->addSpectator($from, $data);
                break;
            case 'leave_spectator':
                $this->removeSpectator($from, $data);
                break;
            default:
                $this->sendError($from, 'Action inconnue.', ['action' => $data['type']]);
                break;
        }
        } catch (Throwable $e) {
            $this->logger->error('Échec pendant le traitement d’une action.', [
                'action' => $data['type'],
                'connection_id' => $from->resourceId,
                'game_id' => $data['game_id'] ?? $data['gameId'] ?? null,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendError($from, 'Erreur interne pendant le traitement de l’action.');
        }
    }

    public function onClose(ConnectionInterface $from) {
        // Supprimer le joueur des clients connectés
        $this->clients->detach($from);
        $disconnectedPlayerId = $from->resourceId;
    
        $this->logger->info("Connexion fermée pour le joueur {$disconnectedPlayerId}");
    
        $playerInfo = $this->players[$disconnectedPlayerId] ?? null;
        $reconnectPending = false;

        // Vérifier si le joueur déconnecté est en partie
        foreach ($this->games as $gameId => $game) {
            if (in_array($disconnectedPlayerId, $game['players'])) {
                // L'autre joueur dans la partie
                $otherPlayerId = $game['players'][0] === $disconnectedPlayerId ? $game['players'][1] : $game['players'][0];
    
                $sessionToken = $playerInfo['session_token'] ?? null;
                if (is_string($sessionToken) && isset($this->authSessions[$sessionToken])) {
                    $reconnectPending = true;
                    $this->schedulePlayerReconnect($sessionToken, $gameId, $disconnectedPlayerId, $otherPlayerId);
                    continue;
                }

                // Sans session récupérable, annuler immédiatement la partie.
                $otherPlayerConnection = $this->getConnectionFromPlayerId($otherPlayerId);
                if ($otherPlayerConnection) {
                    $otherPlayerConnection->send(json_encode([
                        'type' => 'player_disconnected',
                        'message' => 'Votre adversaire s\'est déconnecté. La partie est annulée.'
                    ]));
    
                    $this->logger->info("OUT:" . json_encode([
                        'type' => 'player_disconnected',
                        'message' => 'Votre adversaire s\'est déconnecté. La partie est annulée.'
                    ]));
                }
    
                $this->cancelGameAfterDisconnect($gameId, $disconnectedPlayerId, $otherPlayerId);
            } elseif (isset($game['spectators'])) {
                $this->games[$gameId]['spectators'] = array_values(array_filter(
                    $game['spectators'],
                    fn($id) => $id !== $disconnectedPlayerId
                ));
            }
        }
        foreach ($this->pendingInvitations as $invitationId => $invitation) {
            if ($invitation['inviter'] === $disconnectedPlayerId || $invitation['invitee'] === $disconnectedPlayerId) {
                unset($this->pendingInvitations[$invitationId]);
            }
        }
    
        // Supprimer le joueur de la liste des joueurs connectés
        if (!$reconnectPending) unset($this->players[$disconnectedPlayerId]);
        unset($this->actionWindows[$disconnectedPlayerId]);
        unset($this->sessionValidationTimes[$disconnectedPlayerId]);
    
        // Envoyer la liste mise à jour des joueurs connectés à tous les autres clients
        $this->sendConnectedPlayersList($from);
    }

    protected function schedulePlayerReconnect(string $token, string $gameId, int $oldResourceId, int $otherPlayerId): void {
        if (isset($this->pendingReconnects[$token]['timer'])) {
            \React\EventLoop\Loop::cancelTimer($this->pendingReconnects[$token]['timer']);
        }
        $otherConnection = $this->getConnectionFromPlayerId($otherPlayerId);
        if ($otherConnection) {
            $otherConnection->send(json_encode([
                'type' => 'player_reconnecting',
                'message' => 'Votre adversaire a perdu la connexion. Reprise possible pendant 30 secondes.',
                'timeout' => 30,
            ]));
        }
        $timer = \React\EventLoop\Loop::addTimer(30, function () use ($token, $gameId, $oldResourceId, $otherPlayerId): void {
            $pending = $this->pendingReconnects[$token] ?? null;
            if (!$pending || $pending['old_resource_id'] !== $oldResourceId) return;
            unset($this->pendingReconnects[$token]);
            if (isset($this->games[$gameId])) {
                $otherConnection = $this->getConnectionFromPlayerId($otherPlayerId);
                if ($otherConnection) {
                    $otherConnection->send(json_encode([
                        'type' => 'player_disconnected',
                        'message' => 'Votre adversaire ne s’est pas reconnecté. La partie est annulée.',
                    ]));
                }
                $this->cancelGameAfterDisconnect($gameId, $oldResourceId, $otherPlayerId);
            }
            unset($this->players[$oldResourceId], $this->actionWindows[$oldResourceId]);
            $this->broadcastConnectedPlayersList($oldResourceId);
        });
        $this->pendingReconnects[$token] = [
            'game_id' => $gameId,
            'old_resource_id' => $oldResourceId,
            'other_player_id' => $otherPlayerId,
            'timer' => $timer,
        ];
    }

    public function onError(ConnectionInterface $from, \Exception $e) {
        $this->websocketErrors++;
        $this->logger->error('Erreur de connexion WebSocket.', [
            'connection_id' => $from->resourceId,
            'exception' => get_class($e),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        $from->close();
    }

    protected function isAuthenticated(ConnectionInterface $connection) {
        $player = $this->players[$connection->resourceId] ?? null;
        if (!$player) return false;
        $lastValidation = $this->sessionValidationTimes[$connection->resourceId] ?? 0;
        if (time() - $lastValidation < 15) return true;
        $token = $player['session_token'] ?? '';
        try {
            if (!is_string($token) || !$this->authSessionRepository->findValid($token)) {
                if (is_string($token)) unset($this->authSessions[$token]);
                unset($this->sessionValidationTimes[$connection->resourceId]);
                $connection->close();
                return false;
            }
            $this->sessionValidationTimes[$connection->resourceId] = time();
        } catch (Throwable $e) {
            $this->logger->warning('Validation périodique de session impossible.', ['error' => $e->getMessage()]);
        }
        return true;
    }

    protected function getAuthAttemptKey(ConnectionInterface $connection, $username) {
        $address = $connection->remoteAddress ?? 'unknown';
        return hash('sha256', strtolower($username) . '|' . $address);
    }

    protected function sendError(ConnectionInterface $connection, $message, array $context = []) {
        $this->logger->warning('Action backend refusée.', $context + [
            'connection_id' => $connection->resourceId,
            'reason' => $message,
        ]);
        $connection->send(json_encode(['type' => 'error', 'message' => $message]));
    }

    protected function getValidatedGameAction(ConnectionInterface $from, array $data, $requireTurn = true) {
        if (!$this->isAuthenticated($from)) {
            $this->sendError($from, 'Authentification requise.');
            return null;
        }
        $now = microtime(true);
        $window = $this->actionWindows[$from->resourceId] ?? ['start' => $now, 'count' => 0];
        if ($now - $window['start'] >= 1.0) $window = ['start' => $now, 'count' => 0];
        if (++$window['count'] > 20) {
            $this->actionWindows[$from->resourceId] = $window;
            $this->sendError($from, 'Trop d’actions. Ralentissez.');
            return null;
        }
        $this->actionWindows[$from->resourceId] = $window;
        if (!isset($data['game_id']) || !is_string($data['game_id']) || !isset($this->games[$data['game_id']])) {
            $this->sendError($from, 'Partie introuvable.');
            return null;
        }
        $gameId = $data['game_id'];
        if (!in_array($from->resourceId, $this->games[$gameId]['players'], true)) {
            $this->sendError($from, 'Vous ne participez pas à cette partie.');
            return null;
        }
        if ($requireTurn && $this->games[$gameId]['currentTurn'] !== $from->resourceId) {
            $this->sendError($from, 'Ce n\'est pas votre tour de jouer.');
            return null;
        }
        if (!isset($data['x'], $data['y']) || filter_var($data['x'], FILTER_VALIDATE_INT) === false || filter_var($data['y'], FILTER_VALIDATE_INT) === false) {
            $this->sendError($from, 'Coordonnées invalides.');
            return null;
        }
        $x = (int) $data['x'];
        $y = (int) $data['y'];
        if (!isset($this->games[$gameId]['board'][$x][$y])) {
            $this->sendError($from, 'Coordonnées hors de la grille.');
            return null;
        }
        return [$gameId, $x, $y];
    }

    // Fonction pour inscrire un spectateur à une partie
    protected function addSpectator(ConnectionInterface $from, $data) {
        $gameId = $data['gameId'] ?? '';
    
        if (isset($this->games[$gameId])) {
            if (!empty($this->games[$gameId]['isPrivate'])) {
                $this->sendError($from, 'Cette partie est privée.');
                return;
            }
            if ($this->isPlayerInGame($from->resourceId) || $this->isSpectatorInGame($from->resourceId)) {
                $this->sendError($from, 'Vous participez déjà à une partie.');
                return;
            }
            // Ajouter le spectateur à la liste des spectateurs de cette partie
            if (!in_array($from->resourceId, $this->games[$gameId]['spectators'] ?? [], true)) {
                $this->games[$gameId]['spectators'][] = $from->resourceId;
            }
    
            // Récupérer la taille de la grille pour la partie
            $board = $this->games[$gameId]['board'];
            $gridWidth = count($board);
            $gridHeight = count($board[0]);
    
            // Récupérer les noms des joueurs
            $player1Id = $this->games[$gameId]['players'][0];
            $player2Id = $this->games[$gameId]['players'][1];
            $player1Name = $this->players[$player1Id]['username'];
            $player2Name = $this->players[$player2Id]['username'];
    
            $from->send(json_encode([
                'type' => 'spectator_join_success',
                'game_id' => $gameId,
                'message' => 'Vous suivez maintenant la partie ' . $gameId,
                'gridSize' => ['width' => $gridWidth, 'height' => $gridHeight], // Envoi de la taille de la grille
                'board' => $this->maskMinesForPlayer($board),
                'mineCount' => $this->games[$gameId]['mineCount'],
                'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username'],
                'players' => [
                    'player1' => $player1Name,
                    'player2' => $player2Name
                ] // Envoi des noms des joueurs
            ]));
        } else {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Partie introuvable.'
            ]));
        }
    }

    protected function removeSpectator(ConnectionInterface $from, array $data): void {
        $gameId = (string) ($data['gameId'] ?? '');
        if (isset($this->games[$gameId]['spectators'])) {
            $this->games[$gameId]['spectators'] = array_values(array_filter(
                $this->games[$gameId]['spectators'],
                fn($resourceId) => $resourceId !== $from->resourceId
            ));
        }
        $from->send(json_encode(['type' => 'spectator_left', 'game_id' => $gameId]));
    }


    // Fonction pour envoyer les mises à jour aux spectateurs
    protected function updateSpectators($gameId, ?array $maskedBoard = null) {
        if (isset($this->games[$gameId]['spectators'])) {
            $maskedBoard ??= $this->maskMinesForPlayer($this->games[$gameId]['board']);
            foreach ($this->games[$gameId]['spectators'] as $spectatorId) {
                $connection = $this->getConnectionFromPlayerId($spectatorId);
                if ($connection) {
                    // Masquer les mines et adjacentMines pour les spectateurs également
                    $connection->send(json_encode([
                        'type' => 'update_board',
                        'board' => $maskedBoard,
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                        ,'mineCount' => $this->games[$gameId]['mineCount']
                    ]));
                }
            }
        }
    }


    // Fonction pour récupérer l'état d'une partie spécifique
    protected function handleGetGameState(ConnectionInterface $from, $gameId) {
        if (isset($this->games[$gameId])) {
            $game = $this->games[$gameId];
            $isPlayer = in_array($from->resourceId, $game['players'], true);
            $isSpectator = in_array($from->resourceId, $game['spectators'] ?? [], true);
            if (!$isPlayer && !$isSpectator) {
                $this->sendError($from, 'Accès à cette partie refusé.');
                return;
            }
            $gameState = [
                'board' => $this->maskMinesForPlayer($game['board']),
                'currentTurn' => $game['currentTurn'],
                'moves' => $game['moves']
            ];
            
            // Récupérer l'ID du joueur qui doit jouer
            $currentPlayerId = $this->games[$gameId]['currentTurn'];
            $currentPlayerName = $this->players[$currentPlayerId]['username']; // Récupérer le nom du joueur
    
            // Envoyer l'état du jeu au client avec le nom du joueur actuel
            $from->send(json_encode([
                'type' => 'game_state',
                'state' => $gameState,
                'currentPlayer' => $currentPlayerName // Ajouter le nom du joueur actuel
            ]));
        } else {
            // Envoyer un message d'erreur si la partie n'existe pas
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Partie introuvable.'
            ]));
        }
    }
    
    protected function handleGetActiveGames(ConnectionInterface $from) {
        $activeGames = [];
    
        foreach ($this->games as $gameId => $game) {
            if (!empty($game['isPrivate'])) continue;
            $playerNames = array_map(function ($playerId) {
                return $this->players[$playerId]['username']; // Récupérer les noms des joueurs
            }, $game['players']);
    
            // Inclure l'ID de la partie
            $activeGames[] = [
                'gameId' => $gameId, // Envoi de l'ID de la partie
                'players' => $playerNames,
                'gridSize' => count($game['board']) . 'x' . count($game['board'][0]),
                'spectatorCount' => count($game['spectators'] ?? [])
            ];
        }
    
        // Envoyer la liste des parties au client
        $from->send(json_encode([
            'type' => 'active_games',
            'games' => $activeGames // Utiliser un tableau d'objets plutôt que des clés
        ]));
    }

    protected function handleGetPlayerCount(ConnectionInterface $from) {
        $connectedPlayersCount = count($this->players);
        $gamesInProgress = count($this->games);
    
        $from->send(json_encode([
            'type' => 'player_count',
            'connectedPlayers' => $connectedPlayersCount,
            'gamesInProgress' => $gamesInProgress
        ]));
    
        $this->logger->info("OUT: " . json_encode([
            'type' => 'player_count',
            'connectedPlayers' => $connectedPlayersCount,
            'gamesInProgress' => $gamesInProgress
        ]));
    }

    protected function handleRegister(ConnectionInterface $from, $data) {
        $now = microtime(true);
        $this->registrationAttempts = array_values(array_filter(
            $this->registrationAttempts,
            fn(float $timestamp): bool => $now - $timestamp < 60
        ));
        if (count($this->registrationAttempts) >= 10) {
            $from->send(json_encode(['type' => 'register_failed', 'message' => 'Trop d’inscriptions. Réessayez dans une minute.']));
            return;
        }
        $this->registrationAttempts[] = $now;
        $username = isset($data['username']) ? trim($data['username']) : '';
        $password = $data['password'] ?? '';
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')), 'UTF-8');

        if (!preg_match('/^[\p{L}\p{N}_-]{3,32}$/u', $username)) {
            $from->send(json_encode(['type' => 'register_failed', 'message' => 'Le nom doit contenir 3 à 32 lettres, chiffres, tirets ou underscores.']));
            return;
        }
        if (!is_string($password) || strlen($password) < 10 || strlen($password) > 128) {
            $from->send(json_encode(['type' => 'register_failed', 'message' => 'Le mot de passe doit contenir entre 10 et 128 caractères.']));
            return;
        }
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $from->send(json_encode(['type' => 'register_failed', 'message' => 'Adresse e-mail invalide.']));
            return;
        }
        try {
            $mailService = new MailService();
        } catch (Throwable $e) {
            $this->logger->error('Inscription indisponible : configuration e-mail absente.');
            $from->send(json_encode(['type' => 'register_failed', 'message' => 'L’inscription par e-mail est temporairement indisponible.']));
            return;
        }
    
        // Utilisation de la base de données pour vérifier si le nom d'utilisateur existe déjà
        $db = $this->db;
        $stmt = $db->getPDO()->prepare("SELECT id FROM users WHERE username=:username OR email=:email LIMIT 1");
        $stmt->execute(['username' => $username, 'email' => $email]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($existingUser) {
            // L'utilisateur avec ce login existe déjà
            $from->send(json_encode([
                'type' => 'register_failed',
                'message' => 'Ce nom d’utilisateur ou cette adresse e-mail est déjà utilisé.'
            ]));
            $this->logger->error("OUT:" . json_encode([
                'type' => 'register_failed',
                'message' => 'Nom d\'utilisateur déjà pris.'
            ]));
        } else {
            // L'utilisateur n'existe pas encore, on peut procéder à l'enregistrement
    
            // Hacher le mot de passe avec bcrypt
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
            // Insérer le nouvel utilisateur dans la base de données
            $stmt = $db->getPDO()->prepare("
                INSERT INTO users (username, email, email_verified_at, password_hash, created_at)
                VALUES (:username, :email, NULL, :password_hash, NOW())
            ");
            $registrationCommitted = false;
            try {
                $db->getPDO()->beginTransaction();
                $stmt->execute(['username' => $username, 'email' => $email, 'password_hash' => $passwordHash]);
                $userId = (int) $db->getPDO()->lastInsertId();
                $token = (new AccountTokenService($db->getPDO()))->issue($userId, 'verify_email', 86400);
                $db->getPDO()->commit();
                $registrationCommitted = true;
                (new MailQueueRepository($db->getPDO()))->enqueue($email, 'verify_email', $username, $token);
            } catch (PDOException $e) {
                if ($db->getPDO()->inTransaction()) $db->getPDO()->rollBack();
                $from->send(json_encode(['type' => 'register_failed', 'message' => 'Impossible de créer ce compte.']));
                return;
            } catch (Throwable $e) {
                if (!$registrationCommitted) {
                    if ($db->getPDO()->inTransaction()) $db->getPDO()->rollBack();
                    $from->send(json_encode(['type' => 'register_failed', 'message' => 'Impossible de créer ce compte.']));
                    return;
                }
                $this->logger->error('E-mail de validation non envoyé.', ['user_id' => $userId ?? null, 'exception' => get_class($e)]);
                $from->send(json_encode(['type' => 'register_success', 'message' => 'Compte créé, mais l’e-mail n’a pas pu être envoyé. Utilisez le renvoi de validation.']));
                return;
            }
    
            // Confirmation de l'enregistrement
            $from->send(json_encode([
                'type' => 'register_success',
                'message' => 'Compte créé. Consultez votre e-mail pour le valider.'
            ]));
            $this->logger->info("OUT:" . json_encode([
                'type' => 'register_success',
                'message' => 'Enregistrement réussi. Vous pouvez vous connecter.'
            ]));
            
        }
    }
    
    protected function handleLogin(ConnectionInterface $from, $data) {
        if ($this->isAuthenticated($from)) {
            $from->send(json_encode(['type' => 'login_failed', 'message' => 'Cette connexion est déjà authentifiée.']));
            return;
        }
        $username = isset($data['username']) ? trim($data['username']) : '';
        $password = $data['password'] ?? '';
        $attemptKey = $this->getAuthAttemptKey($from, $username);
        $attempt = $this->authAttempts[$attemptKey] ?? ['count' => 0, 'since' => time()];
        if (time() - $attempt['since'] > 60) {
            $attempt = ['count' => 0, 'since' => time()];
        }
        if ($attempt['count'] >= 5) {
            $from->send(json_encode(['type' => 'login_failed', 'message' => 'Trop de tentatives. Réessayez dans une minute.']));
            return;
        }
        if (!is_string($password) || $username === '' || strlen($username) > 32 || strlen($password) > 128) {
            $attempt['count']++;
            $this->authAttempts[$attemptKey] = $attempt;
            $from->send(json_encode(['type' => 'login_failed', 'message' => 'Identifiants invalides.']));
            return;
        }
    
        // Utilisation de la base de données pour récupérer les informations de l'utilisateur
        $db = $this->db;
        $user = $db->getUserByUsername($username);
    
        // Vérifier si l'utilisateur existe et si le mot de passe correspond
        if ($user && password_verify($password, $user['password_hash'])) {
            if (!empty($user['is_disabled'])) {
                $attempt['count']++;
                $this->authAttempts[$attemptKey] = $attempt;
                $from->send(json_encode(['type' => 'login_failed', 'message' => 'Ce compte est désactivé.']));
                return;
            }
            if (!empty($user['email']) && empty($user['email_verified_at'])) {
                $attempt['count']++;
                $this->authAttempts[$attemptKey] = $attempt;
                $from->send(json_encode(['type' => 'login_failed', 'message' => 'Validez votre adresse e-mail avant de vous connecter.']));
                return;
            }
            unset($this->authAttempts[$attemptKey]);
            $existingResourceId = null;
            foreach ($this->players as $resourceId => $playerInfo) {
                if ($resourceId !== $from->resourceId && $playerInfo['username'] === $username) {
                    $existingResourceId = (int) $resourceId;
                    break;
                }
            }
            $sessionToken = bin2hex(random_bytes(32));
            $this->authSessions[$sessionToken] = [
                'id' => $user['id'],
                'username' => $username,
                'expires_at' => time() + 43200,
            ];
            $this->persistAuthSession($sessionToken, (int) $user['id'], time() + 43200);
            // Connexion réussie
            $this->players[$from->resourceId] = [
                'id' => $user['id'],
                'username' => $username,
                'session_token' => $sessionToken,
            ];
            $this->sessionValidationTimes[$from->resourceId] = time();

            $transferredGameId = null;
            if ($existingResourceId !== null) {
                $transferredGameId = $this->transferAuthenticatedConnection(
                    $existingResourceId,
                    $from->resourceId,
                    $sessionToken
                );
            }
    
            $from->send(json_encode([
                'type' => 'login_success',
                'playerId' => $user['id'],  // Envoi de l'ID du joueur
                'username' => $username,
                'sessionToken' => $sessionToken,
                'sessionTransferred' => $existingResourceId !== null,
                'players' => $this->getConnectedPlayers()
            ]));
            if ($existingResourceId !== null) {
                $this->sendPendingInvitationsForConnection($from);
            }
            if ($transferredGameId !== null && isset($this->games[$transferredGameId])) {
                $this->sendResumedGame($from, $transferredGameId, 'Votre adversaire a transféré sa partie sur un autre terminal.');
            } else {
                $this->tryRestoreGamesForUser((int) $user['id']);
            }

            $this->logger->info("OUT:" . json_encode([
                'type' => 'login_success',
                'playerId' => $user['id'],  // Envoi de l'ID du joueur
                'username' => $username,
                'session_transferred' => $existingResourceId !== null,
                'players' => $this->getConnectedPlayers()
            ]));
    
            // Envoyer la liste des joueurs connectés
            $this->sendConnectedPlayersList($from);
        } else {
            $attempt['count']++;
            $this->authAttempts[$attemptKey] = $attempt;
            // Échec de la connexion
            $from->send(json_encode([
                'type' => 'login_failed',
                'message' => 'Login ou mot de passe incorrect.'
            ]));

            $this->logger->error("OUT:" . json_encode([
                'type' => 'login_failed',
                'message' => 'Login ou mot de passe incorrect.'
            ]));
        }
    }

    /**
     * Remplace une connexion authentifiée sans déclencher la logique d'abandon
     * exécutée par onClose. L'appelant doit avoir vérifié le mot de passe.
     */
    protected function transferAuthenticatedConnection(int $oldResourceId, int $newResourceId, string $newToken): ?string {
        $oldPlayer = $this->players[$oldResourceId] ?? null;
        if (!$oldPlayer) return null;

        $oldToken = $oldPlayer['session_token'] ?? null;
        $transferredGameId = null;
        foreach ($this->games as $gameId => $game) {
            if (in_array($oldResourceId, $game['players'], true)) {
                $this->replaceGameConnection($gameId, $oldResourceId, $newResourceId);
                $this->persistGame($gameId);
                $transferredGameId = $gameId;
            } elseif (isset($game['spectators']) && in_array($oldResourceId, $game['spectators'], true)) {
                $this->games[$gameId]['spectators'] = array_values(array_map(
                    fn($id) => $id === $oldResourceId ? $newResourceId : $id,
                    $game['spectators']
                ));
            }
        }

        foreach ($this->pendingInvitations as &$invitation) {
            if ($invitation['inviter'] === $oldResourceId) $invitation['inviter'] = $newResourceId;
            if ($invitation['invitee'] === $oldResourceId) $invitation['invitee'] = $newResourceId;
        }
        unset($invitation);

        if (is_string($oldToken)) {
            if (isset($this->pendingReconnects[$oldToken]['timer'])) {
                \React\EventLoop\Loop::cancelTimer($this->pendingReconnects[$oldToken]['timer']);
            }
            unset($this->pendingReconnects[$oldToken], $this->authSessions[$oldToken]);
            if ($oldToken !== $newToken) $this->deletePersistedAuthSession($oldToken);
        }

        // Retirer l'ancienne identité laisse l'ancien terminal connecté au
        // transport, mais sans accès au compte ni à la partie transférée.
        unset(
            $this->players[$oldResourceId],
            $this->actionWindows[$oldResourceId],
            $this->sessionValidationTimes[$oldResourceId]
        );
        $oldConnection = $this->getConnectionFromPlayerId($oldResourceId);
        if ($oldConnection) {
            $oldConnection->send(json_encode([
                'type' => 'session_transferred',
                'message' => 'Votre session a été transférée vers un autre terminal.',
            ]));
        }
        $this->logger->info('Session utilisateur transférée.', [
            'user_id' => $oldPlayer['id'],
            'old_connection_id' => $oldResourceId,
            'new_connection_id' => $newResourceId,
            'game_id' => $transferredGameId,
        ]);
        return $transferredGameId;
    }

    protected function sendPendingInvitationsForConnection(ConnectionInterface $connection): void {
        foreach ($this->pendingInvitations as $invitationId => $invitation) {
            if ($invitation['invitee'] !== $connection->resourceId) continue;
            $inviter = $this->players[$invitation['inviter']] ?? null;
            if (!$inviter) continue;
            $connection->send(json_encode([
                'type' => 'invite',
                'inviter' => $inviter['username'],
                'invitationId' => $invitationId,
            ]));
        }
    }

    protected function handleResumeSession(ConnectionInterface $from, $data) {
        $token = $data['sessionToken'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $from->send(json_encode(['type' => 'resume_failed']));
            return;
        }

        // Toujours relire la session persistante : une réinitialisation de mot
        // de passe ou une désactivation doit révoquer même un jeton en mémoire.
        $session = $this->loadPersistedAuthSession($token);
        if (!$session || $session['expires_at'] < time()) {
            unset($this->authSessions[$token]);
            $this->deletePersistedAuthSession($token);
            $from->send(json_encode(['type' => 'resume_failed']));
            return;
        }

        $oldResourceId = null;
        foreach ($this->players as $resourceId => $player) {
            if ($resourceId !== $from->resourceId && ($player['session_token'] ?? null) === $token) {
                $oldResourceId = $resourceId;
                $oldConnection = $this->getConnectionFromPlayerId($resourceId);
                if ($oldConnection) $oldConnection->close();
            }
        }

        $this->players[$from->resourceId] = [
            'id' => $session['id'],
            'username' => $session['username'],
            'session_token' => $token,
        ];
        $this->sessionValidationTimes[$from->resourceId] = time();
        $this->authSessions[$token]['expires_at'] = time() + 43200;
        $this->persistAuthSession($token, (int) $session['id'], time() + 43200);

        $resumedGameId = null;
        if (isset($this->pendingReconnects[$token])) {
            $pending = $this->pendingReconnects[$token];
            \React\EventLoop\Loop::cancelTimer($pending['timer']);
            unset($this->pendingReconnects[$token]);
            $oldResourceId = $pending['old_resource_id'];
            $resumedGameId = $pending['game_id'];
            if (isset($this->games[$resumedGameId])) {
                $this->replaceGameConnection($resumedGameId, $oldResourceId, $from->resourceId);
            }
        }
        if ($oldResourceId !== null) {
            unset($this->players[$oldResourceId], $this->actionWindows[$oldResourceId]);
        }

        $from->send(json_encode([
            'type' => 'login_success',
            'playerId' => $session['id'],
            'username' => $session['username'],
            'sessionToken' => $token,
            'players' => $this->getConnectedPlayers(),
        ]));
        if ($resumedGameId !== null && isset($this->games[$resumedGameId])) {
            $this->sendResumedGame($from, $resumedGameId, 'Votre adversaire s’est reconnecté. La partie reprend.');
        }
        $this->sendConnectedPlayersList($from);
    }

    protected function replaceGameConnection(string $gameId, int $oldResourceId, int $newResourceId): void {
        $game = &$this->games[$gameId];
        foreach ($game['players'] as &$playerId) {
            if ($playerId === $oldResourceId) $playerId = $newResourceId;
        }
        unset($playerId);
        if ($game['inviter'] === $oldResourceId) $game['inviter'] = $newResourceId;
        if ($game['invitee'] === $oldResourceId) $game['invitee'] = $newResourceId;
        if ($game['currentTurn'] === $oldResourceId) $game['currentTurn'] = $newResourceId;
        if (isset($game['spectators'])) {
            foreach ($game['spectators'] as &$spectatorId) {
                if ($spectatorId === $oldResourceId) $spectatorId = $newResourceId;
            }
            unset($spectatorId);
        }
    }

    protected function sendResumedGame(ConnectionInterface $connection, string $gameId, string $opponentMessage): void {
        if (!isset($this->games[$gameId])) return;
        $game = $this->games[$gameId];
        $connection->send(json_encode([
            'type' => 'game_resumed',
            'game_id' => $gameId,
            'board' => $this->maskMinesForPlayer($game['board']),
            'currentPlayer' => $this->players[$game['currentTurn']]['username'],
            'mineCount' => $game['mineCount'],
        ]));
        $otherId = $game['players'][0] === $connection->resourceId ? $game['players'][1] : $game['players'][0];
        $otherConnection = $this->getConnectionFromPlayerId($otherId);
        if ($otherConnection) {
            $otherConnection->send(json_encode([
                'type' => 'player_reconnected',
                'message' => $opponentMessage,
            ]));
        }
    }

    protected function handleLogout(ConnectionInterface $from, array $data = []) {
        // Si le joueur est déjà déconnecté ou introuvable
        if (!isset($this->players[$from->resourceId])) {
            $sessionToken = $data['sessionToken'] ?? '';
            if (is_string($sessionToken) && preg_match('/^[a-f0-9]{64}$/', $sessionToken)) {
                unset($this->authSessions[$sessionToken]);
                $this->deletePersistedAuthSession($sessionToken);
            }
            $from->send(json_encode(['type' => 'logout_success', 'message' => 'Déconnexion réussie']));
            return;
        }
    
        $username = $this->players[$from->resourceId]['username'];
        $sessionToken = $this->players[$from->resourceId]['session_token'] ?? null;
        if ($sessionToken !== null) {
            unset($this->authSessions[$sessionToken]);
            $this->deletePersistedAuthSession($sessionToken);
        }
        foreach ($this->games as $gameId => $game) {
            if (!in_array($from->resourceId, $game['players'], true)) continue;
            $otherPlayerId = $game['players'][0] === $from->resourceId ? $game['players'][1] : $game['players'][0];
            $otherConnection = $this->getConnectionFromPlayerId($otherPlayerId);
            if ($otherConnection) {
                $otherConnection->send(json_encode([
                    'type' => 'player_disconnected',
                    'message' => 'Votre adversaire s’est déconnecté. La partie est annulée.',
                ]));
            }
            $this->cancelGameAfterDisconnect($gameId, $from->resourceId, $otherPlayerId);
        }
        // Retirer le joueur de la liste des joueurs connectés
        unset($this->players[$from->resourceId]);
        unset($this->sessionValidationTimes[$from->resourceId]);
    
        // Informer les autres joueurs que la liste des joueurs disponibles a changé
        
        $this->sendConnectedPlayersList($from);  // Mettre à jour la liste des joueurs
        
    
        // Déconnecter la session du joueur
        $from->send(json_encode([
            'type' => 'logout_success',
            'message' => 'Déconnexion réussie',
            'username' => $username
        ]));

        $this->logger->info("OUT:" . json_encode([
            'type' => 'logout_success',
            'message' => 'Déconnexion réussie'
        ]));
        
        $this->logger->info("Joueur {$from->resourceId} déconnecté.");
    }
    
    protected function handleInvite(ConnectionInterface $from, $data) {
        if (!isset($data['invitee'], $data['gridSize'], $data['difficulty']) ||
            filter_var($data['invitee'], FILTER_VALIDATE_INT) === false ||
            !in_array($data['gridSize'], $this->allowedGridSizes, true) ||
            !in_array((int) $data['difficulty'], $this->allowedDifficulties, true)) {
            $this->sendError($from, 'Paramètres d\'invitation invalides.');
            return;
        }
        $inviteeId = (int) $data['invitee'];
        $fromUser = $this->players[$from->resourceId];
        if ($inviteeId === (int) $fromUser['id']) {
            $this->sendError($from, 'Vous ne pouvez pas vous inviter vous-même.');
            return;
        }
        if ($this->isPlayerInGame($from->resourceId) || $this->isSpectatorInGame($from->resourceId) || $this->hasPendingInvitation($from->resourceId)) {
            $this->sendError($from, 'Vous êtes déjà en partie ou avez une invitation en attente.');
            return;
        }

        foreach ($this->clients as $client) {
            if (isset($this->players[$client->resourceId]) && $this->players[$client->resourceId]['id'] === $inviteeId) {
                if ($this->isPlayerInGame($client->resourceId) || $this->isSpectatorInGame($client->resourceId) || $this->hasPendingInvitation($client->resourceId)) {
                    $this->sendError($from, 'Ce joueur n’est plus disponible.');
                    return;
                }
                // Générer un numéro d'invitation unique
                $invitationId = 'inv_' . bin2hex(random_bytes(16));

                // Stocker les informations d'invitation (taille et difficulté)
                $this->pendingInvitations[$invitationId] = [
                    'inviter' => $from->resourceId,
                    'invitee' => $client->resourceId,
                    'gridSize' => $data['gridSize'],
                    'difficulty' => $data['difficulty']
                    ,'isPrivate' => !isset($data['isPrivate']) || $data['isPrivate'] !== false
                    ,'createdAt' => time()
                ];

                // Envoyer l'invitation avec le numéro unique à l'invité
                $client->send(json_encode([
                    'type' => 'invite',
                    'inviter' => $fromUser['username'],
                    'invitationId' => $invitationId // Transmettre le numéro d'invitation
                ]));

                $this->logger->info("OUT:" . json_encode([
                    'type' => 'invite',
                    'inviter' => $fromUser['username'],
                    'invitationId' => $invitationId // Transmettre le numéro d'invitation
                ]));

                return;
            }
        }

        // Si l'invité n'est pas trouvé
        $from->send(json_encode([
            'type' => 'invite_failed',
            'message' => 'Joueur non trouvé'
        ]));

        $this->logger->error("OUT:" . json_encode([
            'type' => 'invite_failed',
            'message' => 'Joueur non trouvé'
        ]));
    }

    protected function handleAcceptInvite(ConnectionInterface $from, $data) {
        $invitationId = $data['invitationId'] ?? '';
        $this->logger->info('Tentative de démarrage d’une partie.', [
            'connection_id' => $from->resourceId,
            'invitation_id' => $invitationId,
            'invitation_exists' => isset($this->pendingInvitations[$invitationId]),
        ]);
        
        // Si l'invitation est valide
        if (isset($this->pendingInvitations[$invitationId])) {
            $invitation = $this->pendingInvitations[$invitationId];
            if (time() - ($invitation['createdAt'] ?? 0) > 60) {
                unset($this->pendingInvitations[$invitationId]);
                $this->sendError($from, 'Cette invitation a expiré.');
                return;
            }
            if (!$this->isAuthenticated($from) || $invitation['invitee'] !== $from->resourceId || !isset($this->players[$invitation['inviter']])) {
                $this->sendError($from, 'Cette invitation ne vous appartient pas ou a expiré.');
                return;
            }
            if ($this->isPlayerInGame($from->resourceId) || $this->isSpectatorInGame($from->resourceId) ||
                $this->isPlayerInGame($invitation['inviter']) || $this->isSpectatorInGame($invitation['inviter'])) {
                unset($this->pendingInvitations[$invitationId]);
                $this->sendError($from, 'Un des joueurs n’est plus disponible.');
                return;
            }
            $gridSize = $invitation['gridSize'];
            $difficulty = intval($invitation['difficulty']);
    
            list($width, $height) = explode('x', $gridSize);
    
            // Calculer le nombre de mines
            $numMines = intval(($width * $height) * ($difficulty / 100));
    
            // Générer le plateau
            $board = $this->generateBoard($width, $height, $numMines);
    
            // Récupérer les resourceId des deux joueurs
            $inviterResourceId = $invitation['inviter'];  // resourceId du joueur qui a envoyé l'invitation
            $inviteeResourceId = $from->resourceId;       // resourceId du joueur qui a accepté l'invitation
    
            // Mettre a jour la base de donnée pour le nombre de parties jouées
            $this->handleGameStart($inviterResourceId, $inviteeResourceId);

            // Tirer un nombre aléatoire entre 0 et 1
            $randomNumber = random_int(0, 100) / 100;
    
            // Affecter $firstPlay en fonction du tirage
            if ($randomNumber > 0.5) {
                $firstPlay = $inviterResourceId;  // Le joueur qui a lancé la partie joue en premier
            } else {
                $firstPlay = $inviteeResourceId;  // Le joueur qui a accepté l'invitation joue en premier
            }
    
            // Créer la partie
            $gameId = bin2hex(random_bytes(16));
            $this->games[$gameId] = [
                'players' => [$inviteeResourceId, $inviterResourceId],  // Stocker les deux resourceId
                'inviter' => $inviterResourceId,
                'invitee' => $inviteeResourceId,
                'board' => $board,
                'currentTurn' => $firstPlay,
                'moves' => 0,
                'mineCount' => $numMines,
                'isPrivate' => (bool) ($invitation['isPrivate'] ?? true),
                'spectators' => []
            ];
            $this->persistGame($gameId);
            $this->writeStatusSnapshot();
            $this->logger->info('Partie créée.', [
                'game_id' => $gameId,
                'inviter_connection_id' => $inviterResourceId,
                'invitee_connection_id' => $inviteeResourceId,
                'grid_size' => $gridSize,
                'difficulty' => $difficulty,
                'mine_count' => $numMines,
                'first_player_connection_id' => $firstPlay,
            ]);
    
            // Envoyer les informations de la partie aux deux joueurs
            foreach ($this->games[$gameId]['players'] as $playerId) {
                $connection = $this->getConnectionFromPlayerId($playerId);
                if ($connection) {
                    // Utilisez une fonction pour masquer les mines et adjacentMines
                    $maskedBoard = $this->maskMinesForPlayer($board);
    
                    // Récupérer le nom du joueur qui doit jouer en premier
                    $currentPlayerName = $this->players[$this->games[$gameId]['currentTurn']]['username'];
    
                    $connection->send(json_encode([
                        'type' => 'game_start',
                        'game_id' => $gameId,
                        'board' => $maskedBoard,  // N'envoyez pas les mines
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $currentPlayerName  // Envoyer le nom du joueur qui commence
                        ,'mineCount' => $numMines
                    ]));
                }
            }
    
            // Supprimer l'invitation une fois acceptée
            unset($this->pendingInvitations[$invitationId]);
        } else {
            $this->sendError($from, 'Invitation introuvable ou expirée.', [
                'invitation_id' => $invitationId,
            ]);
        }
    }

    protected function handleDeclineInvite(ConnectionInterface $from, $data) {
        $invitationId = $data['invitationId'] ?? '';
        if (!isset($this->pendingInvitations[$invitationId]) || $this->pendingInvitations[$invitationId]['invitee'] !== $from->resourceId) {
            $this->sendError($from, 'Invitation introuvable.');
            return;
        }
        $inviter = $this->pendingInvitations[$invitationId]['inviter'];
        unset($this->pendingInvitations[$invitationId]);
        $connection = $this->getConnectionFromPlayerId($inviter);
        if ($connection) $connection->send(json_encode(['type' => 'invite_declined']));
    }

    protected function handleGameStart($player1ResourceId, $player2ResourceId) {
        $db = $this->db;
        $pdo = $db->getPDO();
    
        // Récupérer les IDs utilisateurs à partir des resourceIds
        $player1Id = $this->players[$player1ResourceId]['id'];
        $player2Id = $this->players[$player2ResourceId]['id'];
        $this->logger->info('Mise à jour des compteurs avant démarrage.', [
            'player_1_id' => $player1Id,
            'player_2_id' => $player2Id,
        ]);
    
        // Incrémenter le compteur de parties jouées pour les deux joueurs
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE users SET games_played = games_played + 1 WHERE id = :id");
            $stmt->execute(['id' => $player1Id]);
            $stmt->execute(['id' => $player2Id]);
            $pdo->commit();
            $this->logger->info('Compteurs de parties mis à jour.', [
                'player_1_id' => $player1Id,
                'player_2_id' => $player2Id,
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->logger->error('Échec de la transaction de démarrage de partie.', [
                'player_1_id' => $player1Id,
                'player_2_id' => $player2Id,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function handleGameOver($game, $gameId, $winnerResourceId = null, $isDraw = false, $losingCell = null, array $flagScores = []) {
        $db = $this->db;
        $pdo = $db->getPDO();
    
        // Récupérer les IDs des joueurs et des détails du jeu
        $inviterId = $this->players[$game['inviter']]['id'];
        $inviteeId = $this->players[$game['invitee']]['id'];
        $winnerId = $winnerResourceId ? $this->players[$winnerResourceId]['id'] : null;
    
        // Compter le nombre de coups joués
        $moves = $game['moves'];
    
        // Zone d'explosion (8 cases autour de la mine qui a explosé)
        $explosionArea = [];
        if ($losingCell) {
            $x = $losingCell['x'];
            $y = $losingCell['y'];
            $explosionArea = $this->getExplosionArea($game['board'], $x, $y);
        }
    
        

        // Enregistrer les détails de la partie
        $pdo->beginTransaction();
        try {
        $ratings = [];
        $ratingStmt = $pdo->prepare('SELECT id, elo_rating FROM users WHERE id IN (:inviter_id, :invitee_id) FOR UPDATE');
        $ratingStmt->execute(['inviter_id' => $inviterId, 'invitee_id' => $inviteeId]);
        foreach ($ratingStmt->fetchAll(PDO::FETCH_ASSOC) as $ratingRow) {
            $ratings[(int) $ratingRow['id']] = (int) $ratingRow['elo_rating'];
        }
        if (!isset($ratings[$inviterId], $ratings[$inviteeId])) {
            throw new RuntimeException('Classements Elo des joueurs introuvables.');
        }
        $eloChanges = $this->calculateEloChanges($ratings[$inviterId], $ratings[$inviteeId], $winnerId, $inviterId, $inviteeId, $isDraw);

        $stmt = $pdo->prepare("
            INSERT INTO game_details
                (game_id, inviter_id, invitee_id, winner_id, moves, explosion_area, flag_scores, inviter_elo_change, invitee_elo_change)
            VALUES
                (:game_id, :inviter_id, :invitee_id, :winner_id, :moves, :explosion_area, :flag_scores, :inviter_elo_change, :invitee_elo_change)
        ");
        $stmt->execute([
            ':game_id' => $gameId,
            ':inviter_id' => $inviterId,
            ':invitee_id' => $inviteeId,
            ':winner_id' => $winnerId,
            ':moves' => $moves,
            ':explosion_area' => json_encode($explosionArea),
            ':flag_scores' => json_encode($flagScores, JSON_THROW_ON_ERROR),
            ':inviter_elo_change' => $eloChanges[$inviterId]['change'],
            ':invitee_elo_change' => $eloChanges[$inviteeId]['change'],
        ]);
    
        if ($isDraw) {
            foreach ($game['players'] as $playerResourceId) {
                $playerId = $this->players[$playerResourceId]['id'];
                $stmt = $pdo->prepare("UPDATE users SET games_draw = games_draw + 1 WHERE id = :id");
                $stmt->bindParam(':id', $playerId);
                $stmt->execute();
            }
        } else {
            $stmt = $pdo->prepare("UPDATE users SET games_won = games_won + 1 WHERE id = :id");
            $stmt->bindParam(':id', $winnerId);
            $stmt->execute();
        }
        $ratingUpdate = $pdo->prepare('UPDATE users SET elo_rating=:rating, elo_games=elo_games+1 WHERE id=:id');
        foreach ($eloChanges as $playerId => $elo) {
            $ratingUpdate->execute(['rating' => $elo['after'], 'id' => $playerId]);
        }
        $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $eloChanges;
    }

    protected function calculateEloChanges(int $ratingA, int $ratingB, ?int $winnerUserId, int $userAId, int $userBId, bool $isDraw, int $kFactor = 32): array {
        $expectedA = 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
        $scoreA = $isDraw ? 0.5 : ($winnerUserId === $userAId ? 1.0 : 0.0);
        $changeA = (int) round($kFactor * ($scoreA - $expectedA));
        $changeB = -$changeA;
        return [
            $userAId => ['before' => $ratingA, 'change' => $changeA, 'after' => $ratingA + $changeA],
            $userBId => ['before' => $ratingB, 'change' => $changeB, 'after' => $ratingB + $changeB],
        ];
    }

    // Fonction pour récupérer les cases autour de la cellule qui a explosé
    private function getExplosionArea($board, $x, $y, $distance = 1) {
        $area = [];
        for ($i = -$distance; $i <= $distance; $i++) {
            for ($j = -$distance; $j <= $distance; $j++) {
                // Calcul des positions absolues
                $newX = $x + $i;
                $newY = $y + $j;
    
                // Calcul des positions relatives
                $relativeX = $i;
                $relativeY = $j;
    
                // Vérifier si la cellule est dans les limites du plateau
                if (isset($board[$newX][$newY])) {
                    // La cellule existe, on utilise les valeurs réelles
                    $area[] = [
                        'x' => $relativeX,
                        'y' => $relativeY,
                        'adjacentMines' => $board[$newX][$newY]['revealed'] ? $board[$newX][$newY]['adjacentMines'] : -1
                    ];
                } else {
                    // La cellule n'existe pas, on ajoute une valeur fictive
                    $area[] = [
                        'x' => $relativeX,
                        'y' => $relativeY,
                        'adjacentMines' => -2
                    ];
                }
            }
        }
        return $area;
    }

    // Fonction pour révéler les cellules adjacentes de manière récursive si elles ont 0 mines adjacentes
    private function revealAdjacentCells(&$board, $x, $y) {
        $directions = [
            [-1, -1], [-1, 0], [-1, 1], // Haut gauche, haut, haut droite
            [0, -1],         [0, 1],    // Gauche, droite
            [1, -1], [1, 0], [1, 1]     // Bas gauche, bas, bas droite
        ];

        foreach ($directions as $dir) {
            $newX = $x + $dir[0];
            $newY = $y + $dir[1];

            // Vérifiez si la cellule est dans les limites du plateau
            if (isset($board[$newX][$newY])) {
                $cell = $board[$newX][$newY];

                // Révéler la cellule si elle n'est pas encore révélée et n'est pas une mine
                if (!$cell['revealed'] && !$cell['mine']) {
                    $board[$newX][$newY]['revealed'] = true;

                    // Si la cellule adjacente a 0 mines, continuer la révélation en cascade
                    if ($cell['adjacentMines'] == 0) {
                        $this->revealAdjacentCells($board, $newX, $newY);
                    }
                }
            }
        }
    }

    protected function handleRevealCell(ConnectionInterface $from, $data) {
        $action = $this->getValidatedGameAction($from, $data);
        if ($action === null) return;
        [$gameId, $x, $y] = $action;
    
        // Révéler la cellule
        $cell = &$this->games[$gameId]['board'][$x][$y];
        if ($cell['flagged']) {
            $this->sendError($from, 'Retirez le drapeau avant de révéler cette case.');
            return;
        }
        if ($cell['revealed']) {
            $this->sendError($from, 'Cette case est déjà révélée.');
            return;
        }
        if (!$cell['revealed']) {
            // Le premier coup d'une partie est toujours sûr.
            if ($this->games[$gameId]['moves'] === 0 && $cell['mine']) {
                $this->moveMineAwayFrom($this->games[$gameId]['board'], $x, $y);
                $cell = &$this->games[$gameId]['board'][$x][$y];
            }
            $cell['revealed'] = true;
    
            $this->games[$gameId]['moves']++;

            // Si la cellule est une mine, terminer la partie
            if ($cell['mine']) {
                $explosionArea = $this->getExplosionArea($this->games[$gameId]['board'], $x, $y,3);
                $this->recordMove($gameId, $x, $y, $explosionArea, 1);
                $this->endGame($from, $gameId, $from->resourceId, ['x' => $x, 'y' => $y]);
                return;
            }
    
            // Révéler en cascade si aucune mine adjacente
            if ($cell['adjacentMines'] == 0) {
                $this->revealAdjacentCells($this->games[$gameId]['board'], $x, $y);
            }
    
            // Enregistrer le coup joué (pas d'explosion)
            $explosionArea = $this->getExplosionArea($this->games[$gameId]['board'], $x, $y,3);
            $this->recordMove($gameId, $x, $y, $explosionArea, 0);

            // Un plateau entièrement sécurisé sans explosion termine la partie à égalité.
            if ($this->allSafeCellsRevealed($gameId)) {
                // Les deux joueurs ont sécurisé le plateau sans explosion.
                $this->endGame($from, $gameId, null, null, true);
                return;
            }
    
            // Passer au prochain joueur
            $this->games[$gameId]['currentTurn'] = $this->getNextPlayer($gameId);
            $this->persistGame($gameId);
            $maskedBoard = $this->maskMinesForPlayer($this->games[$gameId]['board']);

            // Envoyer la mise à jour du plateau à tous les joueurs
            foreach ($this->games[$gameId]['players'] as $playerId) {
                $connection = $this->getConnectionFromPlayerId($playerId);
                if ($connection) {
                    $connection->send(json_encode([
                        'type' => 'update_board',
                        'board' => $maskedBoard,
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                        ,'mineCount' => $this->games[$gameId]['mineCount']
                    ]));
                }
            }
            $this->updateSpectators($gameId, $maskedBoard);
        }
    }

    protected function recordMove($gameId, $x, $y, $explosionArea, $result) {
        $this->pendingMoves[] = [
            ':game_id' => $gameId,
            ':x' => $x,
            ':y' => $y,
            ':explosion_area' => json_encode($explosionArea, JSON_THROW_ON_ERROR),
            ':result' => $result,
        ];
        if (!$this->moveFlushScheduled) {
            $this->moveFlushScheduled = true;
            \React\EventLoop\Loop::addTimer(0.001, function (): void { $this->flushPendingMoves(); });
        }
    }

    protected function flushPendingMoves(): void {
        $this->moveFlushScheduled = false;
        $moves = array_splice($this->pendingMoves, 0, 100);
        if (!$moves) return;
        if ($this->recordMoveStatement === null) {
            $this->recordMoveStatement = $this->db->getPDO()->prepare(
                "INSERT INTO game_moves (game_id, x, y, explosion_area, result) VALUES (:game_id, :x, :y, :explosion_area, :result)"
            );
        }
        $startedAt = microtime(true);
        $pdo = $this->db->getPDO();
        $pdo->beginTransaction();
        try {
            foreach ($moves as $move) $this->recordMoveStatement->execute($move);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->logger->error('Échec de l’historisation groupée des coups.', ['count' => count($moves), 'error' => $e->getMessage()]);
        }
        $durationMs = (microtime(true) - $startedAt) * 1000;
        $this->moveSqlTotalMs += $durationMs;
        $this->moveSqlBatches++;
        if ($durationMs >= 50) {
            $this->logger->warning('Enregistrement SQL lent pour un coup.', [
                'move_count' => count($moves),
                'duration_ms' => round($durationMs, 2),
            ]);
        }
        if ($this->pendingMoves && !$this->moveFlushScheduled) {
            $this->moveFlushScheduled = true;
            \React\EventLoop\Loop::addTimer(0.001, function (): void { $this->flushPendingMoves(); });
        }
    }

    // Masque l'information sur les mines pour l'envoi au client
    protected function maskMinesForPlayer($board) {
        $maskedBoard = [];
        foreach ($board as $row) {
            $maskedRow = [];
            foreach ($row as $cell) {
                if ($cell['revealed']) {
                    // Si la cellule est révélée, on envoie toutes les informations
                    $maskedRow[] = [
                        'revealed' => true,
                        'flagged' => $cell['flagged'],
                        'flaggedBy' => $cell['flaggedBy'] ?? null,
                        'adjacentMines' => $cell['adjacentMines']
                    ];
                } else {
                    // Si la cellule n'est pas révélée, on n'envoie que l'état 'flagged' et 'revealed'
                    $maskedRow[] = [
                        'revealed' => false,
                        'flagged' => $cell['flagged'],
                        'flaggedBy' => $cell['flaggedBy'] ?? null
                        // Pas de 'adjacentMines' envoyé ici
                    ];
                }
            }
            $maskedBoard[] = $maskedRow;
        }
        return $maskedBoard;
    }

    protected function handlePlaceFlag(ConnectionInterface $from, $data) {
        $action = $this->getValidatedGameAction($from, $data);
        if ($action === null) return;
        [$gameId, $x, $y] = $action;
        if ($this->games[$gameId]['board'][$x][$y]['revealed']) {
            $this->sendError($from, 'Impossible de marquer une case révélée.');
            return;
        }

        $playerSlot = array_search($from->resourceId, $this->games[$gameId]['players'], true);
        $playerSlot = $playerSlot === false ? null : $playerSlot + 1;
        $existingOwner = $this->games[$gameId]['board'][$x][$y]['flaggedBy'] ?? null;
        if ($this->games[$gameId]['board'][$x][$y]['flagged'] && $existingOwner !== null && $existingOwner !== $playerSlot) {
            $this->sendError($from, 'Vous ne pouvez retirer que vos propres drapeaux.');
            return;
        }

        $placingFlag = !$this->games[$gameId]['board'][$x][$y]['flagged'];
        $this->games[$gameId]['board'][$x][$y]['flagged'] = $placingFlag;
        if ($placingFlag) {
            $this->games[$gameId]['board'][$x][$y]['flaggedBy'] = $playerSlot;
        } else {
            $this->games[$gameId]['board'][$x][$y]['flaggedBy'] = null;
        }
        $this->persistGame($gameId);
        $maskedBoard = $this->maskMinesForPlayer($this->games[$gameId]['board']);

        foreach ($this->games[$gameId]['players'] as $playerId) {
            $connection = $this->getConnectionFromPlayerId($playerId);
            if ($connection) {
                $connection->send(json_encode([
                    'type' => 'update_board',
                    'board' => $maskedBoard,
                    'mineCount' => $this->games[$gameId]['mineCount'],
                    'turn' => $this->games[$gameId]['currentTurn'],
                    'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                ]));

                $this->logger->info("OUT:" . json_encode([
                    'type' => 'update_board',
                    'board' => '[masked]',
                    'mineCount' => $this->games[$gameId]['mineCount'],
                    'turn' => $this->games[$gameId]['currentTurn'],
                    'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                ]));
            }
        }
        $this->updateSpectators($gameId, $maskedBoard);
    }

    protected function handleQuitGame(ConnectionInterface $from, array $data): void {
        if (!$this->isAuthenticated($from)) {
            $this->sendError($from, 'Authentification requise.');
            return;
        }
        $requestedGameId = (string) ($data['game_id'] ?? '');
        $gameId = null;
        foreach ($this->games as $candidateId => $game) {
            if (in_array($from->resourceId, $game['players'], true)
                && ($requestedGameId === '' || (string) $candidateId === $requestedGameId)) {
                $gameId = $candidateId;
                break;
            }
        }
        if ($gameId === null) {
            $from->send(json_encode(['type' => 'game_cancelled', 'message' => 'Aucune partie active à quitter.']));
            return;
        }

        $game = $this->games[$gameId];
        $otherPlayerId = $game['players'][0] === $from->resourceId ? $game['players'][1] : $game['players'][0];
        $from->send(json_encode([
            'type' => 'game_cancelled',
            'game_id' => $gameId,
            'message' => 'Vous avez quitté la partie.',
        ]));
        $otherConnection = $this->getConnectionFromPlayerId($otherPlayerId);
        if ($otherConnection) {
            $otherConnection->send(json_encode([
                'type' => 'player_disconnected',
                'game_id' => $gameId,
                'message' => 'Votre adversaire a quitté la partie. La partie est annulée.',
            ]));
        }
        $this->cancelGameAfterDisconnect($gameId, $from->resourceId, $otherPlayerId);
        $this->broadcastConnectedPlayersList($from->resourceId);
        $this->logger->info('Partie quittée volontairement.', [
            'game_id' => $gameId,
            'player_connection_id' => $from->resourceId,
        ]);
    }

    protected function handleReadyForNewGame(ConnectionInterface $from, $data) {
        $gameId = $data['game_id'] ?? '';
        if (!isset($this->games[$gameId]) || !in_array($from->resourceId, $this->games[$gameId]['players'], true)) {
            $this->sendError($from, 'Partie introuvable ou accès refusé.');
            return;
        }

        if (!isset($this->games[$gameId]['ready'])) {
            $this->games[$gameId]['ready'] = [];
        }

        if (!in_array($from->resourceId, $this->games[$gameId]['ready'], true)) {
            $this->games[$gameId]['ready'][] = $from->resourceId;
        }

        if (count($this->games[$gameId]['ready']) === 2) {
            $board = $this->generateBoard($this->defaultSize, $this->defaultSize, $this->defaultNbMines);
            $this->games[$gameId]['board'] = $board;
            $this->games[$gameId]['ready'] = [];
            $this->games[$gameId]['currentTurn'] = $this->games[$gameId]['players'][0]; // Réinitialisation du tour
            $this->games[$gameId]['moves'] = 0;
            $this->persistGame($gameId);

            foreach ($this->games[$gameId]['players'] as $playerId) {
                $connection = $this->getConnectionFromPlayerId($playerId);
                if ($connection) {
                    $connection->send(json_encode([
                        'type' => 'new_game_start',
                        'game_id' => $gameId,
                        'board' => $this->maskMinesForPlayer($board),
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                    ]));

                    $this->logger->info("OUT:" . json_encode([
                        'type' => 'new_game_start',
                        'game_id' => $gameId,
                        'board' => '[masked]',
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                    ]));
                }
            }
        }
    }

    protected function allSafeCellsRevealed($gameId) {
        foreach ($this->games[$gameId]['board'] as $row) {
            foreach ($row as $cell) {
                if (!$cell['mine'] && !$cell['revealed']) {
                    return false;
                }
            }
        }
        return true;
    }

    protected function endGame(ConnectionInterface $from, $gameId, $loserId = null, $losingCell = null, $isDraw = false, $explicitWinnerId = null) {
        if (!isset($this->games[$gameId])) return;
        
        $game = $this->games[$gameId];
        $flagScores = $this->calculateFlagScores($this->games[$gameId]);
        if ($isDraw) {
            [$winnerId, $winnerName, $isDraw] = $this->determineFlagScoreOutcome($game, $flagScores);
        } else {
            [$winnerId, $winnerName] = $this->determineGameOutcome($game, $loserId, false, $explicitWinnerId);
        }
        $eloChanges = $this->handleGameOver($game, $gameId, $winnerId, $isDraw, $losingCell, $flagScores);
        // Révéler toutes les cellules du plateau
        $this->revealAllCells($this->games[$gameId]['board']);
        
        // Notifier les joueurs
        foreach ($game['players'] as $playerId) {
            $connection = $this->getConnectionFromPlayerId($playerId);
            if ($connection) {
                $message = $isDraw ? 'La partie se termine par une égalité!' : ($winnerId === $playerId ? 'Vous avez gagné!' : 'Vous avez perdu!');
                $connection->send(json_encode([
                    'type' => 'game_over',
                    'game_id' => $gameId,
                    'winner' => $message,
                    'winner_name' => $winnerName,  // Envoi du nom du gagnant ou "Egalité"
                    'board' => $this->games[$gameId]['board'], // Envoyer le plateau complet révélé
                    'flagScores' => $flagScores,
                    'eloChanges' => $this->formatEloChanges($game, $eloChanges),
                    'losingCell' => $losingCell,
                    'players' => $this->getConnectedPlayers()
                ]));
            }
        }
        
        // Notifier les spectateurs
        if (isset($this->games[$gameId]['spectators'])) {
            foreach ($this->games[$gameId]['spectators'] as $spectatorId) {
                $spectatorConnection = $this->getConnectionFromPlayerId($spectatorId);
                if ($spectatorConnection) {
                    $spectatorConnection->send(json_encode([
                        'type' => 'game_over',
                        'game_id' => $gameId,
                        'winner_name' => $winnerName,
                        'message' => $isDraw ? 'La partie se termine par une égalité!' : "La partie est terminée ! Le vainqueur est $winnerName.",
                        'board' => $this->games[$gameId]['board'],  // Envoyer le plateau complet révélé
                        'flagScores' => $flagScores,
                        'eloChanges' => $this->formatEloChanges($game, $eloChanges),
                        'losingCell' => $losingCell
                    ]));
                }
            }
        }
        
        // Supprimer la partie
        $this->deletePersistedGame($gameId);
        unset($this->games[$gameId]);
        $this->writeStatusSnapshot();
    }

    protected function determineGameOutcome(array $game, $loserId = null, bool $isDraw = false, $explicitWinnerId = null): array {
        if ($isDraw) {
            return [null, 'Egalité'];
        }
        if ($explicitWinnerId !== null && in_array($explicitWinnerId, $game['players'], true)) {
            return [$explicitWinnerId, $this->players[$explicitWinnerId]['username']];
        }
        foreach ($game['players'] as $playerId) {
            if ($playerId !== $loserId) {
                return [$playerId, $this->players[$playerId]['username']];
            }
        }
        throw new RuntimeException('Impossible de déterminer le résultat de la partie.');
    }

    protected function determineFlagScoreOutcome(array $game, array $flagScores): array {
        $scoresBySlot = [];
        foreach ($flagScores as $score) $scoresBySlot[(int) $score['playerSlot']] = (int) $score['score'];
        $firstScore = $scoresBySlot[1] ?? 0;
        $secondScore = $scoresBySlot[2] ?? 0;
        if ($firstScore === $secondScore) return [null, 'Egalité', true];
        $winnerSlot = $firstScore > $secondScore ? 0 : 1;
        $winnerResourceId = $game['players'][$winnerSlot];
        return [$winnerResourceId, $this->players[$winnerResourceId]['username'], false];
    }

    protected function formatEloChanges(array $game, array $eloChanges): array {
        $formatted = [];
        foreach ($game['players'] as $playerResourceId) {
            $userId = (int) $this->players[$playerResourceId]['id'];
            if (!isset($eloChanges[$userId])) continue;
            $formatted[] = ['username' => $this->players[$playerResourceId]['username']] + $eloChanges[$userId];
        }
        return $formatted;
    }

    protected function calculateFlagScores(array &$game): array {
        $scores = [];
        foreach ($game['players'] as $slot => $playerResourceId) {
            $scores[$slot + 1] = [
                'playerSlot' => $slot + 1,
                'username' => $this->players[$playerResourceId]['username'],
                'correctFlags' => 0,
                'incorrectFlags' => 0,
                'score' => 0,
            ];
        }
        foreach ($game['board'] as &$row) {
            foreach ($row as &$cell) {
                $cell['incorrectFlag'] = false;
                if (empty($cell['flagged'])) continue;
                $owner = (int) ($cell['flaggedBy'] ?? 0);
                if (!isset($scores[$owner])) continue;
                if (!empty($cell['mine'])) {
                    $scores[$owner]['correctFlags']++;
                    $scores[$owner]['score']++;
                } else {
                    $cell['incorrectFlag'] = true;
                    $scores[$owner]['incorrectFlags']++;
                    $scores[$owner]['score']--;
                }
            }
        }
        unset($row, $cell);
        return array_values($scores);
    }

    protected function revealAllCells(&$board) {
        foreach ($board as &$row) {
            foreach ($row as &$cell) {
                $cell['revealed'] = true; // Révéler chaque cellule, qu'elle soit une mine ou non
            }
        }
    }
    
    // Fonction pour décrémenter le nombre de parties jouées
    protected function decrementGamesPlayed($playerResourceId) {
        $db = $this->db;
        // Récupérer l'ID utilisateur à partir du resourceId
        $playerId = $this->players[$playerResourceId]['id'];
        $stmt = $db->getPDO()->prepare("UPDATE users SET games_played = GREATEST(games_played - 1, 0) WHERE id = :id");
        $stmt->bindParam(':id', $playerId);
        $stmt->execute();
    }

    protected function persistAuthSession(string $token, int $userId, int $expiresAt): void {
        try {
            $this->authSessionRepository->save($token, $userId, $expiresAt);
            if (random_int(1, 100) === 1) {
                $this->authSessionRepository->purgeExpired();
            }
        } catch (Throwable $e) {
            if ($this->logger) $this->logger->warning('Session persistante non enregistrée.', ['error' => $e->getMessage()]);
        }
    }

    protected function loadPersistedAuthSession(string $token): ?array {
        try {
            $loaded = $this->authSessionRepository->findValid($token);
            if (!$loaded) return null;
            $this->authSessions[$token] = $loaded;
            return $loaded;
        } catch (Throwable $e) {
            if ($this->logger) $this->logger->warning('Lecture de session persistante impossible.', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function deletePersistedAuthSession(string $token): void {
        try {
            $this->authSessionRepository->delete($token);
        } catch (Throwable $e) {
            if ($this->logger) $this->logger->warning('Révocation de session persistante impossible.', ['error' => $e->getMessage()]);
        }
    }

    protected function loadRecoverableGames(): void {
        try {
            $rows = $this->db->getPDO()->query(
                "SELECT game_id, player1_id, player2_id, turn_user_id, state_json FROM active_games WHERE updated_at >= NOW() - INTERVAL 24 HOUR"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $state = json_decode($row['state_json'], true);
                if (!is_array($state) || !isset($state['board'], $state['mineCount'])) continue;
                $this->recoverableGames[$row['game_id']] = [
                    'player_user_ids' => [(int) $row['player1_id'], (int) $row['player2_id']],
                    'turn_user_id' => (int) $row['turn_user_id'],
                    'state' => $state,
                ];
            }
            if ($rows) $this->logger->info('Parties récupérables chargées.', ['count' => count($this->recoverableGames)]);
        } catch (Throwable $e) {
            if ($this->logger) $this->logger->warning('Persistance des parties indisponible; appliquez la migration active_games.', ['error' => $e->getMessage()]);
        }
    }

    protected function persistGame(string $gameId): void {
        if (!isset($this->games[$gameId])) return;
        $game = $this->games[$gameId];
        $player1 = $this->players[$game['players'][0]]['id'] ?? null;
        $player2 = $this->players[$game['players'][1]]['id'] ?? null;
        $turnUser = $this->players[$game['currentTurn']]['id'] ?? null;
        if (!$player1 || !$player2 || !$turnUser) return;
        $state = [
            'board' => $game['board'], 'moves' => $game['moves'], 'mineCount' => $game['mineCount'],
            'isPrivate' => (bool) ($game['isPrivate'] ?? true),
            'inviter_user_id' => $this->players[$game['inviter']]['id'] ?? $player1,
            'invitee_user_id' => $this->players[$game['invitee']]['id'] ?? $player2,
        ];
        try {
            $stmt = $this->db->getPDO()->prepare(
                'INSERT INTO active_games (game_id, player1_id, player2_id, turn_user_id, state_json) VALUES (:game_id,:p1,:p2,:turn_user,:state) '
                . 'ON DUPLICATE KEY UPDATE turn_user_id=VALUES(turn_user_id), state_json=VALUES(state_json), updated_at=CURRENT_TIMESTAMP'
            );
            $stmt->execute(['game_id' => $gameId, 'p1' => $player1, 'p2' => $player2, 'turn_user' => $turnUser, 'state' => json_encode($state, JSON_THROW_ON_ERROR)]);
        } catch (Throwable $e) {
            if ($this->logger) $this->logger->warning('Instantané de partie non enregistré.', ['game_id' => $gameId, 'error' => $e->getMessage()]);
        }
    }

    protected function deletePersistedGame(string $gameId): void {
        unset($this->recoverableGames[$gameId]);
        try {
            $stmt = $this->db->getPDO()->prepare('DELETE FROM active_games WHERE game_id = :game_id');
            $stmt->execute(['game_id' => $gameId]);
        } catch (Throwable $e) {
            if ($this->logger) $this->logger->warning('Instantané de partie non supprimé.', ['game_id' => $gameId, 'error' => $e->getMessage()]);
        }
    }

    protected function tryRestoreGamesForUser(int $userId): void {
        foreach ($this->recoverableGames as $gameId => $snapshot) {
            if (!in_array($userId, $snapshot['player_user_ids'], true)) continue;
            $resources = [];
            foreach ($snapshot['player_user_ids'] as $participantId) {
                foreach ($this->players as $resourceId => $player) {
                    if ((int) $player['id'] === $participantId) { $resources[$participantId] = $resourceId; break; }
                }
            }
            if (count($resources) !== 2) continue;
            $state = $snapshot['state'];
            $inviter = $resources[(int) $state['inviter_user_id']] ?? reset($resources);
            $invitee = $resources[(int) $state['invitee_user_id']] ?? end($resources);
            $this->games[$gameId] = [
                'players' => array_values($resources), 'inviter' => $inviter, 'invitee' => $invitee,
                'board' => $state['board'], 'currentTurn' => $resources[$snapshot['turn_user_id']],
                'moves' => (int) ($state['moves'] ?? 0), 'mineCount' => (int) $state['mineCount'],
                'isPrivate' => (bool) ($state['isPrivate'] ?? true), 'spectators' => [],
            ];
            unset($this->recoverableGames[$gameId]);
            foreach ($this->games[$gameId]['players'] as $resourceId) {
                $connection = $this->getConnectionFromPlayerId($resourceId);
                if ($connection) $connection->send(json_encode([
                    'type' => 'game_resumed', 'game_id' => $gameId,
                    'board' => $this->maskMinesForPlayer($state['board']),
                    'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username'],
                    'mineCount' => (int) $state['mineCount'],
                    'message' => 'Partie restaurée après le redémarrage du serveur.',
                ]));
            }
            $this->logger->info('Partie restaurée après redémarrage.', ['game_id' => $gameId]);
        }
    }

    protected function cancelGameAfterDisconnect($gameId, $disconnectedPlayerId, $otherPlayerId): void {
        // Une partie interrompue ne compte ni comme victoire, ni comme égalité,
        // ni comme partie jouée pour les participants.
        $this->decrementGamesPlayed($disconnectedPlayerId);
        $this->decrementGamesPlayed($otherPlayerId);
        $this->deletePersistedGame($gameId);
        unset($this->games[$gameId]);
        $this->writeStatusSnapshot();
    }
    
    protected function sendConnectedPlayersList(ConnectionInterface $from) {
        $this->broadcastConnectedPlayersList($from->resourceId);
    }

    protected function broadcastConnectedPlayersList(int $sourceConnectionId): void {
        $playersList = $this->getConnectedPlayers();
        foreach ($this->clients as $client) {
            if (!$this->isAuthenticated($client)) continue;
            $client->send(json_encode([
                'type' => 'connected_players',
                'playerId' => $sourceConnectionId,
                'players' => $playersList
            ]));

        }
        $this->logger->info('Liste des joueurs connectés diffusée.', [
            'source_connection_id' => $sourceConnectionId,
            'player_count' => count($playersList),
        ]);
        $this->writeStatusSnapshot();
    }

    protected function writeStatusSnapshot(): void {
        $path = getenv('STATUS_PATH') ?: '/var/log/minesweeper/status.json';
        $payload = json_encode([
            'updatedAt' => date(DATE_ATOM), 'uptimeSeconds' => time() - $this->startedAt,
            'connections' => $this->clients instanceof Countable ? count($this->clients) : 0,
            'authenticatedPlayers' => is_array($this->players) ? count($this->players) : 0,
            'activeGames' => is_array($this->games) ? count($this->games) : 0,
            'pendingInvitations' => count($this->pendingInvitations),
            'pendingReconnects' => count($this->pendingReconnects),
            'actions' => $this->actionCount,
            'websocketErrors' => $this->websocketErrors,
            'pendingMoveWrites' => count($this->pendingMoves),
            'averageMoveSqlMs' => $this->moveSqlBatches > 0 ? round($this->moveSqlTotalMs / $this->moveSqlBatches, 2) : 0,
        ], JSON_THROW_ON_ERROR);
        $temp = $path . '.tmp';
        if (@file_put_contents($temp, $payload, LOCK_EX) !== false) @rename($temp, $path);
    }

    protected function getConnectionFromPlayerId($playerId) {
        foreach ($this->clients as $client) {
            if ($client->resourceId === $playerId) {
                return $client;
            }
        }
        return null;
    }

    protected function getConnectedPlayers() {
        $players = [];
        foreach ($this->players as $resourceId => $playerInfo) {
            $inGame = false;

            // Vérifier si le joueur est déjà dans une partie
            foreach ($this->games as $game) {
                if (in_array($resourceId, $game['players'], true) || in_array($resourceId, $game['spectators'] ?? [], true)) {
                    $inGame = true;
                    break;
                }
            }

            // Si le joueur n'est pas en jeu, on l'ajoute à la liste
            if (!$inGame) {
                $players[] = [
                    'id' => $playerInfo['id'],
                    'username' => $playerInfo['username']
                ];
            }
        }
        return $players;
    }

    protected function isPlayerInGame($resourceId) {
        foreach ($this->games as $game) {
            if (in_array($resourceId, $game['players'], true)) return true;
        }
        return false;
    }

    protected function isSpectatorInGame($resourceId) {
        foreach ($this->games as $game) {
            if (in_array($resourceId, $game['spectators'] ?? [], true)) return true;
        }
        return false;
    }

    protected function hasPendingInvitation($resourceId) {
        foreach ($this->pendingInvitations as $id => $invitation) {
            if (time() - ($invitation['createdAt'] ?? 0) > 60) {
                unset($this->pendingInvitations[$id]);
                continue;
            }
            if ($invitation['inviter'] === $resourceId || $invitation['invitee'] === $resourceId) return true;
        }
        return false;
    }

    protected function getNextPlayer($gameId) {
        $game = $this->games[$gameId];
        $nextPlayer = ($game['currentTurn'] === $game['players'][0]) ? $game['players'][1] : $game['players'][0];
        $this->games[$gameId]['currentTurn'] = $nextPlayer;
        return $nextPlayer;
    }

    protected function moveMineAwayFrom(&$board, $safeX, $safeY) {
        $candidates = [];
        foreach ($board as $x => $row) {
            foreach ($row as $y => $cell) {
                if (!$cell['mine'] && !$cell['flagged'] && ($x !== $safeX || $y !== $safeY)) $candidates[] = [$x, $y];
            }
        }
        if (!$candidates) return;
        $target = $candidates[random_int(0, count($candidates) - 1)];

        $board[$safeX][$safeY]['mine'] = false;
        $board[$target[0]][$target[1]]['mine'] = true;
        foreach ($board as &$row) {
            foreach ($row as &$cell) $cell['adjacentMines'] = 0;
        }
        unset($row, $cell);
        foreach ($board as $mineX => $row) {
            foreach ($row as $mineY => $cell) {
                if (!$cell['mine']) continue;
                for ($i = -1; $i <= 1; $i++) {
                    for ($j = -1; $j <= 1; $j++) {
                        if (isset($board[$mineX + $i][$mineY + $j])) {
                            $board[$mineX + $i][$mineY + $j]['adjacentMines']++;
                        }
                    }
                }
            }
        }
    }

    protected function generateBoard($width, $height, $numMines) {
        
        

        $board = [];

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $board[$x][$y] = [
                    'mine' => false,
                    'revealed' => false,
                    'adjacentMines' => 0,
                    'flagged' => false,
                    'flaggedBy' => null
                ];
            }
        }

        $minesPlaced = 0;
        while ($minesPlaced < $numMines) {
            $randX = random_int(0, $width - 1);
            $randY = random_int(0, $height - 1);

            if (!$board[$randX][$randY]['mine']) {
                $board[$randX][$randY]['mine'] = true;
                $minesPlaced++;

                for ($i = -1; $i <= 1; $i++) {
                    for ($j = -1; $j <= 1; $j++) {
                        $newX = $randX + $i;
                        $newY = $randY + $j;
                        if ($newX >= 0 && $newX < $width && $newY >= 0 && $newY < $height) {
                            $board[$newX][$newY]['adjacentMines']++;
                        }
                    }
                }
            }
        }

        return $board;
    }

    protected function handleGetScores(ConnectionInterface $from, array $data = []) {
        // Récupérer les scores des joueurs depuis la base de données
        $db = $this->db;
        $period = in_array($data['period'] ?? 'all', ['week', 'month', 'all'], true) ? $data['period'] : 'all';
        if ($period === 'all') {
            $stmt = $db->getPDO()->prepare("
            SELECT username, is_ai, games_won, games_draw, games_played, elo_rating, elo_games,
            GREATEST(games_played - games_won - games_draw, 0) AS games_lost,
            (games_won * 3 + games_draw) AS ranking_points,
            (games_won / NULLIF(games_played, 0)) * 100 AS win_percentage
            FROM users
            ORDER BY elo_rating DESC, elo_games DESC, username ASC
            ");
        } else {
            $interval = $period === 'week' ? '7 DAY' : '1 MONTH';
            $stmt = $db->getPDO()->prepare("
                SELECT u.username, u.is_ai, u.elo_rating, u.elo_games,
                  COUNT(g.id) AS games_played,
                  COALESCE(SUM(g.winner_id = u.id), 0) AS games_won,
                  COALESCE(SUM(g.winner_id IS NULL), 0) AS games_draw,
                  COALESCE(SUM(g.winner_id IS NOT NULL AND g.winner_id <> u.id), 0) AS games_lost,
                  (COALESCE(SUM(g.winner_id = u.id), 0) * 3 + COALESCE(SUM(g.winner_id IS NULL), 0)) AS ranking_points,
                  (COALESCE(SUM(g.winner_id = u.id), 0) / NULLIF(COUNT(g.id), 0)) * 100 AS win_percentage
                FROM users u
                LEFT JOIN game_details g ON (g.inviter_id = u.id OR g.invitee_id = u.id)
                  AND g.status = 'finished' AND g.game_date >= NOW() - INTERVAL {$interval}
                  AND (u.stats_reset_at IS NULL OR g.game_date >= u.stats_reset_at)
                GROUP BY u.id, u.username, u.is_ai, u.elo_rating, u.elo_games
                ORDER BY u.elo_rating DESC, games_played DESC, u.username ASC
            ");
        }
        $stmt->execute();
        $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        // Envoyer les scores au client WebSocket
        $from->send(json_encode([
            'type' => 'scores',
            'period' => $period,
            'players' => $scores
        ]));
    
        $this->logger->info("OUT:" . json_encode([
            'type' => 'scores',
            'players' => $scores
        ]));
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', getenv('WS_ALLOWED_ORIGINS') ?: 'localhost,127.0.0.1'))));
    $host = getenv('WS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('WS_PORT') ?: 8080);
    $logger->info('Démarrage du backend WebSocket demandé.', [
        'host' => $host,
        'port' => $port,
        'allowed_origins' => $allowedOrigins,
        'config_dir' => getenv('APP_CONFIG_DIR') ?: null,
        'log_path' => $logFilePath,
    ]);
    try {
        $component = new LoggedOriginCheck(new WsServer(new MinesweeperServer($logger)), $allowedOrigins, $logger);
        $server = IoServer::factory(new HttpServer($component), $port, $host);
        $logger->info('Backend WebSocket prêt et en écoute.', ['host' => $host, 'port' => $port]);
        $server->run();
    } catch (Throwable $e) {
        $logger->critical('Échec du démarrage du backend WebSocket.', [
            'host' => $host,
            'port' => $port,
            'exception' => get_class($e),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        exit(1);
    }
}
