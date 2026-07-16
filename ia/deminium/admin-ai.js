

        $(document).ready(function () {
            $.ajaxSetup({ headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content } });

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

            function getConfigData(iaName) {
                var form = $('#ia-row-' + iaName + ' .ai-config-form');
                return {
                    iaName: iaName,
                    level: form.find('[name="level"]').val(),
                    pause: form.find('[name="pause"]').val(),
                    jitter: form.find('[name="jitter"]').val(),
                    risk: form.find('[name="risk"]').val(),
                    gridSize: form.find('[name="gridSize"]').val(),
                    difficulty: form.find('[name="difficulty"]').val(),
                    inviteTarget: form.find('[name="inviteTarget"]').val(),
                    friendPolicy: form.find('[name="friendPolicy"]').val(),
                    autoAccept: form.find('[name="autoAccept"]').is(':checked') ? 1 : 0,
                    rematch: form.find('[name="rematch"]').is(':checked') ? 1 : 0,
                    useFlags: form.find('[name="useFlags"]').is(':checked') ? 1 : 0
                };
            }

            function updateLevelOptions(form) {
                var master = form.find('[name="level"]').val() === 'master';
                var risk = form.find('.risk-input');
                risk.prop('disabled', master);
                risk.siblings('.risk-value').text(master ? '0 % — analyse maximale' : risk.val() + ' %');
            }

            $('.ai-config-form').each(function () { updateLevelOptions($(this)); });
            $('.ai-config-form [name="level"]').on('change', function () { updateLevelOptions($(this).closest('form')); });
            $('.risk-input').on('input', function () {
                if (!$(this).prop('disabled')) $(this).siblings('.risk-value').text($(this).val() + ' %');
            });

            $('.save-config-btn').click(function () {
                var iaName = $(this).data('ia');
                var button = $(this);
                button.prop('disabled', true);
                $.ajax({
                    url: 'save_config.php',
                    method: 'POST',
                    data: getConfigData(iaName),
                    dataType: 'json',
                    complete: function () { button.prop('disabled', false); },
                    success: function (response) {
                        showToast(response.message, response.success ? 'success' : 'danger');
                        $('#ia-row-' + iaName + ' .config-state').text(response.requiresRestart ? 'Redémarrage nécessaire pour appliquer ces réglages.' : 'Configuration enregistrée.');
                    },
                    error: function (xhr) {
                        showToast(xhr.responseJSON?.message || 'Erreur lors de l’enregistrement.', 'danger');
                    }
                });
            });

            $('.reset-stats-btn').click(function () {
                var iaName = $(this).data('ia');
                if (!window.confirm('Réinitialiser toutes les statistiques de ' + iaName + ' ?')) return;
                $.ajax({
                    url: 'reset_stats.php',
                    method: 'POST',
                    data: { iaName: iaName },
                    dataType: 'json',
                    success: function (response) {
                        showToast(response.message, 'success');
                        window.location.reload();
                    },
                    error: function (xhr) {
                        showToast(xhr.responseJSON?.message || 'Réinitialisation impossible.', 'danger');
                    }
                });
            });

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
                $.ajax({
                    url: 'start.php',
                    method: 'POST',
                    data: getConfigData(iaName),
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            showToast('IA démarrée avec succès.', 'success');
                            button.prop('disabled', true);
                            $('#ia-row-' + iaName + ' .stop-btn').prop('disabled', false);
                            $('#ia-row-' + iaName + ' .delete-icon').addClass('disabled');
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
                            $('#ia-row-' + iaName + ' .delete-icon').removeClass('disabled');
                        } else {
                            showToast(response.message, 'danger');
                        }
                    },
                    error: function () {
                        showToast('Erreur lors de l\'arrêt.', 'danger');
                    }
                });
            });

            $('.leave-game-btn').click(function () {
                var iaName = $(this).data('ia');
                var button = $(this);
                if (!window.confirm('Demander à ' + iaName + ' de quitter sa partie en cours ?')) return;
                button.prop('disabled', true);
                $.ajax({
                    url: 'leave_game.php',
                    method: 'POST',
                    data: { iaName: iaName },
                    dataType: 'json',
                    success: function (response) { showToast(response.message, 'success'); },
                    error: function (xhr) {
                        button.prop('disabled', false);
                        showToast(xhr.responseJSON?.message || 'Demande impossible.', 'danger');
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
                                row.find('.delete-icon').toggleClass('disabled', status.running);
                                row.find('.reset-stats-btn').prop('disabled', status.running);
                                row.find('.leave-game-btn').prop('disabled', !status.running || !status.inGame);
                                row.find('.status-dot').toggleClass('running', status.running);
                                row.find('.status-label').text(status.running ? 'Démarrée' : 'Arrêtée');
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
