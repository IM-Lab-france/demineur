<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_admin();
require_post();
require_csrf();
header('Content-Type: application/json; charset=utf-8');

$playerId = filter_var($_POST['player_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$disabled = filter_var($_POST['disabled'] ?? null, FILTER_VALIDATE_INT);
if ($playerId === false || !in_array($disabled, [0, 1], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides.']);
    exit;
}
try {
    $pdo = (new Database())->getPDO();
    $stmt = $pdo->prepare('UPDATE users SET is_disabled = :disabled WHERE id = :id AND is_admin = 0');
    $stmt->execute(['disabled' => $disabled, 'id' => $playerId]);
    if ($stmt->rowCount() === 0) throw new RuntimeException('Compte introuvable, administrateur, ou déjà dans cet état.');
    error_log(sprintf('admin_audit action=toggle_player admin=%s player=%d disabled=%d', $_SESSION['admin_username'], $playerId, $disabled));
    echo json_encode(['success' => true, 'message' => $disabled ? 'Compte désactivé.' : 'Compte réactivé.']);
} catch (Throwable $e) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
