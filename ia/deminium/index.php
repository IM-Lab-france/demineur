<?php
require_once __DIR__ . '/../../admin/bootstrap.php';
require_admin(false);
$csrf = csrf_token();
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
        <table class="table table-bordered mt-4">
            <thead>
                <tr>
                    <th>Nom de l'IA</th>
                    <th>Mode Invite</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="ia-table-body">
                <?php foreach ($iaList as $iaPath): ?>
                    <?php
                    $iaName = basename($iaPath);
                    $initialized = file_exists("$iaPath/env"); // Vérifie si les dépendances sont installées
                    $pidFile = "$iaPath/pid";
                    $running = file_exists($pidFile) && posix_kill(file_get_contents($pidFile), 0);
                    ?>
                    <tr id="ia-row-<?php echo $iaName; ?>">
                        <td>
                            <!-- Icône de suppression désactivée si l'IA est en cours d'exécution -->
                            <span class="delete-icon <?php echo $running ? 'disabled' : ''; ?>" data-ia="<?php echo $iaName; ?>">
                                🗑️
                            </span>
                            <?php echo htmlspecialchars($iaName); ?>
                        </td>
                        <td class="text-center">
                            <input type="checkbox" class="invite-checkbox" data-ia="<?php echo $iaName; ?>">
                        </td>
                        <td>
                            <button class="btn btn-primary initialize-btn"
                                    data-ia="<?php echo $iaName; ?>"
                                    <?php echo $initialized ? 'disabled' : ''; ?>>
                                Initialiser
                            </button>
                            <button class="btn btn-success start-btn"
                                    data-ia="<?php echo $iaName; ?>"
                                    <?php echo ($running || !$initialized) ? 'disabled' : ''; ?>>
                                Démarrer
                            </button>
                            <button class="btn btn-danger stop-btn"
                                    data-ia="<?php echo $iaName; ?>"
                                    <?php echo !$running ? 'disabled' : ''; ?>>
                                Arrêter
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

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
