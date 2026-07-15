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
    <title>Gestion des IA - Démineur Multijoueur</title>
    <link rel="icon" type="image/png" sizes="64x64" href="/favicon-64.png">
    <!-- Inclure Bootstrap CSS -->
    <link rel="stylesheet" href="/assets/vendor/bootstrap/4.5.0/bootstrap.min.css">
    <style>
        /* Optionnel : personnalisation des toasts */
        .toast {
            min-width: 250px;
        }
        .delete-icon {
            cursor: pointer;
            color: red;
            margin-left: 10px;
        }
    </style>
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
    <div aria-live="polite" aria-atomic="true" style="position: fixed; top: 1rem; right: 1rem; min-width: 300px;">
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
                            <span class="delete-icon <?php echo $running ? 'disabled' : ''; ?>" 
                                data-ia="<?php echo $iaName; ?>" 
                                style="<?php echo $running ? 'cursor: not-allowed; color: grey;' : ''; ?>">
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
    <script>
        
        
        $(document).ready(function () {
            $.ajaxSetup({ headers: { 'X-CSRF-Token': <?= json_encode($csrf) ?> } });

            // Gestion de l'ouverture et de la fermeture du menu burger
            const navbarToggler = document.querySelector(".navbar-toggler");
            const navbarCollapse = document.querySelector(".navbar-collapse");
            const navLinks = document.querySelectorAll(".nav-link");

            // Ouvrir ou fermer le menu burger lors du clic sur le bouton navbar-toggler
            navbarToggler.addEventListener("click", function () {
                navbarCollapse.classList.toggle("show");
            });

            // Fermer le menu burger lorsque l'on clique sur un lien
            navLinks.forEach(link => {
                link.addEventListener("click", function () {
                    if (window.innerWidth < 992) { // Fermer seulement en mode mobile
                        navbarCollapse.classList.remove("show");
                    }
                });
            });

            // Fermer le menu burger si un clic est effectué à l'extérieur de celui-ci
            document.addEventListener("click", function (event) {
                const isClickInsideMenu = navbarCollapse.contains(event.target) || navbarToggler.contains(event.target);
                if (!isClickInsideMenu && navbarCollapse.classList.contains("show")) {
                    navbarCollapse.classList.remove("show");
                }
            });
                
            
            // Variables pour stocker le nom et le mot de passe de l'IA
            var iaName = '';
            var iaPassword = '';

            // Afficher un toast avec le journal de log complet
            function showToast(message, type = 'info', log = '') {
                var $toast = $('#toast-template').clone();
                $toast.removeAttr('id').addClass('bg-' + type + ' text-white');
                const body = $toast.find('.toast-body').empty();
                body.append(document.createTextNode(String(message)));
                if (log) body.append($('<pre>').text(String(log)));
                
                // Ajouter au conteneur et afficher
                $('#toast-container').append($toast);
                $toast.toast('show');
                $toast.on('hidden.bs.toast', function () {
                    $(this).remove();
                });
            }

            // Fonction pour récupérer l'état de la checkbox "Mode Invite"
            function getInviteStatus(iaName) {
                return $('#ia-row-' + iaName + ' .invite-checkbox').is(':checked') ? 1 : 0;
            }

            // Gestion du clic sur le bouton "Initialiser"
            $('.initialize-btn').click(function () {
                var iaName = $(this).data('ia');
                var button = $(this);
                $.ajax({
                    url: 'initialize.php',
                    method: 'POST',
                    data: { iaName: iaName },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            showToast(response.message, 'success');
                            button.prop('disabled', true);
                            $('#ia-row-' + iaName + ' .start-btn').prop('disabled', false);
                        } else {
                            showToast(response.message, 'danger', response.log || '');
                        }
                    },
                    error: function () {
                        showToast('Erreur lors de l\'initialisation.', 'danger');
                    }
                });
            });

            // Gestion du clic sur le bouton "Démarrer"
            // Gestion du clic sur le bouton "Démarrer"
            $('.start-btn').click(function () {
                var iaName = $(this).data('ia');
                var button = $(this);
                var invite = getInviteStatus(iaName);
                $.ajax({
                    url: 'start.php',
                    method: 'POST',
                    data: { iaName: iaName, invite: invite },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            showToast('IA démarrée avec succès.', 'success');
                            button.prop('disabled', true);
                            $('#ia-row-' + iaName + ' .stop-btn').prop('disabled', false);
                            $('#ia-row-' + iaName + ' .delete-icon').addClass('disabled').css({ 'cursor': 'not-allowed', 'color': 'grey' });
                        } else {
                            showToast(response.message, 'danger');
                        }
                    },
                    error: function () {
                        showToast('Erreur lors du démarrage.', 'danger');
                    }
                });
            });

            // Gestion du clic sur le bouton "Arrêter"
            $('.stop-btn').click(function () {
                var iaName = $(this).data('ia');
                var button = $(this);
                $.ajax({
                    url: 'stop.php',
                    method: 'POST',
                    data: { iaName: iaName },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            showToast('IA arrêtée avec succès.', 'success');
                            button.prop('disabled', true);
                            $('#ia-row-' + iaName + ' .start-btn').prop('disabled', false);
                            $('#ia-row-' + iaName + ' .delete-icon').removeClass('disabled').css({ 'cursor': 'pointer', 'color': 'red' });
                        } else {
                            showToast(response.message, 'danger');
                        }
                    },
                    error: function () {
                        showToast('Erreur lors de l\'arrêt.', 'danger');
                    }
                });
            });

            // Gestion du clic sur le bouton "Nouveau"
            $('#new-ia-btn').click(function () {
                // Réinitialiser les variables
                iaName = '';
                iaPassword = '';
                // Ouvrir la modale et charger le premier formulaire
                loadIaNameForm();
                $('#newIaModal').modal('show');
            });

            // Fonction pour charger le formulaire du nom de l'IA
            function loadIaNameForm() {
                $('#newIaModalLabel').text('Créer une nouvelle IA');
                $('#new-ia-modal-body').html(`
                    <div class="form-group">
                        <label for="ia-name">Nom de l'IA</label>
                        <input type="text" class="form-control" id="ia-name" name="iaName" required>
                        <small class="form-text text-muted">Le nom ne doit pas contenir d'espaces ni de caractères spéciaux.</small>
                    </div>
                `);
                $('#new-ia-modal-footer .btn-primary').text('Suivant');
            }

            // Fonction pour charger le formulaire du mot de passe
            function loadIaPasswordForm() {
                $('#newIaModalLabel').text('Définir le mot de passe');
                $('#new-ia-modal-body').html(`
                    <div class="form-group">
                        <label for="ia-password">Mot de passe</label>
                        <input type="password" class="form-control" id="ia-password" name="iaPassword" required>
                    </div>
                `);
                $('#new-ia-modal-footer .btn-primary').text('Créer');
            }

            // Validation du nom de l'IA
            function validateIaName(name) {
                var regex = /^[a-zA-Z0-9_-]+$/;
                return regex.test(name);
            }

            // Soumission du formulaire de création d'une nouvelle IA
            $('#new-ia-form').submit(function (e) {
                e.preventDefault();

                if (iaName === '') {
                    // Étape 1 : Récupérer et valider le nom de l'IA
                    iaName = $('#ia-name').val().trim();

                    if (!validateIaName(iaName)) {
                        showToast('Le nom de l\'IA n\'est pas valide. Utilisez uniquement des lettres, chiffres, tirets et underscores.', 'warning');
                        iaName = '';
                        return;
                    }

                    // Vérifier si le nom de l'IA existe déjà dans ia_accounts.json
                    $.ajax({
                        url: 'check_ia_name.php',
                        method: 'POST',
                        data: { iaName: iaName },
                        dataType: 'json',
                        success: function (response) {
                            if (response.exists) {
                                showToast('Une IA avec ce nom existe déjà.', 'warning');
                                iaName = '';
                            } else {
                                // Passer à l'étape 2 : Demander le mot de passe
                                loadIaPasswordForm();
                            }
                        },
                        error: function () {
                            showToast('Erreur lors de la vérification du nom de l\'IA.', 'danger');
                            iaName = '';
                        }
                    });

                } else {
                    // Étape 2 : Récupérer le mot de passe
                    iaPassword = $('#ia-password').val().trim();

                    if (iaPassword === '') {
                        showToast('Le mot de passe ne peut pas être vide.', 'warning');
                        return;
                    }

                    // Envoyer la requête pour créer l'IA
                    $.ajax({
                        url: 'create_ia.php',
                        method: 'POST',
                        data: {
                            iaName: iaName,
                            iaPassword: iaPassword
                        },
                        dataType: 'json',
                        success: function (response) {
                            if (response.success) {
                                showToast(response.message, 'success');
                                // Fermer la modale
                                $('#newIaModal').modal('hide');
                                // Rafraîchir la liste des IA
                                location.reload();
                            } else {
                                showToast(response.message, 'danger');
                            }
                        },
                        error: function () {
                            showToast('Erreur lors de la création de l\'IA.', 'danger');
                        }
                    });
                }
            });

            // Gestion du clic sur l'icône de suppression
            $('.delete-icon').click(function () {
                if ($(this).hasClass('disabled')) {
                    showToast("Veuillez arrêter l'IA avant de la supprimer.", "warning");
                    return;
                }
                
                var iaName = $(this).data('ia');
                loadDeleteIaModal(iaName);
                $('#deleteIaModal').modal('show');
            });

            // Fonction pour charger la modale de confirmation de suppression
            function loadDeleteIaModal(iaName) {
                $('#deleteIaModalLabel').text('Supprimer l\'IA ' + iaName);
                $('#delete-ia-modal-body').html(`
                    <p>Êtes-vous sûr de vouloir supprimer l'IA "<strong>${iaName}</strong>" ?</p>
                    <p>Pour confirmer, saisissez le nom de l'IA ci-dessous :</p>
                    <div class="form-group">
                        <input type="text" class="form-control" id="confirm-ia-name" placeholder="Nom de l'IA" required>
                    </div>
                `);
                // Stocker le nom de l'IA dans un champ caché
                $('#delete-ia-form').data('iaName', iaName);
            }

            // Soumission du formulaire de suppression
            $('#delete-ia-form').submit(function (e) {
                e.preventDefault();
                var iaName = $(this).data('iaName');
                var confirmIaName = $('#confirm-ia-name').val().trim();

                if (confirmIaName !== iaName) {
                    showToast('Le nom saisi ne correspond pas. Suppression annulée.', 'warning');
                    return;
                }

                // Envoyer la requête pour supprimer l'IA
                $.ajax({
                    url: 'delete_ia.php',
                    method: 'POST',
                    data: { iaName: iaName },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            showToast(response.message, 'success');
                            // Fermer la modale
                            $('#deleteIaModal').modal('hide');
                            // Supprimer la ligne du tableau
                            $('#ia-row-' + iaName).remove();
                        } else {
                            showToast(response.message, 'danger');
                        }
                    },
                    error: function () {
                        showToast('Erreur lors de la suppression de l\'IA.', 'danger');
                    }
                });
            });

            // Fonction pour mettre à jour l'état des boutons
            function updateStatus() {
                $.ajax({
                    url: 'status.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function (response) {
                        for (var iaName in response) {
                            var status = response[iaName];
                            var row = $('#ia-row-' + iaName);
                            if (row.length) {
                                row.find('.initialize-btn').prop('disabled', status.initialized);
                                row.find('.start-btn').prop('disabled', status.running);
                                row.find('.stop-btn').prop('disabled', !status.running);
                                row.find('.delete-icon').toggleClass('disabled', status.running).css({
                                    'cursor': status.running ? 'not-allowed' : 'pointer',
                                    'color': status.running ? 'grey' : 'red'
                                });
                            } else {
                                location.reload();
                            }
                        }
                    },
                    error: function () {
                        showToast('Erreur lors de la mise à jour du statut.', 'danger');
                    }
                });
            }

            // Actualiser l'état toutes les 5 secondes
            setInterval(updateStatus, 5000);

            // Initialiser les toasts
            $('.toast').toast({ delay: 5000 });
        });
    </script>
</body>
</html>
