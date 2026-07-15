$(document).ready(function(){
    $.ajaxSetup({ headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content } });


    // Gestion du menu
    document.addEventListener("DOMContentLoaded", function () {
        const navbarToggler = document.querySelector(".navbar-toggler");
        const navbarCollapse = document.querySelector(".navbar-collapse");
        const navLinks = document.querySelectorAll(".nav-link");

        // Fonction pour fermer le menu après un clic sur un lien (sur mobile)
        function closeMenu() {
            if (window.innerWidth < 992 && navbarCollapse.classList.contains("show")) {
                navbarToggler.click();
            }
        }

        // Ajouter l'écouteur d'événement sur chaque lien du menu
        navLinks.forEach(link => {
            link.addEventListener("click", closeMenu);
        });

        // Gérer la fermeture du menu burger lorsqu'on clique en dehors
        document.addEventListener("click", function (event) {
            const isClickInsideMenu = navbarCollapse.contains(event.target) || navbarToggler.contains(event.target);
            if (!isClickInsideMenu && navbarCollapse.classList.contains("show")) {
                navbarToggler.click();
            }
        });
    });




    // Fonction pour afficher les messages
    function showMessage(type, message) {
        const allowed = ['success', 'danger', 'warning', 'info'];
        const safeType = allowed.includes(type) ? type : 'info';
        const alert = $('<div>').addClass(`alert alert-${safeType} alert-dismissible fade show`).attr('role', 'alert');
        alert.append(document.createTextNode(String(message)));
        alert.append($('<button>').addClass('btn-close').attr({'type':'button','data-bs-dismiss':'alert','aria-label':'Fermer'}));
        $('#alert-container').empty().append(alert);
    }

    // Fonction pour vérifier le statut du serveur
    function checkServerStatus() {
        $.ajax({
            url: '/admin/server_status.php',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                const serverIsOnline = response.server === 'online';

                // Mettre à jour le statut du serveur
                $('#server-status').text(serverIsOnline ? 'En ligne' : 'Hors ligne');
                $('#server-status').removeClass('text-success text-danger').addClass(serverIsOnline ? 'text-success' : 'text-danger');
                $('#connected-players').text(response.connectedPlayers);
                $('#active-games').text(response.activeGames || 0);
                $('#pending-reconnects').text(response.pendingReconnects || 0);
                $('#move-sql-latency').text(Number(response.averageMoveSqlMs || 0).toFixed(1));
                $('#websocket-errors').text(response.websocketErrors || 0);
                $('#backup-status').text(response.backupTimer === 'active' ? 'planifiée' : 'inactive');
                $('#restore-status').text(response.restoreTestTimer === 'active' ? 'planifié' : 'inactif');
                $('#last-backup').text(response.lastBackup?.completedAt || 'Jamais').toggleClass('text-danger', !response.lastBackup || response.lastBackup.ageSeconds > 129600);
                $('#last-restore').text(response.lastRestoreTest?.completedAt || 'Jamais').toggleClass('text-danger', !response.lastRestoreTest || response.lastRestoreTest.ageSeconds > 691200);
                const healthOk = response.health?.status === 'success';
                $('#health-status').text(response.health?.message || 'Aucun contrôle').toggleClass('text-success', healthOk).toggleClass('text-danger', !healthOk);
                const mailHealthy = response.mailConfigured && Number(response.mailQueueFailed || 0) === 0;
                const mailLabel = response.mailConfigured ? `configuré — ${response.mailQueuePending || 0} en attente, ${response.mailQueueFailed || 0} en échec` : 'non configuré';
                $('#mail-status').text(mailLabel).toggleClass('text-success', mailHealthy).toggleClass('text-danger', !mailHealthy);

                // Activer/désactiver les boutons en fonction de l'état du serveur
                $('#start-button').prop('disabled', serverIsOnline);
                $('#stop-button').prop('disabled', !serverIsOnline);
            },
            error: function() {
                $('#server-status').text('Erreur de connexion');
                $('#server-status').removeClass('text-success text-danger').addClass('text-warning');
                $('#connected-players').text('N/A');

                // Désactiver les deux boutons en cas d'erreur de connexion
                $('#start-button').prop('disabled', true);
                $('#stop-button').prop('disabled', true);
            }
        });
    }

    // Fonction pour vérifier l'utilisation des ressources du serveur
    function checkServerUsage() {
        $.ajax({
            url: '/admin/server_usage.php',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                $('#cpu-usage').text(response.cpu !== 'N/A' ? response.cpu.toFixed(1) : 'N/A');
                $('#memory-usage').text(response.memory !== 'N/A' ? response.memory.toFixed(1) : 'N/A');
            },
            error: function() {
                $('#cpu-usage').text('Erreur');
                $('#memory-usage').text('Erreur');
            }
        });
    }

    // Initialiser le statut et l'usage des ressources
    checkServerStatus();
    checkServerUsage();

    // Vérifier le statut toutes les 2 secondes
    setInterval(checkServerStatus, 2000);

    // Vérifier l'usage des ressources toutes les 5 secondes
    setInterval(checkServerUsage, 5000);

    // Gérer le clic sur le bouton Démarrer
    $('#start-button').click(function(){
        $.ajax({
            url: '/admin/start_server.php',
            method: 'POST',
            dataType: 'json',
            beforeSend: function() {
                showMessage('info', 'Démarrage du serveur...');
            },
            success: function(response) {
                if(response.status === 'success') {
                    showMessage('success', response.message);

                    // Lancer monitor_server.php via une requête AJAX à un script dédié
                    $.ajax({
                        url: '/admin/monitor_server.php',
                        method: 'POST',
                        dataType: 'json',
                        success: function(monitorResponse) {
                            if(monitorResponse.status === 'success') {
                                showMessage('success', 'Monitor démarré avec succès.');
                            } else {
                                showMessage('warning', 'Le monitor n\'a pas pu être démarré.');
                            }
                        },
                        error: function() {
                            showMessage('danger', 'Erreur lors du démarrage du monitor.');
                        }
                    });
                } else {
                    showMessage('danger', response.message);
                }
            },
            error: function() {
                showMessage('danger', 'Erreur lors de la tentative de démarrage du serveur.');
            }
        });
    });

    // Gérer le clic sur le bouton Arrêter
    $('#stop-button').click(function(){
        $.ajax({
            url: '/admin/stop_server.php',
            method: 'POST',
            dataType: 'json',
            beforeSend: function() {
                showMessage('info', 'Arrêt du serveur...');
            },
            success: function(response) {
                if(response.status === 'success') {
                    showMessage('success', response.message);
                } else if(response.status === 'partial_success') {
                    showMessage('warning', response.message);
                } else {
                    showMessage('danger', response.message);
                }
            },
            error: function() {
                showMessage('danger', 'Erreur lors de la tentative d\'arrêt du serveur.');
            }
        });
    });

    function resetScores(scope, playerId) {
        const isAll = scope === 'all';
        const target = isAll ? 'tous les joueurs' : $('#score-player option:selected').text().trim();
        if (!isAll && !playerId) {
            showMessage('warning', 'Sélectionnez un joueur.');
            return;
        }
        if (!window.confirm(`Confirmer la remise à zéro des scores pour ${target} ?`)) return;
        if (isAll && window.prompt('Pour confirmer, saisissez RESET') !== 'RESET') {
            showMessage('warning', 'Confirmation annulée.');
            return;
        }
        $.ajax({
            url: '/admin/reset_scores.php',
            method: 'POST',
            dataType: 'json',
            data: { scope, player_id: playerId || '', confirmation: isAll ? 'RESET' : 'PLAYER' },
            success: function(response) {
                if (response.success) {
                    showMessage('success', response.message);
                    window.setTimeout(() => window.location.reload(), 800);
                } else {
                    showMessage('danger', response.message);
                }
            },
            error: function(xhr) {
                showMessage('danger', xhr.responseJSON?.message || 'La réinitialisation a échoué.');
            }
        });
    }

    $('#reset-player-scores').click(function() {
        resetScores('player', $('#score-player').val());
    });
    $('#reset-all-scores').click(function() {
        resetScores('all', null);
    });

    let pendingBackupNonce = null;
    let backupOperationRunning = false;
    const restoreModalElement = document.getElementById('restore-modal');
    const restoreModal = restoreModalElement ? new bootstrap.Modal(restoreModalElement) : null;

    function formatBackupSize(bytes) {
        const value = Number(bytes || 0);
        if (value < 1024) return `${value} o`;
        if (value < 1048576) return `${(value / 1024).toFixed(1)} Kio`;
        return `${(value / 1048576).toFixed(1)} Mio`;
    }

    function setBackupControlsDisabled(disabled) {
        backupOperationRunning = disabled;
        $('#create-backup, .verify-backup, .restore-backup').prop('disabled', disabled);
    }

    function renderBackups(backups) {
        const body = $('#backup-list').empty();
        if (!backups.length) {
            body.append($('<tr>').append($('<td>').attr('colspan', 4).addClass('text-muted').text('Aucune sauvegarde répertoriée.')));
            return;
        }
        backups.forEach(backup => {
            const actions = $('<td>').addClass('text-end');
            actions.append($('<button>').addClass('btn btn-sm btn-outline-primary verify-backup').attr('data-backup', backup.id).text('Tester'));
            actions.append($('<button>').addClass('btn btn-sm btn-outline-danger restore-backup ms-1').attr('data-backup', backup.id).text('Restaurer'));
            const row = $('<tr>')
                .append($('<td>').text(backup.createdAt ? new Date(backup.createdAt).toLocaleString('fr-FR') : backup.id))
                .append($('<td>').text(formatBackupSize(backup.databaseBytes)))
                .append($('<td>').append($('<code>').text(String(backup.checksum || '').slice(0, 12))))
                .append(actions);
            body.append(row);
        });
        setBackupControlsDisabled(backupOperationRunning);
    }

    function refreshBackups() {
        $.getJSON('/admin/backup_status.php').done(response => {
            renderBackups(response.backups || []);
            setBackupControlsDisabled(Boolean(response.running));
            const operation = response.operation || {};
            if (response.running) {
                const runningLabels = {backup: 'Sauvegarde en cours…', verify: 'Test de restauration en cours…', restore: 'Restauration de la base en cours…'};
                $('#backup-operation').removeClass('d-none alert-success alert-danger').addClass('alert-info').text(runningLabels[operation.action] || 'Opération en cours…');
            } else if (pendingBackupNonce && operation.nonce === pendingBackupNonce && ['success', 'error'].includes(operation.status)) {
                const success = operation.status === 'success';
                const successLabels = {backup: 'Sauvegarde créée avec succès.', verify: 'Test de restauration réussi.', restore: 'Base restaurée avec succès. Les sessions utilisateur ont été révoquées.'};
                const message = success ? (successLabels[operation.action] || 'Opération terminée avec succès.') : (operation.message || 'L’opération a échoué.');
                $('#backup-operation').removeClass('d-none alert-info alert-success alert-danger').addClass(success ? 'alert-success' : 'alert-danger').text(message);
                showMessage(success ? 'success' : 'danger', message);
                pendingBackupNonce = null;
            }
        }).fail(() => $('#backup-list').html('<tr><td colspan="4" class="text-danger">Liste indisponible.</td></tr>'));
    }

    function startBackupOperation(data) {
        if (backupOperationRunning) return;
        setBackupControlsDisabled(true);
        $('#backup-operation').removeClass('d-none alert-success alert-danger').addClass('alert-info').text('Démarrage de l’opération…');
        $.post('/admin/backup_action.php', data)
            .done(response => { pendingBackupNonce = response.nonce; window.setTimeout(refreshBackups, 500); })
            .fail(xhr => {
                setBackupControlsDisabled(false);
                const message = xhr.responseJSON?.message || 'Impossible de démarrer l’opération.';
                $('#backup-operation').removeClass('d-none alert-info alert-success').addClass('alert-danger').text(message);
                showMessage('danger', message);
            });
    }

    $('#create-backup').click(() => startBackupOperation({action: 'backup'}));
    $('#backup-list').on('click', '.verify-backup', function() {
        startBackupOperation({action: 'verify', backup_id: $(this).data('backup')});
    }).on('click', '.restore-backup', function() {
        const id = String($(this).data('backup'));
        $('#restore-backup-id').val(id);
        $('#restore-backup-label').text(id);
        $('#restore-password, #restore-totp, #restore-confirmation').val('');
        restoreModal?.show();
    });
    $('#restore-form').submit(function(event) {
        event.preventDefault();
        const confirmation = $('#restore-confirmation').val();
        if (confirmation !== 'RESTAURER') {
            showMessage('warning', 'Saisissez exactement RESTAURER.');
            return;
        }
        const data = {action: 'restore', backup_id: $('#restore-backup-id').val(), password: $('#restore-password').val(), totp_code: $('#restore-totp').val(), confirmation};
        restoreModal?.hide();
        $('#restore-password, #restore-totp').val('');
        startBackupOperation(data);
    });
    refreshBackups();
    setInterval(refreshBackups, 3000);

    $('.toggle-player').click(function() {
        const button = $(this);
        const disabled = button.data('disabled');
        if (!window.confirm(disabled ? 'Désactiver ce compte ?' : 'Réactiver ce compte ?')) return;
        $.post('/admin/toggle_player.php', {player_id: button.data('player'), disabled})
            .done(response => { showMessage('success', response.message); window.setTimeout(() => location.reload(), 500); })
            .fail(xhr => showMessage('danger', xhr.responseJSON?.message || 'Modification impossible.'));
    });
    $('.delete-player').click(function() {
        const button = $(this);
        const username = String(button.data('username'));
        const confirmation = window.prompt(`Cette action est définitive, supprimera toutes les données de ${username} et redémarrera brièvement le serveur.\n\nPour confirmer, saisissez exactement : ${username}`);
        if (confirmation === null) return;
        if (confirmation !== username) {
            showMessage('warning', 'Le nom saisi ne correspond pas. Suppression annulée.');
            return;
        }
        button.prop('disabled', true);
        $.post('/admin/delete_player.php', {player_id: button.data('player'), confirmation})
            .done(response => {
                showMessage('success', response.message);
                window.setTimeout(() => location.reload(), 700);
            })
            .fail(xhr => {
                button.prop('disabled', false);
                showMessage('danger', xhr.responseJSON?.message || 'Suppression impossible.');
            });
    });
});
