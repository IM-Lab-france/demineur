<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_admin();
require_post();
require_csrf();
header('Content-Type: application/json; charset=utf-8');

$serviceState = trim((string) shell_exec('/usr/bin/systemctl is-active minesweeper-websocket.service 2>/dev/null'));
if (!in_array($serviceState, ['inactive', 'failed'], true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Arrêtez le serveur WebSocket avant de réinitialiser les scores, puis réessayez.']);
    exit;
}

$scope = (string) ($_POST['scope'] ?? '');
$confirmation = (string) ($_POST['confirmation'] ?? '');
if (!in_array($scope, ['player', 'all'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Périmètre de réinitialisation invalide.']);
    exit;
}

try {
    $db = new Database();
    $pdo = $db->getPDO();
    $pdo->beginTransaction();

    if ($scope === 'all') {
        if ($confirmation !== 'RESET') {
            throw new InvalidArgumentException('Confirmation globale invalide.');
        }
        $affected = $pdo->exec('UPDATE users SET games_played = 0, games_won = 0, games_draw = 0');
        $message = "Scores réinitialisés pour tous les joueurs ({$affected} compte(s) modifié(s)).";
    } else {
        if ($confirmation !== 'PLAYER') {
            throw new InvalidArgumentException('Confirmation invalide.');
        }
        $playerId = filter_var($_POST['player_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($playerId === false) {
            throw new InvalidArgumentException('Joueur invalide.');
        }
        $stmt = $pdo->prepare('UPDATE users SET games_played = 0, games_won = 0, games_draw = 0 WHERE id = :id');
        $stmt->execute(['id' => $playerId]);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT username FROM users WHERE id = :id');
            $exists->execute(['id' => $playerId]);
            $username = $exists->fetchColumn();
            if ($username === false) throw new InvalidArgumentException('Joueur introuvable.');
        } else {
            $nameStmt = $pdo->prepare('SELECT username FROM users WHERE id = :id');
            $nameStmt->execute(['id' => $playerId]);
            $username = $nameStmt->fetchColumn();
        }
        $message = 'Scores réinitialisés pour ' . $username . '.';
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $message]);
} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Impossible de réinitialiser les scores.']);
}
