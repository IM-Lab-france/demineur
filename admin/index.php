<?php
require_once __DIR__ . '/bootstrap.php';
require_admin(false);
$csrf = csrf_token();
$players = [];
$playerBlockingAvailable = false;
$blockHistory = [];
try {
    $db = new Database();
    $columns = $db->getPDO()->query("SHOW COLUMNS FROM users LIKE 'is_disabled'")->fetchAll(PDO::FETCH_ASSOC);
    $playerBlockingAvailable = count($columns) > 0;
    $players = $db->getPDO()->query(
        'SELECT id, username, games_played, games_won, games_draw, is_admin, is_ai' . ($playerBlockingAvailable ? ', is_disabled' : ', 0 AS is_disabled') . ' FROM users ORDER BY username'
    )->fetchAll(PDO::FETCH_ASSOC);
    try {
        $blockHistory = $db->getPDO()->query(
            "SELECT blocker.username AS blocker, blocked.username AS blocked, b.created_at, b.unblocked_at FROM user_blocks b JOIN users blocker ON blocker.id=b.blocker_id JOIN users blocked ON blocked.id=b.blocked_id WHERE b.created_at >= CURRENT_TIMESTAMP - INTERVAL 90 DAY ORDER BY b.created_at DESC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) {}
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
                <li class="nav-item"><a class="nav-link" href="/admin/security.php">Sécurité</a></li>
                <li class="nav-item"><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><button class="btn btn-link nav-link" type="submit">Déconnexion</button></form></li>
            </ul>
        </div>
    </div>
</nav>
<div class="container">
    <header class="admin-heading">
        <p class="admin-kicker">Centre de contrôle</p>
        <h1>Administration du Démineur</h1>
        <p>État des services et opérations essentielles en temps réel.</p>
    </header>

    <section class="dashboard-grid" aria-label="État du serveur">
        <article id="server-card" class="dashboard-card dashboard-card-primary">
            <div class="card-icon" aria-hidden="true">●</div>
            <div><p class="dashboard-label">Serveur de jeu</p><p id="server-status" class="dashboard-value">Vérification…</p></div>
            <div class="server-actions">
                <button id="start-button" class="btn btn-sm btn-success">Démarrer</button>
                <button id="stop-button" class="btn btn-sm btn-danger">Arrêter</button>
            </div>
        </article>
        <article class="dashboard-card">
            <p class="dashboard-label">Joueurs connectés</p>
            <p id="connected-players" class="dashboard-number">0</p>
            <p class="dashboard-detail">présents actuellement</p>
        </article>
        <article class="dashboard-card">
            <p class="dashboard-label">Parties</p>
            <p class="dashboard-number"><span id="active-games">0</span></p>
            <p class="dashboard-detail"><span id="pending-reconnects">0</span> reconnexion(s)</p>
        </article>
        <article class="dashboard-card">
            <p class="dashboard-label">Performances</p>
            <p class="dashboard-value"><span id="move-sql-latency">0</span> ms SQL</p>
            <p class="dashboard-detail"><span id="websocket-errors">0</span> erreur(s) WebSocket</p>
        </article>
        <article class="dashboard-card dashboard-card-wide">
            <p class="dashboard-label">Sauvegardes automatiques</p>
            <div class="dashboard-split"><div><span class="mini-label">Planification</span><strong id="backup-status">Vérification…</strong></div><div><span class="mini-label">Test</span><strong id="restore-status">Vérification…</strong></div></div>
            <div class="dashboard-dates"><span>Dernière : <strong id="last-backup">Jamais</strong></span><span>Testée : <strong id="last-restore">Jamais</strong></span></div>
        </article>
        <article id="health-card" class="dashboard-card dashboard-card-wide">
            <p class="dashboard-label">Supervision</p>
            <p id="health-status" class="dashboard-value dashboard-value-small">Vérification…</p>
            <p class="dashboard-detail">contrôle global de l’application</p>
        </article>
        <article id="mail-card" class="dashboard-card dashboard-card-wide">
            <p class="dashboard-label">E-mails transactionnels</p>
            <p id="mail-status" class="dashboard-value dashboard-value-small">Vérification…</p>
            <p class="dashboard-detail">validation et récupération des comptes</p>
        </article>
        <article class="dashboard-card dashboard-card-wide">
            <p class="dashboard-label">Ressources du serveur</p>
            <div class="resource-row"><span>CPU</span><div class="resource-track"><span id="cpu-bar"></span></div><strong><span id="cpu-usage">0</span>%</strong></div>
            <div class="resource-row"><span>Mémoire</span><div class="resource-track"><span id="memory-bar"></span></div><strong><span id="memory-usage">0</span>%</strong></div>
        </article>
    </section>

    <section class="card mt-4"><div class="card-body">
        <h2 class="h4">Historique des blocages (90 jours)</h2>
        <?php if (!$blockHistory): ?><p class="text-muted mb-0">Aucun blocage récent.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Joueur</th><th>A bloqué</th><th>Date</th><th>État</th></tr></thead><tbody>
        <?php foreach ($blockHistory as $block): ?><tr><td><?= htmlspecialchars($block['blocker'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($block['blocked'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($block['created_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= $block['unblocked_at'] ? 'Débloqué' : '<span class="badge bg-danger">Actif</span>' ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div></section>

    <section class="card mt-5">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><h2 class="h4 mb-1">Sauvegardes</h2><p class="text-muted mb-0">Sauvegardes SQL contrôlées et restauration protégée.</p></div>
                <button id="create-backup" class="btn btn-primary">Créer une sauvegarde</button>
            </div>
            <div id="backup-operation" class="alert alert-info mt-3 d-none" role="status"></div>
            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle">
                    <thead><tr><th>Date</th><th>Taille SQL</th><th>Contrôle</th><th class="text-end">Actions</th></tr></thead>
                    <tbody id="backup-list"><tr><td colspan="4" class="text-muted">Chargement…</td></tr></tbody>
                </table>
            </div>
            <p class="small text-muted mb-0">La restauration concerne uniquement la base de données. Une sauvegarde de secours est créée automatiquement avant toute restauration.</p>
        </div>
    </section>

    <section class="card mt-5">
        <div class="card-body">
            <h2 class="h4">Réinitialisation des scores</h2>
            <p class="text-muted">Cette action remet à zéro les parties, les résultats et le classement Elo (1200). L’historique reste archivé mais n’est plus compté dans les tableaux.</p>
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
            <p class="text-muted">La suppression efface définitivement le compte, ses sessions, ses e-mails en attente et tout son historique de jeu.</p>
            <div class="alert alert-warning py-2">La suppression redémarre brièvement le serveur et déconnecte les joueurs. Les administrateurs et les IA se gèrent depuis leurs interfaces dédiées.</div>
            <?php if (!$playerBlockingAvailable): ?>
                <div class="alert alert-warning">Appliquez la migration <code>install/migrations/20260715_admin_players.sql</code> pour activer cette fonction.</div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-sm align-middle">
                    <thead><tr><th>Joueur</th><th>Type</th><th>État</th><th class="text-end">Actions</th></tr></thead>
                    <tbody><?php foreach ($players as $player): ?><tr>
                        <td><?= htmlspecialchars($player['username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $player['is_admin'] ? 'Administrateur' : ($player['is_ai'] ? 'IA' : 'Joueur') ?></td>
                        <td><?= $player['is_disabled'] ? '<span class="badge bg-danger">Désactivé</span>' : '<span class="badge bg-success">Actif</span>' ?></td>
                        <td class="text-end">
                            <?php if (!$player['is_admin']): ?><button class="btn btn-sm <?= $player['is_disabled'] ? 'btn-outline-success' : 'btn-outline-secondary' ?> toggle-player" data-player="<?= (int) $player['id'] ?>" data-disabled="<?= $player['is_disabled'] ? '0' : '1' ?>"><?= $player['is_disabled'] ? 'Réactiver' : 'Désactiver' ?></button><?php endif; ?>
                            <?php if (!$player['is_admin'] && !$player['is_ai']): ?><button class="btn btn-sm btn-danger delete-player ms-1" data-player="<?= (int) $player['id'] ?>" data-username="<?= htmlspecialchars($player['username'], ENT_QUOTES, 'UTF-8') ?>">Supprimer</button><?php endif; ?>
                        </td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </section>
    
    <div class="message text-center mt-4">
        <div id="alert-container"></div>
    </div>
</div>

<div class="modal fade" id="restore-modal" tabindex="-1" aria-labelledby="restore-modal-title" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content"><form id="restore-form">
        <div class="modal-header"><h2 class="modal-title fs-5" id="restore-modal-title">Restaurer une sauvegarde</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
        <div class="modal-body">
            <div class="alert alert-danger">Cette opération remplace toute la base actuelle, déconnecte les joueurs et redémarre les services.</div>
            <p>Sauvegarde : <code id="restore-backup-label"></code></p>
            <input type="hidden" id="restore-backup-id">
            <label for="restore-password" class="form-label">Mot de passe administrateur</label><input id="restore-password" class="form-control mb-3" type="password" autocomplete="current-password" required>
            <label for="restore-totp" class="form-label">Code Authenticator</label><input id="restore-totp" class="form-control mb-3" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required>
            <label for="restore-confirmation" class="form-label">Saisissez <code>RESTAURER</code></label><input id="restore-confirmation" class="form-control" autocomplete="off" required>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button id="confirm-restore" type="submit" class="btn btn-danger">Restaurer la base</button></div>
    </form></div></div>
</div>

<script src="/admin/admin.js?v=<?= (int) filemtime(__DIR__ . '/admin.js') ?>"></script>
</body>
</html>
