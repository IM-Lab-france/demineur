<?php
require_once __DIR__ . '/../../admin/bootstrap.php';
require_once __DIR__ . '/ai_config.php';
require_admin(false);
$csrf = csrf_token();
$adminDb = new Database();
// index.php
$pluginsDir = './plugins';

// Récupérer la liste des IA disponibles, en excluant les répertoires cachés et le template
$iaList = array_filter(glob($pluginsDir . '/*'), function($dir) {
    $base = basename($dir);
    return is_dir($dir) && $base[0] !== '.' && $base !== '.template';
});
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>Gestion des IA - Démineur Multijoueur</title>
    <link rel="icon" type="image/png" sizes="64x64" href="/favicon-64.png">
    <!-- Inclure Bootstrap CSS -->
    <link rel="stylesheet" href="/assets/vendor/bootstrap/4.5.0/bootstrap.min.css">
    <link rel="stylesheet" href="/ia/deminium/admin-ai.css?v=<?= (int) filemtime(__DIR__ . '/admin-ai.css') ?>">
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
    <!-- Conteneur des Toasts -->
    <div class="toast-zone" aria-live="polite" aria-atomic="true">
        <div id="toast-container"></div>
    </div>
    
    <div class="container mt-5">
        <h1>Gestion des IA - Démineur Multijoueur</h1>
        <p class="text-muted">Configurez le comportement de chaque adversaire. Une modification appliquée à une IA active nécessite son redémarrage.</p>
        <div id="ia-table-body" class="ai-grid mt-4">
                <?php foreach ($iaList as $iaPath): ?>
                    <?php
                    $iaName = basename($iaPath);
                    $safeIaName = htmlspecialchars($iaName, ENT_QUOTES, 'UTF-8');
                    $initialized = file_exists("$iaPath/env"); // Vérifie si les dépendances sont installées
                    $pidFile = "$iaPath/pid";
                    $running = file_exists($pidFile) && posix_kill(file_get_contents($pidFile), 0);
                    $initialServiceOutput = [];
                    exec('/usr/bin/systemctl is-active --quiet ' . escapeshellarg('minesweeper-ai@' . $iaName . '.service'), $initialServiceOutput, $initialServiceCode);
                    $running = $running || $initialServiceCode === 0;
                    $initialStateFile = (is_readable('/var/log/minesweeper/ai/' . $iaName . '/state.json')
                        ? '/var/log/minesweeper/ai/' . $iaName . '/state.json'
                        : "$iaPath/logs/state.json");
                    $initialRuntimeState = is_readable($initialStateFile) ? json_decode((string) file_get_contents($initialStateFile), true) : null;
                    $inGame = $running && is_array($initialRuntimeState) && !empty($initialRuntimeState['inGame']);
                    $config = read_ai_config($iaName);
                    $policyStmt = $adminDb->getPDO()->prepare('SELECT ai_friend_policy FROM users WHERE username=:username AND is_ai=1');
                    $policyStmt->execute(['username' => $iaName]);
                    $friendPolicy = (string) ($policyStmt->fetchColumn() ?: 'manual');
                    $memory = ['games' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'moves' => 0, 'decision_ms_total' => 0, 'decision_errors' => 0];
                    $memoryPath = "$iaPath/memory.json";
                    if (is_readable($memoryPath) && filesize($memoryPath) <= 65536) {
                        $loadedMemory = json_decode((string) file_get_contents($memoryPath), true);
                        if (is_array($loadedMemory)) {
                            foreach ($memory as $key => $unused) $memory[$key] = max(0, (int) ($loadedMemory[$key] ?? 0));
                        }
                    }
                    $winRate = $memory['games'] > 0 ? round(100 * $memory['wins'] / $memory['games'], 1) : 0;
                    $averageDecision = $memory['moves'] > 0 ? round($memory['decision_ms_total'] / $memory['moves']) : 0;
                    ?>
                    <section class="ai-card" id="ia-row-<?= $safeIaName ?>" data-ia="<?= $safeIaName ?>">
                        <header class="ai-card-header">
                            <div>
                                <span class="status-dot <?= $running ? 'running' : '' ?>" aria-hidden="true"></span>
                                <h2><?= $safeIaName ?></h2>
                                <span class="status-label"><?= $running ? 'Démarrée' : 'Arrêtée' ?></span>
                            </div>
                            <button type="button" class="delete-icon btn btn-link text-danger <?= $running ? 'disabled' : '' ?>" data-ia="<?= $safeIaName ?>" aria-label="Supprimer l’IA <?= $safeIaName ?>">🗑️</button>
                        </header>

                        <div class="ai-stats" aria-label="Statistiques de <?= $safeIaName ?>">
                            <span><strong><?= $memory['games'] ?></strong> parties</span>
                            <span><strong><?= $memory['wins'] ?></strong> victoires</span>
                            <span><strong><?= $memory['losses'] ?></strong> défaites</span>
                            <span><strong><?= $memory['draws'] ?></strong> égalités</span>
                            <span><strong><?= $winRate ?> %</strong> réussite</span>
                            <span><strong><?= $averageDecision ?> ms</strong> décision</span>
                            <span><strong><?= $memory['decision_errors'] ?></strong> erreurs</span>
                        </div>

                        <form class="ai-config-form" data-ia="<?= $safeIaName ?>">
                            <div class="config-grid">
                                <div class="form-group"><label>Niveau
                                    <select class="form-control" name="level">
                                        <option value="easy" <?= $config['level'] === 'easy' ? 'selected' : '' ?>>Débutant</option>
                                        <option value="medium" <?= $config['level'] === 'medium' ? 'selected' : '' ?>>Normal</option>
                                        <option value="hard" <?= $config['level'] === 'hard' ? 'selected' : '' ?>>Difficile</option>
                                        <option value="expert" <?= $config['level'] === 'expert' ? 'selected' : '' ?>>Expert</option>
                                        <option value="master" <?= $config['level'] === 'master' ? 'selected' : '' ?>>Maître</option>
                                    </select></label></div>
                                <div class="form-group"><label>Temps de réflexion (ms)
                                    <input class="form-control" type="number" name="pause" min="100" max="10000" step="100" value="<?= $config['pause'] ?>">
                                </label></div>
                                <div class="form-group"><label>Variation du délai (ms)
                                    <input class="form-control" type="number" name="jitter" min="0" max="5000" step="50" value="<?= $config['jitter'] ?>">
                                </label></div>
                                <div class="form-group"><label>Prise de risque (%)
                                    <input class="form-control risk-input" type="range" name="risk" min="0" max="100" value="<?= $config['risk'] ?>">
                                    <output class="risk-value"><?= $config['risk'] ?> %</output>
                                </label></div>
                                <div class="form-group"><label>Grille créée
                                    <select class="form-control" name="gridSize">
                                        <?php foreach (['10x10', '20x20', '30x30'] as $size): ?><option value="<?= $size ?>" <?= $config['gridSize'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
                                    </select></label></div>
                                <div class="form-group"><label>Difficulté créée
                                    <select class="form-control" name="difficulty">
                                        <option value="10" <?= $config['difficulty'] === 10 ? 'selected' : '' ?>>Facile — 10 %</option>
                                        <option value="15" <?= $config['difficulty'] === 15 ? 'selected' : '' ?>>Moyenne — 15 %</option>
                                        <option value="22" <?= $config['difficulty'] === 22 ? 'selected' : '' ?>>Difficile — 22 %</option>
                                    </select></label></div>
                                <div class="form-group"><label>Invitations automatiques
                                    <select class="form-control" name="inviteTarget">
                                        <option value="none" <?= $config['inviteTarget'] === 'none' ? 'selected' : '' ?>>Désactivées</option>
                                        <option value="human" <?= $config['inviteTarget'] === 'human' ? 'selected' : '' ?>>Joueurs humains</option>
                                        <option value="ai" <?= $config['inviteTarget'] === 'ai' ? 'selected' : '' ?>>Autres IA</option>
                                        <option value="all" <?= $config['inviteTarget'] === 'all' ? 'selected' : '' ?>>Tous les joueurs</option>
                                    </select></label></div>
                                <div class="form-group"><label>Demandes d’amitié
                                    <select class="form-control" name="friendPolicy">
                                        <option value="manual" <?= $friendPolicy === 'manual' ? 'selected' : '' ?>>Validation manuelle</option>
                                        <option value="auto_accept" <?= $friendPolicy === 'auto_accept' ? 'selected' : '' ?>>Acceptation automatique</option>
                                        <option value="reject" <?= $friendPolicy === 'reject' ? 'selected' : '' ?>>Toujours refuser</option>
                                    </select></label></div>
                            </div>
                            <div class="option-switches">
                                <label><input type="checkbox" name="autoAccept" value="1" <?= $config['autoAccept'] ? 'checked' : '' ?>> Accepter les invitations</label>
                                <label><input type="checkbox" name="rematch" value="1" <?= $config['rematch'] ? 'checked' : '' ?>> Proposer une revanche</label>
                                <label><input type="checkbox" name="useFlags" value="1" <?= $config['useFlags'] ? 'checked' : '' ?>> Utiliser les drapeaux</label>
                            </div>
                            <p class="config-state text-muted" aria-live="polite"></p>
                        </form>

                        <footer class="ai-actions">
                            <button class="btn btn-primary initialize-btn"
                                    data-ia="<?= $safeIaName ?>"
                                    <?= $initialized ? 'disabled' : '' ?>>
                                Initialiser
                            </button>
                            <button class="btn btn-outline-primary save-config-btn" data-ia="<?= $safeIaName ?>">Enregistrer</button>
                            <button class="btn btn-outline-secondary reset-stats-btn" data-ia="<?= $safeIaName ?>" <?= $running ? 'disabled' : '' ?>>Réinitialiser les stats</button>
                            <button class="btn btn-success start-btn"
                                    data-ia="<?= $safeIaName ?>"
                                    <?= ($running || !$initialized) ? 'disabled' : '' ?>>
                                Démarrer
                            </button>
                            <button class="btn btn-danger stop-btn"
                                    data-ia="<?= $safeIaName ?>"
                                    <?= !$running ? 'disabled' : '' ?>>
                                Arrêter
                            </button>
                            <button class="btn btn-warning leave-game-btn" data-ia="<?= $safeIaName ?>" <?= !$inGame ? 'disabled' : '' ?>>Quitter la partie</button>
                        </footer>
                    </section>
                <?php endforeach; ?>
        </div>

        <!-- Bouton Nouveau -->
        <button class="btn btn-info" id="new-ia-btn">Nouveau</button>
    </div>

    <!-- Modale pour la création d'une nouvelle IA -->
    <div class="modal fade" id="newIaModal" tabindex="-1" role="dialog" aria-labelledby="newIaModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form id="new-ia-form">
                    <div class="modal-header">
                        <h5 class="modal-title" id="newIaModalLabel">Créer une nouvelle IA</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Fermer">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="new-ia-modal-body">
                        <!-- Contenu dynamique -->
                    </div>
                    <div class="modal-footer" id="new-ia-modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Suivant</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modale de confirmation pour la suppression d'une IA -->
    <div class="modal fade" id="deleteIaModal" tabindex="-1" role="dialog" aria-labelledby="deleteIaModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form id="delete-ia-form">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteIaModalLabel">Supprimer l'IA</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Fermer">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="delete-ia-modal-body">
                        <!-- Contenu dynamique -->
                    </div>
                    <div class="modal-footer" id="delete-ia-modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">Supprimer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modèle de Toast -->
    <div id="toast-template" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="5000">
        <div class="toast-header">
            <strong class="mr-auto">Notification</strong>
            <small class="text-muted">Maintenant</small>
            <button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Fermer">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="toast-body">
            <!-- Message -->
        </div>
    </div>

    <!-- Inclure jQuery et Bootstrap JS -->
    <script src="/assets/vendor/jquery/jquery-3.5.1.min.js"></script>
    <script src="/assets/vendor/bootstrap/4.5.0/bootstrap.bundle.min.js"></script>



    <!-- Script AJAX et Toast -->
    <script src="/ia/deminium/admin-ai.js?v=<?= (int) filemtime(__DIR__ . '/admin-ai.js') ?>"></script>
</body>
</html>
