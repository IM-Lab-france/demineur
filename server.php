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
    protected $actionWindows = [];
    protected $recordMoveStatement;
    protected $allowedGridSizes = ['10x10', '20x20', '30x30'];
    protected $allowedDifficulties = [10, 15, 22];

    protected $logger; // Ajout d'une propriété pour le logger
    protected $db;

    public function __construct($logger) {
        $this->clients = new \SplObjectStorage;
        $this->players = [];
        $this->games = [];
        $this->defaultNbMines = intval($this->defaultSize * $this->defaultSize * $this->difficulty);
        $this->logger = $logger; // Initialisation du logger
        $this->logger->info('Initialisation de la connexion à la base de données.');
        $this->db = new Database();
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

        // Ne jamais écrire les identifiants reçus dans les journaux.
        $loggedData = $data;
        unset($loggedData['password']);
        unset($loggedData['sessionToken']);
        $this->logger->info('Action backend reçue.', [
            'action' => $data['type'],
            'connection_id' => $from->resourceId,
            'authenticated' => $this->isAuthenticated($from),
            'game_id' => $data['game_id'] ?? $data['gameId'] ?? null,
            'payload' => $loggedData,
        ]);

        $publicMessages = ['register', 'login', 'resume_session', 'ping', 'get_scores', 'get_player_count', 'get_active_games'];
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

            case 'ready_for_new_game':
                $this->handleReadyForNewGame($from, $data);
                break;

            case 'logout':
                $this->handleLogout($from);
                break;

            case 'refresh_players':
                $this->sendConnectedPlayersList($from);
                break;
            case 'get_scores':
                $this->handleGetScores($from);
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
    
        // Vérifier si le joueur déconnecté est en partie
        foreach ($this->games as $gameId => $game) {
            if (in_array($disconnectedPlayerId, $game['players'])) {
                // L'autre joueur dans la partie
                $otherPlayerId = $game['players'][0] === $disconnectedPlayerId ? $game['players'][1] : $game['players'][0];
    
                // Envoyer un message à l'autre joueur pour l'informer de la déconnexion
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
        unset($this->players[$disconnectedPlayerId]);
        unset($this->actionWindows[$disconnectedPlayerId]);
    
        // Envoyer la liste mise à jour des joueurs connectés à tous les autres clients
        $this->sendConnectedPlayersList($from);
    }

    public function onError(ConnectionInterface $from, \Exception $e) {
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
        return isset($this->players[$connection->resourceId]);
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
            if ($this->isPlayerInGame($from->resourceId)) {
                $this->sendError($from, 'Un joueur actif ne peut pas devenir spectateur.');
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
                'message' => 'Vous suivez maintenant la partie ' . $gameId,
                'gridSize' => ['width' => $gridWidth, 'height' => $gridHeight], // Envoi de la taille de la grille
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
            $playerNames = array_map(function ($playerId) {
                return $this->players[$playerId]['username']; // Récupérer les noms des joueurs
            }, $game['players']);
    
            // Inclure l'ID de la partie
            $activeGames[] = [
                'gameId' => $gameId, // Envoi de l'ID de la partie
                'players' => $playerNames
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

        if (!preg_match('/^[\p{L}\p{N}_-]{3,32}$/u', $username)) {
            $from->send(json_encode(['type' => 'register_failed', 'message' => 'Le nom doit contenir 3 à 32 lettres, chiffres, tirets ou underscores.']));
            return;
        }
        if (!is_string($password) || strlen($password) < 10 || strlen($password) > 128) {
            $from->send(json_encode(['type' => 'register_failed', 'message' => 'Le mot de passe doit contenir entre 10 et 128 caractères.']));
            return;
        }
    
        // Utilisation de la base de données pour vérifier si le nom d'utilisateur existe déjà
        $db = $this->db;
        $stmt = $db->getPDO()->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($existingUser) {
            // L'utilisateur avec ce login existe déjà
            $from->send(json_encode([
                'type' => 'register_failed',
                'message' => 'Nom d\'utilisateur déjà pris.'
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
                INSERT INTO users (username, password_hash, created_at)
                VALUES (:username, :password_hash, NOW())
            ");
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password_hash', $passwordHash);
            try {
                $stmt->execute();
            } catch (PDOException $e) {
                $from->send(json_encode(['type' => 'register_failed', 'message' => 'Impossible de créer ce compte.']));
                return;
            }
    
            // Confirmation de l'enregistrement
            $from->send(json_encode([
                'type' => 'register_success',
                'message' => 'Enregistrement réussi. Vous pouvez vous connecter.'
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
    
        // Vérifier si l'utilisateur est déjà connecté
        foreach ($this->players as $resourceId => $playerInfo) {
            if ($playerInfo['username'] === $username) {
                // L'utilisateur est déjà connecté
                $from->send(json_encode([
                    'type' => 'login_failed',
                    'message' => 'Cet utilisateur est déjà connecté.'
                ]));
                $this->logger->info("OUT:" . json_encode([
                    'type' => 'login_failed',
                    'message' => 'Cet utilisateur est déjà connecté.'
                ]));
                return; // Arrêter le traitement
            }
        }

        // Utilisation de la base de données pour récupérer les informations de l'utilisateur
        $db = $this->db;
        $user = $db->getUserByUsername($username);
    
        // Vérifier si l'utilisateur existe et si le mot de passe correspond
        if ($user && password_verify($password, $user['password_hash'])) {
            unset($this->authAttempts[$attemptKey]);
            $sessionToken = bin2hex(random_bytes(32));
            $this->authSessions[$sessionToken] = [
                'id' => $user['id'],
                'username' => $username,
                'expires_at' => time() + 43200,
            ];
            // Connexion réussie
            $this->players[$from->resourceId] = [
                'id' => $user['id'],
                'username' => $username,
                'session_token' => $sessionToken,
            ];
    
            $from->send(json_encode([
                'type' => 'login_success',
                'playerId' => $user['id'],  // Envoi de l'ID du joueur
                'username' => $username,
                'sessionToken' => $sessionToken,
                'players' => $this->getConnectedPlayers()
            ]));

            $this->logger->info("OUT:" . json_encode([
                'type' => 'login_success',
                'playerId' => $user['id'],  // Envoi de l'ID du joueur
                'username' => $username,
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

    protected function handleResumeSession(ConnectionInterface $from, $data) {
        $token = $data['sessionToken'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $from->send(json_encode(['type' => 'resume_failed']));
            return;
        }

        $session = $this->authSessions[$token] ?? null;
        if (!$session || $session['expires_at'] < time()) {
            unset($this->authSessions[$token]);
            $from->send(json_encode(['type' => 'resume_failed']));
            return;
        }

        foreach ($this->players as $resourceId => $player) {
            if ($resourceId !== $from->resourceId && ($player['session_token'] ?? null) === $token) {
                $oldConnection = $this->getConnectionFromPlayerId($resourceId);
                if ($oldConnection) $oldConnection->close();
                unset($this->players[$resourceId]);
            }
        }

        $this->players[$from->resourceId] = [
            'id' => $session['id'],
            'username' => $session['username'],
            'session_token' => $token,
        ];
        $this->authSessions[$token]['expires_at'] = time() + 43200;
        $from->send(json_encode([
            'type' => 'login_success',
            'playerId' => $session['id'],
            'username' => $session['username'],
            'sessionToken' => $token,
            'players' => $this->getConnectedPlayers(),
        ]));
        $this->sendConnectedPlayersList($from);
    }

    protected function handleLogout(ConnectionInterface $from) {
        // Si le joueur est déjà déconnecté ou introuvable
        if (!isset($this->players[$from->resourceId])) {
            return;
        }
    
        $username = $this->players[$from->resourceId]['username'];
        $sessionToken = $this->players[$from->resourceId]['session_token'] ?? null;
        if ($sessionToken !== null) unset($this->authSessions[$sessionToken]);
        // Retirer le joueur de la liste des joueurs connectés
        unset($this->players[$from->resourceId]);
    
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
        if ($this->isPlayerInGame($from->resourceId) || $this->hasPendingInvitation($from->resourceId)) {
            $this->sendError($from, 'Vous êtes déjà en partie ou avez une invitation en attente.');
            return;
        }

        foreach ($this->clients as $client) {
            if (isset($this->players[$client->resourceId]) && $this->players[$client->resourceId]['id'] === $inviteeId) {
                if ($this->isPlayerInGame($client->resourceId) || $this->hasPendingInvitation($client->resourceId)) {
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
            if ($this->isPlayerInGame($from->resourceId) || $this->isPlayerInGame($invitation['inviter'])) {
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
            $randomNumber = rand(0, 100) / 100;
    
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
                'spectators' => []
            ];
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

    protected function handleGameOver($game,$gameId, $winnerResourceId = null, $isDraw = false, $losingCell = null) {
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
        $stmt = $pdo->prepare("
            INSERT INTO game_details (game_id, inviter_id, invitee_id, winner_id, moves, explosion_area)
            VALUES (:game_id, :inviter_id, :invitee_id, :winner_id, :moves, :explosion_area)
        ");
        $stmt->execute([
            ':game_id' => $gameId,
            ':inviter_id' => $inviterId,
            ':invitee_id' => $inviteeId,
            ':winner_id' => $winnerId,
            ':moves' => $moves,
            ':explosion_area' => json_encode($explosionArea)
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
        $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
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
        if ($this->recordMoveStatement === null) {
            $this->recordMoveStatement = $this->db->getPDO()->prepare(
                "INSERT INTO game_moves (game_id, x, y, explosion_area, result) VALUES (:game_id, :x, :y, :explosion_area, :result)"
            );
        }
        $startedAt = microtime(true);
        $this->recordMoveStatement->execute([
            ':game_id' => $gameId,
            ':x' => $x,
            ':y' => $y,
            ':explosion_area' => json_encode($explosionArea),
            ':result' => $result
        ]);
        $durationMs = (microtime(true) - $startedAt) * 1000;
        if ($durationMs >= 50) {
            $this->logger->warning('Enregistrement SQL lent pour un coup.', [
                'game_id' => $gameId,
                'duration_ms' => round($durationMs, 2),
            ]);
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
                        'adjacentMines' => $cell['adjacentMines']
                    ];
                } else {
                    // Si la cellule n'est pas révélée, on n'envoie que l'état 'flagged' et 'revealed'
                    $maskedRow[] = [
                        'revealed' => false,
                        'flagged' => $cell['flagged']
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

        if (!$this->games[$gameId]['board'][$x][$y]['flagged']) {
            $flagCount = 0;
            $mineCount = 0;
            foreach ($this->games[$gameId]['board'] as $row) {
                foreach ($row as $cell) {
                    if ($cell['flagged']) $flagCount++;
                    if ($cell['mine']) $mineCount++;
                }
            }
            if ($flagCount >= $mineCount) {
                $this->sendError($from, 'Tous les drapeaux disponibles sont déjà placés.');
                return;
            }
        }

        $this->games[$gameId]['board'][$x][$y]['flagged'] = !$this->games[$gameId]['board'][$x][$y]['flagged'];
        $maskedBoard = $this->maskMinesForPlayer($this->games[$gameId]['board']);

        foreach ($this->games[$gameId]['players'] as $playerId) {
            $connection = $this->getConnectionFromPlayerId($playerId);
            if ($connection) {
                $connection->send(json_encode([
                    'type' => 'update_board',
                    'board' => $maskedBoard,
                    'turn' => $this->games[$gameId]['currentTurn'],
                    'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                ]));

                $this->logger->info("OUT:" . json_encode([
                    'type' => 'update_board',
                    'board' => '[masked]',
                    'turn' => $this->games[$gameId]['currentTurn'],
                    'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                ]));
            }
        }
        $this->updateSpectators($gameId, $maskedBoard);
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
        [$winnerId, $winnerName] = $this->determineGameOutcome($game, $loserId, $isDraw, $explicitWinnerId);
        $this->handleGameOver($game ,$gameId,$winnerId, $isDraw, $losingCell);
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
                        'losingCell' => $losingCell
                    ]));
                }
            }
        }
        
        // Supprimer la partie
        unset($this->games[$gameId]);
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

    protected function cancelGameAfterDisconnect($gameId, $disconnectedPlayerId, $otherPlayerId): void {
        // Une partie interrompue ne compte ni comme victoire, ni comme égalité,
        // ni comme partie jouée pour les participants.
        $this->decrementGamesPlayed($disconnectedPlayerId);
        $this->decrementGamesPlayed($otherPlayerId);
        unset($this->games[$gameId]);
    }
    
    protected function sendConnectedPlayersList(ConnectionInterface $from) {
        $playersList = $this->getConnectedPlayers();
        foreach ($this->clients as $client) {
            if (!$this->isAuthenticated($client)) continue;
            $client->send(json_encode([
                'type' => 'connected_players',
                'playerId' => $from->resourceId,
                'players' => $playersList
            ]));

        }
        $this->logger->info('Liste des joueurs connectés diffusée.', [
            'source_connection_id' => $from->resourceId,
            'player_count' => count($playersList),
        ]);
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
                if (in_array($resourceId, $game['players'])) {
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
                    'flagged' => false
                ];
            }
        }

        $minesPlaced = 0;
        while ($minesPlaced < $numMines) {
            $randX = rand(0, $width - 1);
            $randY = rand(0, $height - 1);

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

    protected function handleGetScores(ConnectionInterface $from) {
        // Récupérer les scores des joueurs depuis la base de données
        $db = $this->db;
        $stmt = $db->getPDO()->prepare("
            SELECT username, games_won, games_draw, games_played, 
            (games_won / NULLIF(games_played, 0)) * 100 AS win_percentage
            FROM users
            ORDER BY win_percentage DESC
        ");
        $stmt->execute();
        $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        // Envoyer les scores au client WebSocket
        $from->send(json_encode([
            'type' => 'scores',
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
