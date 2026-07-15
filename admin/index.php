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
        'SELECT id, username, games_played, games_won, games_draw, is_admin, is_ai' . ($playerBlockingAvailable ? ', is_disabled' : ', 0 AS is_disabled') . ' FROM users ORDER BY username'
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
                <li class="nav-item"><a class="nav-link" href="/admin/security.php">Sécurité</a></li>
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
        <p><strong>Supervision :</strong> <span id="health-status">Vérification...</span></p>
        <p><strong>Envoi des e-mails :</strong> <span id="mail-status">Vérification...</span></p>
        <p><strong>Usage CPU :</strong> <span id="cpu-usage">Calcul...</span>%</p>
        <p><strong>Usage Mémoire :</strong> <span id="memory-usage">Calcul...</span>%</p>
    </div>
    
    <div class="d-flex justify-content-center gap-3">
        <button id="start-button" class="btn btn-success">Démarrer le Serveur</button>
        <button id="stop-button" class="btn btn-danger">Arrêter le Serveur</button>
    </div>

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
