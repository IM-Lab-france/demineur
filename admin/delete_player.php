<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/MailQueueRepository.php';
require_admin();
require_post();
require_csrf();
header('Content-Type: application/json; charset=utf-8');

$playerId = filter_var($_POST['player_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$confirmation = trim((string) ($_POST['confirmation'] ?? ''));
if ($playerId === false || $confirmation === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Paramètres de suppression invalides.']);
    exit;
}

function control_websocket_service(string $action): bool {
    $output = [];
    exec('/usr/bin/sudo -n /usr/bin/systemctl ' . $action . ' minesweeper-websocket.service 2>&1', $output, $code);
    return $code === 0;
}

$serviceState = trim((string) shell_exec('/usr/bin/systemctl is-active minesweeper-websocket.service 2>/dev/null'));
$restartService = in_array($serviceState, ['active', 'activating', 'reloading'], true);
$mustStopService = !in_array($serviceState, ['inactive', 'failed'], true);
if ($mustStopService && !control_websocket_service('stop')) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Impossible d’arrêter temporairement le serveur WebSocket.']);
    exit;
}
$serviceRestarted = false;

try {
    $pdo = (new Database())->getPDO();
    $pdo->beginTransaction();

    $accountQuery = $pdo->prepare('SELECT id,username,email,is_admin,is_ai FROM users WHERE id=:id FOR UPDATE');
    $accountQuery->execute(['id' => $playerId]);
    $account = $accountQuery->fetch(PDO::FETCH_ASSOC);
    if (!$account) throw new InvalidArgumentException('Compte introuvable.');
    if ((int) $account['is_admin'] === 1) throw new InvalidArgumentException('Un compte administrateur ne peut pas être supprimé ici.');
    if ((int) $account['is_ai'] === 1) throw new InvalidArgumentException('Supprimez cette IA depuis la page de gestion des IA.');
    if (!hash_equals((string) $account['username'], $confirmation)) {
        throw new InvalidArgumentException('Le nom saisi ne correspond pas au compte.');
    }

    $gameQuery = $pdo->prepare('SELECT game_id FROM game_details WHERE inviter_id=:inviter OR invitee_id=:invitee OR winner_id=:winner');
    $gameQuery->execute(['inviter' => $playerId, 'invitee' => $playerId, 'winner' => $playerId]);
    $gameIds = array_values(array_unique(array_map('strval', $gameQuery->fetchAll(PDO::FETCH_COLUMN))));
    if ($gameIds) {
        $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
        $pdo->prepare("DELETE FROM game_moves WHERE game_id IN ({$placeholders})")->execute($gameIds);
        $pdo->prepare("DELETE FROM game_details WHERE game_id IN ({$placeholders})")->execute($gameIds);
    }

    $activeGames = $pdo->prepare('DELETE FROM active_games WHERE player1_id=:player1 OR player2_id=:player2 OR turn_user_id=:turn_user');
    $activeGames->execute(['player1' => $playerId, 'player2' => $playerId, 'turn_user' => $playerId]);
    $invitations = $pdo->prepare('DELETE FROM invitations WHERE from_user_id=:sender OR to_user_id=:recipient');
    $invitations->execute(['sender' => $playerId, 'recipient' => $playerId]);
    $tokens = $pdo->prepare('DELETE FROM account_tokens WHERE user_id=:id');
    $tokens->execute(['id' => $playerId]);
    $sessions = $pdo->prepare('DELETE FROM auth_sessions WHERE user_id=:id');
    $sessions->execute(['id' => $playerId]);

    (new MailQueueRepository($pdo))->purgeForIdentity((string) ($account['email'] ?? ''), (string) $account['username']);

    $delete = $pdo->prepare('DELETE FROM users WHERE id=:id AND is_admin=0 AND is_ai=0');
    $delete->execute(['id' => $playerId]);
    if ($delete->rowCount() !== 1) throw new RuntimeException('Le compte n’a pas pu être supprimé.');

    $pdo->commit();
    if ($restartService) $serviceRestarted = control_websocket_service('start');
    error_log(sprintf('admin_audit action=delete_player admin=%s player=%d username=%s', $_SESSION['admin_username'], $playerId, $account['username']));
    $message = 'Le compte et toutes ses données ont été supprimés.';
    if ($restartService && !$serviceRestarted) $message .= ' Le serveur WebSocket doit être redémarré manuellement.';
    echo json_encode(['success' => true, 'message' => $message, 'serverRestarted' => !$restartService || $serviceRestarted]);
} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($restartService) $serviceRestarted = control_websocket_service('start');
    http_response_code(422);
    $message = $e->getMessage();
    if ($restartService && !$serviceRestarted) $message .= ' Le serveur WebSocket doit être redémarré manuellement.';
    echo json_encode(['success' => false, 'message' => $message]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($restartService) $serviceRestarted = control_websocket_service('start');
    error_log('admin_audit action=delete_player_failed error=' . get_class($e));
    http_response_code(500);
    $message = 'La suppression complète du compte a échoué. Aucune donnée n’a été supprimée.';
    if ($restartService && !$serviceRestarted) $message .= ' Le serveur WebSocket doit être redémarré manuellement.';
    echo json_encode(['success' => false, 'message' => $message]);
}
