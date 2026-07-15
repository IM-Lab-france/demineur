<?php
require_once __DIR__ . '/bootstrap.php';
require_admin(false);
$csrf = csrf_token();
$players = [];
$playerBlockingAvailable = false;
try {
    $db = new Database();
    $columns = $db->getPDO()->query("SHOW COLUMNS FROM users LIKE 'is_disabled'")->fetchAll(PDO::FETCH_ASSOC);
    $playerBlockingAvailable = count($columns) > 0;
    $players = $db->getPDO()->query(
        'SELECT id, username, games_played, games_won, games_draw' . ($playerBlockingAvailable ? ', is_disabled' : ', 0 AS is_disabled') . ' FROM users ORDER BY username'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Le panneau de contrôle du service reste accessible si MySQL est indisponible.
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Interface d'Administration - Serveur Démineur</title>
    <link rel="icon" type="image/png" sizes="64x64" href="/favicon-64.png">
    <!-- Bootstrap CSS -->
    <link href="/assets/vendor/bootstrap/5.3.0/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/admin.css?v=<?= (int) filemtime(__DIR__ . '/admin.css') ?>" rel="stylesheet">
    <!-- jQuery (pour AJAX) -->
    <script src="/assets/vendor/jquery/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="/assets/vendor/bootstrap/5.3.0/bootstrap.bundle.min.js"></script>
</head>
<body>
<!-- Menu Burger -->
<nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Serveur Démineur</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/admin">Admin</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/ia/deminium">Démineur IA</a>
                </li>
                <li class="nav-item"><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><button class="btn btn-link nav-link" type="submit">Déconnexion</button></form></li>
            </ul>
        </div>
    </div>
</nav>
<div class="container">
    <h1 class="text-center">Interface d'Administration du Serveur Démineur</h1>
    
    <div class="status text-center">
        <p><strong>Statut du Serveur :</strong> <span id="server-status">Vérification...</span></p>
        <p><strong>Joueurs Connectés :</strong> <span id="connected-players">0</span></p>
        <p><strong>Parties actives :</strong> <span id="active-games">0</span> — <strong>Reconnexions :</strong> <span id="pending-reconnects">0</span></p>
        <p><strong>Latence SQL moyenne :</strong> <span id="move-sql-latency">0</span> ms — <strong>Erreurs WebSocket :</strong> <span id="websocket-errors">0</span></p>
        <p><strong>Sauvegarde :</strong> <span id="backup-status">Vérification...</span> — <strong>Test de restauration :</strong> <span id="restore-status">Vérification...</span></p>
        <p><strong>Dernière sauvegarde :</strong> <span id="last-backup">Jamais</span> — <strong>Dernière restauration testée :</strong> <span id="last-restore">Jamais</span></p>
        <p><strong>Usage CPU :</strong> <span id="cpu-usage">Calcul...</span>%</p>
        <p><strong>Usage Mémoire :</strong> <span id="memory-usage">Calcul...</span>%</p>
    </div>
    
    <div class="d-flex justify-content-center gap-3">
        <button id="start-button" class="btn btn-success">Démarrer le Serveur</button>
        <button id="stop-button" class="btn btn-danger">Arrêter le Serveur</button>
    </div>

    <section class="card mt-5">
        <div class="card-body">
            <h2 class="h4">Réinitialisation des scores</h2>
            <p class="text-muted">Cette action remet à zéro les parties jouées, victoires et égalités. L’historique des parties est conservé.</p>
            <div class="alert alert-warning py-2">Arrêtez le serveur avant de réinitialiser les scores.</div>
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label for="score-player" class="form-label">Joueur</label>
                    <select id="score-player" class="form-select" <?= !$players ? 'disabled' : '' ?>>
                        <option value="">Sélectionnez un joueur</option>
                        <?php foreach ($players as $player): ?>
                            <option value="<?= (int) $player['id'] ?>">
                                <?= htmlspecialchars($player['username'], ENT_QUOTES, 'UTF-8') ?> — <?= (int) $player['games_played'] ?> partie(s), <?= (int) $player['games_won'] ?> victoire(s), <?= (int) $player['games_draw'] ?> égalité(s)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-grid">
                    <button id="reset-player-scores" class="btn btn-warning" <?= !$players ? 'disabled' : '' ?>>Réinitialiser ce joueur</button>
                </div>
                <div class="col-12 d-grid d-md-flex justify-content-md-end">
                    <button id="reset-all-scores" class="btn btn-outline-danger" <?= !$players ? 'disabled' : '' ?>>Réinitialiser tous les joueurs</button>
                </div>
            </div>
        </div>
    </section>

    <section class="card mt-4">
        <div class="card-body">
            <h2 class="h4">Gestion des comptes</h2>
            <?php if (!$playerBlockingAvailable): ?>
                <div class="alert alert-warning">Appliquez la migration <code>install/migrations/20260715_admin_players.sql</code> pour activer cette fonction.</div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-sm align-middle">
                    <thead><tr><th>Joueur</th><th>État</th><th class="text-end">Action</th></tr></thead>
                    <tbody><?php foreach ($players as $player): ?><tr>
                        <td><?= htmlspecialchars($player['username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $player['is_disabled'] ? '<span class="badge bg-danger">Désactivé</span>' : '<span class="badge bg-success">Actif</span>' ?></td>
                        <td class="text-end"><button class="btn btn-sm <?= $player['is_disabled'] ? 'btn-outline-success' : 'btn-outline-danger' ?> toggle-player" data-player="<?= (int) $player['id'] ?>" data-disabled="<?= $player['is_disabled'] ? '0' : '1' ?>"><?= $player['is_disabled'] ? 'Réactiver' : 'Désactiver' ?></button></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </section>
    
    <div class="message text-center mt-4">
        <div id="alert-container"></div>
    </div>
</div>

<script src="/admin/admin.js?v=<?= (int) filemtime(__DIR__ . '/admin.js') ?>"></script>
</body>
</html>
